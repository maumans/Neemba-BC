<?php

namespace Tests\Feature\M03;

use App\Models\BonCaisse;
use App\Models\Delegation;
use App\Models\User;
use App\Models\Validation;
use App\Services\BonCaisse\CircuitBon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Visa d'un bon par un valideur qui en est demandeur ou bénéficiaire (spec v2.2 : RG-M01-04, RG-M03-24, RG-M04-09).
 *
 * Cas d'origine : bons générés par un ODM dont un participant est le chef de service ; il recevait une erreur 403.
 * Désormais son étape est sautée (ou confiée à son suppléant) et un refus s'explique par un message.
 */
class IncompatibilitesValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $chefDirection;
    private User $chefAftermarket;
    private User $cdg;
    private User $daf;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->demandeur = User::factory()->create(['name' => 'DIALLO', 'prenom' => 'Thierno', 'service' => 'Direction']);
        $this->chefDirection = User::factory()->role('responsable_service')->create(['name' => 'CAMARA', 'prenom' => 'Ibrahima', 'service' => 'Direction']);
        $this->chefAftermarket = User::factory()->role('responsable_service')->create(['name' => 'GOMIS', 'prenom' => 'Thierry', 'service' => 'Aftermarket']);
        $this->cdg = User::factory()->role('controle_gestion')->create(['name' => 'BOIRO', 'prenom' => 'Saliou']);
        $this->daf = User::factory()->role('daf')->create(['name' => 'DIAKITE', 'prenom' => 'Mohamed']);
    }

    public function test_le_chef_de_service_beneficiaire_ne_vise_pas_son_bon_qui_passe_au_cdg(): void
    {
        $bon = $this->bonSoumis(['beneficiaire_id' => $this->chefDirection->id]);

        $this->assertSame('EN_ATTENTE_CDG', $bon->statut);
        $etape = $bon->validations()->where('role', 'responsable_service')->first();
        $this->assertSame('saute', $etape->statut);
        $this->assertStringContainsString('Ibrahima CAMARA est demandeur ou bénéficiaire du bon', $etape->commentaire);
        $this->assertNotNull($bon->validations()->where('role', 'controle_gestion')->value('date_attribution'));

        /* Le CDG est prévenu ; la fiche montre l'étape sautée */
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $this->cdg->id, 'bon_caisse_id' => $bon->id]);
        $this->actingAs($this->demandeur)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('etapesValidation.0.statut', 'saute')
                ->where('etapesValidation.0.etat', 'fait')
                ->where('etapesValidation.1.etat', 'en_cours'));

        $this->actingAs($this->cdg)->post(route('validations.approuver', $bon))->assertSessionHas('success');
        $this->assertSame('EN_ATTENTE_DAF', $bon->fresh()->statut);
    }

    public function test_un_refus_s_explique_au_lieu_d_une_erreur_403(): void
    {
        /* Bon soumis avant la règle : le chef bénéficiaire est encore à l'étape en cours */
        $bon = $this->bonSoumis(['beneficiaire_id' => $this->chefDirection->id], sauter: false);

        $this->actingAs($this->chefDirection)->get(route('validations.show', $bon))
            ->assertRedirect(route('bons-caisse.show', $bon))
            ->assertSessionHas('error', 'Vous êtes demandeur ou bénéficiaire de ce bon : vous ne pouvez pas le viser (RG-M01-04).');
        $this->actingAs($this->chefDirection)->post(route('validations.approuver', $bon))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bon->fresh()->statut);

        /* Le chef d'un autre service apprend à qui revient le bon */
        $this->actingAs($this->chefAftermarket)->post(route('validations.approuver', $bon))
            ->assertSessionHas('error', 'Ce bon relève du chef de service Direction : vous ne pouvez pas le viser.');

        /* Ni bouton « Valider » sur la fiche, ni ligne dans « À valider » */
        $this->actingAs($this->chefDirection)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page->where('peutValiderCeBon', false));
        $this->actingAs($this->chefDirection)->get(route('validations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bonsEnAttente.total', 0));
    }

    public function test_la_commande_debloque_les_bons_deja_en_attente(): void
    {
        $bon = $this->bonSoumis(['beneficiaire_id' => $this->chefDirection->id], sauter: false);

        $this->artisan('bons:sauter-etapes', ['--simulation' => true])->assertSuccessful();
        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bon->fresh()->statut);

        $this->artisan('bons:sauter-etapes')->expectsOutputToContain('Bons passés au niveau supérieur : 1')->assertSuccessful();
        $this->assertSame('EN_ATTENTE_CDG', $bon->fresh()->statut);
    }

    public function test_avec_un_suppleant_l_etape_lui_revient(): void
    {
        $suppleant = User::factory()->create(['name' => 'SYLLA', 'prenom' => 'Aïssatou', 'service' => 'Direction']);
        Delegation::create([
            'delegant_id' => $this->chefDirection->id, 'delegue_id' => $suppleant->id,
            'date_debut' => today()->subDay(), 'date_fin' => today()->addDays(5),
            'fonctionnalites' => ['validation'], 'statut' => 'acceptee', 'acceptee_le' => now(),
        ]);
        $bon = $this->bonSoumis(['beneficiaire_id' => $this->chefDirection->id]);

        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bon->statut);
        $this->assertSame(['Aïssatou SYLLA (suppléant de Ibrahima CAMARA)'],
            collect(\App\Services\BonCaisse\CircuitPrevisionnel::pour($bon))->firstWhere('niveau', 'CHEF_SERVICE')['valideurs']);

        $this->actingAs($suppleant)->post(route('validations.approuver', $bon))->assertSessionHas('success');
        $etape = $bon->validations()->where('role', 'responsable_service')->first();
        $this->assertSame('approuve', $etape->statut);
        $this->assertSame($this->chefDirection->id, $etape->au_titre_de_id);
        $this->assertSame('EN_ATTENTE_CDG', $bon->fresh()->statut);
    }

    public function test_l_etape_suivante_est_sautee_apres_un_visa(): void
    {
        /* Le seul CDG est bénéficiaire : après le visa du chef de service, le bon va directement à la Finance */
        $bon = $this->bonSoumis(['beneficiaire_id' => $this->cdg->id]);
        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bon->statut);

        $this->actingAs($this->chefDirection)->post(route('validations.approuver', $bon))->assertSessionHas('success');

        $bon->refresh();
        $this->assertSame('EN_ATTENTE_DAF', $bon->statut);
        $this->assertSame('saute', $bon->validations()->where('role', 'controle_gestion')->value('statut'));
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $this->daf->id, 'bon_caisse_id' => $bon->id]);
    }

    public function test_le_dernier_niveau_n_est_jamais_saute(): void
    {
        /* Seul DAF bénéficiaire, sans suppléant : le bon attend à la Finance (visa requis avant décaissement) */
        $bon = $this->bonSoumis(['beneficiaire_id' => $this->daf->id]);
        $this->actingAs($this->chefDirection)->post(route('validations.approuver', $bon));
        $this->actingAs($this->cdg)->post(route('validations.approuver', $bon));

        $this->assertSame('EN_ATTENTE_DAF', $bon->fresh()->statut);
        $this->actingAs($this->daf)->post(route('validations.approuver', $bon))
            ->assertSessionHas('error', 'Vous êtes demandeur ou bénéficiaire de ce bon : vous ne pouvez pas le viser (RG-M01-04).');
        $this->assertSame('EN_ATTENTE_DAF', $bon->fresh()->statut);
    }

    public function test_un_service_sans_chef_passe_au_niveau_superieur(): void
    {
        /* Q48 : aucun chef de service désigné pour le service du bon */
        $bon = $this->bonSoumis(['service' => 'Logistique']);

        $this->assertSame('EN_ATTENTE_CDG', $bon->statut);
        $this->assertStringContainsString("aucun chef de service n'est désigné pour le service Logistique",
            $bon->validations()->where('role', 'responsable_service')->value('commentaire'));
    }

    public function test_un_role_complementaire_de_chef_de_service_permet_de_viser(): void
    {
        /* Rôle principal « demandeur », rôle complémentaire « chef de service » de la Logistique */
        $chefLogistique = User::factory()->create(['name' => 'TOURE', 'prenom' => 'Youssouf', 'service' => 'Logistique']);
        $chefLogistique->ajouterRoles(['responsable_service']);
        $bon = $this->bonSoumis(['service' => 'Logistique']);

        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bon->statut);
        $this->actingAs($chefLogistique)->get(route('validations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bonsEnAttente.total', 1));
        $this->actingAs($chefLogistique)->post(route('validations.approuver', $bon))->assertSessionHas('success');
        $this->assertSame('EN_ATTENTE_CDG', $bon->fresh()->statut);
    }

    /** Bon de 500 000 GNF (sans visa DP), en attente du chef de service, comme après SoumettreBon */
    private function bonSoumis(array $valeurs = [], bool $sauter = true): BonCaisse
    {
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CHEF_SERVICE')->create($valeurs + [
            'demandeur_id' => $this->demandeur->id, 'service' => 'Direction', 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        if ($sauter) {
            CircuitBon::sauterEtapesSansValideur($bon);
            \App\Services\NotificationService::notifierSoumission($bon->fresh(['demandeur']), $this->demandeur);
        }

        return $bon->fresh();
    }
}
