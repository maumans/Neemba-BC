<?php

namespace Tests\Feature\LotC;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\BonCaisse\ControleCaissier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot C (spec v2.3) : numéro BC-[code caisse]-[AAAA]-[00001] par caisse émettrice et par année (RG-M03-18) ; carnet
 * Atelier repris (RG-M08-11) ; caisse de rattachement du demandeur, site figé (RG-M02-03, RG-M03-11) ; la caisse Atelier
 * paie ses bons (RG-M08-01) ; le caissier rattaché à une caisse paie ses bons (RG-M06-01).
 */
class NumerotationCaisseTest extends TestCase
{
    use RefreshDatabase;

    private Caisse $conakry;
    private Caisse $boke;
    private Caisse $atelier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Carbon::setTestNow('2026-10-17 09:00');

        $siteConakry = Site::factory()->conakry()->create();
        $siteBoke = Site::factory()->create(['nom' => 'Boke']);
        $this->conakry = Caisse::factory()->create(['code' => 'CKY-ESP', 'code_numerotation' => 'CKY', 'libelle' => 'Caisse principale Conakry',
            'site_id' => $siteConakry->id, 'plafond_retrait' => 20000000, 'solde' => 30000000]);
        $this->boke = Caisse::factory()->create(['code' => 'BOK-ESP', 'code_numerotation' => 'BOK', 'libelle' => 'Caisse principale Boke',
            'site_id' => $siteBoke->id, 'plafond_retrait' => 1000000, 'solde' => 5000000]);
        $this->atelier = Caisse::factory()->create(['code' => 'CKY-ATL', 'code_numerotation' => 'ATL', 'libelle' => 'Caisse Atelier',
            'site_id' => $siteConakry->id, 'mode' => 'avance_fixe', 'montant_avance' => 15000000, 'plafond_retrait' => 1000000, 'solde' => 15000000,
            'numero_depart' => 5123, 'chiffres_numero' => 7]);

        $service = Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $service->id]);
        User::factory()->role('responsable_service')->create(['service' => 'Aftermarket']);
        User::factory()->role('controle_gestion')->create();
        User::factory()->role('daf')->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** RG-M03-18 : une séquence par caisse émettrice ; les anciens numéros ne changent pas */
    public function test_une_sequence_par_caisse(): void
    {
        $ancien = BonCaisse::factory()->statut('PAYE')->create(['numero' => 'BC-2026-0007']);
        $conakry = $this->demandeur('Conakry');
        $boke = $this->demandeur('Boke');

        $this->assertSame('BC-CKY-2026-00001', $this->soumettre($conakry)->numero);
        $this->assertSame('BC-BOK-2026-00001', $this->soumettre($boke)->numero);
        $second = $this->soumettre($conakry);
        $this->assertSame('BC-CKY-2026-00002', $second->numero);
        $this->assertSame([$this->conakry->id, 'Conakry'], [$second->caisse_emettrice_id, $second->site]);
        $this->assertSame('BC-2026-0007', $ancien->fresh()->numero);
    }

    /** RG-M08-11 : le carnet Atelier reprend au numéro de départ, sur 7 chiffres ; chaque année repart à 1 */
    public function test_le_carnet_atelier_est_repris(): void
    {
        $atelier = $this->demandeur('Conakry', $this->atelier);

        $premier = $this->soumettre($atelier);
        $this->assertSame('BC-ATL-2026-0005123', $premier->numero);
        $this->assertSame('BC-ATL-2026-0005124', $this->soumettre($atelier)->numero);
        /* La caisse Atelier paie ses bons en espèces (RG-M08-01) */
        $this->assertSame($this->atelier->id, $premier->caisse_id);

        Carbon::setTestNow('2027-01-04 09:00');
        $this->assertSame('BC-ATL-2027-0000001', $this->soumettre($atelier)->numero);
    }

    /** RG-M02-03, RG-M03-11 : le site vient du profil ; le caissier rattaché à une caisse ne paie que ses bons */
    public function test_le_caissier_paie_les_bons_de_sa_caisse(): void
    {
        $bonAtelier = $this->soumettre($this->demandeur('Conakry', $this->atelier));
        $bonConakry = $this->soumettre($this->demandeur('Conakry'));
        $bonAtelier->update(['statut' => 'APPROUVE']);
        $bonConakry->update(['statut' => 'APPROUVE']);

        $caissiereAtelier = User::factory()->role('caissier')->create(['site' => 'Conakry', 'caisse_id' => $this->atelier->id]);
        $this->assertNull(ControleCaissier::refus($bonAtelier->fresh(), $caissiereAtelier));
        $this->assertSame('Ce bon relève de la Caisse principale Conakry : seul un caissier de cette caisse peut le payer (RG-M06-01).',
            ControleCaissier::refus($bonConakry->fresh(), $caissiereAtelier));

        $this->actingAs($caissiereAtelier)->get(route('bons-caisse.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bonsCaisse.total', 1)->where('bonsCaisse.data.0.numero', $bonAtelier->numero));
    }

    /** Paramétrage d'une caisse : code, numéro de départ et nombre de chiffres */
    public function test_le_parametrage_de_la_numerotation(): void
    {
        $admin = User::factory()->role('administrateur')->create();
        $this->actingAs($admin)->put(route('parametrage.caisses.update', $this->boke), [
            'code' => 'BOK-ESP', 'libelle' => 'Caisse principale Boke', 'code_numerotation' => 'BOK', 'numero_depart' => 812, 'chiffres_numero' => 6,
        ])->assertSessionHas('success');
        $this->assertSame([812, 6], [$this->boke->fresh()->numero_depart, $this->boke->fresh()->chiffres_numero]);
        $this->assertSame('BC-BOK-2026-000812', $this->soumettre($this->demandeur('Boke'))->numero);

        $this->actingAs($admin)->put(route('parametrage.caisses.update', $this->boke), ['code' => 'BOK-ESP', 'libelle' => 'Caisse principale Boke', 'code_numerotation' => 'bok!'])
            ->assertSessionHasErrors('code_numerotation');
    }

    /* ------------------------------------------------------------------ */

    private function demandeur(string $site, ?Caisse $caisse = null): User
    {
        return User::factory()->create(['site' => $site, 'service' => 'Aftermarket', 'telephone' => '622461261', 'caisse_id' => $caisse?->id]);
    }

    /** Bon complet (facture jointe) soumis par l'assistant */
    private function soumettre(User $demandeur): BonCaisse
    {
        $id = $this->actingAs($demandeur)->postJson(route('api.bons.creer'), ['type_bon' => 'BD', 'code_analytique' => 'ADAZZZ', 'site' => 'Kankan'])
            ->assertCreated()->json('bon.id');
        $this->actingAs($demandeur)->patchJson(route('api.bons.enregistrer', $id), [
            'motif' => 'Achat de fournitures pour l\'atelier Aftermarket', 'categorie_depense' => 'fournitures', 'montant' => 150000 + $id, 'mode_paiement' => 'especes',
        ])->assertOk();
        $this->actingAs($demandeur)->postJson(route('api.bons.pieces.ajouter', $id), [
            'fichier' => UploadedFile::fake()->createWithContent("facture-{$id}.pdf", "%PDF-1.4 facture {$id} " . uniqid()), 'type_document' => 'facture',
        ])->assertCreated();
        $reponse = $this->actingAs($demandeur)->postJson(route('api.bons.soumettre', $id), [], ['Idempotency-Key' => "cle-{$id}"]);
        $this->assertSame(200, $reponse->status(), (string) $reponse->json('message'));

        return BonCaisse::findOrFail($id);
    }
}
