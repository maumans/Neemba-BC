<?php

namespace Tests\Feature\LotE2;

use App\Mail\CodeConnexion;
use App\Models\ModificationEnAttente;
use App\Models\Notification;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Onglet « Sécurité » (spec v2.4, RG-M01-07) : l'administrateur active ou désactive la double authentification et en
 * règle la durée et les essais. Effet immédiat, tracé, autres administrateurs prévenus ; commande de secours.
 */
class ReglagesSecuriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $autreAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = User::factory()->role('administrateur')->create(['prenom' => 'Thierno', 'name' => 'DIALLO']);
        $this->autreAdmin = User::factory()->role('administrateur')->create();
    }

    private function reglages(array $modifs = []): array
    {
        return $modifs + [
            'double_authentification_active' => true, 'otp_connexion_duree' => 10, 'otp_connexion_tentatives' => 5,
            'otp_visa_urgent_actif' => true, 'otp_visa_duree' => 5,
        ];
    }

    public function test_l_onglet_securite_montre_les_reglages_hors_des_seuils(): void
    {
        User::factory()->role('daf')->create(['telephone' => null, 'prenom' => 'Mohamed', 'name' => 'DIAKITE']);

        $this->actingAs($this->admin)->get(route('parametrage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('securite.valeurs', $this->reglages())
            ->where('securite.bornes.otp_connexion_duree', ['min' => 1, 'max' => 60])
            ->where('securite.email.operationnel', false)
            ->where('securite.sms.operationnel', false)
            ->where('securite.valideursSansTelephone', ['Mohamed DIAKITE'])
            ->where('parametres', fn ($parametres) => collect($parametres)->pluck('cle')->intersect(['double_authentification_active', 'otp_visa_duree'])->isEmpty()));
    }

    /** Désactivation immédiate : tracée, autre administrateur prévenu, connexion par mot de passe seul */
    public function test_l_administrateur_desactive_la_double_authentification(): void
    {
        $this->actingAs($this->admin)->put(route('parametrage.securite.update'), $this->reglages(['double_authentification_active' => false]))
            ->assertSessionHas('success', 'Réglages de sécurité appliqués : Code de connexion par email.');

        $this->assertFalse(Parametre::valeur('double_authentification_active'));
        $trace = ModificationEnAttente::latest('id')->first();
        $this->assertSame(['approuvee', 'true', 'false', $this->admin->id, $this->admin->id],
            [$trace->statut, $trace->ancienne_valeur, $trace->nouvelle_valeur, $trace->demandeur_id, $trace->valideur_id]);
        $notification = Notification::where('destinataire_id', $this->autreAdmin->id)->where('type', 'securite')->first();
        $this->assertStringContainsString('Thierno DIALLO a modifié la double authentification. Code de connexion par email : désactivé.', $notification->message);
        $this->assertFalse(Notification::where('destinataire_id', $this->admin->id)->where('type', 'securite')->exists());

        $this->actingAs($this->admin)->get(route('parametrage.index'))
            ->assertInertia(fn (Assert $page) => $page->where('securite.historique.0.avant', 'Activé')->where('securite.historique.0.apres', 'Désactivé'));

        auth()->logout();
        $utilisateur = User::factory()->create();
        $this->post('/login', ['email' => $utilisateur->email, 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($utilisateur);
        Mail::assertNothingSent();
    }

    public function test_reactivation_et_durees(): void
    {
        Parametre::majValeur('double_authentification_active', 'false');
        Parametre::majValeur('otp_visa_urgent_actif', 'false');

        $this->actingAs($this->admin)->put(route('parametrage.securite.update'), $this->reglages(['otp_connexion_duree' => 15, 'otp_visa_duree' => 3]))
            ->assertSessionHas('success');

        $this->assertSame([true, 15.0, true, 3.0], [Parametre::valeur('double_authentification_active'), Parametre::valeur('otp_connexion_duree'),
            Parametre::valeur('otp_visa_urgent_actif'), Parametre::valeur('otp_visa_duree')]);
        $this->assertSame(4, ModificationEnAttente::where('statut', 'approuvee')->count());

        auth()->logout();
        $utilisateur = User::factory()->create();
        $this->post('/login', ['email' => $utilisateur->email, 'password' => 'password'])->assertRedirect(route('login.code'));
        Mail::assertSent(CodeConnexion::class, fn (CodeConnexion $mail) => $mail->minutes === 15);
    }

    public function test_bornes_et_aucun_changement(): void
    {
        $this->actingAs($this->admin)->put(route('parametrage.securite.update'), $this->reglages(['otp_connexion_duree' => 0, 'otp_connexion_tentatives' => 50]))
            ->assertSessionHasErrors(['otp_connexion_duree', 'otp_connexion_tentatives']);
        $this->actingAs($this->admin)->put(route('parametrage.securite.update'), $this->reglages())
            ->assertSessionHas('success', 'Aucune modification détectée.');
        $this->assertSame(0, ModificationEnAttente::count());
    }

    public function test_reserve_a_l_administrateur(): void
    {
        $daf = User::factory()->role('daf')->create();
        $this->actingAs($daf)->put(route('parametrage.securite.update'), $this->reglages(['double_authentification_active' => false]))->assertForbidden();
        $this->assertTrue(Parametre::valeur('double_authentification_active'));
    }

    /** Le réglage ne passe plus par la double validation ; une demande en attente est close */
    public function test_la_double_validation_ne_s_applique_plus_a_ces_reglages(): void
    {
        $parametre = Parametre::where('cle', 'otp_visa_urgent_actif')->first();
        $this->actingAs($this->admin)->put(route('parametrage.parametres.update', $parametre), ['valeur' => 'false'])->assertSessionHasErrors('valeur');

        $enAttente = ModificationEnAttente::create(['type_entite' => 'parametre', 'entite_id' => $parametre->id, 'champ' => 'valeur',
            'ancienne_valeur' => 'true', 'nouvelle_valeur' => 'false', 'demandeur_id' => $this->autreAdmin->id, 'statut' => 'en_attente']);
        $this->actingAs($this->admin)->put(route('parametrage.securite.update'), $this->reglages(['otp_visa_urgent_actif' => false]))->assertSessionHas('success');

        $this->assertSame('refusee', $enAttente->fresh()->statut);
        $this->assertFalse(Parametre::valeur('otp_visa_urgent_actif'));
    }

    public function test_la_commande_de_secours(): void
    {
        $this->artisan('securite:double-authentification', ['etat' => 'desactiver'])
            ->expectsOutput('Code de connexion par email : désactivé.')->assertSuccessful();
        $this->assertFalse(Parametre::valeur('double_authentification_active'));

        $this->artisan('securite:double-authentification', ['etat' => 'desactiver', '--visa' => true])->assertSuccessful();
        $this->assertFalse(Parametre::valeur('otp_visa_urgent_actif'));

        $this->artisan('securite:double-authentification', ['etat' => 'activer'])->assertSuccessful();
        $this->assertTrue(Parametre::valeur('double_authentification_active'));

        $this->artisan('securite:double-authentification', ['etat' => 'peut-etre'])->assertFailed();
    }
}
