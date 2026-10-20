<?php

namespace Tests\Feature\LotE2;

use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\MotifUrgence;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\Service;
use App\Models\User;
use App\Services\NimbaSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Demandes urgentes (spec v2.4, RG-M01-07, Q76 ; décision de l'utilisateur du 20/10, Q104) :
 * - plus de code SMS pour viser un bon ou un ODM urgent : le valideur, déjà authentifié par le code de connexion, vise
 *   comme pour une demande normale ;
 * - un ODM urgent ou très urgent prévient ses valideurs par SMS à chaque étape ;
 * - l'ODM porte un niveau d'urgence et son motif.
 */
class UrgenceVisaTest extends TestCase
{
    use RefreshDatabase;

    /** Messages SMS envoyés pendant le test */
    private array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->mock(NimbaSmsService::class, function ($mock) {
            $mock->shouldReceive('envoyerSms')->andReturnUsing(function ($telephone, $message) {
                $this->sms[] = ['telephone' => $telephone, 'message' => $message];

                return ['success' => true];
            });
        });
    }

    private function smsA(string $telephone): array
    {
        return array_values(array_filter($this->sms, fn (array $s) => $s['telephone'] === $telephone));
    }

    /** Q104 : un bon urgent se vise sans code ; plus de route ni d'écran de code de visa */
    public function test_un_bon_urgent_se_vise_sans_code(): void
    {
        $daf = User::factory()->role('daf')->create(['telephone' => '622000045']);
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create([
            'niveau_urgence' => 'tres_urgente', 'motif_urgence' => 'Panne machine client', 'justification_urgence' => 'Machine arrêtée chez le client',
            'montant' => 500000, 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        $bon->validations()->where('role', '!=', 'daf')->update(['statut' => 'approuve']);

        $this->actingAs($daf)->get(route('validations.show', $bon))->assertInertia(fn (Assert $page) => $page->missing('codeVisaExige'));
        $this->actingAs($daf)->post(route('validations.approuver', $bon->fresh()))->assertSessionHas('success');
        $this->assertNotSame('EN_ATTENTE_DAF', $bon->fresh()->statut);
        $this->assertFalse(Route::has('validations.code-visa'));
        $this->assertFalse(Route::has('odm.code-visa'));
        $this->assertDatabaseMissing('codes_otp', ['objet' => 'visa_bon']);
        $this->assertNull(\App\Models\Parametre::where('cle', 'otp_visa_urgent_actif')->first());
    }

    /** Q76, Q104 : ODM urgent — motif obligatoire, alerte SMS aux valideurs à chaque étape, visa sans code */
    public function test_l_odm_urgent_previent_ses_valideurs_par_sms(): void
    {
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);
        MotifUrgence::create(['libelle' => 'Panne machine client', 'actif' => true]);
        $demandeur = User::factory()->create(['service' => 'Technique', 'prenom' => 'Raby', 'name' => 'TOUNKARA']);
        $participant = User::factory()->create(['service' => 'Technique']);
        $chefAtelier = User::factory()->create(['service' => 'Technique', 'telephone' => '628112233']);
        $chefAtelier->ajouterRoles(['chef_atelier']);
        $daf = User::factory()->role('daf')->create(['telephone' => '622000045']);

        $reponse = $this->actingAs($demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => now()->addDays(5)->toDateString(), 'date_retour_prevue' => now()->addDays(8)->toDateString(),
            'prise_en_charge' => 'neemba', 'participants' => [['user_id' => $participant->id]],
            'niveau_urgence' => 'urgente',
        ])->assertCreated();
        $odm = OrdreMission::findOrFail($reponse->json('odm.id'));

        $this->actingAs($demandeur)->postJson(route('api.odm.soumettre', $odm))->assertStatus(422)->assertJsonFragment(['champ' => 'motif_urgence']);
        $this->actingAs($demandeur)->putJson(route('api.odm.enregistrer', $odm), ['motif_urgence' => 'Panne machine client'])->assertOk();
        $this->actingAs($demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        $odm->refresh();

        /* Soumission : le chef d'atelier est prévenu par SMS, en plus de la notification */
        $this->assertCount(1, $this->smsA('628112233'));
        $this->assertStringStartsWith("NEEMBA [URGENT] - L'ordre de mission {$odm->numero} de Raby TOUNKARA (du ", $this->smsA('628112233')[0]['message']);
        $this->assertStringEndsWith('Merci d\'agir rapidement.', $this->smsA('628112233')[0]['message']);
        $this->assertTrue(Notification::where('destinataire_id', $chefAtelier->id)->where('type', 'odm_a_viser')->exists());

        /* Visa sans code ; l'étape suivante prévient le DAF */
        $this->actingAs($chefAtelier)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page
            ->missing('visa.code_exige')->where('odm.niveau_urgence_label', 'Urgente')->where('odm.motif_urgence', 'Panne machine client'));
        $this->actingAs($chefAtelier)->post(route('odm.viser', $odm))->assertSessionHas('success');
        $this->assertSame('EN_VALIDATION', $odm->fresh()->statut);
        $this->assertCount(1, $this->smsA('622000045'));
        $this->assertStringContainsString('attend votre visa', $this->smsA('622000045')[0]['message']);
    }

    /** Très urgent : SMS « immédiatement » ; normal : aucun SMS ; valideur sans téléphone : pas de SMS, sans erreur */
    public function test_niveau_et_valideur_sans_telephone(): void
    {
        $valideurs = collect([User::factory()->create(['telephone' => '628112233']), User::factory()->create(['telephone' => null])]);

        $tresUrgent = OrdreMission::factory()->create(['niveau_urgence' => 'tres_urgente', 'motif_urgence' => 'Panne machine client']);
        \App\Services\Odm\NotificationsOdm::alerteSmsUrgence($tresUrgent, $valideurs, "Chef d'atelier");
        $this->assertCount(1, $this->sms);
        $this->assertSame('628112233', $this->sms[0]['telephone']);
        $this->assertStringStartsWith('NEEMBA [TRES URGENT]', $this->sms[0]['message']);
        $this->assertStringEndsWith("Merci d'agir immediatement.", $this->sms[0]['message']);

        \App\Services\Odm\NotificationsOdm::alerteSmsUrgence(OrdreMission::factory()->create(['niveau_urgence' => 'normale']), $valideurs, "Chef d'atelier");
        $this->assertCount(1, $this->sms);
    }

    /** Un ODM repassé en « normale » perd son motif d'urgence */
    public function test_l_odm_normal_n_a_pas_de_motif_d_urgence(): void
    {
        $odm = OrdreMission::factory()->create(['niveau_urgence' => 'urgente', 'motif_urgence' => 'Panne machine client']);
        \App\Services\Odm\EnregistrementOdm::enregistrer($odm, ['niveau_urgence' => 'normale'], $odm->demandeur);
        $this->assertSame(['normale', null], [$odm->fresh()->niveau_urgence, $odm->fresh()->motif_urgence]);
    }
}
