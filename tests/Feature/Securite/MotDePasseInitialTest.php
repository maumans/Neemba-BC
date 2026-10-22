<?php

namespace Tests\Feature\Securite;

use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Q108 (signalement du 22/10) : des comptes importés n'avaient pas le mot de passe initial. Il est remis, et tout compte
 * au mot de passe initial (import, création ou réinitialisation par l'administrateur) le change à sa première connexion.
 */
class MotDePasseInitialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function compteInitial(): User
    {
        return User::factory()->create(['password' => User::MOT_DE_PASSE_INITIAL, 'mot_de_passe_a_changer' => true]);
    }

    public function test_compte_au_mot_de_passe_initial_redirige_puis_libere(): void
    {
        $compte = $this->compteInitial();

        $this->actingAs($compte)->get(route('dashboard'))->assertRedirect(route('mot-de-passe.initial'));
        $this->actingAs($compte)->getJson(route('dashboard'))->assertStatus(409);
        $this->actingAs($compte)->get(route('mot-de-passe.initial'))
            ->assertInertia(fn (Assert $page) => $page->component('Auth/MotDePasseInitial'));

        /* Le mot de passe initial n'est pas accepté comme nouveau mot de passe */
        $this->actingAs($compte)->put(route('mot-de-passe.initial.update'), [
            'password' => User::MOT_DE_PASSE_INITIAL, 'password_confirmation' => User::MOT_DE_PASSE_INITIAL,
        ])->assertSessionHasErrors('password');
        $this->assertTrue($compte->fresh()->mot_de_passe_a_changer);

        $this->actingAs($compte)->put(route('mot-de-passe.initial.update'), [
            'password' => 'MonSecret-2026', 'password_confirmation' => 'MonSecret-2026',
        ])->assertRedirect(route('dashboard'));
        $compte->refresh();
        $this->assertFalse($compte->mot_de_passe_a_changer);
        $this->assertTrue(Hash::check('MonSecret-2026', $compte->password));
        $this->actingAs($compte)->get(route('dashboard'))->assertOk();
    }

    public function test_reglage_coupe_pas_de_changement_impose(): void
    {
        Parametre::majValeur('changement_mot_de_passe_initial', 'false');
        $this->actingAs($this->compteInitial())->get(route('dashboard'))->assertOk();
    }

    public function test_creation_et_reinitialisation_par_l_administrateur_marquent_le_compte(): void
    {
        $admin = User::factory()->role('administrateur')->create();
        $this->actingAs($admin)->post(route('utilisateurs.store'), [
            'name' => 'SOUMAH', 'prenom' => 'Kadiatou', 'email' => 'kadiatou.soumah@neemba.com', 'password' => User::MOT_DE_PASSE_INITIAL,
            'matricule' => 'M-0011', 'role' => 'demandeur', 'service' => 'Direction', 'site' => 'Conakry',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'kadiatou.soumah@neemba.com')->sole()->mot_de_passe_a_changer);

        /* Mot de passe saisi par l'administrateur sur la fiche d'un autre : à changer ; sur la sienne : non */
        $autre = User::factory()->create();
        $donnees = fn (User $u) => ['name' => $u->name, 'prenom' => $u->prenom, 'email' => $u->email, 'role' => $u->role,
            'service' => $u->service, 'site' => $u->site, 'password' => 'Provisoire-2026'];
        $this->actingAs($admin)->put(route('utilisateurs.update', $autre), $donnees($autre))->assertSessionHasNoErrors();
        $this->assertTrue($autre->fresh()->mot_de_passe_a_changer);
        $this->actingAs($admin)->put(route('utilisateurs.update', $admin), $donnees($admin))->assertSessionHasNoErrors();
        $this->assertFalse($admin->fresh()->mot_de_passe_a_changer);
    }

    public function test_mot_de_passe_oublie_et_profil_demarquent(): void
    {
        $compte = $this->compteInitial();
        $jeton = Password::createToken($compte);
        $this->post(route('password.store'), [
            'token' => $jeton, 'email' => $compte->email, 'password' => 'Oublie-2026!', 'password_confirmation' => 'Oublie-2026!',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($compte->fresh()->mot_de_passe_a_changer);

        $compte = $compte->fresh();
        $compte->update(['mot_de_passe_a_changer' => true]);
        /* Le profil reste derrière l'écran imposé ; démarqué dès qu'il change par « Mot de passe oublié » ou par l'écran */
        $this->actingAs($compte->fresh())->put(route('password.update'), [
            'current_password' => 'Oublie-2026!', 'password' => 'Profil-2026!', 'password_confirmation' => 'Profil-2026!',
        ])->assertRedirect(route('mot-de-passe.initial'));
    }

    public function test_commande_remet_le_mot_de_passe_initial(): void
    {
        $a = User::factory()->create(['password' => 'Aleatoire-xyz-123']);
        $b = User::factory()->create(['password' => 'Aleatoire-abc-456']);
        $ok = User::factory()->create(['password' => User::MOT_DE_PASSE_INITIAL]);

        $this->artisan('utilisateurs:mot-de-passe-initial', ['ids' => [$a->id]])->assertSuccessful();
        $this->assertTrue(Hash::check(User::MOT_DE_PASSE_INITIAL, $a->fresh()->password));
        $this->assertTrue($a->fresh()->mot_de_passe_a_changer);
        $this->assertFalse(Hash::check(User::MOT_DE_PASSE_INITIAL, $b->fresh()->password));

        $this->artisan('utilisateurs:mot-de-passe-initial', ['--tous' => true])->assertSuccessful();
        $this->assertTrue(Hash::check(User::MOT_DE_PASSE_INITIAL, $b->fresh()->password));
        $this->assertFalse($ok->fresh()->mot_de_passe_a_changer);
        $this->artisan('utilisateurs:mot-de-passe-initial')->assertExitCode(2);
    }
}
