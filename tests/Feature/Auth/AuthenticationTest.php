<?php

namespace Tests\Feature\Auth;

use App\Mail\CodeConnexion;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Connexion en deux temps (spec v2.4, RG-M01-07, SC-40) : mot de passe, puis code à 6 chiffres reçu par email. La session
 * n'est ouverte qu'après le code ; le paramètre de secours `double_authentification_active` rétablit la connexion simple.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** Code envoyé au dernier email de connexion */
    private function codeEnvoye(): string
    {
        $code = null;
        Mail::assertSent(CodeConnexion::class, function (CodeConnexion $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    /** SC-40 : le mot de passe juste mène à l'écran du code ; la session ne s'ouvre qu'avec le bon code */
    public function test_la_connexion_exige_le_code_recu_par_email(): void
    {
        $user = User::factory()->create(['email' => 'souadou.barry@neemba.com']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('login.code'));
        $this->assertGuest();
        Mail::assertSent(CodeConnexion::class, fn (CodeConnexion $mail) => $mail->hasTo($user->email) && preg_match('/^\d{6}$/', $mail->code));
        $this->assertDatabaseMissing('codes_otp', ['user_id' => $user->id, 'code_hash' => $this->codeEnvoye()]);

        $this->get(route('login.code'))->assertInertia(fn (Assert $page) => $page->component('Auth/CodeConnexion')
            ->where('email', 's••••••••••••@neemba.com')->where('minutes', 10));

        $this->post(route('login.code'), ['code' => $this->codeEnvoye()])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_un_code_faux_compte_un_essai_puis_le_code_tombe(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $faux = $this->codeEnvoye() === '000000' ? '111111' : '000000';

        $this->post(route('login.code'), ['code' => $faux])->assertSessionHasErrors(['code' => 'Code incorrect. Il vous reste 4 essai(s).']);
        foreach (range(1, 3) as $essai) {
            $this->post(route('login.code'), ['code' => $faux]);
        }
        $this->post(route('login.code'), ['code' => $faux])
            ->assertSessionHasErrors(['code' => 'Code expiré ou nombre d’essais dépassé : demandez un nouveau code.']);

        /* Le code juste ne sert plus */
        $this->post(route('login.code'), ['code' => $this->codeEnvoye()])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->travel(11)->minutes();
        $this->post(route('login.code'), ['code' => $this->codeEnvoye()])
            ->assertSessionHasErrors(['code' => 'Code expiré ou nombre d’essais dépassé : demandez un nouveau code.']);
        $this->assertGuest();
    }

    public function test_le_renvoi_attend_une_minute_et_remplace_le_code(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $premier = $this->codeEnvoye();

        $this->post(route('login.code.renvoyer'))->assertSessionHasErrors(['code' => 'Patientez une minute avant de demander un nouveau code.']);
        Mail::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->post(route('login.code.renvoyer'))->assertSessionHas('status');
        Mail::assertSentCount(2);
        $second = null;
        Mail::assertSent(CodeConnexion::class, function (CodeConnexion $mail) use (&$second) {
            $second = $mail->code;

            return true;
        });

        if ($premier !== $second) {
            $this->post(route('login.code'), ['code' => $premier])->assertSessionHasErrors('code');
        }
        $this->post(route('login.code'), ['code' => $second]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_l_ecran_du_code_sans_mot_de_passe_renvoie_a_la_connexion(): void
    {
        $this->get(route('login.code'))->assertRedirect(route('login'));
        $this->post(route('login.code'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_un_compte_desactive_est_refuse(): void
    {
        $user = User::factory()->create(['actif' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Ce compte est désactivé. Contactez l’administrateur.']);
        $this->assertGuest();
        Mail::assertNothingSent();
    }

    /** Paramètre de secours (panne du serveur d'email) : connexion sans code */
    public function test_sans_double_authentification_la_connexion_est_directe(): void
    {
        Parametre::majValeur('double_authentification_active', 'false');
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        Mail::assertNothingSent();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
