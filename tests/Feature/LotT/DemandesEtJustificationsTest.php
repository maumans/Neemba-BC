<?php

namespace Tests\Feature\LotT;

use App\Models\BonCaisse;
use App\Models\JustificationBp;
use App\Models\ModificationEnAttente;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Signalement du 22/10 :
 * - Q107 : une demande de rôle (double validation) prévient les autres administrateurs et se voit (liste, fiche, menu,
 *   « À traiter ») ; avant, le rôle « gestionnaire des référentiels » demandé restait inactif sans que personne le sache.
 * - Q106 : les justifications de BP à viser apparaissent dans « Validations » et « À traiter » pour qui peut les viser
 *   (étape Comptabilité : Comptabilité et Chef comptable, pas le DAF, RG-M07-03).
 */
class DemandesEtJustificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_demande_de_role_signalee_aux_autres_administrateurs(): void
    {
        $thierno = User::factory()->role('administrateur')->create(['prenom' => 'Thierno', 'name' => 'DIALLO']);
        $baba = User::factory()->role('administrateur')->create(['prenom' => 'Baba', 'name' => 'DIAKITE']);

        $this->actingAs($thierno)->put(route('utilisateurs.update', $thierno), [
            'name' => 'DIALLO', 'prenom' => 'Thierno', 'email' => $thierno->email, 'role' => 'administrateur', 'service' => 'Direction', 'site' => 'Conakry',
            'roles_complementaires' => ['demandeur', 'gestionnaire_referentiels'],
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Baba DIAKITE, prévenus'));
        $m = ModificationEnAttente::sole();
        $this->assertSame('en_attente', $m->statut);

        /* Baba est prévenu, pas l'auteur */
        $note = Notification::where('destinataire_id', $baba->id)->where('type', 'modification_a_valider')->sole();
        $this->assertStringContainsString('Rôles de Thierno DIALLO : Demandeur, Gestionnaire des référentiels', $note->message);
        $this->assertFalse(Notification::where('destinataire_id', $thierno->id)->where('type', 'modification_a_valider')->exists());

        /* Visible : liste, fiche, compteur du menu et « À traiter » de Baba */
        $this->actingAs($thierno)->get(route('utilisateurs.index'))->assertInertia(fn (Assert $page) => $page
            ->where('utilisateurs.data', fn ($lignes) => collect($lignes)->firstWhere('id', $thierno->id)['roles_en_attente'][0]
                === 'Rôles de Thierno DIALLO : Demandeur, Gestionnaire des référentiels'));
        $this->actingAs($thierno)->get(route('utilisateurs.edit', $thierno))->assertInertia(fn (Assert $page) => $page->has('rolesEnAttente', 1));
        $this->actingAs($thierno)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.compteurs', fn ($c) => collect($c)->get('admin.modifications-en-attente.index') === 0));
        $this->actingAs($baba)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.compteurs', fn ($c) => collect($c)->get('admin.modifications-en-attente.index') === 1)
            ->where('tachesCaisse', fn ($t) => collect($t)->pluck('cle')->contains('modifications')));

        /* Tant que ce n'est pas validé, pas de référentiels ; après la validation de Baba, oui */
        $this->assertFalse($thierno->fresh()->aLeRole('gestionnaire_referentiels'));
        $this->actingAs($baba)->post(route('admin.modifications-en-attente.approuver', $m))->assertSessionHas('success');
        $this->assertTrue($thierno->fresh()->aLeRole('gestionnaire_referentiels'));
        $this->actingAs($thierno->fresh())->get(route('parametrage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('onglets', fn ($o) => collect($o)->contains('types-or') && collect($o)->contains('baremes-odm')));
    }

    public function test_justification_a_viser_visible_pour_la_comptabilite(): void
    {
        $souadou = User::factory()->create(['prenom' => 'Souadou', 'name' => 'BARRY']);
        $bp = BonCaisse::factory()->create(['type_bon' => 'BP', 'statut' => 'EN_ATTENTE_REGULARISATION', 'montant' => 1000000,
            'demandeur_id' => $souadou->id, 'beneficiaire_id' => $souadou->id, 'beneficiaire' => 'Souadou BARRY']);
        JustificationBp::create(['bon_caisse_id' => $bp->id, 'initiateur_id' => $souadou->id, 'statut' => 'en_validation', 'etape' => 'comptabilite',
            'version' => 1, 'montant_avance' => 1000000, 'montant_justifie' => 800000, 'montant_a_retourner' => 200000, 'trop_justifie' => 0,
            'soumise_le' => now()]);

        /* Un Chef comptable nommé après l'ouverture de l'étape (aucune notification reçue) la retrouve */
        $gando = tap(User::factory()->create(['prenom' => 'Gando', 'name' => 'Diallo']), fn (User $u) => $u->update(['role' => 'chef_comptable']));
        $this->actingAs($gando)->get(route('validations.index'))->assertInertia(fn (Assert $page) => $page
            ->has('justificationsAViser', 1)->where('justificationsAViser.0.numero', $bp->numero)->where('justificationsAViser.0.etape', 'Comptabilité'));
        $this->actingAs($gando)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('tachesCaisse', fn ($t) => collect($t)->firstWhere('cle', 'justifications')['nombre'] === 1));

        /* Rôle Comptabilité aussi ; ni le DAF (RG-M07-03, Q106) ni un demandeur */
        $comptable = User::factory()->create(['role' => 'comptable']);
        $this->actingAs($comptable)->get(route('validations.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('justificationsAViser', 1));
        $daf = User::factory()->role('daf')->create();
        $this->actingAs($daf)->get(route('validations.index'))->assertInertia(fn (Assert $page) => $page->has('justificationsAViser', 0));
        $this->actingAs(User::factory()->create())->get(route('validations.index'))->assertForbidden();
    }
}
