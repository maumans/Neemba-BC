<?php

namespace Tests\Feature\LotA;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\BonCaisse\CircuitBon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot A « contrôle interne » (spec v2.3) : adjoints dans le circuit (§8.2, RG-M05-04), caissier limité à son site et
 * jamais bénéficiaire (RG-M06-01, RG-M01-04), BP réservé aux salariés (RG-M03-04), doublon probable (RG-M03-20),
 * BP en retard du bénéficiaire et dérogation du DAF (RG-M03-21, RG-M07-06), alertes visibles des valideurs (RG-M04-05).
 */
class ControleInterneTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private User $souadou;
    private User $yacouba;
    private User $daf;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        $conakry = Site::factory()->conakry()->create();
        Site::factory()->create(['nom' => 'Boke']);
        Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id,
            'plafond_retrait' => 20000000, 'solde' => 30000000]);
        $aftermarket = Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $aftermarket->id]);

        $this->souadou = User::factory()->create(['prenom' => 'Souadou', 'name' => 'BARRY', 'site' => 'Conakry', 'service' => 'Aftermarket', 'telephone' => '622461261']);
        $this->yacouba = User::factory()->create(['prenom' => 'Yacouba', 'name' => 'BARRY', 'site' => 'Conakry', 'service' => 'Aftermarket', 'telephone' => '622112233']);
        User::factory()->role('responsable_service')->create(['prenom' => 'Lamine', 'name' => 'SOUMAH', 'service' => 'Aftermarket']);
        User::factory()->role('controle_gestion')->create(['prenom' => 'Saliou', 'name' => 'BOIRO']);
        $this->daf = User::factory()->role('daf')->create(['prenom' => 'Mohamed', 'name' => 'DIAKITE']);
    }

    /* ------------------------------------------------------------------ */

    /** §8.2, RG-M05-04 : la Finance est tenue par le DAF, le DAF adjoint ou le Chef comptable ; le DP par le DP ou son adjoint */
    public function test_les_adjoints_visent_les_bons(): void
    {
        /* Rôles complémentaires, comme à l'import des référentiels */
        $adjoint = User::factory()->create(['prenom' => 'Aïssatou', 'name' => 'SYLLA']);
        $adjoint->ajouterRoles(['daf_adjoint']);
        $chefComptable = User::factory()->create(['prenom' => 'Mamadou Alpha', 'name' => 'BAH']);
        $chefComptable->ajouterRoles(['chef_comptable']);
        $dpAdjoint = User::factory()->create(['prenom' => 'Kerfala', 'name' => 'CAMARA']);
        $dpAdjoint->ajouterRoles(['dp_adjoint']);

        $bon = BonCaisse::factory()->statut('EN_ATTENTE_DAF')->create([
            'demandeur_id' => $this->souadou->id, 'service' => 'Aftermarket', 'montant' => 6000000, 'date_soumission' => now(),
        ]);
        $bon->creerEtapesValidation();
        $bon->validations()->where('role', '!=', 'daf')->where('role', '!=', 'directeur_pays')->update(['statut' => 'approuve']);
        $this->assertTrue($bon->fresh()->necessite_validation_dp);

        foreach ([$this->daf, $adjoint, $chefComptable] as $tenant) {
            $this->assertSame('daf', CircuitBon::peutViser($bon->fresh(), $tenant)['role'] ?? null, $tenant->nom_complet);
        }
        $this->actingAs($chefComptable)->get(route('validations.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bonsEnAttente.total', 1));
        $this->actingAs($chefComptable)->post(route('validations.approuver', $bon))->assertSessionHas('success');
        $this->assertSame('EN_ATTENTE_DP', $bon->fresh()->statut);

        $this->actingAs($dpAdjoint)->post(route('validations.approuver', $bon))->assertSessionHas('success');
        $this->assertSame('APPROUVE', $bon->fresh()->statut);
    }

    /** RG-M06-01, RG-M01-04 : le caissier paie les bons de son site, jamais un bon dont il est bénéficiaire */
    public function test_le_caissier_ne_paie_que_son_site_et_jamais_pour_lui_meme(): void
    {
        $caissierBoke = User::factory()->role('caissier')->create(['site' => 'Boke']);
        $caissierConakry = User::factory()->role('caissier')->create(['site' => 'Conakry']);
        $bon = BonCaisse::factory()->approuve()->create(['site' => 'Conakry', 'demandeur_id' => $this->souadou->id, 'beneficiaire_id' => $caissierConakry->id]);

        $this->actingAs($caissierBoke)->post(route('bons-caisse.otp.generer', $bon))
            ->assertSessionHas('error', 'Ce bon relève du site Conakry : seul un caissier de ce site peut le payer (RG-M06-01).');
        $this->actingAs($caissierConakry)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'especes'])
            ->assertSessionHas('error', 'Vous êtes le bénéficiaire de ce bon : un autre caissier doit le payer (RG-M01-04).');
        $this->actingAs($caissierConakry)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page->where('refusPaiement', 'Vous êtes le bénéficiaire de ce bon : un autre caissier doit le payer (RG-M01-04).'));
        $this->assertSame('APPROUVE', $bon->fresh()->statut);
    }

    /** RG-M08-12 : une caisse qui a un gestionnaire désigné n'est tenue que par lui, son suppléant ou leur délégué */
    public function test_la_caisse_d_un_gestionnaire_designe(): void
    {
        $gestionnaire = User::factory()->role('caissier')->create(['site' => 'Conakry']);
        $autre = User::factory()->role('caissier')->create(['site' => 'Conakry']);
        $caisse = Caisse::where('code', 'CKY-ESP')->sole();
        $caisse->update(['gestionnaire_id' => $gestionnaire->id]);
        $bon = BonCaisse::factory()->approuve()->create(['site' => 'Conakry', 'demandeur_id' => $this->souadou->id]);

        $this->assertNull(\App\Services\BonCaisse\ControleCaissier::refus($bon, $gestionnaire, $caisse));
        $this->assertSame('Vous n\'êtes ni le gestionnaire ni le suppléant de la Caisse principale Conakry (RG-M08-12).',
            \App\Services\BonCaisse\ControleCaissier::refus($bon, $autre, $caisse));
    }

    /** RG-M03-04 : un BP n'est émis qu'au profit d'un salarié */
    public function test_un_bp_ne_va_pas_a_un_tiers(): void
    {
        $bon = $this->brouillon(['type_bon' => 'BP']);
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), [
            'type_beneficiaire' => 'fournisseur', 'beneficiaire' => 'Garage du Niger', 'telephone_beneficiaire' => '622000000',
        ])->assertOk();

        $controle = collect($this->controles($bon))->firstWhere('numero', 1);
        $this->assertSame('bloquant', $controle['niveau']);
        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'bp-tiers'])->assertStatus(422);
        $this->assertSame(['type_beneficiaire' => [__('MSG-M03-02')]],
            \App\Services\BonCaisse\ReglesSaisie::erreursCompletes(\App\Services\BonCaisse\ReglesSaisie::donneesDu($bon->fresh()), [2], false)->toArray());
    }

    /** RG-M03-20 : même bénéficiaire, même montant sur 7 jours → le demandeur confirme ; l'alerte reste visible des valideurs */
    public function test_le_doublon_probable(): void
    {
        BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create([
            'numero' => 'BC-2026-0040', 'beneficiaire_id' => $this->yacouba->id, 'beneficiaire' => 'Yacouba BARRY',
            'montant' => 450000, 'date_soumission' => now()->subDays(3),
        ]);
        $bon = $this->bonComplet(['beneficiaire_id' => $this->yacouba->id]);

        /* Hors de la période paramétrée (2 jours) : pas d'alerte */
        Parametre::majValeur('doublon_delai_jours', '2');
        $this->assertSame('ok', collect($this->controles($bon))->firstWhere('numero', 13)['niveau']);
        Parametre::majValeur('doublon_delai_jours', '7');

        $controle = collect($this->controles($bon))->firstWhere('numero', 13);
        $this->assertSame('bloquant', $controle['niveau']);
        $this->assertSame('Un bon de 450' . self::NBSP . '000 GNF pour Yacouba BARRY existe déjà (BC-2026-0040, ' . now()->subDays(3)->format('d/m/Y')
            . '). Confirmez qu\'il ne s\'agit pas d\'un doublon.', $controle['message']);

        $this->actingAs($this->souadou)->postJson(route('api.bons.doublon-probable', $bon), [])->assertStatus(422);
        $this->actingAs($this->souadou)->postJson(route('api.bons.doublon-probable', $bon), ['confirme' => true])->assertOk();
        $this->assertSame('avertissement', collect($this->controles($bon))->firstWhere('numero', 13)['niveau']);

        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'doublon'])->assertOk();
        $this->actingAs($this->souadou)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('alertes.doublon.numero', 'BC-2026-0040')
                ->where('alertes.doublon.confirme_par', 'Souadou BARRY'));

    }

    /** RG-M03-21, RG-M07-06 : partiel par défaut (BP bloqué, BD signalé) ; dérogation du DAF pour un bon donné */
    public function test_le_bp_en_retard_du_beneficiaire_et_la_derogation_du_daf(): void
    {
        BonCaisse::factory()->provisoire()->statut('EN_ATTENTE_REGULARISATION')->create([
            'numero' => 'BC-2026-0031', 'beneficiaire_id' => $this->yacouba->id, 'beneficiaire' => 'Yacouba BARRY',
            'date_limite_regularisation' => today()->subDays(5),
        ]);

        /* BD : signalé, soumis, bandeau pour les valideurs */
        $bd = $this->bonComplet(['beneficiaire_id' => $this->yacouba->id, 'montant' => 300000]);
        $controle = collect($this->controles($bd))->firstWhere('numero', 14);
        $this->assertSame(['avertissement', 'MSG-M03-14'], [$controle['niveau'], $controle['message_cle']]);
        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bd), [], ['Idempotency-Key' => 'bd-retard'])->assertOk();
        $this->actingAs($this->souadou)->get(route('bons-caisse.show', $bd))
            ->assertInertia(fn (Assert $page) => $page->where('alertes.bp_en_retard.0.numero', 'BC-2026-0031'));

        /* BP : bloqué ; dérogation demandée, accordée par le DAF, puis soumission */
        $bp = $this->brouillon(['type_bon' => 'BP']);
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bp), $this->depense() + [
            'type_beneficiaire' => 'employe', 'beneficiaire_id' => $this->yacouba->id, 'montant' => 200000,
        ])->assertOk();
        $controle = collect($this->controles($bp))->firstWhere('numero', 14);
        $this->assertSame('bloquant', $controle['niveau']);
        $this->assertSame('Yacouba BARRY a un bon provisoire non régularisé en retard (BC-2026-0031, échéance '
            . today()->subDays(5)->format('d/m/Y') . '). Aucun nouveau bon provisoire ne peut être émis.', $controle['message']);
        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bp), [], ['Idempotency-Key' => 'bp-retard'])->assertStatus(422);

        $this->actingAs($this->souadou)->postJson(route('api.bons.derogation-bp', $bp), ['motif' => 'Mission urgente à Kamsar demandée par la DG'])->assertOk();
        $this->assertDatabaseHas('notifications', ['destinataire_id' => $this->daf->id, 'bon_caisse_id' => $bp->id, 'type' => 'derogation_bp']);
        $this->actingAs($this->daf)->get(route('bons-caisse.show', $bp))
            ->assertInertia(fn (Assert $page) => $page->where('peutDeciderDerogation', true)->where('alertes.derogation.statut', 'demandee'));
        $this->actingAs($this->souadou)->post(route('bons-caisse.derogation-bp', $bp), ['decision' => 'accorder', 'motif' => 'Je m\'accorde la dérogation'])
            ->assertForbidden();
        $this->actingAs($this->daf)->post(route('bons-caisse.derogation-bp', $bp), ['decision' => 'accorder', 'motif' => 'Mission validée par la Direction générale'])
            ->assertSessionHas('success');

        $controle = collect($this->controles($bp))->firstWhere('numero', 14);
        $this->assertSame('avertissement', $controle['niveau']);
        $this->assertSame('Dérogation accordée par Mohamed DIAKITE : Mission validée par la Direction générale', $controle['message']);
        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bp), [], ['Idempotency-Key' => 'bp-derogation'])->assertOk();

        /* Mode « total » : le BD aussi est bloqué ; « aucun » : alerte seule */
        Parametre::majValeur('blocage_bp_en_retard', 'total');
        $bd2 = $this->bonComplet(['beneficiaire_id' => $this->yacouba->id, 'montant' => 150000]);
        $this->assertSame('bloquant', collect($this->controles($bd2))->firstWhere('numero', 14)['niveau']);
        Parametre::majValeur('blocage_bp_en_retard', 'aucun');
        $this->assertSame('avertissement', collect($this->controles($bd2))->firstWhere('numero', 14)['niveau']);
    }

    /* ------------------------------------------------------------------ */

    private function brouillon(array $valeurs = []): BonCaisse
    {
        $id = $this->actingAs($this->souadou)->postJson(route('api.bons.creer'), $valeurs + [
            'type_bon' => 'BD', 'code_analytique' => 'ADAZZZ',
        ])->assertCreated()->json('bon.id');

        return BonCaisse::findOrFail($id);
    }

    private function depense(): array
    {
        return ['motif' => 'Achat de fournitures pour l\'atelier Aftermarket', 'categorie_depense' => 'fournitures', 'montant' => 450000, 'mode_paiement' => 'especes'];
    }

    /** BD complet avec une facture, au profit d'un salarié */
    private function bonComplet(array $valeurs): BonCaisse
    {
        $bon = $this->brouillon();
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), $valeurs + $this->depense() + ['type_beneficiaire' => 'employe'])->assertOk();
        $this->actingAs($this->souadou)->postJson(route('api.bons.pieces.ajouter', $bon), [
            'fichier' => UploadedFile::fake()->create('facture-' . $bon->id . '.pdf', 120 + $bon->id, 'application/pdf'),
            'type_document' => 'facture',
        ])->assertCreated();

        return $bon->fresh();
    }

    private function controles(BonCaisse $bon): array
    {
        return $this->actingAs($this->souadou)->getJson(route('api.bons.controles', $bon))->assertOk()->json('controles');
    }
}
