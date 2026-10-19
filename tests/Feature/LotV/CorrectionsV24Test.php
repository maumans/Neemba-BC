<?php

namespace Tests\Feature\LotV;

use App\Models\BonCaisse;
use App\Models\MotifVisiteur;
use App\Models\Notification;
use App\Models\Parametre;
use App\Models\User;
use App\Models\Visiteur;
use App\Services\BonCaisse\AlertesBon;
use App\Services\BonCaisse\CircuitBon;
use App\Services\NotificationService;
use App\Services\Rapports\ExportBons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot V (spec v2.4) : corrections de l'existant contredit par la v2.4.
 * Bon exceptionnel au profit d'un visiteur du groupe (RG-M03-28), étape Finance réservée au DAF (RG-M04-10), signalé au DAF
 * (M11), visible à chaque étape et dans l'export (M14) ; motifs paramétrables (PO-30).
 */
class CorrectionsV24Test extends TestCase
{
    use RefreshDatabase;

    private User $daf;
    private User $dafAdjoint;
    private User $chefComptable;
    private Visiteur $visiteur;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->daf = User::factory()->role('daf')->create(['prenom' => 'Mohamed', 'name' => 'DIAKITE']);
        $this->dafAdjoint = User::factory()->create(['prenom' => 'Mor', 'name' => 'CISS']);
        $this->dafAdjoint->ajouterRoles(['daf_adjoint']);
        $this->chefComptable = User::factory()->create(['prenom' => 'Mamadou Alpha', 'name' => 'BAH']);
        $this->chefComptable->ajouterRoles(['chef_comptable']);
        $directeur = User::factory()->create(['service' => 'Technique']);
        $this->visiteur = Visiteur::create(['nom' => 'KOUASSI', 'prenom' => 'Yao', 'filiale' => 'Neemba Côte d\'Ivoire', 'departement_accueil' => 'Technique',
            'directeur_id' => $directeur->id, 'actif' => true, 'date_debut' => today()->subDay(), 'date_fin' => today()->addWeek()]);
    }

    /** RG-M04-10 : la Finance d'un bon visiteur est visée par le DAF ou son adjoint, pas par le Chef comptable ; paramètre */
    public function test_la_finance_d_un_bon_visiteur_est_reservee_au_daf(): void
    {
        $bon = $this->bonVisiteur('EN_ATTENTE_DAF');
        $salarie = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create(['date_soumission' => now()]);
        $salarie->creerEtapesValidation();

        $this->assertNull(CircuitBon::peutViser($bon, $this->chefComptable));
        $this->assertNotNull(CircuitBon::peutViser($bon, $this->daf));
        $this->assertNotNull(CircuitBon::peutViser($bon, $this->dafAdjoint));
        $this->assertNotNull(CircuitBon::peutViser($salarie, $this->chefComptable));
        $this->assertSame("Bon au profit d'un visiteur du groupe : l'étape Finance est réservée au DAF ou au DAF adjoint (RG-M04-10).",
            CircuitBon::motifRefus($bon, $this->chefComptable));
        $this->assertSame([$salarie->id], CircuitBon::requeteAViser($this->chefComptable)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$bon->id, $salarie->id], CircuitBon::requeteAViser($this->daf)->pluck('id')->all());
        $this->actingAs($this->chefComptable)->post(route('validations.approuver', $bon), ['commentaire' => 'Visa'])
            ->assertSessionHas('error', CircuitBon::motifRefus($bon, $this->chefComptable));
        $this->assertSame('EN_ATTENTE_DAF', $bon->fresh()->statut);

        /* Le paramètre désactivé rend l'étape à toute la Finance */
        Parametre::majValeur('visiteur_finance_daf_seul', 'false');
        $this->assertNotNull(CircuitBon::peutViser($bon, $this->chefComptable));
    }

    /** RG-M03-28 : bon signalé au DAF ; visible à chaque étape ; repris dans l'export avec la filiale */
    public function test_le_bon_visiteur_est_signale_et_repris_dans_l_export(): void
    {
        $bon = $this->bonVisiteur('EN_ATTENTE_CHEF_SERVICE');
        $auteur = User::factory()->create();

        NotificationService::notifierBonVisiteur($bon, $auteur);
        $this->assertEqualsCanonicalizing([$this->daf->id, $this->dafAdjoint->id],
            Notification::where('type', 'bon_visiteur')->pluck('destinataire_id')->all());

        $this->assertSame(['filiale' => 'Neemba Côte d\'Ivoire', 'motif' => 'Mission d\'appui technique', 'commentaire' => 'Hébergement avancé pour la filiale',
            'finance_daf' => true], AlertesBon::pourAffichage($bon)['refacturation']);

        $lignes = ExportBons::lignes(BonCaisse::whereKey($bon->id)->get());
        $colonne = array_search('Refacturation inter-filiale', $lignes[0], true);
        $this->assertSame(['Oui', 'Neemba Côte d\'Ivoire'], [$lignes[1][$colonne], $lignes[1][$colonne + 1]]);
    }

    /** PO-30 : motifs du bon visiteur tenus au paramétrage ; un motif désactivé n'est plus proposé */
    public function test_les_motifs_du_bon_visiteur_sont_parametrables(): void
    {
        /* Spec v2.4, RG-M01-09 : tenus par le gestionnaire des référentiels */
        $admin = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['gestionnaire_referentiels']));
        $this->assertSame([], MotifVisiteur::libellesActifs());

        $this->actingAs($admin)->post(route('parametrage.motifs-visiteur.store'), ['libelle' => 'Mission d\'appui technique'])->assertSessionHas('success');
        $motif = MotifVisiteur::sole();
        $this->actingAs($admin)->put(route('parametrage.motifs-visiteur.update', $motif), ['libelle' => 'Mission d\'appui de la filiale'])->assertSessionHas('success');
        $this->assertSame(['Mission d\'appui de la filiale'], MotifVisiteur::libellesActifs());
        $this->actingAs($admin)->get(route('parametrage.index'))->assertInertia(fn (Assert $page) => $page->has('motifsVisiteur', 1));

        $this->actingAs($admin)->post(route('parametrage.motifs-visiteur.toggle', $motif))->assertSessionHas('success');
        $this->assertSame([], MotifVisiteur::libellesActifs());
    }

    private function bonVisiteur(string $statut): BonCaisse
    {
        $bon = BonCaisse::factory()->statut($statut)->create([
            'type_beneficiaire' => 'visiteur', 'visiteur_id' => $this->visiteur->id, 'beneficiaire_id' => null, 'beneficiaire' => 'Yao KOUASSI',
            'motif_visiteur' => 'Mission d\'appui technique', 'commentaire_visiteur' => 'Hébergement avancé pour la filiale',
            'refacturation_inter_filiale' => true, 'filiale_refacturation' => 'Neemba Côte d\'Ivoire', 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();

        return $bon;
    }
}
