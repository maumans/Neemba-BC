<?php

namespace Tests\Feature\M12;

use App\Models\CodeAnalytique;
use App\Models\Delegation;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\User;
use App\Services\Odm\CircuitOdm;
use App\Services\Odm\EnregistrementOdm;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * M12-3 — Circuit de validation des ODM (RG-M12-11, RG-M12-12, RG-M12-25, RG-M01-04 ; §6.7) ; scénario SC-20.
 */
class CircuitOdmTest extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $thierno;
    private User $chefAtelier;
    private User $daf;
    private User $dafAdjoint;
    private User $dp;
    private User $assistante;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');

        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);
        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique']);
        $this->thierno = User::factory()->create(['name' => 'BAH', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre']);
        $this->chefAtelier = $this->avecRole(User::factory()->create(['name' => 'BANGOURA', 'prenom' => 'Thomas', 'service' => 'Technique']), 'chef_atelier');
        $this->daf = User::factory()->role('daf')->create(['name' => 'DIAKITE', 'prenom' => 'Mohamed']);
        $this->dafAdjoint = $this->avecRole(User::factory()->create(['name' => 'CISS', 'prenom' => 'Mor']), 'daf_adjoint');
        $this->dp = User::factory()->role('directeur_pays')->create(['name' => 'LO', 'prenom' => 'Mamadou']);
        $this->assistante = User::factory()->create(['service' => 'Technique']);
        $technique->update(['diffusion_odm' => [$this->assistante->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function avecRole(User $utilisateur, string $role): User
    {
        $utilisateur->ajouterRoles([$role]);

        return $utilisateur;
    }

    /** ODM B.1 soumis par Philippe KOLIE pour Thierno BAH */
    private function odmSoumis(?array $participants = null): OrdreMission
    {
        $reponse = $this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26', 'prise_en_charge' => 'neemba',
            'participants' => $participants ?? [['user_id' => $this->thierno->id]],
        ])->assertCreated();
        $odm = OrdreMission::findOrFail($reponse->json('odm.id'));
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();

        return $odm->fresh();
    }

    private function viser(OrdreMission $odm, User $valideur, ?string $commentaire = null)
    {
        return $this->actingAs($valideur)->post(route('odm.viser', $odm), ['commentaire' => $commentaire]);
    }

    private function statutsEtapes(OrdreMission $odm): array
    {
        return $odm->etapes()->where('version', $odm->fresh()->version)->pluck('statut', 'role')->all();
    }

    /** SC-20 : chef d'atelier → DAF (ici le DAF adjoint, Q27) → DP ; validation finale (RG-M12-25, MSG-M12-08) */
    public function test_le_circuit_complet_jusqu_a_la_validation(): void
    {
        $odm = $this->odmSoumis();

        $this->viser($odm, $this->chefAtelier, 'Mission urgente')->assertRedirect(route('odm.show', $odm))
            ->assertSessionHas('success', "Visa enregistré : l'ordre de mission N°1/AT/26 passe au niveau suivant.");
        $this->assertSame('EN_VALIDATION', $odm->fresh()->statut);
        $this->assertSame(['chef_atelier' => 'validee', 'daf' => 'en_attente', 'directeur_pays' => 'a_venir'], $this->statutsEtapes($odm));
        $this->assertTrue(Notification::where('destinataire_id', $this->dafAdjoint->id)->where('type', 'odm_a_viser')->exists());

        $this->viser($odm, $this->dafAdjoint)->assertSessionHas('success');
        $this->viser($odm, $this->dp)->assertSessionHas('success', 'ODM N°1/AT/26 validé. Vous pouvez générer le ou les bons de caisse.');

        $odm->refresh();
        $this->assertSame('VALIDE', $odm->statut);
        $this->assertNotNull($odm->date_validation);
        $this->assertSame(250000, $odm->parametres_figes['baremes']['indemnite_journaliere']);
        $this->assertArrayHasKey('valide_le', $odm->parametres_figes);
        $this->assertSame(
            [$this->chefAtelier->id, $this->dafAdjoint->id, $this->dp->id],
            $odm->etapes()->orderBy('niveau')->pluck('valideur_id')->all(),
        );
        $this->assertSame('Mission urgente', $odm->etapes()->where('role', 'chef_atelier')->value('commentaire'));
        $this->assertSame('ODM N°1/AT/26 validé. Vous pouvez générer le ou les bons de caisse.',
            Notification::where('destinataire_id', $this->demandeur->id)->where('type', 'odm_valide')->sole()->message);
        $this->assertTrue(Notification::where('destinataire_id', $this->assistante->id)->where('type', 'odm_valide')->exists());
        $this->assertSame(['soumission', 'visa', 'visa', 'validation'], $odm->historique()->where('action', '!=', 'creation')->pluck('action')->all());
    }

    /** Ordre du circuit et périmètre du chef d'atelier (son service) */
    public function test_seul_le_valideur_de_l_etape_en_cours_vise(): void
    {
        $odm = $this->odmSoumis();
        $autreChef = $this->avecRole(User::factory()->create(['service' => 'Logistique']), 'chef_atelier');

        $this->viser($odm, $this->daf)->assertSessionHas('error', 'Vous ne pouvez pas viser cet ordre de mission.');
        $this->viser($odm, $autreChef)->assertSessionHas('error', 'Vous ne pouvez pas viser cet ordre de mission.');
        $this->assertSame('SOUMIS', $odm->fresh()->statut);
        $this->assertSame(['chef_atelier' => 'en_attente', 'daf' => 'a_venir', 'directeur_pays' => 'a_venir'], $this->statutsEtapes($odm));
    }

    /** RG-M12-12 : rejet motivé, ODM modifiable, resoumis avec le même numéro en version 2 */
    public function test_le_rejet_puis_la_resoumission(): void
    {
        $odm = $this->odmSoumis();
        $this->viser($odm, $this->chefAtelier);

        $this->actingAs($this->daf)->post(route('odm.rejeter', $odm), ['motif' => 'Trop'])
            ->assertSessionHasErrors(['motif' => 'Indiquez le motif du rejet (10 caractères minimum).']);
        $this->actingAs($this->daf)->post(route('odm.rejeter', $odm), ['motif' => 'Le client prend en charge l\'hébergement'])
            ->assertSessionHas('success');

        $odm->refresh();
        $this->assertSame('REJETE', $odm->statut);
        $this->assertNull($odm->parametres_figes);
        $this->assertSame(['chef_atelier' => 'validee', 'daf' => 'rejetee', 'directeur_pays' => 'annulee'], $this->statutsEtapes($odm));
        $this->assertStringContainsString('Le client prend en charge l\'hébergement',
            Notification::where('destinataire_id', $this->demandeur->id)->where('type', 'odm_rejete')->sole()->message);

        $this->actingAs($this->demandeur)->get(route('odm.edit', $odm))
            ->assertInertia(fn (Assert $page) => $page->where('odm.rejet.niveau', 'DAF')->where('odm.rejet.motif', 'Le client prend en charge l\'hébergement'));
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['prise_en_charge' => 'client'])->assertOk();
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk()->assertJsonPath('odm.numero', 'N°1/AT/26');

        $odm->refresh();
        $this->assertSame(2, $odm->version);
        $this->assertSame(['chef_atelier' => 'en_attente', 'daf' => 'a_venir', 'directeur_pays' => 'a_venir'], $this->statutsEtapes($odm));
        $this->assertSame(6, $odm->etapes()->count());
        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page->has('etapes', 6)->where('etapes.3.version', 2));
    }

    /** RG-M01-04 : le chef d'atelier participant ne vise pas ; sans suppléant, l'étape est sautée */
    public function test_un_participant_ne_vise_pas_et_l_etape_passe_au_niveau_superieur(): void
    {
        $odm = $this->odmSoumis([['user_id' => $this->thierno->id], ['user_id' => $this->chefAtelier->id]]);

        $this->assertSame(['chef_atelier' => 'sautee', 'daf' => 'en_attente', 'directeur_pays' => 'a_venir'], $this->statutsEtapes($odm));
        $this->viser($odm, $this->chefAtelier)->assertSessionHas('error');
        $this->assertTrue(Notification::where('destinataire_id', $this->daf->id)->where('type', 'odm_a_viser')->exists());
        $this->assertFalse(Notification::where('destinataire_id', $this->chefAtelier->id)->where('type', 'odm_a_viser')->exists());
        $this->viser($odm, $this->daf)->assertSessionHas('success');
    }

    /** Suppléant du chef d'atelier (délégation « Visa des ordres de mission ») : il vise « au titre de » */
    public function test_le_suppleant_vise_au_titre_du_titulaire(): void
    {
        $suppleant = User::factory()->create(['name' => 'TOUNKARA', 'prenom' => 'Raby', 'service' => 'Technique']);
        Delegation::create([
            'delegant_id' => $this->chefAtelier->id, 'delegue_id' => $suppleant->id, 'date_debut' => today(), 'date_fin' => today()->addDays(10),
            'motif' => 'Congés', 'fonctionnalites' => ['visa_odm'], 'statut' => 'acceptee',
        ]);
        $odm = $this->odmSoumis([['user_id' => $this->thierno->id], ['user_id' => $this->chefAtelier->id]]);

        /* Le titulaire est participant : son suppléant vise, l'étape n'est pas sautée */
        $this->assertSame('en_attente', $this->statutsEtapes($odm)['chef_atelier']);
        $this->assertTrue(Notification::where('destinataire_id', $suppleant->id)->where('type', 'odm_a_viser')->exists());
        $this->actingAs($suppleant)->get(route('odm.index'))->assertInertia(fn (Assert $page) => $page->has('aViser', 1));
        $this->actingAs($suppleant)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page->where('visa.niveau', "Chef d'atelier / chef d'équipe")->where('visa.au_titre_de', 'Thomas BANGOURA'));

        $this->viser($odm, $suppleant)->assertSessionHas('success');
        $etape = $odm->etapes()->where('role', 'chef_atelier')->sole();
        $this->assertSame($suppleant->id, $etape->valideur_id);
        $this->assertSame($this->chefAtelier->id, $etape->au_titre_de_id);
        $this->actingAs($suppleant)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page->where('etapes.0.au_titre_de', 'Thomas BANGOURA'));
    }

    /** Une délégation de validation des bons ne donne pas le visa des ODM */
    public function test_une_delegation_de_validation_des_bons_ne_suffit_pas(): void
    {
        $suppleant = User::factory()->create(['service' => 'Technique']);
        Delegation::create([
            'delegant_id' => $this->chefAtelier->id, 'delegue_id' => $suppleant->id, 'date_debut' => today(), 'date_fin' => today()->addDays(10),
            'motif' => 'Congés', 'fonctionnalites' => ['validation'], 'statut' => 'acceptee',
        ]);
        $odm = $this->odmSoumis();

        $this->viser($odm, $suppleant)->assertSessionHas('error');
    }

    /** Le DP adjoint vise le niveau DP ; le DP demandeur d'un ODM ne le vise pas */
    public function test_le_dp_adjoint_vise_quand_le_dp_est_demandeur(): void
    {
        $dpAdjoint = $this->avecRole(User::factory()->create(['name' => 'SYLLA', 'prenom' => 'Mama']), 'dp_adjoint');
        $this->demandeur = $this->dp;
        $this->dp->update(['service' => 'Technique']);
        $odm = $this->odmSoumis();
        $this->viser($odm, $this->chefAtelier);
        $this->viser($odm, $this->daf);

        $this->viser($odm, $this->dp)->assertSessionHas('error');
        $this->viser($odm, $dpAdjoint)->assertSessionHas('success');
        $this->assertSame('VALIDE', $odm->fresh()->statut);
    }

    /** RG-M12-11 : étape RH ajoutée si le paramètre est actif */
    public function test_l_etape_rh_en_option(): void
    {
        Parametre::majValeur('odm_etape_rh', 'true');
        $rh = $this->avecRole(User::factory()->create(), 'rh');
        $odm = $this->odmSoumis();

        $this->assertSame(['chef_atelier', 'daf', 'rh', 'directeur_pays'], $odm->etapes()->orderBy('niveau')->pluck('role')->all());
        $this->viser($odm, $this->chefAtelier);
        $this->viser($odm, $this->daf);
        $this->viser($odm, $rh)->assertSessionHas('success');
        $this->assertSame('en_attente', $this->statutsEtapes($odm)['directeur_pays']);
    }

    /** RG-M12-25 : un barème modifié après la validation ne change pas le calcul de l'ODM */
    public function test_le_calcul_est_fige_a_la_validation(): void
    {
        $odm = $this->odmSoumis();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->viser($odm, $valideur);
        }

        Parametre::majValeur('odm_indemnite_journaliere', '300000');
        Cache::flush();
        EnregistrementOdm::recalculer($odm->fresh());

        $this->assertEquals(3250000, (float) $odm->fresh()->total);
        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page->where('odm.calcul.fige', true)->where('odm.calcul.indemnite_journaliere', 250000));
    }

    /** §6.7 : relance à l'échéance, escalade au double du délai */
    public function test_les_relances_et_l_escalade_des_visas(): void
    {
        $odm = $this->odmSoumis();
        $etape = CircuitOdm::etapeEnCours($odm);
        $this->assertSame('01/09/2026 12:00', \App\Support\Format::dateHeure(CircuitOdm::echeance($etape)));   // 4 h (sla_responsable_service)

        Carbon::setTestNow('2026-09-01 12:30');
        $this->artisan('odm:relancer-visas')->expectsOutput('1 relance(s), 0 escalade(s).')->assertSuccessful();
        $this->artisan('odm:relancer-visas')->expectsOutput('0 relance(s), 0 escalade(s).');
        $this->assertSame(1, Notification::where('destinataire_id', $this->chefAtelier->id)->where('type', 'odm_relance')->count());

        Carbon::setTestNow('2026-09-01 16:30');
        $this->artisan('odm:relancer-visas')->expectsOutput('0 relance(s), 1 escalade(s).');
        $this->assertTrue(Notification::where('destinataire_id', $this->daf->id)->where('type', 'odm_escalade')->exists());
        $this->assertTrue($etape->fresh()->escalade);
    }
}
