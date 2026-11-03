<?php

namespace Tests\Feature\M12;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ajout d'un participant (RG-M12-04) : la liste des salariés actifs s'ouvre au clic dans le champ, sans saisie,
 * puis se filtre pendant la frappe.
 */
class RechercheParticipantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_saisie_la_liste_des_salaries_actifs_est_renvoyee(): void
    {
        $demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe']);
        User::factory()->create(['name' => 'Bah', 'prenom' => 'Thierno']);
        User::factory()->create(['name' => 'Barry', 'prenom' => 'Yacouba', 'actif' => false]);

        $reponse = $this->actingAs($demandeur)->getJson(route('api.odm.employes'))->assertOk();

        $noms = collect($reponse->json('resultats'))->pluck('nom');
        $this->assertContains('BAH Thierno', $noms);
        $this->assertContains('KOLIE Philippe', $noms);
        $this->assertNotContains('BARRY Yacouba', $noms, 'un salarié inactif ne peut pas être ajouté');
        $this->assertFalse($reponse->json('plus'));
    }

    public function test_la_saisie_filtre_des_le_premier_caractere(): void
    {
        $demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe']);
        User::factory()->create(['name' => 'Bah', 'prenom' => 'Thierno']);

        $noms = collect($this->actingAs($demandeur)->getJson(route('api.odm.employes', ['q' => 'b']))->assertOk()->json('resultats'))->pluck('nom');

        $this->assertSame(['BAH Thierno'], $noms->all());
    }

    public function test_au_dela_de_50_salaries_la_liste_invite_a_affiner(): void
    {
        $demandeur = User::factory()->create();
        User::factory()->count(55)->create();

        $reponse = $this->actingAs($demandeur)->getJson(route('api.odm.employes'))->assertOk();

        $this->assertCount(50, $reponse->json('resultats'));
        $this->assertTrue($reponse->json('plus'));
    }
}
