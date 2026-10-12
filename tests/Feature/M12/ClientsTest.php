<?php

namespace Tests\Feature\M12;

use App\Models\Client;
use App\Models\CodeAnalytique;
use App\Models\OrdreMission;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Spec v2.3, §6.5 : référentiel des clients (Comptabilité, CDG, administrateur), import avec aperçu (PO-26),
 * plus de saisie libre sur l'ODM.
 */
class ClientsTest extends TestCase
{
    use RefreshDatabase;

    private User $comptable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comptable = User::factory()->create();
        $this->comptable->ajouterRoles(['chef_comptable']);
    }

    private function fichier(string $contenu, string $nom = 'clients.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, $contenu);
    }

    public function test_droits_et_saisie(): void
    {
        $this->actingAs(User::factory()->create())->get(route('clients.index'))->assertForbidden();
        $this->actingAs(User::factory()->role('controle_gestion')->create())->get(route('clients.index'))->assertOk();

        $this->actingAs($this->comptable)->post(route('clients.store'), ['code' => 'smd', 'raison_sociale' => '  Société  Minière de Dinguiraye '])
            ->assertSessionHas('success');
        $client = Client::sole();
        $this->assertSame(['SMD', 'Société Minière de Dinguiraye', true, $this->comptable->id], [$client->code, $client->raison_sociale, $client->actif, $client->cree_par_id]);
        $this->actingAs($this->comptable)->post(route('clients.store'), ['code' => 'SMD', 'raison_sociale' => 'Autre'])
            ->assertSessionHasErrors(['code' => 'Ce code client existe déjà.']);

        $this->actingAs($this->comptable)->get(route('clients.index', ['q' => 'miniere']))
            ->assertInertia(fn (Assert $page) => $page->component('Referentiels/Clients')->where('clients.total', 1)->where('totaux.actifs', 1));
    }

    public function test_import_avec_apercu_puis_application(): void
    {
        Client::create(['code' => 'CBG', 'raison_sociale' => 'CBG']);
        $csv = "Code client;Raison sociale\nSMD;Société Minière de Dinguiraye\nCBG;Compagnie des Bauxites de Guinée\nGAC;\n;Sans code\nSMD;Doublon\n";

        $apercu = $this->actingAs($this->comptable)->post(route('clients.importer'), ['fichier' => $this->fichier($csv)])->assertOk()->json();
        $this->assertSame(['crees' => 1, 'mis_a_jour' => 1, 'inchanges' => 0, 'ignores' => 3], $apercu['resume']);
        $this->assertFalse($apercu['applique']);
        $this->assertSame('Code en double dans le fichier (déjà ligne 2).', $apercu['lignes'][4]['motif']);
        $this->assertSame(1, Client::count());                                   // rien n'est écrit à l'aperçu

        $this->actingAs($this->comptable)->post(route('clients.importer'), ['fichier' => $this->fichier($csv), 'appliquer' => 1])
            ->assertOk()->assertJsonPath('applique', true);
        $this->assertSame(2, Client::count());
        $this->assertSame('Compagnie des Bauxites de Guinée', Client::where('code', 'CBG')->value('raison_sociale'));
    }

    public function test_un_client_desactive_n_est_plus_propose(): void
    {
        $service = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $service->id]);
        $actif = Client::create(['code' => 'SMD', 'raison_sociale' => 'Société Minière de Dinguiraye']);
        $inactif = Client::create(['code' => 'OLD', 'raison_sociale' => 'Ancien client', 'actif' => false]);

        $this->actingAs($this->comptable)->getJson(route('api.referentiels.clients', ['q' => '']))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.code', 'SMD');

        $demandeur = User::factory()->create(['service' => 'Technique']);
        $id = $this->actingAs($demandeur)->postJson(route('api.odm.creer'), ['clients' => [$actif->id, $inactif->id]])->assertCreated()->json('odm.id');
        $this->assertSame(['SMD'], OrdreMission::find($id)->clients->pluck('code')->all());

        /* Saisie libre refusée */
        $this->actingAs($demandeur)->putJson(route('api.odm.enregistrer', $id), ['clients' => ['SMD']])->assertStatus(422);

        $this->actingAs($this->comptable)->post(route('clients.basculer', $actif))->assertSessionHas('success');
        $this->assertFalse($actif->fresh()->actif);
        /* Un client désactivé déjà rattaché à l'ODM y reste */
        $this->actingAs($demandeur)->putJson(route('api.odm.enregistrer', $id), ['clients' => [$actif->id]])->assertOk();
        $this->assertSame(['SMD'], OrdreMission::find($id)->clients->pluck('code')->all());
    }
}
