<?php

namespace Tests\Feature\LotF1;

use App\Models\Caisse;
use App\Models\Client;
use App\Models\EcritureCaisse;
use App\Models\EncaissementClient;
use App\Models\Notification;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot F1a (spec v2.4, M08 : RG-M08-02, 03, 10, 12 ; MSG-M08-01, 05, 06) : alerte de seuil au franchissement puis rappel
 * quotidien, encaissements clients sur Conakry principale, visibilité par caisse, plus de retrait libre.
 */
class CaissesM08Test extends TestCase
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
        $this->conakry = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => Site::factory()->conakry()->create()->id,
            'solde' => 0, 'seuil_alerte' => 1000000, 'plafond_caisse' => 5000000, 'encaissements_clients' => true]);
        $this->boke = Caisse::factory()->create(['code' => 'BOK-ESP', 'libelle' => 'Caisse Boké', 'site_id' => Site::factory()->create(['nom' => 'Boke'])->id,
            'solde' => 0, 'seuil_alerte' => 500000]);
        $this->conakry->crediter(1200000, 'solde_initial');
        $this->boke->crediter(800000, 'solde_initial');
        $this->caissier = User::factory()->role('caissier')->create(['prenom' => 'Youssouf', 'name' => 'TOURE', 'caisse_id' => $this->conakry->id, 'telephone' => null]);
        $this->caissierBoke = User::factory()->role('caissier')->create(['caisse_id' => $this->boke->id, 'telephone' => null]);
        $this->tresoriere = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->chefComptable = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['chef_comptable']));
        $this->daf = User::factory()->role('daf')->create();
    }

    private function alertes(User $u): int
    {
        return Notification::where('destinataire_id', $u->id)->where('type', 'alerte_solde')->count();
    }

    /** RG-M08-03 : une alerte au franchissement, au gérant, à la trésorerie et au Chef comptable ; rappel quotidien */
    public function test_alerte_au_franchissement_puis_rappel_quotidien(): void
    {
        $this->assertSame(0, $this->alertes($this->caissier));
        $this->conakry->fresh()->debiter(300000, 'paiement_bon');
        foreach ([$this->caissier, $this->tresoriere, $this->chefComptable] as $destinataire) {
            $this->assertSame(1, $this->alertes($destinataire));
        }
        $this->assertSame(0, $this->alertes($this->daf));
        $this->assertSame(0, $this->alertes($this->caissierBoke));
        $notification = Notification::where('destinataire_id', $this->caissier->id)->where('type', 'alerte_solde')->sole();
        $this->assertStringStartsWith('Caisse Caisse principale Conakry : solde 900 000 GNF, sous le seuil de réapprovisionnement (1 000 000 GNF).',
            str_replace("\u{202F}", ' ', str_replace("\u{00A0}", ' ', $notification->message)));

        /* Déjà sous le seuil : pas de nouvelle alerte au mouvement suivant, ni au rappel du même jour */
        $this->conakry->fresh()->debiter(100000, 'paiement_bon');
        $this->artisan('caisse:verifier-seuils')->assertSuccessful();
        $this->assertSame(1, $this->alertes($this->caissier));

        /* Le lendemain : rappel */
        $this->travel(1)->days();
        $this->artisan('caisse:verifier-seuils')->assertSuccessful();
        $this->assertSame(2, $this->alertes($this->caissier));
        $this->assertTrue(Notification::where('destinataire_id', $this->tresoriere->id)->where('titre', 'like', 'Rappel%')->exists());

        /* Remontée au-dessus du seuil, puis nouveau franchissement : nouvelle alerte */
        $this->conakry->fresh()->crediter(2000000, 'approvisionnement');
        $this->assertNull($this->conakry->fresh()->alerte_seuil_le);
        $this->conakry->fresh()->debiter(2500000, 'paiement_bon');
        $this->assertSame(3, $this->alertes($this->caissier));
    }

    /** RG-M08-10 : encaissement client sur Conakry principale, reçu numéroté, entrée au registre */
    public function test_encaissement_client_et_recu(): void
    {
        $client = Client::create(['code' => 'SMD', 'raison_sociale' => 'Société Minière de Dinguiraye', 'actif' => true]);
        $saisie = ['caisse_id' => $this->conakry->id, 'client_id' => $client->id, 'numero_piece' => 'fac-2026-118', 'montant' => 750000, 'mode' => 'especes'];

        $this->actingAs($this->caissier)->post(route('encaissements-clients.store'), $saisie)->assertSessionHas('success');
        $encaissement = EncaissementClient::sole();
        $this->assertSame(['REC-CKY-' . now()->year . '-00001', 'FAC-2026-118'], [$encaissement->numero, $encaissement->numero_piece]);
        $this->assertSame(1950000.0, (float) $this->conakry->fresh()->solde);
        $this->assertSame('encaissement_client', EcritureCaisse::findOrFail($encaissement->ecriture_caisse_id)->nature);
        $this->actingAs($this->caissier)->get(route('encaissements-clients.recu', $encaissement))->assertOk()->assertHeader('content-type', 'application/pdf');

        /* Un chèque est enregistré, sans toucher au solde espèces */
        $this->actingAs($this->tresoriere)->post(route('encaissements-clients.store'), ['mode' => 'cheque', 'montant' => 3000000] + $saisie)->assertSessionHas('success');
        $this->assertSame(1950000.0, (float) $this->conakry->fresh()->solde);
        $this->assertSame('REC-CKY-' . now()->year . '-00002', EncaissementClient::latest('id')->first()->numero);
    }

    public function test_encaissement_refuse_hors_conakry_et_au_dela_du_plafond(): void
    {
        $client = Client::create(['code' => 'CBG', 'raison_sociale' => 'CBG', 'actif' => true]);
        $saisie = ['client_id' => $client->id, 'numero_piece' => '11022219', 'mode' => 'especes'];

        $this->actingAs($this->caissierBoke)->post(route('encaissements-clients.store'), $saisie + ['caisse_id' => $this->boke->id, 'montant' => 10000])
            ->assertSessionHasErrors(['caisse_id' => 'Les encaissements clients ne sont autorisés que sur la caisse de Conakry principale.']);

        $this->actingAs($this->caissier)->post(route('encaissements-clients.store'), $saisie + ['caisse_id' => $this->conakry->id, 'montant' => 4000000])
            ->assertSessionHasErrors('montant');
        $this->assertSame(0, EncaissementClient::count());
        $this->assertTrue(Notification::where('destinataire_id', $this->tresoriere->id)->where('type', 'remise_en_banque')->exists());

        /* Un caissier d'une autre caisse n'encaisse pas sur Conakry ; un demandeur n'a pas accès */
        $this->actingAs($this->caissierBoke)->post(route('encaissements-clients.store'), $saisie + ['caisse_id' => $this->conakry->id, 'montant' => 1000])->assertRedirect();
        $this->assertSame(0, EncaissementClient::count());
        $this->actingAs(User::factory()->create())->get(route('encaissements-clients.index'))->assertForbidden();
    }

    /** RG-M08-12 : le caissier voit et opère sa caisse ; les rôles transverses toutes. RG-M08-02 : plus de retrait libre */
    public function test_visibilite_par_caisse_et_plus_de_retrait_libre(): void
    {
        $this->actingAs($this->caissierBoke)->get(route('mouvements-caisse.index'))->assertInertia(fn (Assert $page) => $page
            ->where('soldesCaisses', fn ($caisses) => collect($caisses)->pluck('code')->all() === ['BOK-ESP']));
        $this->actingAs($this->caissierBoke)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('soldesCaisses', fn ($caisses) => collect($caisses)->pluck('code')->all() === ['BOK-ESP']));
        $this->actingAs($this->tresoriere)->get(route('mouvements-caisse.index'))->assertInertia(fn (Assert $page) => $page
            ->where('soldesCaisses', fn ($caisses) => count($caisses) === 2));

        $this->actingAs($this->caissierBoke)->post(route('mouvements-caisse.store'), [
            'type' => 'retrait', 'caisse_id' => $this->boke->id, 'montant' => 10000, 'motif' => 'Retrait libre',
        ])->assertSessionHasErrors('type');
        $this->actingAs($this->caissierBoke)->post(route('mouvements-caisse.store'), [
            'type' => 'approvisionnement', 'caisse_id' => $this->conakry->id, 'montant' => 10000, 'motif' => 'Pas sa caisse',
        ])->assertForbidden();
    }
}
