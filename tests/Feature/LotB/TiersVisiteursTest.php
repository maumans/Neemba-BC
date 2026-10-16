<?php

namespace Tests\Feature\LotB;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\Service;
use App\Models\Site;
use App\Models\Tiers;
use App\Models\User;
use App\Models\Visiteur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot B (spec v2.3, §6.5) : tiers proposés puis validés par la Comptabilité ou le CDG (RG-M02-06), contrôle de doublon
 * (RG-M02-07), bénéficiaire choisi dans un référentiel (RG-M03-03), visiteurs du groupe et BP (RG-M03-04).
 */
class TiersVisiteursTest extends TestCase
{
    use RefreshDatabase;

    private User $souadou;
    private User $cdg;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $conakry = Site::factory()->conakry()->create();
        Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id, 'plafond_retrait' => 20000000, 'solde' => 30000000]);
        $aftermarket = Service::factory()->create(['nom' => 'Aftermarket']);
        Service::factory()->create(['nom' => 'Technique']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $aftermarket->id]);
        $this->souadou = User::factory()->create(['prenom' => 'Souadou', 'name' => 'BARRY', 'site' => 'Conakry', 'service' => 'Aftermarket']);
        $this->cdg = User::factory()->role('controle_gestion')->create(['prenom' => 'Saliou', 'name' => 'BOIRO']);
    }

    /** US-03, RG-M02-06 : proposé par le demandeur, pas sélectionnable avant validation ; le CDG est notifié */
    public function test_un_tiers_propose_attend_sa_validation(): void
    {
        $reponse = $this->actingAs($this->souadou)->postJson(route('api.tiers.proposer'), [
            'nom' => 'Garage Kaba', 'type' => 'fournisseur', 'telephone' => '622 00 01 11', 'numero_om' => '622000111', 'nif' => 'gn-12345',
        ])->assertCreated()->assertJsonPath('message', __('MSG-M03-03'))->assertJsonPath('tiers.selectionnable', false);
        $tiers = Tiers::findOrFail($reponse->json('tiers.id'));
        $this->assertSame(['propose', '+224622000111', 'GN-12345'], [$tiers->statut, $tiers->telephone, $tiers->nif]);
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $this->cdg->id, 'type' => 'tiers_propose']);

        /* Visible (grisé) pour celui qui l'a proposé seulement */
        $this->actingAs($this->souadou)->getJson(route('api.referentiels.tiers', ['q' => 'Kaba']))->assertJsonPath('resultats.0.selectionnable', false);
        $this->actingAs(User::factory()->create())->getJson(route('api.referentiels.tiers', ['q' => 'Kaba']))->assertJsonCount(0, 'resultats');

        /* Un bon ne peut pas le prendre comme bénéficiaire */
        $bon = $this->brouillon();
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['etape' => 2, 'type_beneficiaire' => 'tiers', 'tiers_id' => $tiers->id])
            ->assertOk()->assertJsonPath('erreurs.tiers_id.0', __('MSG-M03-03'));
    }

    /** RG-M02-07 : même téléphone (quel que soit le format), même n° OM ou même NIF → blocage avec renvoi vers le tiers existant */
    public function test_le_doublon_de_tiers_est_bloque(): void
    {
        Tiers::create(['nom' => 'Garage Kaba', 'type' => 'fournisseur', 'telephone' => '+224622000111', 'nif' => 'GN-12345', 'statut' => 'valide']);

        foreach ([['telephone' => '+224 622 00 01 11'], ['numero_om' => '622000111'], ['nif' => ' gn-12345 ']] as $champ) {
            $this->actingAs($this->souadou)->postJson(route('api.tiers.proposer'), ['nom' => 'Kaba Garage SARL', 'type' => 'fournisseur'] + $champ)
                ->assertStatus(422)->assertJsonPath('message', 'Ce tiers existe déjà : Garage Kaba. Sélectionnez-le dans la liste.');
        }
        $this->assertSame(1, Tiers::count());
    }

    /** RG-M02-06 : validation ou refus motivé par le CDG ; le demandeur est notifié ; le tiers validé devient sélectionnable */
    public function test_le_cdg_valide_ou_refuse_un_tiers(): void
    {
        $garage = Tiers::create(['nom' => 'Garage Kaba', 'type' => 'fournisseur', 'numero_om' => '+224622000111', 'statut' => 'propose', 'origine' => 'saisie', 'propose_par_id' => $this->souadou->id, 'propose_le' => now()]);
        $taxi = Tiers::create(['nom' => 'Taxi Camara', 'type' => 'particulier', 'statut' => 'propose', 'origine' => 'saisie', 'propose_par_id' => $this->souadou->id, 'propose_le' => now()]);

        $this->actingAs($this->souadou)->get(route('tiers.index'))->assertForbidden();
        $admin = User::factory()->role('administrateur')->create();
        $this->actingAs($admin)->get(route('tiers.index'))->assertInertia(fn (Assert $page) => $page->where('peutValider', false)->where('compteurs.propose', 2));
        $this->actingAs($admin)->post(route('tiers.decider', $garage), ['decision' => 'valider'])->assertForbidden();

        $this->actingAs($this->cdg)->post(route('tiers.decider', $taxi), ['decision' => 'refuser'])->assertSessionHasErrors('motif');
        $this->actingAs($this->cdg)->post(route('tiers.decider', $taxi), ['decision' => 'refuser', 'motif' => 'Particulier sans pièce d\'identité fournie'])->assertSessionHas('success');
        $this->actingAs($this->cdg)->post(route('tiers.decider', $garage), ['decision' => 'valider'])->assertSessionHas('success');
        $this->assertSame(['valide', 'refuse'], [$garage->fresh()->statut, $taxi->fresh()->statut]);
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $this->souadou->id, 'type' => 'tiers_decide', 'titre' => 'Tiers refusé']);

        /* Bénéficiaire : nom et n° OM repris du référentiel ; un BP lui est refusé (RG-M03-04) */
        $bon = $this->brouillon();
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['type_beneficiaire' => 'tiers', 'tiers_id' => $garage->id])->assertOk();
        $bon->refresh();
        $this->assertSame(['Garage Kaba', '+224622000111', null], [$bon->beneficiaire, $bon->telephone_beneficiaire, $bon->beneficiaire_id]);
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['type_bon' => 'BP'])->assertOk();
        $this->assertSame(['type_beneficiaire' => [__('MSG-M03-02')]],
            \App\Services\BonCaisse\ReglesSaisie::erreursCompletes(\App\Services\BonCaisse\ReglesSaisie::donneesDu($bon->fresh()), [2], false)->toArray());

        /* Désactivé : il n'est plus proposé */
        $this->actingAs($this->cdg)->post(route('tiers.basculer', $garage))->assertSessionHas('success');
        $this->actingAs($this->souadou)->getJson(route('api.referentiels.tiers', ['q' => 'Kaba']))->assertJsonCount(0, 'resultats');
    }

    /** Un valideur crée directement un tiers validé */
    public function test_un_tiers_cree_par_un_valideur_est_valide(): void
    {
        $this->actingAs($this->cdg)->post(route('tiers.store'), ['nom' => 'SOTRAGUI', 'type' => 'prestataire', 'telephone' => '624112233'])->assertSessionHas('success');
        $this->assertSame(['valide', $this->cdg->id], [Tiers::sole()->statut, Tiers::sole()->valide_par_id]);
    }

    /** §6.5, RG-M03-04, RG-M03-28 (v2.4) : visiteur du groupe tenu par les RH ; BD exceptionnel seulement, jamais de BP */
    public function test_le_visiteur_du_groupe_recoit_un_bd_exceptionnel(): void
    {
        $rh = User::factory()->create();
        $rh->ajouterRoles(['rh']);
        $directeur = User::factory()->create(['prenom' => 'Ibrahima', 'name' => 'CAMARA', 'service' => 'Technique']);

        $this->actingAs($this->souadou)->get(route('visiteurs.index'))->assertForbidden();
        $this->actingAs($rh)->post(route('visiteurs.store'), [
            'nom' => 'Kouassi', 'prenom' => 'Yao', 'filiale' => 'Neemba Côte d\'Ivoire', 'matricule_groupe' => 'CI-0451', 'numero_om' => '625 44 33 22',
            'departement_accueil' => 'Technique', 'directeur_id' => $directeur->id, 'date_debut' => today()->subDays(2)->toDateString(), 'date_fin' => today()->addDays(10)->toDateString(),
        ])->assertSessionHas('success');
        $visiteur = Visiteur::sole();

        $this->actingAs($this->souadou)->getJson(route('api.referentiels.visiteurs', ['q' => 'Yao']))
            ->assertJsonPath('resultats.0.directeur', 'Ibrahima CAMARA')->assertJsonPath('resultats.0.telephone', '+224625443322');

        /* Un BP est refusé pour un visiteur (MSG-M03-02) */
        $bon = $this->brouillon(['type_bon' => 'BP']);
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['type_beneficiaire' => 'visiteur', 'visiteur_id' => $visiteur->id])->assertOk();
        $bon->refresh();
        $this->assertSame(['Yao KOUASSI', '+224625443322'], [$bon->beneficiaire, $bon->telephone_beneficiaire]);
        $erreurs = \App\Services\BonCaisse\ReglesSaisie::erreursCompletes(\App\Services\BonCaisse\ReglesSaisie::donneesDu($bon), [2], false)->toArray();
        $this->assertSame([__('MSG-M03-02')], $erreurs['type_beneficiaire']);

        /* BD exceptionnel : motif de la liste et commentaire obligatoires (MSG-M03-17) ; marqué « refacturation inter-filiale » */
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['type_bon' => 'BD'])->assertOk();
        $erreurs = \App\Services\BonCaisse\ReglesSaisie::erreursCompletes(\App\Services\BonCaisse\ReglesSaisie::donneesDu($bon->fresh()), [2], false)->toArray();
        $this->assertSame([__('MSG-M03-17')], $erreurs['motif_visiteur']);
        $this->assertSame([__('MSG-M03-17')], $erreurs['commentaire_visiteur']);
        \App\Models\MotifVisiteur::create(['libelle' => 'Mission d\'appui technique de la filiale']);
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), [
            'motif_visiteur' => 'Mission d\'appui technique de la filiale', 'commentaire_visiteur' => 'Hébergement avancé pour Neemba Côte d\'Ivoire',
        ])->assertOk();
        $bon->refresh();
        $this->assertEmpty(\App\Services\BonCaisse\ReglesSaisie::erreursCompletes(\App\Services\BonCaisse\ReglesSaisie::donneesDu($bon), [2], false)->toArray());
        $this->assertSame([true, 'Neemba Côte d\'Ivoire'], [$bon->refacturation_inter_filiale, $bon->filiale_refacturation]);

        /* BP en retard du visiteur (RG-M03-21) */
        BonCaisse::factory()->provisoire()->statut('EN_ATTENTE_REGULARISATION')->create(['visiteur_id' => $visiteur->id, 'type_beneficiaire' => 'visiteur',
            'beneficiaire' => 'Yao KOUASSI', 'numero' => 'BC-2026-0090', 'date_limite_regularisation' => today()->subDay()]);
        $this->assertSame('BC-2026-0090', \App\Services\BonCaisse\AlertesBon::bpEnRetard($bon)->first()?->numero);

        /* Désactivé : refusé sur un bon */
        $this->actingAs($rh)->post(route('visiteurs.basculer', $visiteur))->assertSessionHas('success');
        $this->assertSame(['visiteur_id' => [__('MSG-APP-061')]],
            \App\Services\BonCaisse\ReglesSaisie::erreursCompletes(\App\Services\BonCaisse\ReglesSaisie::donneesDu($bon->fresh()), [2], false)->toArray());
    }

    private function brouillon(array $valeurs = []): BonCaisse
    {
        $id = $this->actingAs($this->souadou)->postJson(route('api.bons.creer'), $valeurs + ['type_bon' => 'BD', 'code_analytique' => 'ADAZZZ'])
            ->assertCreated()->json('bon.id');

        return BonCaisse::findOrFail($id);
    }
}
