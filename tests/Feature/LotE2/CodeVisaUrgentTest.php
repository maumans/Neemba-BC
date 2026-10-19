<?php

namespace Tests\Feature\LotE2;

use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\MotifUrgence;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\User;
use App\Services\NimbaSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot E2 (spec v2.4, RG-M01-07, SC-40, Q76, Q77) : le visa d'un bon ou d'un ODM urgent ou très urgent exige un code reçu
 * par SMS ; le rejet n'en demande pas. L'ODM porte désormais un niveau d'urgence et son motif.
 */
class CodeVisaUrgentTest extends TestCase
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

    /** Code à 6 chiffres du dernier SMS */
    private function dernierCode(): string
    {
        preg_match('/Code de visa (\d{6})/', end($this->sms)['message'], $m);

        return $m[1];
    }

    private function bonDaf(string $urgence): array
    {
        $daf = User::factory()->role('daf')->create(['telephone' => '622000045']);
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create([
            'niveau_urgence' => $urgence, 'motif_urgence' => $urgence === 'normale' ? null : 'Panne machine client',
            'justification_urgence' => $urgence === 'normale' ? null : 'Machine arrêtée chez le client',
            'montant' => 500000, 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        $bon->validations()->where('role', '!=', 'daf')->update(['statut' => 'approuve']);

        return [$bon->fresh(), $daf];
    }

    /** SC-40 : visa d'un bon urgent avec le code SMS */
    public function test_le_visa_d_un_bon_urgent_exige_le_code_sms(): void
    {
        [$bon, $daf] = $this->bonDaf('urgente');

        $this->actingAs($daf)->get(route('validations.show', $bon))
            ->assertInertia(fn (Assert $page) => $page->where('codeVisaExige', true));
        $this->actingAs($daf)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page->where('codeVisaExige', true));

        /* Sans code : refusé */
        $this->actingAs($daf)->post(route('validations.approuver', $bon))
            ->assertSessionHasErrors(['code_visa' => 'Demande urgente : demandez le code de visa par SMS, puis saisissez-le.']);
        $this->assertSame('EN_ATTENTE_DAF', $bon->fresh()->statut);

        $this->actingAs($daf)->postJson(route('validations.code-visa', $bon))->assertOk()
            ->assertJsonPath('message', 'Code de visa envoyé par SMS au ••• 45. Il est valable 5 minutes.');
        $this->assertCount(1, $this->sms);
        $this->assertSame('622000045', $this->sms[0]['telephone']);
        $this->assertStringContainsString("pour le bon {$bon->numero}", $this->sms[0]['message']);

        $faux = $this->dernierCode() === '000000' ? '111111' : '000000';
        $this->actingAs($daf)->post(route('validations.approuver', $bon), ['code_visa' => $faux])
            ->assertSessionHasErrors(['code_visa' => 'Code incorrect. Il vous reste 4 essai(s).']);
        $this->assertSame('EN_ATTENTE_DAF', $bon->fresh()->statut);

        $this->actingAs($daf)->post(route('validations.approuver', $bon), ['code_visa' => $this->dernierCode()])->assertSessionHas('success');
        $this->assertNotSame('EN_ATTENTE_DAF', $bon->fresh()->statut);
        $this->assertDatabaseHas('codes_otp', ['user_id' => $daf->id, 'objet' => 'visa_bon', 'cible_id' => $bon->id, 'canal' => 'sms']);
    }

    /** Le code d'un bon ne sert pas pour un autre */
    public function test_le_code_est_lie_au_bon(): void
    {
        [$bon, $daf] = $this->bonDaf('tres_urgente');
        $autre = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create([
            'niveau_urgence' => 'urgente', 'motif_urgence' => 'Panne machine client', 'justification_urgence' => 'Machine arrêtée',
            'montant' => 400000, 'date_soumission' => now(),
        ]);
        $autre->creerEtapesValidation();
        $autre->validations()->where('role', '!=', 'daf')->update(['statut' => 'approuve']);

        $this->actingAs($daf)->postJson(route('validations.code-visa', $bon))->assertOk();
        $this->actingAs($daf)->post(route('validations.approuver', $autre), ['code_visa' => $this->dernierCode()])->assertSessionHasErrors('code_visa');
        $this->assertSame('EN_ATTENTE_DAF', $autre->fresh()->statut);
    }

    /** Q77 : un bon normal se vise sans code ; le rejet d'un bon urgent n'en demande pas */
    public function test_ni_le_bon_normal_ni_le_rejet_ne_demandent_de_code(): void
    {
        [$normal, $daf] = $this->bonDaf('normale');
        $this->actingAs($daf)->get(route('validations.show', $normal))
            ->assertInertia(fn (Assert $page) => $page->where('codeVisaExige', false));
        $this->actingAs($daf)->postJson(route('validations.code-visa', $normal))->assertForbidden();
        $this->actingAs($daf)->post(route('validations.approuver', $normal))->assertSessionHas('success');

        [$urgent, $daf2] = $this->bonDaf('urgente');
        $this->actingAs($daf2)->post(route('validations.rejeter', $urgent), [
            'motif_rejet' => 'manque_authentification', 'commentaire' => 'Signature du bénéficiaire absente',
        ]);
        $this->assertSame('REJETE', $urgent->fresh()->statut);
        $this->assertSame([], $this->sms);
    }

    /** Seul le valideur de l'étape en cours peut demander le code */
    public function test_un_autre_utilisateur_ne_peut_pas_demander_le_code(): void
    {
        [$bon] = $this->bonDaf('urgente');
        $this->actingAs(User::factory()->create())->postJson(route('validations.code-visa', $bon))->assertForbidden();
        $this->assertSame([], $this->sms);
    }

    public function test_sans_telephone_le_code_ne_part_pas(): void
    {
        [$bon, $daf] = $this->bonDaf('urgente');
        $daf->update(['telephone' => null]);

        $this->actingAs($daf)->postJson(route('validations.code-visa', $bon))->assertStatus(422)
            ->assertJsonPath('message', 'Aucun numéro de téléphone sur votre compte : le code de visa ne peut pas être envoyé. Contactez l’administrateur.');
        $this->assertSame([], $this->sms);
    }

    /** Paramètre de secours (panne SMS) : le visa urgent se fait sans code */
    public function test_le_parametre_de_secours_retire_le_code_de_visa(): void
    {
        Parametre::majValeur('otp_visa_urgent_actif', 'false');
        [$bon, $daf] = $this->bonDaf('urgente');

        $this->actingAs($daf)->post(route('validations.approuver', $bon))->assertSessionHas('success');
        $this->assertNotSame('EN_ATTENTE_DAF', $bon->fresh()->statut);
    }

    /** Q76 : l'ODM porte une urgence ; urgent sans motif = MSG-M03-08 ; visa urgent avec le code SMS */
    public function test_l_odm_urgent_exige_un_motif_puis_le_code_au_visa(): void
    {
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);
        MotifUrgence::create(['libelle' => 'Panne machine client', 'actif' => true]);
        $demandeur = User::factory()->create(['service' => 'Technique']);
        $participant = User::factory()->create(['service' => 'Technique']);
        $chefAtelier = User::factory()->create(['service' => 'Technique', 'telephone' => '628112233']);
        $chefAtelier->ajouterRoles(['chef_atelier']);

        $reponse = $this->actingAs($demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => now()->addDays(5)->toDateString(), 'date_retour_prevue' => now()->addDays(8)->toDateString(),
            'prise_en_charge' => 'neemba', 'participants' => [['user_id' => $participant->id]],
            'niveau_urgence' => 'urgente',
        ])->assertCreated();
        $odm = OrdreMission::findOrFail($reponse->json('odm.id'));
        $this->assertSame('urgente', $odm->niveau_urgence);

        $this->actingAs($demandeur)->postJson(route('api.odm.soumettre', $odm))->assertStatus(422)
            ->assertJsonFragment(['champ' => 'motif_urgence']);
        $this->actingAs($demandeur)->putJson(route('api.odm.enregistrer', $odm), ['motif_urgence' => 'Panne machine client'])->assertOk();
        $this->actingAs($demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();

        $this->actingAs($chefAtelier)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page
            ->where('visa.code_exige', true)->where('odm.niveau_urgence_label', 'Urgente')->where('odm.motif_urgence', 'Panne machine client'));

        $this->actingAs($chefAtelier)->post(route('odm.viser', $odm))->assertSessionHasErrors('code_visa');
        $this->assertSame('SOUMIS', $odm->fresh()->statut);

        $this->actingAs($chefAtelier)->postJson(route('odm.code-visa', $odm))->assertOk();
        $this->assertStringContainsString("pour l'ordre de mission {$odm->fresh()->numero}", end($this->sms)['message']);
        $this->actingAs($chefAtelier)->post(route('odm.viser', $odm), ['code_visa' => $this->dernierCode()])->assertSessionHas('success');
        $this->assertSame('EN_VALIDATION', $odm->fresh()->statut);
    }

    /** Un ODM repassé en « normale » perd son motif d'urgence */
    public function test_l_odm_normal_n_a_pas_de_motif_d_urgence(): void
    {
        $odm = OrdreMission::factory()->create(['niveau_urgence' => 'urgente', 'motif_urgence' => 'Panne machine client']);
        \App\Services\Odm\EnregistrementOdm::enregistrer($odm, ['niveau_urgence' => 'normale'], $odm->demandeur);
        $this->assertSame(['normale', null], [$odm->fresh()->niveau_urgence, $odm->fresh()->motif_urgence]);
    }
}
