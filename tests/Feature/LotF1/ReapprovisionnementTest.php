<?php

namespace Tests\Feature\LotF1;

use App\Models\Caisse;
use App\Models\DemandeReapprovisionnement as Demande;
use App\Models\EcritureCaisse;
use App\Models\Notification;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use App\Services\Caisse\ArreteCaisse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot F1b (spec v2.4, RG-M08-04 à RG-M08-09, MSG-M08-02 à 04 ; Q89) : demande de réapprovisionnement d'une caisse, circuit
 * trésorerie → Finance → DP (ou deux membres du CODIR en son absence), exécution, réception.
 */
class ReapprovisionnementTest extends TestCase
{
    use RefreshDatabase;

    private Caisse $conakry;
    private Caisse $boke;
    private User $caissier;
    private User $caissierBoke;
    private User $tresoriere;
    private User $chefComptable;
    private User $daf;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->conakry = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => Site::factory()->conakry()->create()->id,
            'solde' => 0, 'plafond_caisse' => 250000000, 'seuil_alerte' => 50000000]);
        $this->boke = Caisse::factory()->create(['code' => 'BOK-ESP', 'libelle' => 'Caisse Boké', 'site_id' => Site::factory()->create(['nom' => 'Boke'])->id,
            'solde' => 0, 'plafond_caisse' => 20000000]);
        $this->conakry->crediter(40000000, 'solde_initial');
        $this->boke->crediter(8000000, 'solde_initial');
        $this->caissier = User::factory()->role('caissier')->create(['prenom' => 'Youssouf', 'name' => 'TOURE', 'caisse_id' => $this->conakry->id]);
        $this->caissierBoke = User::factory()->role('caissier')->create(['caisse_id' => $this->boke->id]);
        $this->tresoriere = tap(User::factory()->create(['prenom' => 'Mariamagbè', 'name' => 'CAMARA']), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->chefComptable = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['chef_comptable']));
        $this->daf = User::factory()->role('daf')->create();
    }

    /** PV d'arrêté du jour signé par le gérant (billetage = solde, sans écart sauf indication) */
    private function pvDuJour(Caisse $caisse, User $gerant, ?array $billetage = null): void
    {
        $billetage ??= ['20000' => (int) ((float) $caisse->fresh()->solde / 20000)];
        $pv = ArreteCaisse::etablir($gerant, $caisse->fresh(), 'reapprovisionnement', $billetage, null, null);
        ArreteCaisse::signer($gerant, $pv, 'gerant');
    }

    private function demander(User $auteur, Caisse $caisse, float $montant, array $champs = [])
    {
        return $this->actingAs($auteur)->post(route('reapprovisionnements.store'), $champs + [
            'caisse_id' => $caisse->id, 'montant' => $montant,
            'rapprochement' => UploadedFile::fake()->create('rapprochement.pdf', 50, 'application/pdf'),
        ]);
    }

    private function agir(User $u, Demande $d, string $action, array $champs = [])
    {
        return $this->actingAs($u)->post(route('reapprovisionnements.agir', $d), ['action' => $action] + $champs);
    }

    /** RG-M08-05, RG-M08-06 : PV du jour signé et rapprochement exigés ; montant proposé = plafond − solde */
    public function test_la_demande_exige_le_pv_du_jour_et_le_rapprochement(): void
    {
        $this->demander($this->caissier, $this->conakry, 210000000)->assertSessionHasErrors(['pv' => __('MSG-M08-03')]);

        /* PV établi mais pas signé par le gérant : toujours refusé */
        ArreteCaisse::etablir($this->caissier, $this->conakry->fresh(), 'reapprovisionnement', ['20000' => 2000], null, null);
        $this->demander($this->caissier, $this->conakry, 210000000)->assertSessionHasErrors('pv');

        /* PV d'hier : refusé */
        $this->travel(-1)->days();
        $this->pvDuJour($this->conakry, $this->caissier);
        $this->travelBack();
        $this->demander($this->caissier, $this->conakry, 210000000)->assertSessionHasErrors('pv');

        $this->pvDuJour($this->conakry, $this->caissier, ['20000' => 1999]);
        $this->actingAs($this->caissier)->get(route('reapprovisionnements.create'))->assertInertia(fn (Assert $page) => $page
            ->component('Caisses/Reapprovisionnements/Create')
            ->where('caisses.0.propose', 210000000)
            ->where('caisses.0.pv.ecart', -20000));
        $this->actingAs($this->caissier)->post(route('reapprovisionnements.store'), ['caisse_id' => $this->conakry->id, 'montant' => 210000000])
            ->assertSessionHasErrors('rapprochement');

        $this->demander($this->caissier, $this->conakry, 210000000)->assertRedirect();
        $d = Demande::sole();
        $this->assertSame(['soumise', 'DRA-CKY-' . now()->year . '-0001', '210000000.00', '40000000.00'], [$d->statut, $d->numero, $d->montant_propose, $d->solde_a_la_demande]);
        Storage::disk('public')->assertExists($d->rapprochement);
        $this->assertTrue(Notification::where('destinataire_id', $this->tresoriere->id)->where('type', 'reapprovisionnement')->exists());

        /* Une seule demande en cours par caisse ; le caissier d'une autre caisse ne la voit pas et ne demande pas pour elle */
        $this->demander($this->caissier, $this->conakry, 100000000, ['motif_baisse' => 'Besoins réduits cette semaine'])->assertSessionHasErrors('general');
        $this->actingAs($this->caissierBoke)->get(route('reapprovisionnements.show', $d))->assertForbidden();
        $this->pvDuJour($this->boke, $this->caissierBoke);
        $this->demander($this->caissierBoke, $this->conakry, 1000000)->assertSessionHasErrors('general');
    }

    /** RG-M08-04, RG-M08-06, RG-M08-07 : plafond, baisse avec motif, habilitation du DP */
    public function test_plafond_baisse_avec_motif_et_habilitation(): void
    {
        $this->pvDuJour($this->conakry, $this->caissier);
        $this->demander($this->caissier, $this->conakry, 220000000)->assertSessionHasErrors('montant');
        $this->assertSame('Le montant demandé porterait le solde à 260 000 000 GNF, au-dessus du plafond de caisse (250 000 000 GNF).',
            str_replace(["\u{202F}", "\u{00A0}"], ' ', session('errors')->first('montant')));
        $this->demander($this->caissier, $this->conakry, 150000000)->assertSessionHasErrors('motif_baisse');

        Parametre::majValeur('habilitation_dp_reappro', '100000000');
        $this->demander($this->caissier, $this->conakry, 150000000, ['motif_baisse' => 'Besoins réduits cette semaine'])->assertSessionHasErrors('montant');
        Parametre::majValeur('habilitation_dp_reappro', '500000000');

        $this->demander($this->caissier, $this->conakry, 150000000, ['motif_baisse' => 'Besoins réduits cette semaine'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Besoins réduits cette semaine', Demande::sole()->motif_baisse);
    }

    /** RG-M08-07 à RG-M08-09 : circuit complet, crédit à la réception seulement, écart signalé au DAF */
    public function test_circuit_complet_jusqu_a_la_reception(): void
    {
        $dp = User::factory()->role('directeur_pays')->create();
        $this->pvDuJour($this->conakry, $this->caissier);
        $this->demander($this->caissier, $this->conakry, 210000000);
        $d = Demande::sole();

        /* Pas d'étape sautée ; la trésorerie réduit avec motif */
        $this->agir($this->chefComptable, $d, 'viser_finance')->assertSessionHasErrors('general');
        $this->agir($this->tresoriere, $d, 'valider_tresorerie', ['montant_valide' => 200000000])->assertSessionHasErrors('motif_reduction');
        $this->agir($this->tresoriere, $d, 'valider_tresorerie', ['montant_valide' => 200000000, 'motif_reduction' => 'Trésorerie bancaire tendue'])->assertSessionHasNoErrors();
        $this->assertSame(['validee_tresorerie', '200000000.00'], [$d->fresh()->statut, $d->fresh()->montant_valide]);
        $this->assertTrue(Notification::where('destinataire_id', $this->chefComptable->id)->where('type', 'reapprovisionnement')->exists());

        $this->agir($dp, $d, 'approuver_dp')->assertSessionHasErrors('general');
        $this->agir($this->chefComptable, $d, 'viser_finance')->assertSessionHasNoErrors();
        $this->assertTrue(Notification::where('destinataire_id', $dp->id)->where('type', 'reapprovisionnement')->exists());
        $this->agir($dp, $d, 'approuver_dp')->assertSessionHasNoErrors();
        $this->assertSame('approuvee', $d->fresh()->statut);

        /* Exécution : la caisse n'est pas encore créditée ; le transfert n'est pas ouvert à cette caisse */
        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'transfert', 'caisse_source_id' => $this->boke->id])->assertSessionHasErrors('origine_fonds');
        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'cheque', 'reference' => 'CHQ 0012345', 'montant_emis' => 200000000])->assertSessionHasNoErrors();
        $this->assertSame(['en_cours', '40000000.00'], [$d->fresh()->statut, $this->conakry->fresh()->solde]);
        $this->assertTrue(Notification::where('destinataire_id', $this->caissier->id)->where('titre', 'Réapprovisionnement à réceptionner')->exists());

        /* Réception : pièce exigée ; montant reçu inférieur → écart au DAF */
        $this->agir($this->tresoriere, $d, 'confirmer_reception', ['montant_recu' => 199000000])->assertSessionHasErrors('general');
        $this->agir($this->caissier, $d, 'confirmer_reception', ['montant_recu' => 199000000])->assertSessionHasErrors('piece');
        $this->agir($this->caissier, $d, 'confirmer_reception', ['montant_recu' => 199000000, 'piece' => UploadedFile::fake()->image('bordereau.jpg')])->assertSessionHasNoErrors();
        $this->assertSame(['recue', '239000000.00'], [$d->fresh()->statut, $this->conakry->fresh()->solde]);
        $this->assertSame(199000000.0, (float) EcritureCaisse::where('caisse_id', $this->conakry->id)->where('nature', 'approvisionnement')->sole()->montant);
        $alerte = Notification::where('destinataire_id', $this->daf->id)->where('titre', 'Écart de réception')->sole();
        $this->assertSame(sprintf('Écart de réception sur le transfert %s : émis 200 000 000 GNF, reçu 199 000 000 GNF.', $d->numero),
            str_replace(["\u{202F}", "\u{00A0}"], ' ', $alerte->message));

        $this->actingAs($this->caissier)->get(route('reapprovisionnements.show', $d))->assertInertia(fn (Assert $page) => $page
            ->component('Caisses/Reapprovisionnements/Show')->where('actions', [])->where('demande.reception.ecart', -1000000));
    }

    /** Q89 : en l'absence du DP et de son adjoint, deux membres distincts du CODIR approuvent ensemble */
    public function test_deux_membres_du_codir_en_absence_du_dp(): void
    {
        $codir1 = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['membre_codir']));
        $codir2 = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['membre_codir']));
        $dp = User::factory()->role('directeur_pays')->create();
        $this->pvDuJour($this->conakry, $this->caissier);
        $this->demander($this->caissier, $this->conakry, 210000000);
        $d = Demande::sole();
        $this->agir($this->tresoriere, $d, 'valider_tresorerie');
        $this->agir($this->chefComptable, $d, 'viser_finance');

        /* Le DP est là et son délai court : pas de CODIR */
        $this->agir($codir1, $d, 'approuver_codir')->assertSessionHasErrors('general');

        /* Délai du DP écoulé (heures ouvrées) */
        $this->travel(5)->days();
        $this->actingAs($codir1)->get(route('reapprovisionnements.show', $d))->assertInertia(fn (Assert $page) => $page
            ->where('actions', ['approuver_codir', 'refuser'])->where('demande.codir_attendu', true));
        $this->agir($codir1, $d, 'approuver_codir')->assertSessionHasNoErrors();
        $this->assertSame('visee_finance', $d->fresh()->statut);
        $this->agir($codir1, $d, 'approuver_codir')->assertSessionHasErrors('general');
        $this->assertTrue(Notification::where('destinataire_id', $codir2->id)->where('titre', 'Second visa du CODIR attendu')->exists());
        $this->agir($codir2, $d, 'approuver_codir')->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame(['approuvee', $codir1->id, $codir2->id], [$d->statut, $d->codir1_par, $d->codir2_par]);

        /* Sans DP ni adjoint actifs : CODIR tout de suite */
        $dp->update(['actif' => false]);
        $this->travelBack();
        $this->pvDuJour($this->boke, $this->caissierBoke);
        $this->demander($this->caissierBoke, $this->boke, 12000000);
        $autre = Demande::where('caisse_id', $this->boke->id)->sole();
        $this->agir($this->tresoriere, $autre, 'valider_tresorerie');
        $this->agir($this->chefComptable, $autre, 'viser_finance');
        $this->agir($codir1, $autre, 'approuver_codir')->assertSessionHasNoErrors();
    }

    /** RG-M08-08 : caisse Atelier en avance fixe, réapprovisionnée par transfert depuis Conakry principale */
    public function test_transfert_depuis_une_autre_caisse(): void
    {
        $atelier = Caisse::factory()->create(['code' => 'CKY-ATL', 'libelle' => 'Caisse Atelier', 'mode' => 'avance_fixe', 'montant_avance' => 15000000,
            'reappro_par_transfert' => true, 'solde' => 0]);
        $atelier->sites()->sync([$this->conakry->sites()->first()->id]);
        $atelier->crediter(4000000, 'solde_initial');
        $gestionnaire = User::factory()->create();
        $atelier->update(['gestionnaire_id' => $gestionnaire->id]);
        $dp = User::factory()->role('directeur_pays')->create();

        $this->pvDuJour($atelier, $gestionnaire, ['20000' => 200]);
        $this->actingAs($gestionnaire)->get(route('reapprovisionnements.create'))->assertInertia(fn (Assert $page) => $page
            ->where('caisses.0.propose', 11000000)->where('caisses.0.plafond', 15000000));
        $this->demander($gestionnaire, $atelier, 11000000)->assertSessionHasNoErrors();
        $d = Demande::sole();
        $this->agir($this->tresoriere, $d, 'valider_tresorerie');
        $this->agir($this->chefComptable, $d, 'viser_finance');
        $this->agir($dp, $d, 'approuver_dp');

        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'transfert', 'caisse_source_id' => $this->boke->id])->assertSessionHasErrors('caisse_source_id');
        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'transfert', 'caisse_source_id' => $this->conakry->id])->assertSessionHasNoErrors();
        $this->assertSame('29000000.00', $this->conakry->fresh()->solde);
        $this->assertSame('transfert_emis', EcritureCaisse::where('caisse_id', $this->conakry->id)->latest('id')->first()->nature);
        $this->assertSame('4000000.00', $atelier->fresh()->solde);

        $this->agir($gestionnaire, $d, 'confirmer_reception', ['montant_recu' => 11000000, 'piece' => UploadedFile::fake()->create('bon.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $this->assertSame('15000000.00', $atelier->fresh()->solde);
        $this->assertFalse(Notification::where('titre', 'Écart de réception')->exists());
    }

    /** Refus motivé ; le demandeur ne vise pas sa propre demande ; plus de mouvement saisi à la main */
    public function test_refus_motive_et_plus_de_mouvement_manuel(): void
    {
        $caissierTresorier = tap(User::factory()->role('caissier')->create(['caisse_id' => $this->conakry->id]), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->pvDuJour($this->conakry, $caissierTresorier);
        $this->demander($caissierTresorier, $this->conakry, 210000000);
        $d = Demande::sole();
        $this->agir($caissierTresorier, $d, 'valider_tresorerie')->assertSessionHasErrors('general');
        $this->assertFalse(Notification::where('destinataire_id', $caissierTresorier->id)->where('type', 'reapprovisionnement')->exists());

        $this->agir($this->tresoriere, $d, 'refuser', ['motif' => 'Trop'])->assertSessionHasErrors('motif');
        $this->agir($this->tresoriere, $d, 'refuser', ['motif' => 'Le rapprochement joint est celui du mois dernier'])->assertSessionHasNoErrors();
        $this->assertSame('refusee', $d->fresh()->statut);
        $this->assertTrue(Notification::where('destinataire_id', $caissierTresorier->id)->where('titre', 'Réapprovisionnement refusé')->exists());

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('mouvements-caisse.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('mouvements-caisse.create'));
    }

    /**
     * Revue du 22/10 : montant émis plafonné au montant approuvé et au plafond (solde du moment) ; caisse OM par virement
     * seulement ; à la réception, un solde monté au-dessus du plafond entre-temps alerte la trésorerie (remise en banque).
     */
    public function test_execution_et_reception_tiennent_le_plafond(): void
    {
        $dp = User::factory()->role('directeur_pays')->create();
        $this->pvDuJour($this->conakry, $this->caissier);
        $this->demander($this->caissier, $this->conakry, 150000000, ['motif_baisse' => 'Besoin limité au mois en cours'])->assertSessionHasNoErrors();
        $d = Demande::sole();
        $this->agir($this->tresoriere, $d, 'valider_tresorerie')->assertSessionHasNoErrors();
        $this->agir($this->chefComptable, $d, 'viser_finance')->assertSessionHasNoErrors();
        $this->agir($dp, $d, 'approuver_dp')->assertSessionHasNoErrors();

        /* Pas plus que le montant approuvé ; pas de virement pour une caisse en espèces */
        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'cheque', 'montant_emis' => 160000000])->assertSessionHasErrors('montant_emis');
        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'virement', 'montant_emis' => 150000000])->assertSessionHasErrors('origine_fonds');

        /* Le solde a monté depuis la demande (40 M → 110 M) : 150 M dépasserait le plafond de 250 M */
        $this->conakry->crediter(70000000, 'solde_initial');
        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'cheque', 'montant_emis' => 150000000])->assertSessionHasErrors('montant_emis');
        $this->agir($this->tresoriere, $d, 'executer', ['origine_fonds' => 'cheque', 'montant_emis' => 140000000])->assertSessionHasNoErrors();
        $this->assertSame('en_cours', $d->fresh()->statut);

        /* Avant la réception, le solde monte encore : les fonds sont crédités, la trésorerie est alertée */
        $this->conakry->crediter(5000000, 'solde_initial');
        $this->agir($this->caissier, $d, 'confirmer_reception', ['montant_recu' => 140000000, 'piece' => UploadedFile::fake()->image('bordereau.jpg')])
            ->assertSessionHasNoErrors();
        $this->assertSame('255000000.00', $this->conakry->fresh()->solde);
        $alerte = Notification::where('destinataire_id', $this->tresoriere->id)->where('titre', 'Plafond de caisse dépassé')->sole();
        $this->assertSame("Réapprovisionnement {$d->numero} reçu : le solde de la Caisse principale Conakry atteint 255 000 000 GNF, au-dessus du plafond de caisse (250 000 000 GNF). Remise en banque nécessaire.",
            str_replace(["\u{202F}", "\u{00A0}"], ' ', $alerte->message));

        /* Caisse Orange Money : virement bancaire seulement (RG-M08-08) */
        $om = Caisse::factory()->create(['code' => 'CKY-OM', 'type' => 'orange_money', 'solde' => 0, 'plafond_caisse' => 500000000]);
        $this->assertSame(['virement'], \App\Services\Caisse\Reapprovisionnement::origines($om));
        $this->assertSame(['cheque', 'mise_a_disposition', 'orange_money'], \App\Services\Caisse\Reapprovisionnement::origines($this->conakry));
    }
}
