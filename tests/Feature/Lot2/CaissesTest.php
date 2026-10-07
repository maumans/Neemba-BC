<?php

namespace Tests\Feature\Lot2;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\EcritureCaisse;
use App\Models\ModificationEnAttente;
use App\Models\MouvementCaisse;
use App\Models\Notification;
use App\Models\OtpValidation;
use App\Models\Site;
use App\Models\User;
use App\Services\RapportJournalierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Lot 2 — caisses (RG-BC-11, RG-BC-12, ANO-09, ANO-10) et registre des écritures de caisse.
 */
class CaissesTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private Site $conakry;
    private Caisse $especes;
    private Caisse $om;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conakry = Site::factory()->conakry()->create();
        $this->especes = Caisse::factory()->create([
            'code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $this->conakry->id,
            'plafond_retrait' => 20000000, 'seuil_alerte' => 1000000,
        ]);
        $this->om = Caisse::factory()->orangeMoney()->create([
            'code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $this->conakry->id,
        ]);
    }

    /* ------------------------------------------------------------------
     * Registre
     * ------------------------------------------------------------------ */

    public function test_chaque_mouvement_est_inscrit_au_registre_avec_solde_avant_et_apres(): void
    {
        $this->especes->crediter(5000000, 'approvisionnement', ['libelle' => 'Appro']);
        $ecriture = $this->especes->debiter(1200000, 'paiement_bon');

        $this->assertEquals(3800000, (float) $this->especes->fresh()->solde);
        $this->assertEquals(5000000, (float) $ecriture->solde_avant);
        $this->assertEquals(3800000, (float) $ecriture->solde_apres);
        $this->assertSame('sortie', $ecriture->sens);
        $this->assertSame(2, EcritureCaisse::where('caisse_id', $this->especes->id)->count());
    }

    public function test_une_ecriture_doit_avoir_un_montant_positif(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->especes->crediter(0, 'ajustement');
    }

    public function test_le_solde_a_une_date_se_lit_dans_le_registre(): void
    {
        $this->especes->crediter(1000000, 'approvisionnement', ['date_ecriture' => now()->subDays(2)]);
        $this->especes->debiter(300000, 'paiement_bon', ['date_ecriture' => now()->subDay()]);

        $this->assertEquals(1000000, $this->especes->soldeAu(now()->subDay()->startOfDay()));
        $this->assertEquals(700000, $this->especes->soldeAu(now()));
        $this->assertNull($this->especes->soldeAu(now()->subDays(3)));
    }

    /* ------------------------------------------------------------------
     * Caisse payeuse (RG-BC-12)
     * ------------------------------------------------------------------ */

    public function test_la_caisse_payeuse_depend_du_site_et_du_mode(): void
    {
        $boke = Site::factory()->create(['nom' => 'Boke']);
        $caisseBoke = Caisse::factory()->create(['site_id' => $boke->id, 'libelle' => 'Caisse principale Boke']);
        Site::factory()->create(['nom' => 'Kindia']);   // site sans caisse

        $this->assertTrue(Caisse::payeusePour('Boke', 'especes')->is($caisseBoke));
        $this->assertTrue(Caisse::payeusePour('Kindia', 'especes')->is($this->especes));   // repli : caisse principale de Conakry
        $this->assertTrue(Caisse::payeusePour('Boke', 'orange_money')->is($this->om));      // caisse OM unique
        $this->assertNull(Caisse::payeusePour('Conakry', 'virement'));
    }

    public function test_un_paiement_en_especes_debite_la_caisse_payeuse_et_l_inscrit_au_registre(): void
    {
        $this->especes->crediter(5000000, 'approvisionnement');
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 750000]);
        $this->otpVerifie($bon);

        $this->actingAs(User::factory()->role('caissier')->create())
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes'])
            ->assertRedirect(route('bons-caisse.show', $bon));

        $this->assertEquals(4250000, (float) $this->especes->fresh()->solde);
        $this->assertSame($this->especes->id, $bon->fresh()->caisse_id);
        $ecriture = EcritureCaisse::where('nature', 'paiement_bon')->sole();
        $this->assertSame($bon->id, $ecriture->bon_caisse_id);
        $this->assertEquals(750000, (float) $ecriture->montant);
    }

    /** RG-BC-11, TC-BC-009 */
    public function test_au_dela_du_plafond_de_retrait_le_paiement_en_especes_est_refuse(): void
    {
        $this->especes->crediter(30000000, 'approvisionnement');
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 23500000]);
        $otp = $this->otpVerifie($bon);

        $this->actingAs(User::factory()->role('caissier')->create())
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes'])
            ->assertSessionHas('error', 'Au-delà de 20' . self::NBSP . '000' . self::NBSP . '000 GNF, ce bon ne peut pas être payé en espèces '
                . 'sur la caisse principale Conakry. Choisissez Orange Money, chèque ou virement.');

        $this->assertSame('APPROUVE', $bon->fresh()->statut);
        $this->assertFalse((bool) $otp->fresh()->is_used);
        $this->assertEquals(30000000, (float) $this->especes->fresh()->solde);
    }

    /* ------------------------------------------------------------------
     * Mouvements de caisse et alertes
     * ------------------------------------------------------------------ */

    public function test_un_approvisionnement_valide_credite_la_caisse(): void
    {
        $caissier = User::factory()->role('caissier')->create();
        $this->actingAs($caissier)->post(route('mouvements-caisse.store'), [
            'type' => 'approvisionnement', 'caisse_id' => $this->om->id, 'montant' => 2000000, 'motif' => 'Recharge du compte OM',
        ])->assertRedirect(route('mouvements-caisse.index'));

        $mouvement = MouvementCaisse::sole();
        $this->assertSame('om', $mouvement->type_caisse);
        $this->assertEquals(0, (float) $this->om->fresh()->solde);   // rien avant validation

        $this->actingAs(User::factory()->role('daf')->create())
            ->post(route('mouvements-caisse.valider', $mouvement))
            ->assertSessionHas('success');

        $this->assertEquals(2000000, (float) $this->om->fresh()->solde);
        $this->assertSame($mouvement->id, EcritureCaisse::sole()->mouvement_caisse_id);
    }

    public function test_un_caissier_ne_peut_pas_agir_sur_la_caisse_d_un_autre_site(): void
    {
        $boke = Site::factory()->create(['nom' => 'Boke']);
        $caisseBoke = Caisse::factory()->create(['site_id' => $boke->id]);

        $this->actingAs(User::factory()->role('caissier')->create(['site' => 'Conakry']))
            ->post(route('mouvements-caisse.store'), [
                'type' => 'approvisionnement', 'caisse_id' => $caisseBoke->id, 'montant' => 1000, 'motif' => 'Essai interdit',
            ])->assertForbidden();
    }

    /** ANO-10 */
    public function test_une_caisse_sous_son_seuil_declenche_une_alerte(): void
    {
        $daf = User::factory()->role('daf')->create();
        $this->especes->crediter(1200000, 'approvisionnement');
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 500000]);
        $this->otpVerifie($bon);

        $this->actingAs(User::factory()->role('caissier')->create())
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes']);

        $alerte = Notification::where('destinataire_id', $daf->id)->where('type', 'alerte_solde')->sole();
        $this->assertStringContainsString('Caisse principale Conakry', $alerte->message);
    }

    /* ------------------------------------------------------------------
     * Rapport journalier lu dans le registre (Q13)
     * ------------------------------------------------------------------ */

    public function test_le_rapport_journalier_lit_le_registre(): void
    {
        $hier = now()->subDay()->startOfDay();
        $this->especes->crediter(3000000, 'solde_initial', ['date_ecriture' => $hier->copy()->subDays(2)]);
        $this->especes->debiter(400000, 'paiement_bon', ['date_ecriture' => $hier->copy()->subDay()->setTime(15, 0)]);
        $this->especes->crediter(1000000, 'approvisionnement', ['date_ecriture' => $hier->copy()->setTime(9, 0)]);
        $this->especes->debiter(250000, 'paiement_bon', ['date_ecriture' => $hier->copy()->setTime(11, 0)]);
        $this->om->crediter(500000, 'approvisionnement', ['date_ecriture' => $hier->copy()->setTime(10, 0)]);

        $rapport = RapportJournalierService::construire($hier, 'Conakry')['rapport'];

        $this->assertEquals(2600000, (float) $rapport->solde_ouverture_especes);   // 3 000 000 − 400 000 la veille
        $this->assertEquals(1000000, (float) $rapport->total_entrees_especes);
        $this->assertEquals(250000, (float) $rapport->total_sorties_especes);
        $this->assertEquals(3350000, (float) $rapport->solde_cloture_especes);
        $this->assertEquals(0, (float) $rapport->solde_ouverture_om);
        $this->assertEquals(500000, (float) $rapport->solde_cloture_om);
    }

    /* ------------------------------------------------------------------
     * Paramétrage
     * ------------------------------------------------------------------ */

    public function test_un_nouveau_site_recoit_sa_caisse_principale(): void
    {
        $this->actingAs($this->administrateur())
            ->post(route('parametrage.sites.store'), ['code' => '77', 'nom' => 'Kindia', 'ville' => 'Kindia'])
            ->assertSessionHas('success');

        $caisse = Caisse::whereHas('site', fn ($q) => $q->where('nom', 'Kindia'))->sole();
        $this->assertSame('Caisse principale Kindia', $caisse->libelle);
        $this->assertSame('KIN-ESP', $caisse->code);
        $this->assertEquals(0, (float) $caisse->solde);
    }

    public function test_plafond_et_solde_d_une_caisse_passent_par_la_double_validation(): void
    {
        $this->especes->crediter(1000000, 'approvisionnement');
        $demandeur = $this->administrateur();

        $this->actingAs($demandeur)->put(route('parametrage.caisses.update', $this->especes), [
            'code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry',
            'plafond_retrait' => 30000000, 'seuil_alerte' => 1000000, 'solde' => 1150000,
        ])->assertSessionHas('success');

        $this->assertEquals(20000000, (float) $this->especes->fresh()->plafond_retrait);   // inchangé avant approbation
        $this->assertSame(2, ModificationEnAttente::where('type_entite', 'caisse')->where('statut', 'en_attente')->count());

        $valideur = $this->administrateur();
        foreach (ModificationEnAttente::where('type_entite', 'caisse')->get() as $modification) {
            $this->actingAs($valideur)->post(route('admin.modifications-en-attente.approuver', $modification))->assertSessionHas('success');
        }

        $caisse = $this->especes->fresh();
        $this->assertEquals(30000000, (float) $caisse->plafond_retrait);
        $this->assertEquals(1150000, (float) $caisse->solde);
        $correction = EcritureCaisse::where('nature', 'correction_solde')->sole();
        $this->assertSame('entree', $correction->sens);
        $this->assertEquals(150000, (float) $correction->montant);
    }

    /** ANO-09 */
    public function test_le_tableau_de_bord_affiche_les_soldes_par_caisse(): void
    {
        Caisse::factory()->create(['site_id' => Site::factory()->create(['nom' => 'Boke'])->id]);

        $this->actingAs(User::factory()->role('caissier')->create(['site' => 'Conakry']))
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('soldesCaisses', 2));

        $this->actingAs(User::factory()->role('daf')->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->has('soldesCaisses', 3));
    }

    /** Écrans touchés par le lot 2 : ils s'ouvrent sans erreur */
    public function test_les_ecrans_de_caisse_s_ouvrent(): void
    {
        $caissier = User::factory()->role('caissier')->create(['site' => 'Conakry']);
        $bon = BonCaisse::factory()->approuve()->create();

        $this->actingAs($caissier)->get(route('rapports.index'))->assertOk();
        $this->actingAs($caissier)->get(route('rapports.index', ['site' => 'Conakry']))->assertOk();
        $this->actingAs($caissier)->get(route('rapports.create'))->assertOk();
        $this->actingAs($caissier)->get(route('mouvements-caisse.create'))
            ->assertInertia(fn (Assert $page) => $page->has('caisses', 2));
        $this->actingAs($caissier)->get(route('mouvements-caisse.index'))->assertOk();
        /* Un caissier voit les bons approuvés de son site (un DAF ne voit que ceux de son niveau : règle v15, Q4) */
        $this->actingAs($caissier)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page->where('soldeCaisseSite.caisse_especes', 'Caisse principale Conakry'));
        $this->actingAs($this->administrateur())->get(route('parametrage.index'))
            ->assertInertia(fn (Assert $page) => $page->has('caisses', 2));
    }

    /* ------------------------------------------------------------------ */

    private function otpVerifie(BonCaisse $bon): OtpValidation
    {
        return OtpValidation::create([
            'bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false,
        ]);
    }

    private function administrateur(): User
    {
        return User::factory()->role('administrateur')->create();
    }
}
