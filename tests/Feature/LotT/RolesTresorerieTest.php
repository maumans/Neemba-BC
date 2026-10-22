<?php

namespace Tests\Feature\LotT;

use App\Models\Caisse;
use App\Models\Delegation;
use App\Models\ModificationEnAttente;
use App\Models\Site;
use App\Models\User;
use App\Services\Caisse\AccesCaisse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot T (demande de l'utilisateur du 20/10, Q103) : la trésorerie et les autres rôles métier (Chef comptable, DAF adjoint,
 * DP adjoint, Comptabilité, assistant trésorier…) sont des rôles principaux visibles ; tous les rôles d'une personne
 * s'affichent ; changer de rôle principal ne fait plus perdre de rôle ; une délégation ne transmet que des rôles délégables.
 */
class RolesTresorerieTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $admin2;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Site::factory()->conakry()->create();
        $this->admin = User::factory()->role('administrateur')->create();
        $this->admin2 = User::factory()->role('administrateur')->create();
    }

    private function fiche(User $u, array $champs): array
    {
        return $champs + ['name' => $u->name, 'prenom' => $u->prenom, 'email' => $u->email, 'role' => $u->role, 'service' => 'Finance', 'site' => 'Conakry'];
    }

    private function approuver(ModificationEnAttente $m): void
    {
        $this->actingAs($this->admin2)->post(route('admin.modifications-en-attente.approuver', $m))->assertSessionHas('success');
    }

    public function test_creer_un_compte_tresorerie(): void
    {
        $this->actingAs($this->admin)->get(route('utilisateurs.create'))->assertInertia(fn (Assert $page) => $page
            ->where('rolesPrincipaux.tresorerie', 'Trésorerie')->where('rolesPrincipaux.chef_comptable', 'Chef comptable')
            ->where('rolesPrincipaux.assistant_tresorier', 'Assistant trésorier')->missing('rolesPrincipaux.membre_codir'));

        $this->actingAs($this->admin)->post(route('utilisateurs.store'), [
            'name' => 'CAMARA', 'prenom' => 'Mariamagbè', 'email' => 'mariamagbe.camara@neemba.com', 'password' => 'Neemba@2026-Fort!',
            'matricule' => 'NMB-010', 'role' => 'tresorerie', 'service' => 'Finance', 'site' => 'Conakry',
        ])->assertSessionHasNoErrors();
        $u = User::where('email', 'mariamagbe.camara@neemba.com')->sole();
        $this->assertSame('tresorerie', $u->role);
        $this->assertEqualsCanonicalizing(['tresorerie', 'demandeur'], $u->roles()->pluck('role')->all());
        $this->assertTrue($u->aLeRole('tresorerie') && $u->peutInitierBon());

        /* Q108 : le compte créé passe d'abord par le choix de son mot de passe */
        $this->assertTrue($u->mot_de_passe_a_changer);
        $this->actingAs($u)->get(route('dashboard'))->assertRedirect(route('mot-de-passe.initial'));
        $u->update(['mot_de_passe_a_changer' => false]);

        /* Bandeau et liste : « Trésorerie » */
        $this->actingAs($u)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.roles_affiches', ['Trésorerie']));
        $this->actingAs($this->admin)->get(route('utilisateurs.index', ['role' => 'tresorerie']))->assertInertia(fn (Assert $page) => $page
            ->has('utilisateurs.data', 1)->where('utilisateurs.data.0.roles_affiches', ['Trésorerie']));
    }

    /** Le filtre trouve aussi un rôle complémentaire ; tous les rôles s'affichent, rôle principal d'abord */
    public function test_filtre_et_affichage_de_tous_les_roles(): void
    {
        $youssouf = tap(User::factory()->role('caissier')->create(['prenom' => 'Youssouf']), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->actingAs($this->admin)->get(route('utilisateurs.index', ['role' => 'tresorerie']))->assertInertia(fn (Assert $page) => $page
            ->has('utilisateurs.data', 1)->where('utilisateurs.data.0.roles_affiches', ['Caissier', 'Trésorerie']));
        $this->actingAs($this->admin)->get(route('utilisateurs.index'))->assertInertia(fn (Assert $page) => $page
            ->where('roles.tresorerie', 'Trésorerie')->where('roles.comptable', 'Comptabilité'));
        $this->assertSame(['Demandeur'], User::libellesRoles(User::factory()->create()));
        $this->assertSame([$youssouf->id], User::parRole('tresorerie')->pluck('id')->all());
    }

    /** Demandeur → Trésorerie : « demandeur » reste (l'écran le garde coché) */
    public function test_passer_en_tresorerie_sans_perdre_demandeur(): void
    {
        $m = tap(User::factory()->create(['prenom' => 'Mariamagbè']), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->actingAs($this->admin)->put(route('utilisateurs.update', $m), $this->fiche($m, ['role' => 'tresorerie', 'roles_complementaires' => ['demandeur']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['utilisateur_role'], ModificationEnAttente::pluck('type_entite')->all());
        $this->approuver(ModificationEnAttente::sole());
        $m->refresh();
        $this->assertSame('tresorerie', $m->role);
        $this->assertEqualsCanonicalizing(['demandeur', 'tresorerie'], $m->roles()->pluck('role')->all());
        $this->assertTrue($m->peutInitierBon());
    }

    /** Caissier → Trésorerie en décochant « caissier » : même résultat quel que soit l'ordre des deux validations */
    public function test_ordre_des_validations_sans_effet(): void
    {
        foreach (['roles_d_abord', 'role_d_abord'] as $ordre) {
            ModificationEnAttente::query()->delete();
            $u = User::factory()->role('caissier')->create();
            $this->actingAs($this->admin)->put(route('utilisateurs.update', $u), $this->fiche($u, ['role' => 'tresorerie', 'roles_complementaires' => ['demandeur']]))
                ->assertSessionHasNoErrors();
            $role = ModificationEnAttente::where('type_entite', 'utilisateur_role')->sole();
            $roles = ModificationEnAttente::where('type_entite', 'utilisateur_roles')->sole();
            foreach ($ordre === 'roles_d_abord' ? [$roles, $role] : [$role, $roles] as $mod) {
                $this->approuver($mod->fresh());
            }
            $u->refresh();
            $this->assertSame('tresorerie', $u->role, $ordre);
            $this->assertEqualsCanonicalizing(['demandeur', 'tresorerie'], $u->roles()->pluck('role')->all(), $ordre);
        }
    }

    /** L'ancien rôle principal reste coché : il demeure un rôle de la personne */
    public function test_ancien_role_garde_s_il_reste_coche(): void
    {
        $u = User::factory()->role('daf')->create();
        $this->actingAs($this->admin)->put(route('utilisateurs.update', $u), $this->fiche($u, ['role' => 'chef_comptable', 'roles_complementaires' => ['daf', 'demandeur']]));
        $this->assertSame(1, ModificationEnAttente::count());
        $this->approuver(ModificationEnAttente::sole());
        $this->assertEqualsCanonicalizing(['chef_comptable', 'daf', 'demandeur'], $u->fresh()->roles()->pluck('role')->all());

        $this->actingAs($this->admin)->get(route('utilisateurs.edit', $u))->assertInertia(fn (Assert $page) => $page
            ->where('rolesComplementaires', ['daf', 'demandeur'])->where('rolesPrincipaux.chef_comptable', 'Chef comptable'));
    }

    /** Assistant trésorier : les droits du caissier sur sa caisse */
    public function test_assistant_tresorier(): void
    {
        $caisse = Caisse::factory()->create();
        $a = User::factory()->create(['role' => 'assistant_tresorier', 'caisse_id' => $caisse->id]);
        $this->assertTrue($a->aLeRole('caissier'));
        $this->assertTrue($a->peutPayer());
        $this->assertTrue(AccesCaisse::estGerant($a, $caisse));
        $this->assertContains($a->id, User::avecRoleDans(['caissier'])->pluck('id')->all());
        $this->actingAs($a)->get(route('clotures-caisse.index'))->assertOk();
        $this->actingAs($a)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.roles_affiches', ['Assistant trésorier']));
    }

    /** Une délégation ne transmet que les rôles qui ont une fonctionnalité déléguée */
    public function test_delegation_ne_transmet_pas_la_tresorerie(): void
    {
        $tresoriere = User::factory()->create(['role' => 'tresorerie']);
        $delegue = User::factory()->create();
        Delegation::create(['delegant_id' => $tresoriere->id, 'delegue_id' => $delegue->id, 'date_debut' => today(), 'date_fin' => today()->addDays(5),
            'motif' => 'Congés', 'statut' => 'acceptee', 'fonctionnalites' => ['initiation']]);
        $this->actingAs($delegue)->get(route('reconciliations-om.index'))->assertForbidden();

        $daf = User::factory()->role('daf')->create();
        $suppleant = User::factory()->create();
        Delegation::create(['delegant_id' => $daf->id, 'delegue_id' => $suppleant->id, 'date_debut' => today(), 'date_fin' => today()->addDays(5),
            'motif' => 'Mission', 'statut' => 'acceptee', 'fonctionnalites' => ['mouvement_caisse']]);
        $this->actingAs($suppleant)->get(route('mouvements-caisse.index'))->assertOk();
    }

    /** Bloc « À traiter » de la trésorière */
    public function test_a_traiter_de_la_tresorerie(): void
    {
        $t = User::factory()->create(['role' => 'tresorerie']);
        $this->actingAs($t)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('tachesCaisse', fn ($taches) => collect($taches)->pluck('cle')->contains('taux')));
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('tachesCaisse', []));
    }
}
