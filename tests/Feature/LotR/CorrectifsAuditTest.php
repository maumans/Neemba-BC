<?php

namespace Tests\Feature\LotR;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueAction;
use App\Models\Notification;
use App\Models\OtpValidation;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\NimbaSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot R « correctifs de l'audit » (spec v2.3) : code de retrait envoyé au bénéficiaire seul, sans prolongation au-delà
 * de sa validité, et valant accusé de réception (RG-M06-02).
 */
class CorrectifsAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $souadou;
    private User $caissier;
    private Caisse $especes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $conakry = Site::factory()->conakry()->create();
        $this->especes = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry',
            'site_id' => $conakry->id, 'plafond_retrait' => 20000000, 'solde' => 30000000]);
        $aftermarket = Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $aftermarket->id]);

        $this->souadou = User::factory()->create(['prenom' => 'Souadou', 'name' => 'BARRY', 'site' => 'Conakry',
            'service' => 'Aftermarket', 'telephone' => '622461261']);
        $this->caissier = User::factory()->role('caissier')->create(['prenom' => 'Youssouf', 'name' => 'TOURE', 'site' => 'Conakry']);
    }

    /** RG-M06-02 : le code part au téléphone du bénéficiaire, jamais à celui du demandeur */
    public function test_le_code_de_retrait_part_au_beneficiaire_et_jamais_au_demandeur(): void
    {
        $beneficiaire = User::factory()->create(['prenom' => 'Fodé', 'name' => 'BANGOURA', 'telephone' => '628990011']);
        $bon = $this->bonApprouve([
            'beneficiaire_id' => $beneficiaire->id,
            'beneficiaire' => 'Fodé BANGOURA',
            'telephone_beneficiaire' => '628990011',
        ]);

        $this->mock(NimbaSmsService::class, function ($mock) {
            $mock->shouldReceive('envoyerCodeOtp')
                ->once()
                ->withArgs(fn (string $telephone, string $code, string $nom) => $telephone === '628990011' && $nom === 'Fodé BANGOURA')
                ->andReturn(['success' => true]);
        });

        $this->actingAs($this->caissier)->post(route('bons-caisse.otp.generer', $bon))->assertSessionHas('success');

        $otp = OtpValidation::where('bon_caisse_id', $bon->id)->sole();
        $this->assertSame('628990011', $otp->telephone);
        $this->assertNotSame($this->souadou->telephone, $otp->telephone);
    }

    /** RG-M06-02 : sans téléphone du bénéficiaire, pas de repli sur le demandeur ; aucun code n'est créé */
    public function test_sans_telephone_du_beneficiaire_aucun_code_n_est_envoye(): void
    {
        $bon = $this->bonApprouve(['beneficiaire' => 'Mariama CONDE', 'telephone_beneficiaire' => null]);

        $this->mock(NimbaSmsService::class, fn ($mock) => $mock->shouldNotReceive('envoyerCodeOtp'));

        $this->actingAs($this->caissier)->post(route('bons-caisse.otp.generer', $bon))
            ->assertSessionHas('error', "Aucun téléphone n'est enregistré pour le bénéficiaire Mariama CONDE :"
                . ' le code de retrait ne peut pas lui être envoyé. Complétez sa fiche avant de payer.');
        $this->assertSame(0, OtpValidation::where('bon_caisse_id', $bon->id)->count());
    }

    /** RG-M06-02 : un envoi manqué ne crée pas de code et laisse le précédent intact */
    public function test_un_envoi_manque_ne_cree_pas_de_code(): void
    {
        $bon = $this->bonApprouve(['telephone_beneficiaire' => '622461261']);
        $precedent = $this->otpVerifie($bon);

        $this->mock(NimbaSmsService::class, fn ($mock) => $mock->shouldReceive('envoyerCodeOtp')
            ->andReturn(['success' => false, 'error' => 'Service SMS non configuré']));

        $this->actingAs($this->caissier)->post(route('bons-caisse.otp.generer', $bon))
            ->assertSessionHas('error', 'Erreur SMS : Service SMS non configuré');
        $this->assertSame(1, OtpValidation::where('bon_caisse_id', $bon->id)->count());
        $this->assertFalse((bool) $precedent->fresh()->is_used);
    }

    /** RG-M06-02 : un code vérifié n'ouvre pas de délai supplémentaire au-delà de ses 5 minutes */
    public function test_un_code_expire_ne_paie_plus_meme_verifie(): void
    {
        $bon = $this->bonApprouve(['telephone_beneficiaire' => '622461261']);
        $otp = $this->otpVerifie($bon, expiresAt: now()->subMinute(), verifiedAt: now()->subMinutes(4));

        $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes'])
            ->assertSessionHas('error', 'Aucun code de retrait valide : générez un code et faites-le valider par le bénéficiaire avant de payer.');

        $this->assertSame('APPROUVE', $bon->fresh()->statut);
        $this->assertFalse((bool) $otp->fresh()->is_used);
        $this->assertSame(30000000.0, (float) $this->especes->fresh()->solde);
    }

    /** RG-M06-02 : le code validé vaut accusé de réception, tracé au journal du bon */
    public function test_le_paiement_trace_l_accuse_de_reception(): void
    {
        $bon = $this->bonApprouve(['beneficiaire' => 'Souadou BARRY', 'telephone_beneficiaire' => '622461261']);
        $this->otpVerifie($bon);

        $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes'])
            ->assertSessionHas('success');

        $this->assertSame('PAYE', $bon->fresh()->statut);
        $accuse = HistoriqueAction::where('bon_caisse_id', $bon->id)
            ->where('action', HistoriqueAction::ACTION_ACCUSE_RECEPTION)->sole();
        $this->assertStringContainsString('Reçu par Souadou BARRY', $accuse->commentaire);
        $this->assertStringContainsString('au 622461261', $accuse->commentaire);
        $this->assertSame('622461261', $accuse->metadata['telephone']);
    }

    /** RG-M04-03 : le code analytique retenu par le CDG est contrôlé par le serveur, comme à la saisie */
    public function test_le_code_analytique_du_cdg_est_controle_par_le_serveur(): void
    {
        $technique = Service::factory()->create(['nom' => 'Technique']);
        CodeAnalytique::factory()->create(['code' => 'TEAZZZ', 'libelle' => 'Technique', 'service_id' => $technique->id]);
        CodeAnalytique::factory()->create(['code' => 'ADCZZZ', 'libelle' => 'Aftermarket hors service', 'actif' => false]);
        $cdg = User::factory()->role('controle_gestion')->create(['prenom' => 'Saliou', 'name' => 'BOIRO']);

        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create([
            'demandeur_id' => $this->souadou->id, 'service' => 'Aftermarket', 'code_analytique' => 'ADAZZZ', 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        $bon->validations()->where('role', 'responsable_service')->update(['statut' => 'approuve']);

        /* Code inconnu, code inactif, puis code d'un autre service : tous refusés, le bon ne bouge pas */
        foreach (['ZZZZZZ', 'ADCZZZ', 'TEAZZZ'] as $code) {
            $this->actingAs($cdg)->post(route('validations.approuver', $bon), ['code_analytique' => $code])
                ->assertSessionHasErrors('code_analytique');
        }
        $this->assertSame(['EN_ATTENTE_CDG', 'ADAZZZ'], [$bon->fresh()->statut, $bon->fresh()->code_analytique]);

        /* Une ventilation sur un code hors service est refusée de la même façon */
        $this->actingAs($cdg)->post(route('validations.approuver', $bon), [
            'ventilations' => [['code_analytique' => 'TEAZZZ', 'montant' => 500000]],
        ])->assertSessionHasErrors('ventilations.0.code_analytique');

        /* Un code actif et rattaché au service passe */
        CodeAnalytique::factory()->create(['code' => 'ADBZZZ', 'libelle' => 'Aftermarket atelier',
            'service_id' => Service::where('nom', 'Aftermarket')->value('id')]);
        $this->actingAs($cdg)->post(route('validations.approuver', $bon), ['code_analytique' => 'ADBZZZ'])
            ->assertSessionHas('success');
        $this->assertSame(['EN_ATTENTE_DAF', 'ADBZZZ'], [$bon->fresh()->statut, $bon->fresh()->code_analytique]);
    }

    /** Q73 (§5.1) : le rejet s'explique toujours, en 10 caractères au moins, quel que soit le motif */
    public function test_le_rejet_exige_toujours_une_explication(): void
    {
        $chef = User::factory()->role('responsable_service')->create(['service' => 'Aftermarket']);
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CHEF_SERVICE')->create([
            'demandeur_id' => $this->souadou->id, 'service' => 'Aftermarket', 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        $motif = array_key_first(\App\Models\MotifRejet::options());

        /* Sans commentaire, puis avec un commentaire trop court : refusé même sur un motif prédéfini */
        $this->actingAs($chef)->post(route('validations.rejeter', $bon), ['motif_rejet' => $motif])
            ->assertSessionHasErrors(['commentaire' => __('MSG-BC-002')]);
        $this->actingAs($chef)->post(route('validations.rejeter', $bon), ['motif_rejet' => $motif, 'commentaire' => 'À revoir'])
            ->assertSessionHasErrors(['commentaire' => __('MSG-BC-002')]);
        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $bon->fresh()->statut);

        $this->actingAs($chef)->post(route('validations.rejeter', $bon), [
            'motif_rejet' => $motif, 'commentaire' => 'Le devis joint ne correspond pas au montant demandé.',
        ])->assertSessionHas('success');
        $this->assertSame('REJETE', $bon->fresh()->statut);
    }

    /** §6.7 : SLA 4 / 24 / 8 / 24 h, comptés en heures ouvrées */
    public function test_les_delais_de_visa_suivent_la_spec_et_les_heures_ouvrees(): void
    {
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CHEF_SERVICE')->create([
            'demandeur_id' => $this->souadou->id, 'service' => 'Aftermarket', 'montant' => 2000000, 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        $heures = $bon->validations()->orderBy('niveau')->get()->mapWithKeys(fn ($v) => [$v->role => $v->slaHeures()]);

        $this->assertSame([
            'responsable_service' => 4.0, 'controle_gestion' => 24.0, 'daf' => 8.0, 'directeur_pays' => 24.0,
        ], $heures->all());

        /* Vendredi 16 h + 4 h ouvrées : l'échéance tombe le lundi à 11 h, pas le samedi matin */
        $etape = $bon->validations()->where('role', 'responsable_service')->sole();
        $etape->update(['date_attribution' => Carbon::parse('2026-09-04 16:00')]);

        $this->assertSame('2026-09-07 11:00', $etape->fresh()->echeanceSla()->format('Y-m-d H:i'));

        Carbon::setTestNow('2026-09-07 09:00');   // lundi matin : le délai court encore
        $this->assertFalse($etape->fresh()->slaDepasse());
        Carbon::setTestNow('2026-09-07 11:30');
        $this->assertTrue($etape->fresh()->slaDepasse());
        Carbon::setTestNow();
    }

    /** §6.7 : passé le double du délai, le DP est escaladé au DAF (et non laissé sans recours) */
    public function test_le_dp_est_escalade_au_daf(): void
    {
        $daf = User::factory()->role('daf')->create(['prenom' => 'Mohamed', 'name' => 'DIAKITE']);
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_DP')->create([
            'demandeur_id' => $this->souadou->id, 'service' => 'Aftermarket', 'montant' => 6000000, 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        $bon->validations()->where('role', '!=', 'directeur_pays')->update(['statut' => 'approuve']);
        /* 48 h ouvrées de retard : bien au-delà du double des 24 h du DP */
        $bon->validations()->where('role', 'directeur_pays')
            ->update(['date_attribution' => now()->subMonth(), 'statut' => 'en_attente']);

        $this->artisan('validations:relancer-sla')->assertSuccessful();

        $etape = $bon->validations()->where('role', 'directeur_pays')->sole();
        $this->assertTrue((bool) $etape->escalade);
        $this->assertTrue(Notification::where('destinataire_id', $daf->id)->where('bon_caisse_id', $bon->id)->exists());
        $this->assertSame('escalade_sla', HistoriqueAction::where('bon_caisse_id', $bon->id)->latest('id')->value('action'));
    }

    /** RG-M04-05 : l'écran de visa montre le doublon probable et les BP en retard du bénéficiaire */
    public function test_l_ecran_de_visa_affiche_les_alertes(): void
    {
        $chef = User::factory()->role('responsable_service')->create(['service' => 'Aftermarket']);

        /* Un BP du bénéficiaire, non régularisé et échu */
        $retard = BonCaisse::factory()->provisoire()->statut('EN_ATTENTE_REGULARISATION')->create([
            'demandeur_id' => $this->souadou->id, 'beneficiaire_id' => $this->souadou->id,
            'montant' => 400000, 'date_paiement' => now()->subDays(10),
            'date_limite_regularisation' => today()->subDays(3),
        ]);

        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CHEF_SERVICE')->create([
            'demandeur_id' => $this->souadou->id, 'beneficiaire_id' => $this->souadou->id,
            'service' => 'Aftermarket', 'date_soumission' => now(),
            'doublon_probable' => ['bon_id' => $retard->id, 'numero' => $retard->numero, 'montant' => 400000,
                'beneficiaire' => 'Souadou BARRY', 'date' => today()->toDateString(),
                'confirme_par' => 'Souadou BARRY', 'confirme_le' => now()->toIso8601String()],
        ]);
        $bon->creerEtapesValidation();

        $this->actingAs($chef)->get(route('validations.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alertes.doublon.numero', $retard->numero)
                ->where('alertes.doublon.confirme_par', 'Souadou BARRY')
                ->has('alertes.bp_en_retard', 1)
                ->where('alertes.bp_en_retard.0.numero', $retard->numero));
    }

    /** RG-M06-01 : un bon approuvé s'affiche « Bon à payer », pour le caissier comme pour le demandeur */
    public function test_un_bon_approuve_s_affiche_bon_a_payer(): void
    {
        $bon = $this->bonApprouve();

        $this->assertSame('Bon à payer', BonCaisse::STATUTS_LABELS['APPROUVE']);
        $this->assertSame('Bon à payer', $bon->statut_label);
        $this->actingAs($this->caissier)->get(route('bons-caisse.index'))
            ->assertInertia(fn (Assert $page) => $page->where('statuts.APPROUVE', 'Bon à payer'));
    }

    /* ------------------------------------------------------------------ */

    private function bonApprouve(array $attributs = []): BonCaisse
    {
        return BonCaisse::factory()->approuve()->create($attributs + [
            'demandeur_id' => $this->souadou->id,
            'site' => 'Conakry',
            'service' => 'Aftermarket',
            'montant' => 500000,
        ]);
    }

    private function otpVerifie(BonCaisse $bon, $expiresAt = null, $verifiedAt = null): OtpValidation
    {
        return OtpValidation::create([
            'bon_caisse_id' => $bon->id,
            'code' => '123456',
            'telephone' => $bon->telephone_beneficiaire ?? '622461261',
            'expires_at' => $expiresAt ?? now()->addMinutes(5),
            'verified_at' => $verifiedAt ?? now(),
            'is_used' => false,
        ]);
    }
}
