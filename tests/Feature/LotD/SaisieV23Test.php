<?php

namespace Tests\Feature\LotD;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lot D1 (spec v2.3, saisie) : n° Orange Money repris du référentiel, motif s'il est modifié (RG-M03-10) ; estimation
 * des frais OM (RG-M03-09) ; motif de 10 à 250 caractères (RG-M03-05) ; facture ou proforma (RG-M03-15).
 */
class SaisieV23Test extends TestCase
{
    use RefreshDatabase;

    private User $souadou;
    private User $yacouba;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        $conakry = Site::factory()->conakry()->create();
        Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id, 'plafond_retrait' => 20000000, 'solde' => 30000000]);
        Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $conakry->id]);
        $service = Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $service->id]);
        $this->souadou = User::factory()->create(['prenom' => 'Souadou', 'name' => 'BARRY', 'site' => 'Conakry', 'service' => 'Aftermarket', 'numero_om' => '622461261']);
        $this->yacouba = User::factory()->create(['prenom' => 'Yacouba', 'name' => 'BARRY', 'site' => 'Conakry', 'service' => 'Aftermarket', 'numero_om' => null]);
    }

    /** RG-M03-10 : n° OM pré-rempli depuis le référentiel ; modifié → motif obligatoire, tracé ; absent → à saisir */
    public function test_le_numero_orange_money(): void
    {
        $bon = $this->brouillon();
        $this->etape3($bon, ['mode_paiement' => 'orange_money'])->assertJsonPath('erreurs', []);
        $this->assertSame('+224622461261', $bon->fresh()->numero_om);

        $this->etape3($bon, ['mode_paiement' => 'orange_money', 'numero_om' => '625000111'])
            ->assertJsonPath('erreurs.motif_numero_om.0', __('MSG-APP-063'));
        $this->etape3($bon, ['mode_paiement' => 'orange_money', 'numero_om' => '625000111', 'motif_numero_om' => 'Nouveau numéro communiqué par la bénéficiaire'])
            ->assertJsonPath('erreurs', []);
        $this->assertSame(['+224625000111', 'Nouveau numéro communiqué par la bénéficiaire'], [$bon->fresh()->numero_om, $bon->fresh()->motif_numero_om]);
        $this->assertTrue($bon->historiqueActions()->where('action', 'modification')->get()
            ->contains(fn ($ligne) => str_contains(json_encode($ligne->metadata, JSON_UNESCAPED_UNICODE), 'numero_om')));

        /* Bénéficiaire sans n° OM au référentiel : à saisir */
        $autre = $this->brouillon();
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $autre), ['beneficiaire_id' => $this->yacouba->id])->assertOk();
        $this->etape3($autre, ['mode_paiement' => 'orange_money'])->assertJsonPath('erreurs.numero_om.0', __('MSG-BC-001'));

        /* Hors Orange Money : effacé */
        $this->etape3($bon, ['mode_paiement' => 'especes'])->assertJsonPath('erreurs', []);
        $this->assertNull($bon->fresh()->numero_om);
    }

    /** RG-M03-09 : estimation des frais Orange Money (paliers), pour information */
    public function test_l_estimation_des_frais_orange_money(): void
    {
        $this->actingAs($this->souadou)->getJson(route('api.referentiels.frais-om', ['montant' => 6500000]))
            ->assertOk()
            ->assertJsonPath('estimation.frais', 52000)
            ->assertJsonPath('estimation.message', "Si le caissier retient Orange Money : frais estimés 52\u{00A0}000 GNF (0,8 %), montant versé 6\u{00A0}552\u{00A0}000 GNF.");
        $this->actingAs($this->souadou)->getJson(route('api.referentiels.frais-om'))->assertJsonPath('estimation', null);
    }

    /** RG-M03-05 : motif de 10 à 250 caractères ; RG-M03-15 : un reçu ne justifie pas un BD, une proforma oui */
    public function test_le_motif_et_les_justificatifs(): void
    {
        $bon = $this->brouillon();
        $this->etape3($bon, ['motif' => str_repeat('a', 250)])->assertJsonPath('erreurs', []);
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['motif' => str_repeat('a', 251)])->assertStatus(422);

        $this->piece($bon, 'recu', 'recu.pdf');
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['etape' => 4])->assertJsonPath('erreurs.pieces.0', __('MSG-M03-09'));
        $this->piece($bon, 'proforma', 'proforma.pdf');
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['etape' => 4])->assertJsonPath('erreurs', []);
    }

    private function brouillon(): BonCaisse
    {
        return BonCaisse::findOrFail($this->actingAs($this->souadou)->postJson(route('api.bons.creer'), ['type_bon' => 'BD', 'code_analytique' => 'ADAZZZ'])
            ->assertCreated()->json('bon.id'));
    }

    private function etape3(BonCaisse $bon, array $valeurs)
    {
        return $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), $valeurs + [
            'etape' => 3, 'motif' => 'Achat de fournitures pour l\'atelier', 'categorie_depense' => 'fournitures', 'montant' => 450000, 'mode_paiement' => 'especes',
        ])->assertOk();
    }

    private function piece(BonCaisse $bon, string $type, string $nom): void
    {
        $this->actingAs($this->souadou)->postJson(route('api.bons.pieces.ajouter', $bon), [
            'fichier' => UploadedFile::fake()->createWithContent($nom, "%PDF-1.4 {$nom} " . uniqid()), 'type_document' => $type,
        ])->assertCreated();
    }
}
