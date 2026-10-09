<?php

namespace Tests\Feature\M12;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\EcritureCaisse;
use App\Models\HistoriqueAction;
use App\Models\ModificationEnAttente;
use App\Models\OtpValidation;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\TauxChange;
use App\Models\User;
use App\Services\Paiement\FraisOrangeMoney;
use App\Support\JoursOuvres;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * M12-0 — Prérequis du module « Ordres de mission » (spec v2.2 du 07/10/2026) :
 * frais Orange Money (§6.6), taux de change du jour, jours ouvrés (RG-M07-02), paramètres ODM, rôles et n° OM (RG-M02-05).
 */
class PrerequisTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private Caisse $om;
    private Caisse $especes;

    protected function setUp(): void
    {
        parent::setUp();

        $conakry = Site::factory()->conakry()->create();
        $this->especes = Caisse::factory()->create([
            'code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id, 'plafond_retrait' => 20000000,
        ]);
        $this->om = Caisse::factory()->orangeMoney()->create([
            'code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $conakry->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------
     * Frais Orange Money (§6.6)
     * ------------------------------------------------------------------ */

    /** Exemples de la spec (§6.6, annexes B.1 à B.3) */
    public function test_les_frais_om_se_calculent_par_palier_sur_le_total_du_bon(): void
    {
        $cas = [
            3250000 => [32500, 3282500],     // B.1 : 1 %
            7000000 => [56000, 7056000],     // §6.6 : 0,8 %
            8250000 => [66000, 8316000],     // §6.6
            6500000 => [52000, 6552000],     // B.2, bon groupé
            5250000 => [42000, 5292000],     // B.3, prolongation
            5000000 => [50000, 5050000],     // borne haute du premier palier
            100001 => [1001, 101002],        // 1 000,01 arrondi au franc supérieur
            5000001 => [40001, 5040002],     // 40 000,008 arrondi au franc supérieur
        ];
        foreach ($cas as $total => [$frais, $verse]) {
            $calcul = FraisOrangeMoney::calculer($total);
            $this->assertSame($frais, $calcul['frais'], "Frais de {$total}");
            $this->assertEquals($verse, $calcul['montant_verse'], "Montant versé pour {$total}");
        }
    }

    /** PO-01 : ≤ 100 000 et > 15 000 000 GNF, pas de calcul */
    public function test_hors_paliers_les_frais_ne_sont_pas_calcules(): void
    {
        $this->assertNull(FraisOrangeMoney::calculer(100000));
        $this->assertNull(FraisOrangeMoney::calculer(15000001));
        $this->assertSame(120000, FraisOrangeMoney::calculer(15000000)['frais']);
    }

    public function test_la_grille_des_paliers_est_un_parametre(): void
    {
        Parametre::majValeur('frais_om_paliers', '[{"de":1,"a":20000000,"taux":2}]');

        $this->assertSame(2000, FraisOrangeMoney::calculer(100000)['frais']);
        $this->assertSame(400000, FraisOrangeMoney::calculer(20000000)['frais']);
    }

    /** RG-M06-07 : le caissier retient OM, les frais s'ajoutent au montant versé et la caisse OM le décaisse */
    public function test_un_paiement_om_ajoute_les_frais_au_montant_verse(): void
    {
        $this->om->crediter(10000000, 'approvisionnement');
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 3250000]);
        $this->otpVerifie($bon);

        $this->actingAs(User::factory()->role('caissier')->create())
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money'])
            ->assertRedirect(route('bons-caisse.show', $bon));

        $bon->refresh();
        $this->assertSame('PAYE', $bon->statut);
        $this->assertEquals(32500, (float) $bon->frais_om);
        $this->assertEquals(1, (float) $bon->frais_om_taux);
        $this->assertFalse($bon->frais_om_saisis);
        $this->assertEquals(3282500, (float) $bon->montant_verse);
        $this->assertEquals(3250000, (float) $bon->montant);   // la dépense reste hors frais (seuil DP)
        $this->assertEquals(10000000 - 3282500, (float) $this->om->fresh()->solde);
        $this->assertEquals(3282500, (float) EcritureCaisse::where('nature', 'paiement_bon')->sole()->montant);

        $journal = HistoriqueAction::where('bon_caisse_id', $bon->id)->where('action', HistoriqueAction::ACTION_PAIEMENT)->sole();
        $this->assertStringContainsString('frais Orange Money 32' . self::NBSP . '500' . self::NBSP . 'GNF (1 %), montant versé 3' . self::NBSP . '282' . self::NBSP . '500' . self::NBSP . 'GNF', $journal->commentaire);
    }

    /** PO-01 : hors paliers, le caissier saisit les frais ; le champ est tracé */
    public function test_hors_paliers_les_frais_sont_saisis_au_paiement(): void
    {
        $this->om->crediter(1000000, 'approvisionnement');
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 80000]);
        $otp = $this->otpVerifie($bon);
        $caissier = User::factory()->role('caissier')->create();

        $this->actingAs($caissier)
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money'])
            ->assertSessionHas('error', 'Montant hors des paliers Orange Money : saisissez les frais à ajouter au montant versé.');
        $this->assertSame('APPROUVE', $bon->fresh()->statut);
        $this->assertFalse((bool) $otp->fresh()->is_used);

        $this->actingAs($caissier)
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money', 'frais_om' => 1500])
            ->assertRedirect(route('bons-caisse.show', $bon));

        $bon->refresh();
        $this->assertEquals(1500, (float) $bon->frais_om);
        $this->assertNull($bon->frais_om_taux);
        $this->assertTrue($bon->frais_om_saisis);
        $this->assertEquals(81500, (float) $bon->montant_verse);
        $this->assertEquals(918500, (float) $this->om->fresh()->solde);
    }

    public function test_en_especes_aucun_frais_n_est_ajoute(): void
    {
        $this->especes->crediter(5000000, 'approvisionnement');
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 750000]);
        $this->otpVerifie($bon);

        $this->actingAs(User::factory()->role('caissier')->create())
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes', 'frais_om' => 9999]);

        $bon->refresh();
        $this->assertNull($bon->frais_om);
        $this->assertEquals(750000, (float) $bon->montant_verse);
        $this->assertEquals(4250000, (float) $this->especes->fresh()->solde);
    }

    /** Le solde de la caisse OM doit couvrir le montant versé, frais compris */
    public function test_le_solde_om_doit_couvrir_les_frais(): void
    {
        $this->om->crediter(3260000, 'approvisionnement');
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 3250000]);
        $this->otpVerifie($bon);

        $this->actingAs(User::factory()->role('caissier')->create())
            ->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money'])
            ->assertSessionHas('error');

        $this->assertSame('APPROUVE', $bon->fresh()->statut);
    }

    /** MSG-M03-06 : estimation affichée au caissier */
    public function test_la_fiche_du_bon_donne_l_estimation_des_frais_om(): void
    {
        $bon = BonCaisse::factory()->approuve()->create(['montant' => 7000000]);

        $this->actingAs(User::factory()->role('caissier')->create())
            ->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('fraisOrangeMoney.frais', 56000)
                ->where('fraisOrangeMoney.taux_texte', '0,8')
                ->where('fraisOrangeMoney.montant_verse', 7056000));

        $this->assertSame(
            'Si le caissier retient Orange Money : frais estimés 56' . self::NBSP . '000 GNF (0,8 %), montant versé 7' . self::NBSP . '056' . self::NBSP . '000 GNF.',
            \App\Exceptions\ErreurMetier::texte('MSG-M03-06', ['frais' => 56000, 'taux' => '0,8', 'montant_verse' => 7056000]),
        );
    }

    /* ------------------------------------------------------------------
     * Jours ouvrés (RG-M07-02)
     * ------------------------------------------------------------------ */

    public function test_les_jours_ouvres_excluent_les_week_ends_et_les_jours_feries(): void
    {
        Parametre::majValeur('jours_feries', '10-02, 10-14, 2026-10-05');

        // Jeudi 01/10/2026 + 3 jours ouvrés : vendredi 02/10 férié, week-end, lundi 05/10 férié → 06, 07, 08/10
        $this->assertSame('2026-10-08', JoursOuvres::ajouter(Carbon::parse('2026-10-01'), 3)->toDateString());
        $this->assertSame('2026-10-12', JoursOuvres::ajouter(Carbon::parse('2026-10-09'), 1)->toDateString());   // vendredi → lundi
        $this->assertFalse(JoursOuvres::estOuvre(Carbon::parse('2027-10-14')));                                   // date fixe, chaque année (un jeudi)
        $this->assertTrue(JoursOuvres::estOuvre(Carbon::parse('2027-10-05')));                                    // date mobile, 2026 seulement
        $this->assertSame('2026-10-06', JoursOuvres::retrancher(Carbon::parse('2026-10-08'), 2)->toDateString());
    }

    public function test_l_echeance_d_un_bp_se_compte_en_jours_ouvres(): void
    {
        Carbon::setTestNow('2026-10-08 10:00');   // jeudi
        $bp = BonCaisse::factory()->provisoire()->make(['lie_mission' => false]);
        $this->assertSame('2026-10-12', $bp->dateLimiteRegularisation()->toDateString());   // 2 jours ouvrés : vendredi, lundi

        $mission = BonCaisse::factory()->provisoire()->make(['lie_mission' => true, 'date_retour_mission' => '2026-10-09']);
        $this->assertSame('2026-10-14', $mission->dateLimiteRegularisation()->toDateString());   // 3 jours ouvrés après le vendredi 09/10
    }

    /* ------------------------------------------------------------------
     * Paramètres ODM
     * ------------------------------------------------------------------ */

    public function test_les_parametres_odm_ont_les_valeurs_de_la_spec(): void
    {
        $this->assertSame(250000.0, Parametre::valeur('odm_indemnite_journaliere'));
        $this->assertSame(500000.0, Parametre::valeur('odm_hebergement_nuit'));
        $this->assertSame(22000.0, Parametre::valeur('odm_bareme_fcfa_non_cadre'));
        $this->assertSame(34000.0, Parametre::valeur('odm_bareme_fcfa_cadre'));
        $this->assertSame(10.0, Parametre::valeur('odm_participants_max'));
        $this->assertSame('Indemnité de repas', Parametre::valeur('odm_libelle_indemnite_1'));
        $this->assertSame('Indemnité de déplacement', Parametre::valeur('odm_libelle_indemnite_2'));
        $this->assertTrue(Parametre::valeur('odm_genere_bp'));
        $this->assertFalse(Parametre::valeur('odm_etape_rh'));
        $this->assertSame('par_participant', Parametre::valeur('odm_mode_generation'));
        $this->assertSame('variante_a', Parametre::valeur('odm_prise_en_charge_client'));
        $this->assertSame(2.0, Parametre::valeur('odm_delai_rappel'));
    }

    public function test_une_valeur_de_parametre_est_controlee_selon_son_type(): void
    {
        $administrateur = User::factory()->role('administrateur')->create();
        $modifier = fn (string $cle, string $valeur) => $this->actingAs($administrateur)
            ->put(route('parametrage.parametres.update', Parametre::where('cle', $cle)->sole()), ['valeur' => $valeur]);

        $modifier('odm_mode_generation', 'tous')->assertSessionHasErrors(['valeur' => 'Valeur attendue : par_participant, groupe.']);
        $modifier('jours_feries', '01-01, 13-01')->assertSessionHasErrors(['valeur' => 'Dates invalides : 13-01 (format MM-JJ ou AAAA-MM-JJ).']);
        $modifier('frais_om_paliers', '[{"de":1,"a":10,"taux":1},{"de":5,"a":20,"taux":1}]')->assertSessionHasErrors(['valeur' => 'Deux paliers se chevauchent.']);
        $modifier('odm_indemnite_journaliere', 'beaucoup')->assertSessionHasErrors(['valeur' => 'Saisissez un nombre positif.']);
        $this->assertSame(0, ModificationEnAttente::count());

        $modifier('odm_mode_generation', 'groupe')->assertSessionHasNoErrors();
        $demande = ModificationEnAttente::sole();
        $this->assertSame('groupe', $demande->nouvelle_valeur);

        /* Double validation par un second administrateur */
        $demande->approuver(User::factory()->role('administrateur')->create());
        Cache::flush();
        $this->assertSame('groupe', Parametre::valeur('odm_mode_generation'));
    }

    public function test_l_ecran_parametrage_propose_les_choix_et_le_groupe_odm(): void
    {
        $this->actingAs(User::factory()->role('administrateur')->create())
            ->get(route('parametrage.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('choixParametres.odm_prise_en_charge_client.variante_b', 'Payée directement par le client')
                ->where('parametres', fn ($parametres) => collect($parametres)->where('groupe', 'odm')->count() === 12));
    }

    /* ------------------------------------------------------------------
     * Taux de change FCFA → GNF
     * ------------------------------------------------------------------ */

    public function test_la_tresorerie_saisit_le_taux_du_jour(): void
    {
        $tresoriere = User::factory()->role('demandeur')->create();
        $tresoriere->ajouterRoles(['tresorerie']);
        TauxChange::create(['date_taux' => today()->subDays(3), 'taux' => 14.2]);

        $this->assertNull(TauxChange::duJour());
        $this->assertEquals(14.2, (float) TauxChange::dernier()->taux);

        $this->actingAs($tresoriere)->get(route('tresorerie.taux.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Tresorerie/TauxChange')->where('tauxDuJour', null)->where('peutSaisir', true));

        $this->actingAs($tresoriere)->post(route('tresorerie.taux.store'), ['taux' => '14.52'])
            ->assertSessionHas('success', 'Taux du jour enregistré : 1 FCFA = 14,52 GNF.');

        $duJour = TauxChange::duJour();
        $this->assertEquals(14.52, (float) $duJour->taux);
        $this->assertSame($tresoriere->id, $duJour->saisi_par_id);
        $this->assertTrue($duJour->is(TauxChange::dernier()));
        $this->assertSame(319440, $duJour->convertir(22000));   // 1 jour non-cadre : 22 000 FCFA
    }

    public function test_corriger_le_taux_du_jour_exige_un_motif(): void
    {
        $daf = User::factory()->role('daf')->create();
        TauxChange::create(['date_taux' => today(), 'taux' => 14.5, 'saisi_par_id' => $daf->id]);

        $this->actingAs($daf)->post(route('tresorerie.taux.store'), ['taux' => '15'])
            ->assertSessionHasErrors(['motif' => 'Indiquez le motif de la correction du taux du jour.']);

        $this->actingAs($daf)->post(route('tresorerie.taux.store'), ['taux' => '15', 'motif' => 'Erreur de frappe'])
            ->assertSessionHas('success');
        $this->assertSame(1, TauxChange::count());
        $this->assertSame('Correction : Erreur de frappe (ancien taux 14,5)', TauxChange::duJour()->commentaire);
    }

    public function test_un_caissier_consulte_le_taux_sans_pouvoir_le_saisir(): void
    {
        $caissier = User::factory()->role('caissier')->create();

        $this->actingAs($caissier)->get(route('tresorerie.taux.index'))->assertOk();
        $this->actingAs($caissier)->post(route('tresorerie.taux.store'), ['taux' => '14'])->assertForbidden();
        $this->actingAs(User::factory()->role('demandeur')->create())->get(route('tresorerie.taux.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------
     * Rôles et n° Orange Money
     * ------------------------------------------------------------------ */

    public function test_les_roles_du_module_odm_existent(): void
    {
        $this->assertSame("Chef d'atelier / chef d'équipe", User::ROLES['chef_atelier']);
        $this->assertSame('DP adjoint', User::ROLES['dp_adjoint']);
        $this->assertContains('logistique', User::ROLES_DECLARES);
    }

    /** RG-M02-05 */
    public function test_le_numero_om_est_au_format_guineen(): void
    {
        $administrateur = User::factory()->role('administrateur')->create();
        $salarie = User::factory()->create();
        $donnees = fn (string $numero) => [
            'name' => $salarie->name, 'prenom' => $salarie->prenom, 'email' => $salarie->email, 'matricule' => $salarie->matricule ?? 'M001',
            'role' => $salarie->role, 'service' => 'Technique', 'site' => 'Conakry', 'numero_om' => $numero, 'statut_cadre' => 'cadre',
        ];

        $this->actingAs($administrateur)->put(route('utilisateurs.update', $salarie), $donnees('522 33 44 55'))
            ->assertSessionHasErrors(['numero_om' => 'Le n° Orange Money est au format guinéen (9 chiffres commençant par 6).']);

        $this->actingAs($administrateur)->put(route('utilisateurs.update', $salarie), $donnees('+224 622 33 44 55'))
            ->assertSessionHasNoErrors();
        $salarie->refresh();
        $this->assertSame('622334455', $salarie->numero_om);
        $this->assertSame('cadre', $salarie->statut_cadre);
    }

    public function test_les_roles_complementaires_passent_par_la_double_validation(): void
    {
        $administrateur = User::factory()->role('administrateur')->create();
        $chef = User::factory()->role('demandeur')->create(['service' => 'Technique', 'site' => 'Conakry']);

        $this->actingAs($administrateur)->put(route('utilisateurs.update', $chef), [
            /* Compte créé par l'import des référentiels, sans matricule : il reste modifiable */
            'name' => $chef->name, 'prenom' => $chef->prenom, 'email' => $chef->email, 'matricule' => '',
            'role' => 'demandeur', 'service' => 'Technique', 'site' => 'Conakry', 'roles_complementaires' => ['chef_atelier'],
        ])->assertSessionHasNoErrors();

        $this->assertFalse($chef->fresh()->aLeRole('chef_atelier'));
        $demande = ModificationEnAttente::where('type_entite', 'utilisateur_roles')->sole();
        $this->assertSame('chef_atelier', $demande->nouvelle_valeur);

        $demande->approuver(User::factory()->role('administrateur')->create());
        $chef = User::find($chef->id);
        $this->assertTrue($chef->aLeRole('chef_atelier'));
        $this->assertTrue($chef->aLeRole('demandeur'));
    }

    /* ------------------------------------------------------------------ */

    private function otpVerifie(BonCaisse $bon): OtpValidation
    {
        return OtpValidation::create([
            'bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false,
        ]);
    }
}
