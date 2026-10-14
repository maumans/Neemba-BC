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

    public function test_seul_le_demandeur_regularise_son_bp(): void
    {
        $demandeur = User::factory()->create();
        $bon = BonCaisse::factory()->provisoire()->statut('EN_ATTENTE_REGULARISATION')->create(['demandeur_id' => $demandeur->id]);

        $this->actingAs(User::factory()->create())
            ->post(route('bons-caisse.regulariser', $bon), ['motif_regularisation' => 'Justificatifs fournis'])
            ->assertForbidden();
        $this->assertSame('EN_ATTENTE_REGULARISATION', $bon->fresh()->statut);

        $this->actingAs($demandeur)
            ->post(route('bons-caisse.regulariser', $bon), ['motif_regularisation' => 'Justificatifs fournis'])
            ->assertSessionHas('success');
        $this->assertSame('REGULARISE', $bon->fresh()->statut);
    }

    public function test_le_pdf_d_un_bon_suit_les_droits_de_sa_fiche(): void
    {
        $demandeur = User::factory()->create();
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['demandeur_id' => $demandeur->id]);

        $this->actingAs(User::factory()->create())->get(route('bons-caisse.export-pdf', $bon))->assertForbidden();
        $this->actingAs($demandeur)->get(route('bons-caisse.export-pdf', $bon))->assertOk();
    }
}
