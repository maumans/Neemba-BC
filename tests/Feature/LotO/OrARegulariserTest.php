<?php

namespace Tests\Feature\LotO;

use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueAction;
use App\Models\HistoriqueOdm;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\User;
use App\Services\Odm\RegulariserOr;
use App\Services\Odm\TableauBordOdm;
use App\Support\JoursOuvres;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot O (spec v2.4, RG-M12-02, RG-M12-39 à RG-M12-42, MSG-M12-01, 10, 11, SC-38) : ODM technique « OR à régulariser » :
 * soumission sans OR, échéance à la validation, OR rattaché après coup et reporté sur les bons, relances J+3 et à
 * l'échéance, alerte de retard, blocage optionnel du prochain ODM, suivi au tableau de bord ; besoin carburant.
 */
class OrARegulariserTest extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $participant;
    private User $chefAtelier;
    private User $daf;
    private User $dp;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 08:00');
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);
        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique']);
        $this->participant = User::factory()->create(['service' => 'Technique', 'statut_cadre' => 'non_cadre']);
        $this->chefAtelier = User::factory()->create(['name' => 'BANGOURA', 'prenom' => 'Thomas', 'service' => 'Technique']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        $this->daf = User::factory()->role('daf')->create(['name' => 'DIAKITE', 'prenom' => 'Mohamed']);
        $this->dp = User::factory()->role('directeur_pays')->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function brouillon(array $saisie = []): OrdreMission
    {
        $reponse = $this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $saisie + [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => true, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => '2026-11-10', 'date_retour_prevue' => '2026-11-13', 'prise_en_charge' => 'neemba',
            'participants' => [['user_id' => $this->participant->id]],
        ])->assertCreated();

        return OrdreMission::findOrFail($reponse->json('odm.id'));
    }

    /** ODM « OR à régulariser » soumis puis validé (chef d'atelier, DAF, DP) */
    private function odmValide(): OrdreMission
    {
        $odm = $this->brouillon(['or_a_regulariser' => true]);
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm))->assertSessionHas('success');
        }

        return $odm->fresh();
    }

    /** RG-M12-02, MSG-M12-01 : mission technique sans OR refusée, sauf « OR à régulariser » */
    public function test_une_mission_technique_sans_or_se_soumet_avec_or_a_regulariser(): void
    {
        $odm = $this->brouillon();
        $reponse = $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertStatus(422);
        $this->assertSame('Mission technique : rattachez au moins un OR ou cochez « OR à régulariser ».', $reponse->json('message'));

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['or_a_regulariser' => true])->assertOk()
            ->assertJsonPath('odm.or_a_regulariser', true);
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();

        /* L'indicateur ne vaut que pour une mission technique sans OR */
        $autre = $this->brouillon(['or_a_regulariser' => true, 'technique' => false]);
        $this->assertFalse($autre->fresh()->or_a_regulariser);
        $avecOr = $this->brouillon(['or_a_regulariser' => true, 'ordres_reparation' => [['numero' => '11022219', 'type' => 'vente']]]);
        $this->assertFalse($avecOr->fresh()->or_a_regulariser);
    }

    /** RG-M12-40 : échéance à la validation (5 jours ouvrés), demandeur prévenu (MSG-M12-10) */
    public function test_l_echeance_est_fixee_a_la_validation(): void
    {
        $odm = $this->odmValide();
        $echeance = JoursOuvres::ajouter(Carbon::parse('2026-11-02'), 5)->toDateString();

        $this->assertSame('VALIDE', $odm->statut);
        $this->assertSame($echeance, $odm->or_echeance->toDateString());
        $this->assertTrue(Notification::where('destinataire_id', $this->demandeur->id)->where('type', 'odm_or_a_regulariser')
            ->where('message', 'like', "ODM {$odm->numero} : OR à régulariser avant le " . $odm->or_echeance->format('d/m/Y') . '.%')->exists());
        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page
            ->where('odm.or_a_regulariser', true)->where('odm.or_echeance_format', $odm->or_echeance->format('d/m/Y'))
            ->where('peutAjouterOr', true)->where('typesOr', ['vente' => 'Vente', 'garantie' => 'Garantie']));
    }

    /** RG-M12-39, Q80 : OR rattaché après coup, tracé, reporté sur les bons ; l'indicateur tombe */
    public function test_l_or_rattache_apres_coup_est_reporte_sur_les_bons(): void
    {
        $odm = $this->odmValide();
        $bon = BonCaisse::factory()->statut('APPROUVE')->create(['odm_id' => $odm->id, 'genere_par_odm' => true, 'references_or' => null, 'types_or' => null]);

        $this->actingAs(User::factory()->create())->post(route('odm.ajouter-or', $odm), ['numero' => '11022219', 'type' => 'vente'])
            ->assertSessionHasErrors();
        $this->actingAs($this->demandeur)->post(route('odm.ajouter-or', $odm), ['numero' => '12345678', 'type' => 'vente'])->assertSessionHasErrors('numero');
        $this->actingAs($this->demandeur)->post(route('odm.ajouter-or', $odm), ['numero' => '11022219', 'type' => 'inconnu'])->assertSessionHasErrors('type');

        $this->actingAs($this->demandeur)->post(route('odm.ajouter-or', $odm), ['numero' => '11022219', 'type' => 'garantie'])
            ->assertSessionHas('success', "OR 11022219 rattaché à l'ordre de mission {$odm->numero} ; il est reporté sur ses bons.");
        $odm->refresh();
        $this->assertFalse($odm->or_a_regulariser);
        $or = $odm->ordresReparation()->sole();
        $this->assertSame(['garantie', $this->demandeur->id], [$or->type, $or->saisi_par]);
        $this->assertNotNull($or->saisi_apres_coup_le);
        $this->assertTrue(HistoriqueOdm::where('ordre_mission_id', $odm->id)->where('action', 'or_ajoute')->exists());

        $bon->refresh();
        $this->assertSame(['11022219'], $bon->references_or);
        $this->assertSame(['11022219' => 'garantie'], $bon->types_or);
        $this->assertTrue(HistoriqueAction::where('bon_caisse_id', $bon->id)->where('action', HistoriqueAction::ACTION_OR_AJOUTE)->exists());

        /* Le chef d'atelier en rattache un second ; un doublon est refusé */
        $this->actingAs($this->chefAtelier)->post(route('odm.ajouter-or', $odm), ['numero' => '11022220', 'type' => 'vente'])->assertSessionHas('success');
        $this->actingAs($this->chefAtelier)->post(route('odm.ajouter-or', $odm), ['numero' => '11022220', 'type' => 'vente'])
            ->assertSessionHasErrors(['numero' => "L'OR 11022220 est déjà rattaché à cet ordre de mission."]);
        $this->assertSame(['11022219', '11022220'], $bon->fresh()->references_or);
    }

    /** RG-M12-40 : relance J+3, relance à l'échéance, alerte de retard au chef d'atelier et au DAF ; une fois chacune */
    public function test_les_relances_et_l_alerte_de_retard(): void
    {
        $odm = $this->odmValide();
        $echeance = $odm->or_echeance;
        $notifications = fn (User $u, string $type) => Notification::where('destinataire_id', $u->id)->where('type', $type)->count();
        $avant = $notifications($this->demandeur, 'odm_or_a_regulariser');

        Carbon::setTestNow(JoursOuvres::ajouter(Carbon::parse('2026-11-02'), 3)->setTime(7, 15));
        $this->artisan('odm:relancer-or')->assertSuccessful();
        $this->artisan('odm:relancer-or')->assertSuccessful();
        $this->assertSame($avant + 1, $notifications($this->demandeur, 'odm_or_a_regulariser'));

        Carbon::setTestNow($echeance->copy()->setTime(7, 15));
        $this->artisan('odm:relancer-or')->assertSuccessful();
        $this->assertSame($avant + 2, $notifications($this->demandeur, 'odm_or_a_regulariser'));

        Carbon::setTestNow($echeance->copy()->addDay()->setTime(7, 15));
        $this->artisan('odm:relancer-or')->assertSuccessful();
        $this->artisan('odm:relancer-or')->assertSuccessful();
        $this->assertSame(1, $notifications($this->chefAtelier, 'odm_or_en_retard'));
        $this->assertSame(1, $notifications($this->daf, 'odm_or_en_retard'));
        $this->assertSame(0, $notifications($this->demandeur, 'odm_or_en_retard'));
        $this->assertSame(3, HistoriqueOdm::where('ordre_mission_id', $odm->id)->where('action', 'rappel')->count());

        /* RG-M12-41 : suivi au tableau de bord (retard en jours) et dans l'export */
        $suivi = TableauBordOdm::orARegulariser();
        $this->assertCount(1, $suivi);
        $this->assertSame(['Philippe KOLIE', 'Thomas BANGOURA', 1], [$suivi[0]['demandeur'], $suivi[0]['chef_atelier'], $suivi[0]['retard']]);
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord'))->assertInertia(fn (Assert $page) => $page
            ->where('indicateurs.or_a_regulariser', 1)->where('indicateurs.or_en_retard', 1)->has('orARegulariser', 1));
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord.export'))->assertOk();
    }

    /** RG-M12-40 : blocage du prochain ODM, désactivé par défaut (MSG-M12-11) */
    public function test_le_blocage_du_prochain_odm_est_optionnel(): void
    {
        $odm = $this->odmValide();
        Carbon::setTestNow($odm->or_echeance->copy()->addDays(2)->setTime(9, 0));
        $this->assertSame($odm->id, RegulariserOr::odmEnRetard($this->demandeur)?->id);

        $this->brouillon(['date_depart' => '2026-12-01', 'date_retour_prevue' => '2026-12-02']);

        Parametre::majValeur('odm_blocage_or_retard', 'true');
        $reponse = $this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), ['service' => 'Technique'])->assertStatus(422);
        $this->assertSame("Vous avez un ODM avec OR à régulariser en retard ({$odm->numero}). Rattachez l'OR avant de créer un nouvel ODM.", $reponse->json('message'));

        $this->actingAs($this->demandeur)->post(route('odm.ajouter-or', $odm), ['numero' => '11022219', 'type' => 'vente'])->assertSessionHas('success');
        $this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), ['service' => 'Technique'])->assertCreated();
    }

    /** RG-M12-42 : besoin carburant porté par l'ODM (véhicule, immatriculation, itinéraire) */
    public function test_le_besoin_carburant_est_porte_par_l_odm(): void
    {
        $odm = $this->brouillon(['vehicule' => 'Toyota Hilux', 'immatriculation' => ' rc 1234  a ', 'itineraire' => 'Conakry – Kindia – Kouroussa',
            'ordres_reparation' => [['numero' => '11022219', 'type' => 'vente']]]);
        $odm->refresh();
        $this->assertSame(['Toyota Hilux', 'RC 1234 A', 'Conakry – Kindia – Kouroussa'], [$odm->vehicule, $odm->immatriculation, $odm->itineraire]);
        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page
            ->where('odm.immatriculation', 'RC 1234 A')->where('odm.itineraire', 'Conakry – Kindia – Kouroussa'));
        $this->actingAs($this->demandeur)->get(route('odm.pdf', $odm))->assertOk();
    }

    /** Q111 (décision du 23/10) : la prolongation d'une mission qui attend encore son OR reste « OR à régulariser » */
    public function test_la_prolongation_reprend_l_or_a_regulariser(): void
    {
        $odm = $this->odmValide();
        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), ['date_retour_prevue' => '2026-11-20'])->assertSessionHas('success');
        $segment = OrdreMission::where('segment_precedent_id', $odm->id)->sole();
        $this->assertTrue($segment->or_a_regulariser);
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $segment))->assertOk();
    }
}
