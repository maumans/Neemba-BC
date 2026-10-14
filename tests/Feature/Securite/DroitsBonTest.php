<?php

namespace Tests\Feature\Securite;

use App\Models\BonCaisse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Droits sur les actions d'un bon hors de sa fiche : régularisation d'un BP et export PDF (audit v2.3 du 14/10/2026).
 */
class DroitsBonTest extends TestCase
{
    use RefreshDatabase;

    /** Spec v2.3 (RG-M07-03) : seul le demandeur justifie son BP ; la régularisation passe par les visas de la justification */
    public function test_seul_le_demandeur_justifie_son_bp(): void
    {
        $demandeur = User::factory()->create();
        $bon = BonCaisse::factory()->provisoire()->statut('EN_ATTENTE_REGULARISATION')->create(['demandeur_id' => $demandeur->id]);

        $this->actingAs(User::factory()->create())
            ->put(route('bons-caisse.justification.enregistrer', $bon), ['lignes' => []])
            ->assertForbidden();
        $this->assertNull($bon->justification()->first());

        $this->actingAs($demandeur)
            ->put(route('bons-caisse.justification.enregistrer', $bon), ['lignes' => []])
            ->assertSessionHas('success');
        $this->assertSame(['brouillon', 'EN_ATTENTE_REGULARISATION'], [$bon->justification()->sole()->statut, $bon->fresh()->statut]);
    }

    public function test_le_pdf_d_un_bon_suit_les_droits_de_sa_fiche(): void
    {
        $demandeur = User::factory()->create();
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $demandeur->id]);

        $this->actingAs(User::factory()->create())->get(route('bons-caisse.export-pdf', $bon))->assertForbidden();
        $this->actingAs($demandeur)->get(route('bons-caisse.export-pdf', $bon))->assertOk();
    }
}
