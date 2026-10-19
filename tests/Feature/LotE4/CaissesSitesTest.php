<?php

namespace Tests\Feature\LotE4;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueAction;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\BonCaisse\ControleCaissier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot E4 (spec v2.4, RG-M02-11, RG-M03-27, RG-M03-11, §6.1, SC-33) : une caisse sert un ou plusieurs sites, un site peut
 * avoir plusieurs caisses, la caisse Orange Money n'a pas de site ; le bon porte un site analytique, modifiable à la
 * saisie et corrigé par le CDG avec trace, sans changer la caisse qui paie.
 */
class CaissesSitesTest extends TestCase
{
    use RefreshDatabase;

    private Site $conakry;
    private Site $boke;
    private Caisse $especesConakry;
    private Caisse $atelier;
    private Caisse $om;
    private User $admin;
    private User $souadou;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->conakry = Site::factory()->conakry()->create();
        $this->boke = Site::factory()->create(['nom' => 'Boke', 'code' => '31']);
        Site::factory()->create(['nom' => 'Sangaredi', 'code' => '11']);
        $this->especesConakry = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $this->conakry->id,
            'plafond_retrait' => 20000000, 'solde' => 30000000]);
        $this->atelier = Caisse::factory()->create(['code' => 'CKY-ATL', 'libelle' => 'Caisse Atelier', 'site_id' => $this->conakry->id, 'mode' => 'avance_fixe',
            'montant_avance' => 15000000, 'solde' => 15000000, 'plafond_retrait' => 1000000]);
        $this->om = Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money']);
        Caisse::factory()->create(['code' => 'BOK-ESP', 'libelle' => 'Caisse principale Boke', 'site_id' => $this->boke->id, 'plafond_retrait' => 1000000, 'solde' => 5000000]);

        $aftermarket = Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $aftermarket->id]);
        $this->admin = User::factory()->role('administrateur')->create();
        $this->souadou = User::factory()->create(['prenom' => 'Souadou', 'name' => 'BARRY', 'site' => 'Conakry', 'service' => 'Aftermarket',
            'caisse_id' => $this->especesConakry->id]);
    }

    /** SC-33 : un site a plusieurs caisses ; la caisse OM n'a pas de site ; l'écran des caisses montre les sites servis */
    public function test_un_site_a_plusieurs_caisses_et_la_caisse_om_aucun_site(): void
    {
        $this->assertSame(['CKY-ATL', 'CKY-ESP'], $this->conakry->caisses()->orderBy('code')->pluck('code')->all());
        $this->assertSame(45000000.0, $this->conakry->fresh()->solde_caisse);
        $this->assertSame('Aucun site', $this->om->sites_libelle);
        $this->assertSame([], Caisse::duSite('Conakry')->where('type', 'orange_money')->pluck('id')->all());

        $this->actingAs($this->admin)->get(route('parametrage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('caisses', fn ($caisses) => collect($caisses)->firstWhere('code', 'CKY-OM')['sites_libelle'] === 'Aucun site'
                && collect($caisses)->firstWhere('code', 'CKY-ESP')['sites_libelle'] === 'Conakry'));

        /* Les écrans qui affichent les caisses ne plantent plus sur une caisse sans site */
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('mouvements-caisse.index'))->assertOk();
    }

    /** SC-33 : une caisse sert deux sites ; un caissier de l'un ou l'autre site voit et paie ses bons */
    public function test_une_caisse_sert_deux_sites(): void
    {
        $kamsar = Site::factory()->create(['nom' => 'Kamsar', 'code' => '32']);
        $boke = Caisse::where('code', 'BOK-ESP')->sole();
        $this->actingAs($this->admin)->put(route('parametrage.caisses.update', $boke), [
            'code' => 'BOK-ESP', 'libelle' => 'Caisse principale Boke', 'site_ids' => [$this->boke->id, $kamsar->id],
            'plafond_retrait' => 1000000, 'seuil_alerte' => null, 'montant_avance' => null, 'plafond_caisse' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Boke, Kamsar', $boke->fresh()->sites_libelle);
        $this->assertSame([$boke->id], Caisse::duSite('Kamsar')->pluck('id')->all());

        $bon = BonCaisse::factory()->statut('APPROUVE')->create(['site' => 'Boke', 'caisse_emettrice_id' => $boke->id, 'caisse_id' => $boke->id,
            'mode_paiement' => 'especes', 'montant' => 300000]);
        $caissierKamsar = User::factory()->role('caissier')->create(['site' => 'Kamsar']);
        $caissierSangaredi = User::factory()->role('caissier')->create(['site' => 'Sangaredi']);
        $this->assertTrue(ControleCaissier::bonDuSite($bon, 'Kamsar'));
        $this->assertNull(ControleCaissier::refus($bon, $caissierKamsar, $boke));
        $this->assertStringContainsString("seul un caissier d'un site qu'elle sert", (string) ControleCaissier::refus($bon, $caissierSangaredi, $boke));
        $this->actingAs($caissierKamsar)->get(route('bons-caisse.show', $bon))->assertOk();
    }

    public function test_les_sites_servis_au_parametrage(): void
    {
        $base = ['libelle' => 'Caisse', 'mode' => 'standard', 'plafond_retrait' => null, 'seuil_alerte' => null];
        $this->actingAs($this->admin)->post(route('parametrage.caisses.store'), $base + ['code' => 'KAM-ESP', 'type' => 'especes', 'site_ids' => []])
            ->assertSessionHasErrors('site_ids');
        $this->actingAs($this->admin)->post(route('parametrage.caisses.store'), $base + ['code' => 'OM2', 'type' => 'orange_money', 'site_ids' => [$this->conakry->id]])
            ->assertSessionHasErrors('site_ids');
        $this->actingAs($this->admin)->post(route('parametrage.caisses.store'), $base + ['code' => 'MUL-ESP', 'type' => 'especes', 'site_ids' => [$this->conakry->id, $this->boke->id]])
            ->assertSessionHasNoErrors();
        $this->assertSame('Boke, Conakry', Caisse::where('code', 'MUL-ESP')->sole()->sites_libelle);

        $this->actingAs($this->admin)->put(route('parametrage.caisses.update', $this->om), [
            'code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money', 'site_ids' => [$this->conakry->id],
        ])->assertSessionHasErrors(['site_ids' => 'La caisse Orange Money ne sert aucun site.']);
        $this->assertSame('Aucun site', $this->om->fresh()->sites_libelle);
    }

    /** RG-M03-27 : site analytique modifiable à la saisie ; la caisse émettrice et la caisse qui paie ne changent pas */
    public function test_le_site_analytique_ne_change_pas_la_caisse(): void
    {
        $id = $this->actingAs($this->souadou)->postJson(route('api.bons.creer'), ['type_bon' => 'BD', 'code_analytique' => 'ADAZZZ'])->assertCreated()->json('bon.id');
        $bon = BonCaisse::findOrFail($id);
        $this->assertSame('Conakry', $bon->site);

        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['site' => 'Boke', 'etape' => 1])->assertOk()
            ->assertJsonMissingPath('erreurs.site');
        /* Plafond de retrait de la caisse qui paie (20 M à Conakry), pas de celle du site analytique (1 M à Boké) */
        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['etape' => 3,
            'motif' => 'Pièces pour une intervention à Boké', 'categorie_depense' => 'fournitures', 'montant' => 5000000, 'mode_paiement' => 'especes'])
            ->assertOk()->assertJsonMissingPath('erreurs.mode_paiement');
        $bon->refresh();
        $this->assertSame('Boke', $bon->site);
        $this->assertSame([$this->especesConakry->id, $this->especesConakry->id], [$bon->caisse_emettrice_id, $bon->caisse_id]);

        $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['site' => 'Nulle part'])->assertStatus(422);
    }

    /** RG-M03-27 : le CDG corrige le site analytique à son étape, avec trace */
    public function test_le_cdg_corrige_le_site_analytique(): void
    {
        $cdg = User::factory()->role('controle_gestion')->create(['prenom' => 'Saliou', 'name' => 'BOIRO']);
        $bon = BonCaisse::factory()->statut('EN_ATTENTE_CDG')->create(['site' => 'Conakry', 'service' => 'Aftermarket', 'code_analytique' => 'ADAZZZ',
            'montant' => 400000, 'date_soumission' => now(), 'caisse_emettrice_id' => $this->especesConakry->id]);
        $bon->creerEtapesValidation();
        $bon->validations()->where('role', 'responsable_service')->update(['statut' => 'approuve']);

        $this->actingAs($cdg)->get(route('validations.show', $bon))->assertInertia(fn (Assert $page) => $page->where('sitesActifs', ['Boke', 'Conakry', 'Sangaredi']));
        $this->actingAs($cdg)->post(route('validations.approuver', $bon), ['site' => 'Inconnu'])->assertSessionHasErrors('site');
        $this->actingAs($cdg)->post(route('validations.approuver', $bon), ['site' => 'Sangaredi'])->assertSessionHas('success');

        $this->assertSame('Sangaredi', $bon->fresh()->site);
        $trace = HistoriqueAction::where('bon_caisse_id', $bon->id)->where('action', HistoriqueAction::ACTION_MODIFICATION_SITE)->sole();
        $this->assertSame('Site analytique corrigé par le CDG : Conakry → Sangaredi', $trace->commentaire);
        $this->assertSame($cdg->id, $trace->utilisateur_id);
    }
}
