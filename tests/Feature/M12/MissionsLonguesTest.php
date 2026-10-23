<?php

namespace Tests\Feature\M12;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\Odm\MissionLongue;
use App\Support\JoursOuvres;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot L — missions ponctuelles et missions longue durée (complément du 22/10 à la spec v2.4, RG-M12-43 à 51).
 * Scénarios du complément : création d'une mission longue, renouvellement, mission échue puis régularisée,
 * requalification d'une mission ponctuelle.
 */
class MissionsLonguesTest extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $thierno;
    private User $yacouba;
    private User $chefAtelier;
    private User $daf;
    private User $dp;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');
        Parametre::majValeur('seuil_validation_dp', '1500000');

        $conakry = Site::factory()->conakry()->create();
        Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id]);
        Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $conakry->id])
            ->crediter(50000000, 'approvisionnement');
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);

        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique']);
        $this->thierno = User::factory()->create(['name' => 'BAH', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre', 'numero_om' => '622334455']);
        $this->yacouba = User::factory()->create(['name' => 'BARRY', 'prenom' => 'Yacouba', 'service' => 'Technique', 'statut_cadre' => 'cadre', 'numero_om' => '622112233']);
        $this->chefAtelier = User::factory()->create(['service' => 'Technique']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        /* Valideurs des bons générés : sans eux, leurs étapes seraient sautées (RG-M04-09, Q48) */
        User::factory()->role('responsable_service')->create(['service' => 'Technique']);
        User::factory()->role('controle_gestion')->create();
        $this->daf = User::factory()->role('daf')->create();
        $this->dp = User::factory()->role('directeur_pays')->create();
        User::factory()->role('caissier')->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function brouillon(array $autres = []): OrdreMission
    {
        return OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $autres + [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Assistance permanente sur le site de la SMD',
            'destinations' => ['Boké'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26', 'prise_en_charge' => 'neemba',
            'participants' => [['user_id' => $this->thierno->id], ['user_id' => $this->yacouba->id]],
        ])->assertCreated()->json('odm.id'));
    }

    private function soumettreEtViser(OrdreMission $odm): OrdreMission
    {
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm))->assertSessionHas('success');
        }

        return $odm->fresh();
    }

    /** Mission longue validée : du 22/09, renouvelée tous les 15 jours jusqu'au 30/11 (période 1 : 22/09 → 06/10) */
    private function missionLongue(): OrdreMission
    {
        return $this->soumettreEtViser($this->brouillon(['duree_mission' => 'longue', 'frequence_renouvellement' => 15, 'date_fin_previsionnelle' => '2026-11-30']));
    }

    private function renouveler(OrdreMission $odm, array $autres = [])
    {
        return $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), $autres + ['type' => 'renouvellement']);
    }

    private function suivre(string|Carbon $quand): void
    {
        Carbon::setTestNow(Carbon::parse($quand)->setTime(7, 5));
        $this->artisan('odm:suivre-missions-longues')->assertSuccessful();
    }

    private function notifications(User $u, string $type): int
    {
        return Notification::where('destinataire_id', $u->id)->where('type', $type)->count();
    }

    /* ------------------------------------------------------------------ */

    /** RG-M12-43, 44 : nature à la création ; mission longue, fréquence de la liste, fin prévisionnelle, période calculée */
    public function test_creation_d_une_mission_longue(): void
    {
        $odm = $this->brouillon(['duree_mission' => 'longue', 'frequence_renouvellement' => 20, 'date_fin_previsionnelle' => '2026-11-30']);
        $this->assertSame('2026-09-26', $odm->date_retour_prevue->toDateString());   // fréquence hors liste : pas de période
        $this->assertSame('Mission longue durée : choisissez la fréquence de renouvellement.',
            $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertStatus(422)->json('message'));

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['frequence_renouvellement' => 15, 'date_fin_previsionnelle' => ''])->assertOk();
        $this->assertSame('Mission longue durée : saisissez la date de fin prévisionnelle, au plus tôt le jour du départ.',
            $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertStatus(422)->json('message'));

        /* Période 1 : départ + 15 jours − 1, fixée par la fréquence */
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['date_fin_previsionnelle' => '2026-11-30', 'date_retour_prevue' => '2026-12-31'])
            ->assertOk()->assertJsonPath('odm.date_retour_prevue', '2026-10-06');
        $odm = $this->soumettreEtViser($odm);
        $this->assertSame(['VALIDE', 'longue', 15], [$odm->statut, $odm->duree_mission, $odm->frequence_renouvellement]);
        $this->actingAs($this->daf)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page
            ->where('odm.duree_label', 'Mission longue durée')->where('odm.date_fin_previsionnelle_format', '30/11/2026')
            ->where('odm.derniere_periode', false)->where('frequencesRenouvellement', [15, 30, 45]));

        /* Une mission ponctuelle n'a ni fréquence ni fin prévisionnelle */
        $ponctuelle = $this->brouillon(['frequence_renouvellement' => 15, 'date_fin_previsionnelle' => '2026-11-30', 'participants' => [['user_id' => $this->demandeur->id]]]);
        $this->assertSame(['ponctuelle', null, null], [$ponctuelle->duree_mission, $ponctuelle->frequence_renouvellement, $ponctuelle->date_fin_previsionnelle]);
    }

    /** RG-M12-45 à 47 : renouvellement pré-rempli, circuit complet, bons générés à la validation ; fin prévisionnelle bornée puis reportée */
    public function test_renouvellement(): void
    {
        $odm = $this->missionLongue();
        $this->assertSame(0, BonCaisse::where('odm_id', $odm->id)->count());   // ODM initial : génération manuelle

        /* Fin prévisionnelle ramenée au 10/10 : la période s'y arrête */
        $this->renouveler($odm, ['date_fin_previsionnelle' => '2026-10-10'])->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Renouvellement : période du 07/10/2026 au 10/10/2026.'));
        $segment = OrdreMission::where('segment_precedent_id', $odm->id)->sole();
        $this->assertSame(['2026-10-07', '2026-10-10', 'renouvellement', 'longue', 15], [$segment->date_depart->toDateString(),
            $segment->date_retour_prevue->toDateString(), $segment->type_segment, $segment->duree_mission, $segment->frequence_renouvellement]);
        $this->assertSame([1, 1], $segment->participantsActifs()->pluck('nuit_rattrapage')->all());   // RG-M12-18

        $segment = $this->soumettreEtViser($segment);
        $this->assertSame('Renouvellement 1 de N°1/AT/26', $segment->libelle_prolongation);
        $this->assertSame('BONS_GENERES', $segment->statut);
        $this->assertSame(2, BonCaisse::where('odm_id', $segment->id)->count());   // un bon par participant, à la validation
        $this->assertTrue(MissionLongue::dernierePeriode($segment));

        /* Dernière période : renouveler exige de reporter la fin prévisionnelle */
        $this->renouveler($segment)->assertSessionHasErrors(['date_fin_previsionnelle' => 'La date de fin prévisionnelle (10/10/2026) est atteinte : reportez-la pour renouveler la mission, ou clôturez-la.']);
        $this->renouveler($segment, ['date_fin_previsionnelle' => '2026-10-31', 'frequence_renouvellement' => 30])->assertSessionHas('success');
        $suivant = OrdreMission::where('segment_precedent_id', $segment->id)->sole();
        $this->assertSame(['2026-10-11', '2026-10-31', 30], [$suivant->date_depart->toDateString(), $suivant->date_retour_prevue->toDateString(), $suivant->frequence_renouvellement]);
        $this->assertTrue($suivant->historique()->where('commentaire', 'like', '%reportée du 10/10/2026 au 31/10/2026%')->exists());
    }

    /** RG-M12-48, 49 : relances J-5, J-2, J-1, mission échue, puis renouvellement après coup, avec motif, sans trou */
    public function test_mission_echue_puis_regularisee(): void
    {
        $odm = $this->missionLongue();   // terme de la période : 06/10
        $terme = Carbon::parse('2026-10-06');

        /* J-5 ouvrés : demandeur et chef d'atelier, une seule fois */
        $this->suivre(JoursOuvres::retrancher($terme, 5)->toDateString());
        $this->suivre(JoursOuvres::retrancher($terme, 5)->toDateString());
        $this->assertSame([1, 1, 0], [$this->notifications($this->demandeur, 'odm_renouvellement'), $this->notifications($this->chefAtelier, 'odm_renouvellement'),
            $this->notifications($this->daf, 'odm_renouvellement')]);

        /* J-2 : rien de soumis, relance au chef d'atelier ; J-1 : non validé, alerte au DAF */
        $this->suivre(JoursOuvres::retrancher($terme, 2)->toDateString());
        $this->assertSame([1, 2], [$this->notifications($this->demandeur, 'odm_renouvellement'), $this->notifications($this->chefAtelier, 'odm_renouvellement')]);
        $this->suivre(JoursOuvres::retrancher($terme, 1)->toDateString());
        $this->assertSame(1, $this->notifications($this->daf, 'odm_renouvellement'));
        $this->assertStringContainsString("Aucun renouvellement n'est soumis", Notification::where('destinataire_id', $this->daf->id)->latest('id')->first()->message);

        /* Terme dépassé : « Échue », chef d'atelier et DAF alertés */
        $this->suivre('2026-10-07');
        $this->assertSame('2026-10-07', $odm->fresh()->echue_le->toDateString());
        $this->assertSame('Échue', $odm->fresh()->statut_label);
        $this->assertSame([1, 1], [$this->notifications($this->chefAtelier, 'odm_echue'), $this->notifications($this->daf, 'odm_echue')]);
        $this->actingAs($this->demandeur)->get(route('odm.index'))->assertInertia(fn (Assert $page) => $page->where('odms.data.0.echue', true));

        /* Renouvellement après coup : il part du lendemain du terme, motif obligatoire, jours écoulés payés à la validation */
        Carbon::setTestNow('2026-10-09 09:00');
        $this->renouveler($odm)->assertSessionHas('success');
        $segment = OrdreMission::where('segment_precedent_id', $odm->id)->sole();
        $this->assertSame(['2026-10-07', '2026-10-21'], [$segment->date_depart->toDateString(), $segment->date_retour_prevue->toDateString()]);
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $segment))->assertStatus(422);
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $segment), ['motif_depart_passe' => 'Renouvellement validé en retard'])->assertOk();
        $this->soumettreEtViser($segment);
        $this->assertNull($odm->fresh()->echue_le);
        $this->assertSame(2, BonCaisse::where('odm_id', $segment->id)->count());
        $this->assertEquals(15 * 250000 + 15 * 500000, (float) $segment->participantsActifs()->where('user_id', $this->thierno->id)->sole()->total);
    }

    /** RG-M12-50 : prolongation avec motif ; au-delà du seuil, alerte de requalification ; requalification par renouvellement */
    public function test_requalification_d_une_mission_ponctuelle(): void
    {
        $odm = $this->soumettreEtViser($this->brouillon());   // ponctuelle, 22/09 → 26/09
        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), ['date_retour_prevue' => '2026-10-26', 'motif_prolongation' => 'Court'])
            ->assertSessionHasErrors(['motif_prolongation' => 'Indiquez le motif de la prolongation (10 caractères minimum).']);
        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), ['date_retour_prevue' => '2026-10-26', 'motif_prolongation' => 'Panne plus grave que prévu'])
            ->assertSessionHas('success');
        $segment = OrdreMission::where('segment_precedent_id', $odm->id)->sole();
        $this->assertSame(['prolongation', 'ponctuelle', 'Panne plus grave que prévu'], [$segment->type_segment, $segment->duree_mission, $segment->motif_prolongation]);

        /* 35 jours cumulés > 30 : requalification proposée, une fois */
        $segment = $this->soumettreEtViser($segment);
        $this->assertSame([1, 1], [$this->notifications($this->demandeur, 'odm_requalification'), $this->notifications($this->chefAtelier, 'odm_requalification')]);
        $this->assertNotNull($odm->fresh()->alerte_requalification_le);

        /* Requalification : renouvellement d'une mission ponctuelle, fréquence et fin prévisionnelle choisies */
        $this->renouveler($segment, ['frequence_renouvellement' => 30, 'date_fin_previsionnelle' => '2026-12-31'])->assertSessionHas('success');
        $suivant = OrdreMission::where('segment_precedent_id', $segment->id)->sole();
        $this->assertSame(['longue', 'renouvellement', '2026-10-27', '2026-11-25'], [$suivant->duree_mission, $suivant->type_segment,
            $suivant->date_depart->toDateString(), $suivant->date_retour_prevue->toDateString()]);
        $this->assertTrue($suivant->historique()->where('commentaire', 'like', '%requalifiée en longue durée%')->exists());

        /* Les rappels RG-M12-28 restent réservés aux missions ponctuelles */
        Carbon::setTestNow('2026-10-22 07:00');
        $this->artisan('odm:rappeler-fin-segment')->assertSuccessful();
    }
}
