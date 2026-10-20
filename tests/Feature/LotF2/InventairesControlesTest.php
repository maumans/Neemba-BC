<?php

namespace Tests\Feature\LotF2;

use App\Models\Caisse;
use App\Models\Notification;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use App\Services\Caisse\ArreteCaisse;
use App\Services\Caisse\InventairesCaisse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot F2b (spec v2.4, RG-M10-01) : inventaire de fin de mois, contrôles inopinés à la fréquence de la caisse (trimestrielle
 * pour Conakry, mensuelle par défaut), rappels et alertes au Chef comptable, contrôle jamais fait par le gérant.
 */
class InventairesControlesTest extends TestCase
{
    use RefreshDatabase;

    private Caisse $conakry;
    private Caisse $boke;
    private User $caissier;
    private User $caissierBoke;
    private User $tresoriere;
    private User $chefComptable;
    private User $daf;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-05 09:00'));
        Parametre::majValeur('inventaires_suivis_depuis', '2026-10-01');
        $this->conakry = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => Site::factory()->conakry()->create()->id,
            'solde' => 1000000, 'frequence_controle' => 'trimestrielle']);
        $this->boke = Caisse::factory()->create(['code' => 'BOK-ESP', 'libelle' => 'Caisse Boké', 'solde' => 500000]);
        $this->caissier = User::factory()->role('caissier')->create(['caisse_id' => $this->conakry->id]);
        $this->caissierBoke = User::factory()->role('caissier')->create(['caisse_id' => $this->boke->id]);
        $this->tresoriere = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->chefComptable = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['chef_comptable']));
        $this->daf = User::factory()->role('daf')->create();
    }

    private function notifications(User $u, string $titre): int
    {
        return Notification::where('destinataire_id', $u->id)->where('type', 'inventaire_caisse')->where('titre', $titre)->count();
    }

    public function test_frequence_et_periode(): void
    {
        $this->assertSame(['trimestrielle', 'mensuelle'], [InventairesCaisse::frequence($this->conakry), InventairesCaisse::frequence($this->boke)]);
        [$debut, $fin] = InventairesCaisse::periode($this->conakry, now());
        $this->assertSame(['2026-10-01', '2026-12-31'], [$debut->toDateString(), $fin->toDateString()]);
        Parametre::majValeur('frequence_controle_inopine', 'trimestrielle');
        $this->assertSame('trimestrielle', InventairesCaisse::frequence($this->boke->fresh()));
        /* Samedi 31/10 : le dernier jour ouvré d'octobre 2026 est le vendredi 30 */
        $this->assertSame('2026-10-30', InventairesCaisse::dernierJourOuvre(now())->toDateString());

        /* Fréquence réglée à l'écran des caisses, effet immédiat */
        $admin = User::factory()->role('administrateur')->create();
        $this->actingAs($admin)->put(route('parametrage.caisses.update', $this->boke), ['code' => 'BOK-ESP', 'libelle' => 'Caisse Boké', 'frequence_controle' => 'trimestrielle'])
            ->assertSessionHas('success');
        $this->assertSame('trimestrielle', $this->boke->fresh()->frequence_controle);
        $this->actingAs($admin)->put(route('parametrage.caisses.update', $this->boke), ['code' => 'BOK-ESP', 'libelle' => 'Caisse Boké', 'frequence_controle' => 'hebdomadaire'])
            ->assertSessionHasErrors('frequence_controle');
    }

    /** Le dernier jour ouvré du mois, rappel au gérant, à la trésorerie et au Chef comptable ; le mois suivant, alerte au garant */
    public function test_inventaire_de_fin_de_mois(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
        ArreteCaisse::etablir($this->caissierBoke, $this->boke, 'inventaire_mensuel', ['20000' => 25], null, null);

        $this->travelTo(Carbon::parse('2026-10-29 08:15'));
        InventairesCaisse::verifier();
        $this->assertSame(0, $this->notifications($this->caissier, 'Inventaire de fin de mois à faire'));

        $this->travelTo(Carbon::parse('2026-10-30 08:15'));
        InventairesCaisse::verifier();
        InventairesCaisse::verifier();
        foreach ([$this->caissier, $this->tresoriere, $this->chefComptable] as $u) {
            $this->assertSame(1, $this->notifications($u, 'Inventaire de fin de mois à faire'));
        }
        $this->assertSame(0, $this->notifications($this->caissierBoke, 'Inventaire de fin de mois à faire'));

        $this->travelTo(Carbon::parse('2026-11-02 08:15'));
        $this->artisan('caisse:verifier-inventaires')->assertSuccessful();
        $this->assertSame([1, 1, 0], [$this->notifications($this->chefComptable, 'Inventaire de fin de mois manquant'),
            $this->notifications($this->daf, 'Inventaire de fin de mois manquant'), $this->notifications($this->caissier, 'Inventaire de fin de mois manquant')]);
        $this->assertStringContainsString('Caisse principale Conakry', Notification::where('titre', 'Inventaire de fin de mois manquant')->first()->message);
        $this->assertFalse(Notification::where('titre', 'Inventaire de fin de mois manquant')->where('message', 'like', '%Boké%')->exists());
    }

    /** Contrôle inopiné : rappel au seul Chef comptable en fin de période, alerte après ; jamais par le gérant */
    public function test_controle_inopine(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 08:15'));
        InventairesCaisse::verifier();
        $this->assertSame(1, $this->notifications($this->chefComptable, 'Contrôle inopiné à faire'));
        $this->assertSame(0, $this->notifications($this->caissierBoke, 'Contrôle inopiné à faire'));
        $this->assertSame(0, $this->notifications($this->tresoriere, 'Contrôle inopiné à faire'));
        /* Conakry : contrôle trimestriel, rien avant la fin de décembre */
        $this->assertFalse(Notification::where('titre', 'Contrôle inopiné à faire')->where('message', 'like', '%Conakry%')->exists());

        /* Le gérant ne fait pas son propre contrôle inopiné */
        $this->actingAs($this->caissierBoke)->post(route('pv-arrete.store'), ['caisse_id' => $this->boke->id, 'motif' => 'controle_inopine', 'billetage' => ['20000' => 25]])
            ->assertSessionHasErrors(['motif' => __('MSG-APP-109')]);

        $this->travelTo(Carbon::parse('2026-11-02 08:15'));
        InventairesCaisse::verifier();
        $this->assertSame([1, 1], [$this->notifications($this->chefComptable, 'Contrôle inopiné non fait'), $this->notifications($this->daf, 'Contrôle inopiné non fait')]);

        /* Contrôle fait par la trésorerie en novembre : plus de rappel pour la période */
        $this->actingAs($this->tresoriere)->post(route('pv-arrete.store'), ['caisse_id' => $this->boke->id, 'motif' => 'controle_inopine', 'billetage' => ['20000' => 25]])
            ->assertSessionHasNoErrors();
        $this->travelTo(Carbon::parse('2026-11-26 08:15'));
        InventairesCaisse::verifier();
        $this->assertSame(1, $this->notifications($this->chefComptable, 'Contrôle inopiné à faire'));
    }

    /** Suivi montré aux rôles transverses, pas au gérant (le contrôle reste inopiné) */
    public function test_suivi_reserve_aux_roles_transverses(): void
    {
        $this->actingAs($this->chefComptable)->get(route('pv-arrete.index'))->assertInertia(fn (Assert $page) => $page
            ->has('suivi', 2)
            ->where('suivi', fn ($s) => collect($s)->firstWhere('caisse', 'Caisse principale Conakry')['frequence'] === 'Trimestrielle'
                && collect($s)->firstWhere('caisse', 'Caisse principale Conakry')['controle_periode'] === null));
        $this->actingAs($this->caissier)->get(route('pv-arrete.index'))->assertInertia(fn (Assert $page) => $page->where('suivi', null));
    }
}
