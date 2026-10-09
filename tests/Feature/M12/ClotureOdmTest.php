<?php

namespace Tests\Feature\M12;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\EcritureCaisse;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\OtpValidation;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\TauxChange;
use App\Models\User;
use App\Services\BonCaisse\EnregistrementBon;
use App\Services\BonCaisse\ReglesSaisie;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * M12-6 — Clôture, retour anticipé et trop-perçu, annulation par le DAF, hébergement à l'étranger payé au retour
 * (RG-M12-20, RG-M12-22, RG-M12-26 ; SC-25, SC-29 ; décisions Q24 et Q26).
 */
class ClotureOdmTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private User $demandeur;
    private User $thierno;
    private User $yacouba;
    private User $chefAtelier;
    private User $daf;
    private User $dp;
    private User $caissier;
    private User $rh;
    private User $assistante;
    private Caisse $especes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');
        Parametre::majValeur('seuil_validation_dp', '1500000');

        $conakry = Site::factory()->conakry()->create();
        $this->especes = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id]);
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
        $this->caissier = User::factory()->role('caissier')->create();
        $this->rh = User::factory()->create();
        $this->rh->ajouterRoles(['rh']);
        $this->assistante = User::factory()->create(['service' => 'Technique']);
        $technique->update(['diffusion_odm' => [$this->assistante->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ODM B.1 à deux participants, validé et ses bons générés */
    private function odmAvecBons(array $autres = [], ?array $participants = null, bool $generer = true): OrdreMission
    {
        $odm = OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $autres + [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26', 'prise_en_charge' => 'neemba',
            'participants' => $participants ?? [['user_id' => $this->thierno->id], ['user_id' => $this->yacouba->id]],
        ])->assertCreated()->json('odm.id'));
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm));
        }
        if ($generer) {
            $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        }

        return $odm->fresh();
    }

    private function payer(BonCaisse $bon): void
    {
        $bon->update(['statut' => 'APPROUVE']);
        OtpValidation::create(['bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false]);
        $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money'])->assertSessionHas('success');
    }

    private function bonDe(User $participant): BonCaisse
    {
        return BonCaisse::where('beneficiaire_id', $participant->id)->where('statut', '!=', 'ANNULE')->sole();
    }

    private function cloturer(OrdreMission $odm, string $date, array $autres = [])
    {
        return $this->actingAs($this->demandeur)->post(route('odm.cloturer', $odm), ['date_retour_reelle' => $date] + $autres);
    }

    /* ------------------------------------------------------------------ */

    /** SC-25 : retour à la date prévue, clôture simple, notifiée à la liste de diffusion */
    public function test_la_cloture_au_retour_prevu(): void
    {
        $odm = $this->odmAvecBons();

        $this->cloturer($odm, '2026-09-26')->assertSessionHas('success', 'Mission N°1/AT/26 clôturée.');

        $odm->refresh();
        $this->assertSame('CLOTURE', $odm->statut);
        $this->assertSame('2026-09-26', $odm->date_retour_reelle->toDateString());
        $this->assertNotNull($odm->date_cloture);
        $this->assertEquals(6500000, (float) $odm->total);
        $this->assertTrue(Notification::where('destinataire_id', $this->assistante->id)->where('type', 'odm_cloture')->exists());
        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page->where('peutCloturer', false)->where('peutProlonger', false)->where('generation', null));
    }

    /** SC-25, RG-M12-20, Q26 : retour anticipé ; trop-perçu du participant payé ; bon non payé remplacé au réel */
    public function test_le_retour_anticipe(): void
    {
        $odm = $this->odmAvecBons();
        $this->payer($this->bonDe($this->thierno));
        $ancienBonYacouba = $this->bonDe($this->yacouba);

        $this->cloturer($odm, '2026-09-24', ['regularisations' => [$this->thierno->id => 'reversement']])
            ->assertSessionHas('success', fn ($message) => str_contains($message,
                'Retour anticipé : trop-perçu de 1' . self::NBSP . '500' . self::NBSP . '000 GNF pour BAH Thierno, à régulariser. (reversement en caisse)'));

        $odm->refresh();
        $this->assertSame('CLOTURE', $odm->statut);
        $this->assertEquals(3500000, (float) $odm->total);   // 2 × (3 jours, 2 nuits) = 2 × 1 750 000

        $thierno = $odm->participants()->where('user_id', $this->thierno->id)->sole();
        $this->assertEquals(1500000, (float) $thierno->trop_percu);
        $this->assertSame(['reversement', 'a_regulariser'], [$thierno->regularisation, $thierno->regularisation_statut]);
        $this->assertTrue(Notification::where('destinataire_id', $this->caissier->id)->where('type', 'odm_regularisation')->exists());

        /* Q26 : le bon non payé de Yacouba est annulé et remplacé par un bon au réel */
        $this->assertSame('ANNULE', $ancienBonYacouba->fresh()->statut);
        $nouveau = $this->bonDe($this->yacouba);
        $this->assertEquals(1750000, (float) $nouveau->montant);
        $this->assertSame('EN_ATTENTE_CHEF_SERVICE', $nouveau->statut);
        $this->assertNull($odm->participants()->where('user_id', $this->yacouba->id)->value('trop_percu'));

        /* Le caissier encaisse le reversement, inscrit au registre de la caisse espèces */
        $this->actingAs($this->caissier)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page->where('regularisations.0.peut_regulariser', true));
        $this->actingAs($this->demandeur)->post(route('odm.regulariser', [$odm, $thierno]))->assertSessionHas('error');
        $this->actingAs($this->caissier)->post(route('odm.regulariser', [$odm, $thierno]))->assertSessionHas('success');

        $this->assertSame('regularise', $thierno->fresh()->regularisation_statut);
        $ecriture = EcritureCaisse::where('nature', 'reversement_odm')->sole();
        $this->assertSame($this->especes->id, $ecriture->caisse_id);
        $this->assertEquals(1500000, (float) $ecriture->montant);
        $this->assertEquals(1500000, (float) $this->especes->fresh()->solde);
    }

    /** Q47 : un ODM validé dont les bons n'ont pas été générés reçoit ses bons au montant réel à la clôture */
    public function test_la_cloture_genere_les_bons_manquants_au_reel(): void
    {
        $odm = $this->odmAvecBons(generer: false);

        $this->cloturer($odm, '2026-09-23')->assertSessionHas('success', fn ($m) => str_contains($m, 'Bon(s) généré(s) au montant réel : BC-'));

        $this->assertSame('CLOTURE', $odm->fresh()->statut);
        $this->assertSame([1000000.0, 1000000.0], BonCaisse::orderBy('id')->pluck('montant')->map(fn ($m) => (float) $m)->all());   // 2 jours, 1 nuit
        $this->assertSame(0, BonCaisse::where('statut', 'BROUILLON')->count());
    }

    /** Retenue sur salaire : les RH sont prévenus et confirment */
    public function test_la_retenue_sur_salaire(): void
    {
        $odm = $this->odmAvecBons(participants: [['user_id' => $this->thierno->id]]);
        $this->payer($this->bonDe($this->thierno));

        $this->cloturer($odm, '2026-09-25', ['regularisations' => [$this->thierno->id => 'retenue']])->assertSessionHas('success');
        $participant = $odm->participants()->sole();
        $this->assertEquals(750000, (float) $participant->trop_percu);   // 1 jour et 1 nuit de moins
        $this->assertTrue(Notification::where('destinataire_id', $this->rh->id)->where('type', 'odm_regularisation')->exists());

        $this->actingAs($this->caissier)->post(route('odm.regulariser', [$odm, $participant]))->assertSessionHas('error');
        $this->actingAs($this->rh)->post(route('odm.regulariser', [$odm, $participant]))->assertSessionHas('success');
        $this->assertSame('regularise', $participant->fresh()->regularisation_statut);
        $this->assertSame(0, EcritureCaisse::where('nature', 'reversement_odm')->count());
    }

    /** RG-M12-20 : retour tardif → prolongation ; un segment prolongé ne se clôture pas */
    public function test_les_refus_de_cloture(): void
    {
        $odm = $this->odmAvecBons();

        $this->cloturer($odm, '2026-09-28')->assertSessionHasErrors(['date_retour_reelle' => 'Retour après la date prévue : prolongez la mission avant de la clôturer.']);
        $this->cloturer($odm, '2026-09-20')->assertSessionHasErrors(['date_retour_reelle' => 'La date de retour doit être postérieure ou égale à la date de départ.']);
        $this->actingAs($this->thierno)->post(route('odm.cloturer', $odm), ['date_retour_reelle' => '2026-09-26'])
            ->assertSessionHas('error', 'Seul le dernier segment validé d\'une mission en cours peut être clôturé.');

        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), ['date_retour_prevue' => '2026-09-30']);
        $this->cloturer($odm, '2026-09-26')->assertSessionHas('error');
        $this->assertSame('BONS_GENERES', $odm->fresh()->statut);
    }

    /** RG-M12-22 : après la génération, seul le DAF annule, avec ses bons non payés ; un bon payé interdit l'annulation */
    public function test_l_annulation_par_le_daf(): void
    {
        $odm = $this->odmAvecBons();

        $this->actingAs($this->demandeur)->postJson(route('api.odm.annuler', $odm), ['motif' => 'Mission reportée'])->assertStatus(409);
        $this->actingAs($this->demandeur)->post(route('odm.annuler-daf', $odm), ['motif' => 'Mission reportée au mois prochain'])
            ->assertSessionHas('error', 'Seul le DAF annule un ordre de mission dont les bons sont générés.');
        $this->actingAs($this->daf)->post(route('odm.annuler-daf', $odm), ['motif' => 'Court'])
            ->assertSessionHasErrors(['motif' => 'Indiquez le motif de l\'annulation (10 caractères minimum).']);
        $this->actingAs($this->daf)->get(route('odm.show', $odm))->assertInertia(fn (Assert $page) => $page->where('peutAnnulerDaf', true));

        $this->actingAs($this->daf)->post(route('odm.annuler-daf', $odm), ['motif' => 'Mission reportée au mois prochain'])->assertSessionHas('success');

        $odm->refresh();
        $this->assertSame('ANNULE', $odm->statut);
        $this->assertSame(['ANNULE', 'ANNULE'], BonCaisse::pluck('statut')->all());
        $this->assertSame(0, BonCaisse::first()->validations()->where('statut', 'en_attente')->count());
        $this->assertStringContainsString('Bons annulés : BC-', $odm->motif_annulation);
    }

    public function test_un_bon_paye_interdit_l_annulation(): void
    {
        $odm = $this->odmAvecBons();
        $this->payer($this->bonDe($this->thierno));

        $this->actingAs($this->daf)->post(route('odm.annuler-daf', $odm), ['motif' => 'Mission reportée au mois prochain'])
            ->assertSessionHas('error', 'Un bon de cet ordre de mission est déjà payé : clôturez-le avec régularisation au lieu de l\'annuler.');
        $this->assertSame('BONS_GENERES', $odm->fresh()->statut);
    }

    /** SC-29, RG-M12-26, Q24 : hébergement à l'étranger payé au retour, bon complémentaire avec justificatif */
    public function test_l_hebergement_paye_au_retour(): void
    {
        TauxChange::create(['date_taux' => today(), 'taux' => 14.5]);
        $odm = $this->odmAvecBons(
            ['type' => 'exterieur', 'hebergement_exterieur' => 'au_retour', 'destinations' => ['Abidjan']],
            [['user_id' => $this->thierno->id]],
        );
        $this->assertEquals(1595000, (float) $this->bonDe($this->thierno)->montant);   // indemnité seule

        $this->cloturer($odm, '2026-09-26', ['factures_retour' => [$this->thierno->id => '1 200 000']])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Bon(s) complémentaire(s) d\'hébergement préparé(s) en brouillon'));

        $complement = BonCaisse::find($odm->participants()->sole()->bon_complement_id);
        $this->assertSame('BROUILLON', $complement->statut);
        $this->assertSame('hebergement', $complement->categorie_depense);
        $this->assertEquals(1200000, (float) $complement->montant);
        $this->assertSame($odm->id, $complement->odm_id);
        $this->assertFalse($complement->genere_par_odm);
        $this->assertSame('MSG-BC-017', ReglesSaisie::erreurPieces($complement));   // facture obligatoire

        /* Le demandeur complète le bon dans l'assistant : il reste rattaché à l'ODM */
        EnregistrementBon::appliquer($complement, ['motif' => 'Hôtel Pullman Abidjan, 4 nuits, facture jointe'], $this->demandeur);
        $this->assertSame($odm->id, $complement->fresh()->odm_id);
    }
}
