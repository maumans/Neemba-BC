<?php

namespace Tests\Feature\M12;

use App\Models\CodeAnalytique;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\Service;
use App\Models\User;
use App\Services\Odm\VueMission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * M12-5 — Prolongations et vue mission (RG-M12-17 à RG-M12-19, RG-M12-28) ; SC-24, annexes B.3 et B.4.
 */
class ProlongationOdmTest extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $thierno;
    private User $yacouba;
    private User $chefAtelier;
    private User $daf;
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
        $this->yacouba = User::factory()->create(['name' => 'BARRY', 'prenom' => 'Yacouba', 'service' => 'Technique', 'statut_cadre' => 'cadre']);
        $this->chefAtelier = User::factory()->create(['service' => 'Technique']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        $this->daf = User::factory()->role('daf')->create();
        $this->dp = User::factory()->role('directeur_pays')->create();
        $this->assistante = User::factory()->create(['service' => 'Technique']);
        $technique->update(['diffusion_odm' => [$this->assistante->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Exemple B.1 validé : du 22/09 au 26/09 */
    private function odmValide(?array $participants = null): OrdreMission
    {
        $odm = OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26', 'prise_en_charge' => 'neemba',
            'participants' => $participants ?? [['user_id' => $this->thierno->id]],
        ])->assertCreated()->json('odm.id'));
        $this->soumettreEtViser($odm);

        return $odm->fresh();
    }

    private function soumettreEtViser(OrdreMission $odm, bool $visaFinal = true): void
    {
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ($visaFinal ? [$this->chefAtelier, $this->daf, $this->dp] : [$this->chefAtelier, $this->daf] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm))->assertSessionHas('success');
        }
    }

    private function prolonger(OrdreMission $odm, string $retour)
    {
        return $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), ['date_retour_prevue' => $retour]);
    }

    /* ------------------------------------------------------------------ */

    /** SC-24, annexe B.3 : prolongation du 27/09 au 03/10, nuitée de rattrapage, numéro propre (Q23) */
    public function test_la_prolongation_et_sa_nuitee_de_rattrapage(): void
    {
        $initial = $this->odmValide();

        $reponse = $this->prolonger($initial, '2026-10-03');
        $segment = OrdreMission::where('segment_precedent_id', $initial->id)->sole();
        $reponse->assertRedirect(route('odm.edit', $segment))
            ->assertSessionHas('success', 'Prolongation : une nuitée de rattrapage du segment N°1/AT/26 est ajoutée pour 1 participant(s).');

        $this->assertSame('2026-09-27', $segment->date_depart->toDateString());   // retour + 1 jour
        $this->assertSame(2, $segment->rang);
        $this->assertSame($initial->id, $segment->mission_id);
        $participant = $segment->participantsActifs()->sole();
        $this->assertSame([7, 6, 1], [$participant->jours, $participant->nuits, $participant->nuit_rattrapage]);
        $this->assertEquals(500000, (float) $participant->rattrapage);
        $this->assertEquals(5250000, (float) $segment->total);

        $this->actingAs($this->demandeur)->get(route('odm.edit', $segment))
            ->assertInertia(fn (Assert $page) => $page->where('odm.prolongation.precedent', 'N°1/AT/26')->where('odm.prolongation.rattrapages', 1)
                ->where('odm.calcul.participants.0.rattrapage', 500000));

        $this->soumettreEtViser($segment);
        $segment->refresh();
        $this->assertSame('N°2/AT/26', $segment->numero);
        $this->assertSame('Prolongation 1 de N°1/AT/26', $segment->libelle_prolongation);
        $this->assertStringContainsString('la prolongation N°2/AT/26 (Prolongation 1 de N°1/AT/26)',
            Notification::where('destinataire_id', $this->assistante->id)->where('type', 'odm_soumis')->latest('id')->first()->message);

        /* Vue mission : 5 + 7 = 12 jours ; 4 + 7 = 11 nuits = 12 − 1 (RG-M12-19) */
        $mission = VueMission::pour($segment);
        $this->assertSame(12, $mission['jours']);
        $this->assertSame(['jours' => 12, 'nuits' => 11, 'nuits_attendues' => 11, 'coherent' => true],
            collect($mission['participants'][0])->only(['jours', 'nuits', 'nuits_attendues', 'coherent'])->all());
        $this->assertEquals(8500000, $mission['total']);
        $this->assertSame([], $mission['incoherences']);
        $this->assertSame('Prolongation 1 de N°1/AT/26', $mission['segments'][1]['libelle_prolongation']);

        /* Seconde prolongation, depuis la première */
        $this->prolonger($segment, '2026-10-05')->assertSessionHas('success');
        $troisieme = OrdreMission::where('segment_precedent_id', $segment->id)->sole();
        $this->assertSame([3, '2026-10-04'], [$troisieme->rang, $troisieme->date_depart->toDateString()]);
        $this->assertSame('Prolongation 2 de N°1/AT/26', $troisieme->libelle_prolongation);
    }

    /** RG-M12-17 : participants repris, retrait possible, ajout impossible ; type et départ fixés */
    public function test_les_participants_d_une_prolongation(): void
    {
        $initial = $this->odmValide([['user_id' => $this->thierno->id], ['user_id' => $this->yacouba->id, 'base_vie' => true]]);
        $this->prolonger($initial, '2026-10-03')
            ->assertSessionHas('success', 'Prolongation : une nuitée de rattrapage du segment N°1/AT/26 est ajoutée pour 1 participant(s).');   // pas pour la base vie
        $segment = OrdreMission::where('segment_precedent_id', $initial->id)->sole();
        $autre = User::factory()->create();

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $segment), [
            'participants' => [['user_id' => $this->thierno->id], ['user_id' => $autre->id]],
        ])->assertStatus(422)->assertJsonPath('message', 'Une prolongation reprend les participants du segment précédent : on peut en retirer, pas en ajouter.');

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $segment), [
            'participants' => [['user_id' => $this->thierno->id]], 'date_depart' => '2026-09-30', 'type' => 'exterieur',
        ])->assertOk()->assertJsonCount(1, 'odm.participants');

        $segment->refresh();
        $this->assertSame('2026-09-27', $segment->date_depart->toDateString());
        $this->assertSame('interieur', $segment->type);
        $this->assertTrue($segment->participants()->where('user_id', $this->yacouba->id)->value('retire'));
        $this->assertEquals(5250000, (float) $segment->total);
    }

    /** RG-M12-17 : seul le dernier segment validé, par son demandeur ; retour après le départ */
    public function test_les_refus_de_prolongation(): void
    {
        $odm = OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'but' => 'Dépannage d\'une chargeuse chez SMD', 'destinations' => ['Kouroussa'],
            'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26', 'participants' => [['user_id' => $this->thierno->id]],
        ])->json('odm.id'));
        $this->prolonger($odm, '2026-10-03')->assertSessionHas('error', 'Seul le dernier segment validé d\'une mission en cours peut être prolongé.');

        $valide = $this->odmValide([['user_id' => $this->yacouba->id]]);
        $this->actingAs($this->thierno)->post(route('odm.prolonger', $valide), ['date_retour_prevue' => '2026-10-03'])->assertSessionHas('error');
        $this->prolonger($valide, '2026-09-25')->assertSessionHasErrors(['date_retour_prevue' => 'La date de retour doit être postérieure ou égale à la date de départ.']);
        $this->prolonger($valide, '2026-10-03')->assertSessionHas('success');
        $this->prolonger($valide, '2026-10-10')->assertSessionHas('error');   // déjà prolongé
        $this->actingAs($this->demandeur)->get(route('odm.show', $valide))
            ->assertInertia(fn (Assert $page) => $page->where('peutProlonger', false)->where('prolongation.libelle', 'Brouillon'));
    }

    /** Annexe B.4 : mission KOUROUMA, 57 nuits payées pour 59 jours (MSG-M12-07) */
    public function test_la_vue_mission_signale_l_ecart_de_la_mission_kourouma(): void
    {
        $kourouma = User::factory()->create(['name' => 'KOUROUMA', 'prenom' => 'Sekou']);
        $segments = [['2026-07-29', '2026-08-06', 9, 8, 0], ['2026-08-07', '2026-08-20', 14, 13, 1], ['2026-08-21', '2026-08-26', 6, 5, 1],
            ['2026-08-27', '2026-09-20', 25, 24, 1], ['2026-09-21', '2026-09-25', 5, 4, 0]];   // dernier segment : rattrapage oublié
        $precedent = null;
        foreach ($segments as $rang => [$depart, $retour, $jours, $nuits, $rattrapage]) {
            $odm = OrdreMission::factory()->numerote(100 + $rang)->create([
                'statut' => 'VALIDE', 'date_depart' => $depart, 'date_retour_prevue' => $retour, 'rang' => $rang + 1,
                'mission_id' => $precedent ? ($precedent->mission_id ?? $precedent->id) : null, 'segment_precedent_id' => $precedent?->id,
            ]);
            $odm->participants()->create(['user_id' => $kourouma->id, 'nom' => 'KOUROUMA Sekou', 'jours' => $jours, 'nuits' => $nuits, 'nuit_rattrapage' => $rattrapage]);
            $precedent = $odm;
        }

        $mission = VueMission::pour($precedent);
        $this->assertSame(59, $mission['jours']);
        $this->assertSame(57, $mission['participants'][0]['nuits']);
        $this->assertSame(['Incohérence sur la mission N°100/AT/26 : 57 nuits payées pour 59 jours. (KOUROUMA Sekou)'], $mission['incoherences']);
        $this->assertCount(5, $mission['segments']);
    }

    /** RG-M12-19 : une incohérence constatée à la validation d'un segment est signalée au DAF */
    public function test_l_incoherence_est_signalee_au_daf(): void
    {
        $initial = $this->odmValide();
        $this->prolonger($initial, '2026-10-03');
        $segment = OrdreMission::where('segment_precedent_id', $initial->id)->sole();
        $this->soumettreEtViser($segment, false);
        $initial->participants()->update(['nuits' => 3]);   // donnée faussée : une nuit de moins sur le segment 1

        $this->actingAs($this->dp)->post(route('odm.viser', $segment))->assertSessionHas('success');

        $alerte = Notification::where('destinataire_id', $this->daf->id)->where('type', 'odm_incoherence')->sole();
        $this->assertSame('Incohérence sur la mission N°1/AT/26 : 10 nuits payées pour 12 jours. (BAH Thierno)', $alerte->message);
        $this->assertSame('incoherence', $initial->historique()->reorder('id', 'desc')->value('action'));
    }

    /** RG-M12-28 : rappel 2 jours ouvrés avant la fin du segment, une seule fois */
    public function test_le_rappel_avant_la_fin_de_la_mission(): void
    {
        $odm = $this->odmValide();   // retour prévu le samedi 26/09 : rappel le jeudi 24/09

        Carbon::setTestNow('2026-09-23 07:00');
        $this->artisan('odm:rappeler-fin-segment')->expectsOutput('0 rappel(s) envoyé(s).');
        Carbon::setTestNow('2026-09-24 07:00');
        $this->artisan('odm:rappeler-fin-segment')->expectsOutput('1 rappel(s) envoyé(s).');
        $this->artisan('odm:rappeler-fin-segment')->expectsOutput('0 rappel(s) envoyé(s).');

        $rappel = Notification::where('destinataire_id', $this->demandeur->id)->where('type', 'odm_rappel')->sole();
        $this->assertSame('La mission N°1/AT/26 (Kouroussa) se termine le 26/09/2026 : prolongez-la si elle continue, ou clôturez-la au retour.', $rappel->message);
        $this->assertNotNull($odm->fresh()->rappel_envoye_le);
    }

    public function test_pas_de_rappel_pour_un_segment_deja_prolonge(): void
    {
        $odm = $this->odmValide();
        $this->prolonger($odm, '2026-10-03');

        Carbon::setTestNow('2026-09-24 07:00');
        $this->artisan('odm:rappeler-fin-segment')->expectsOutput('0 rappel(s) envoyé(s).');
    }
}
