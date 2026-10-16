<?php

namespace Tests\Feature\LotE1;

use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\Entite;
use App\Models\User;
use App\Services\BonCaisse\CircuitBon;
use App\Services\Odm\EnregistrementOdm;
use App\Services\Odm\NumeroteurOdm;
use App\Services\Rapports\ExportBons;
use App\Support\EntiteCourante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot E1 (spec v2.4, RG-M01-02, RG-M01-08, SC-41) : dimension « entité » transverse. Neemba Mining est activée pour le
 * test : isolation des bons et des référentiels, vue consolidée des rôles habilités, droits par entité × rôle,
 * numérotation des ODM par entité, entité reprise du demandeur.
 */
class EntiteTest extends TestCase
{
    use RefreshDatabase;

    private int $guinee;
    private int $mining;
    private User $dafGuinee;
    private User $dafMining;
    private User $demandeurGuinee;
    private User $demandeurMining;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->guinee = Entite::where('code', 'NGU')->value('id');
        $this->mining = Entite::where('code', 'NMI')->value('id');
        Entite::whereKey($this->mining)->update(['actif' => true]);
        Entite::oublierCache();

        $this->dafGuinee = User::factory()->role('daf')->create(['prenom' => 'Mohamed', 'name' => 'DIAKITE']);
        $this->dafMining = User::factory()->role('daf')->create(['prenom' => 'Awa', 'name' => 'SYLLA', 'entite_id' => $this->mining]);
        $this->dafMining->roles()->update(['entite_id' => $this->mining]);
        $this->demandeurGuinee = User::factory()->create(['service' => 'Aftermarket']);
        $this->demandeurMining = User::factory()->create(['service' => 'Aftermarket', 'entite_id' => $this->mining]);
        $this->demandeurMining->roles()->update(['entite_id' => $this->mining]);
    }

    /** SC-41 : un bon d'une autre entité n'est ni listé ni ouvert ; l'entité est celle du demandeur */
    public function test_les_bons_sont_isoles_par_entite(): void
    {
        $bonGuinee = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create(['demandeur_id' => $this->demandeurGuinee->id, 'date_soumission' => now()]);
        $bonMining = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create(['demandeur_id' => $this->demandeurMining->id, 'date_soumission' => now()]);
        $this->assertSame([$this->guinee, $this->mining], [$bonGuinee->entite_id, $bonMining->entite_id]);

        $this->actingAs($this->dafGuinee)->get(route('bons-caisse.show', $bonGuinee))->assertOk();
        $this->actingAs($this->dafGuinee)->get(route('bons-caisse.show', $bonMining))->assertNotFound();
        $this->actingAs($this->dafMining)->get(route('bons-caisse.show', $bonMining))->assertOk();
        $this->actingAs($this->dafMining)->get(route('bons-caisse.show', $bonGuinee))->assertNotFound();

        $this->actingAs($this->dafGuinee);
        $this->assertSame([$bonGuinee->id], BonCaisse::pluck('id')->all());
        $this->assertEqualsCanonicalizing([$bonGuinee->id, $bonMining->id], BonCaisse::deLEntite(null)->pluck('id')->all());
    }

    /** RG-M01-08 : vue consolidée pour les rôles du paramètre ; refusée aux autres */
    public function test_la_vue_consolidee_est_reservee_aux_roles_habilites(): void
    {
        $bonMining = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create(['demandeur_id' => $this->demandeurMining->id, 'date_soumission' => now()]);

        $this->actingAs($this->demandeurGuinee)->post(route('entite.choisir'), ['entite' => 'consolide'])
            ->assertSessionHas('error', 'Vous n\'avez pas de droits dans cette entité.');
        $this->actingAs($this->demandeurGuinee)->post(route('entite.choisir'), ['entite' => (string) $this->mining])->assertSessionHas('error');

        $this->actingAs($this->dafGuinee)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.entite.peut_consolider', true)->where('auth.entite.courante.code', 'NGU'));
        $this->actingAs($this->dafGuinee)->post(route('entite.choisir'), ['entite' => 'consolide'])
            ->assertSessionHas('success', 'Vue consolidée : toutes les entités.');
        $this->actingAs($this->dafGuinee)->get(route('bons-caisse.show', $bonMining))->assertOk();
        $this->actingAs($this->dafGuinee)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.entite.consolide', true));
    }

    /** RG-M01-08 : un rôle vaut dans son entité ; les valideurs d'un bon sont ceux de son entité */
    public function test_les_droits_sont_attribues_par_entite_et_role(): void
    {
        $bonMining = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create(['demandeur_id' => $this->demandeurMining->id, 'date_soumission' => now()]);
        $bonMining->creerEtapesValidation();
        $comptable = User::factory()->create(['prenom' => 'Mamadou Alpha', 'name' => 'BAH']);
        $comptable->ajouterRoles(['chef_comptable'], $this->mining);

        $this->assertEqualsCanonicalizing([$this->dafMining->id, $comptable->id], CircuitBon::titulaires($bonMining, 'daf')->pluck('id')->all());
        $this->assertNull(CircuitBon::peutViser($bonMining, $this->dafGuinee));

        /* Le chef comptable de Mining travaille dans Mining : il y est chef comptable, pas en Guinée */
        $this->assertEqualsCanonicalizing([$this->guinee, $this->mining], EntiteCourante::accessibles($comptable)->pluck('id')->all());
        $this->actingAs($comptable)->post(route('entite.choisir'), ['entite' => (string) $this->mining])->assertSessionHas('success');
        $this->actingAs($comptable)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.entite.courante.code', 'NMI')
            ->where('auth.user.roles', fn ($roles) => collect($roles)->contains('chef_comptable')));
    }

    /** RG-M01-08 : numérotation des ODM par entité ; entité de l'ODM reprise du demandeur */
    public function test_la_numerotation_des_odm_est_propre_a_chaque_entite(): void
    {
        $this->assertSame('N°1/AT/26', NumeroteurOdm::prochain('AT', 2026, $this->guinee)['numero']);
        $this->assertSame('N°1/AT/26', NumeroteurOdm::prochain('AT', 2026, $this->mining)['numero']);
        $this->assertSame('N°2/AT/26', NumeroteurOdm::prochain('AT', 2026, $this->guinee)['numero']);
        NumeroteurOdm::definirDepart('AT', 2026, 40, $this->mining);
        $this->assertSame([2, 40], [NumeroteurOdm::dernier('AT', 2026, $this->guinee), NumeroteurOdm::dernier('AT', 2026, $this->mining)]);

        $this->actingAs($this->demandeurMining);
        $odm = EnregistrementOdm::creer($this->demandeurMining);
        $this->assertSame($this->mining, $odm->entite_id);
    }

    /** RG-M01-08 : référentiels par entité (codes Mining « MMCBS »), et référentiel commun */
    public function test_les_referentiels_sont_propres_a_chaque_entite(): void
    {
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'entite_id' => $this->guinee]);
        CodeAnalytique::factory()->create(['code' => 'MMCBS1', 'libelle' => 'Mining', 'entite_id' => $this->mining]);
        CodeAnalytique::factory()->create(['code' => 'COMMUN', 'libelle' => 'Groupe', 'entite_id' => null]);

        $this->actingAs($this->demandeurGuinee);
        $this->assertEqualsCanonicalizing(['ADAZZZ', 'COMMUN'], CodeAnalytique::pluck('code')->all());
        $this->actingAs($this->demandeurMining);
        EntiteCourante::oublier();
        $this->assertEqualsCanonicalizing(['MMCBS1', 'COMMUN'], CodeAnalytique::pluck('code')->all());
    }

    /** RG-M03-11 : entité affichée à la saisie ; RG-M14 et RG-M16-03 : entité dans les exports */
    public function test_l_entite_est_affichee_a_la_saisie_et_exportee(): void
    {
        /* Le champ texte « entite » du compte (import des référentiels) ne masque pas l'entité */
        $this->demandeurGuinee->update(['entite' => 'Neemba Guinée']);
        $this->actingAs($this->demandeurGuinee)->get(route('bons-caisse.create'))
            ->assertInertia(fn (Assert $page) => $page->where('entite', 'Neemba Guinée'));

        $bon = BonCaisse::factory()->create(['demandeur_id' => $this->demandeurGuinee->id]);
        $lignes = ExportBons::lignes(BonCaisse::deLEntite(null)->whereKey($bon->id)->get());
        $this->assertSame(['Entité', 'NGU'], [$lignes[0][1], $lignes[1][1]]);
    }

    /** Neemba Mining inactive (situation réelle) : ni sélecteur ni vue consolidée */
    public function test_sans_seconde_entite_active_rien_ne_change_a_l_ecran(): void
    {
        Entite::whereKey($this->mining)->update(['actif' => false]);
        $this->actingAs($this->dafGuinee)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.entite.peut_consolider', false)
            ->where('auth.entite.accessibles', fn ($a) => count($a) === 1));
    }
}
