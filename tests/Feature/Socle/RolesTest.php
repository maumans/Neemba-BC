<?php

namespace Tests\Feature\Socle;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Plusieurs rôles par utilisateur (users.role = rôle principal) et droit de créer un bon.
 */
class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_nouveau_compte_recoit_son_role_et_le_role_demandeur(): void
    {
        $daf = User::factory()->role('daf')->create();

        $this->assertEqualsCanonicalizing(['daf', 'demandeur'], $daf->listeRoles());
        $this->assertTrue($daf->peutInitierBon());
    }

    public function test_changer_le_role_principal_remplace_l_ancien(): void
    {
        $utilisateur = User::factory()->role('controle_gestion')->create();

        $utilisateur->update(['role' => 'daf']);

        $this->assertEqualsCanonicalizing(['daf', 'demandeur'], $utilisateur->fresh()->listeRoles());
    }

    /** US-BC-01 scénario 2, TC-BC-032 */
    public function test_un_caissier_sans_role_demandeur_ne_peut_pas_creer_de_bon(): void
    {
        $caissier = $this->utilisateurAvecRoles(['caissier']);

        $this->actingAs($caissier)->get(route('bons-caisse.create'))->assertForbidden();
        $this->actingAs($caissier)->post(route('bons-caisse.store'), [])->assertForbidden();
        $this->actingAs($caissier)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.peut_initier_bon', false));
    }

    public function test_un_caissier_qui_est_aussi_demandeur_peut_creer_un_bon(): void
    {
        $caissier = $this->utilisateurAvecRoles(['caissier', 'demandeur']);

        $this->actingAs($caissier)->get(route('bons-caisse.create'))->assertOk();
    }

    public function test_un_role_secondaire_ouvre_les_ecrans_proteges(): void
    {
        /* Rôle principal demandeur, rôle secondaire caissier : accès aux rapports de caisse (role:caissier,…) */
        $utilisateur = $this->utilisateurAvecRoles(['demandeur', 'caissier']);

        $this->assertTrue($utilisateur->peutPayer());
        $this->actingAs($utilisateur)->get(route('rapports.index'))->assertOk();
        $this->actingAs($this->utilisateurAvecRoles(['demandeur']))->get(route('rapports.index'))->assertForbidden();
    }
}
