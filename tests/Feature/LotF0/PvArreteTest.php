<?php

namespace Tests\Feature\LotF0;

use App\Models\Caisse;
use App\Models\MouvementCaisse;
use App\Models\PvArrete;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Lot F0 (spec v2.4, RG-M10-02, RG-M10-03, Annexe 2 ; billetage et PV avant le réapprovisionnement, décision du 19/10) :
 * PV d'arrêté par caisse, trois signatures, écart → ajustement d'inventaire validé par le DAF avec motif.
 */
class PvArreteTest extends TestCase
{
    use RefreshDatabase;

    private Caisse $conakry;
    private Caisse $boke;
    private User $caissier;
    private User $tresoriere;
    private User $chefComptable;
    private User $daf;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->conakry = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => Site::factory()->conakry()->create()->id, 'solde' => 1250000]);
        $this->boke = Caisse::factory()->create(['code' => 'BOK-ESP', 'libelle' => 'Caisse Boké', 'site_id' => Site::factory()->create(['nom' => 'Boke'])->id, 'solde' => 300000]);
        $this->caissier = User::factory()->role('caissier')->create(['prenom' => 'Youssouf', 'name' => 'TOURE', 'caisse_id' => $this->conakry->id]);
        $this->tresoriere = tap(User::factory()->create(['prenom' => 'Mariamagbè', 'name' => 'CAMARA']), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->chefComptable = tap(User::factory()->create(['prenom' => 'Mamadou Alpha', 'name' => 'BAH']), fn (User $u) => $u->ajouterRoles(['chef_comptable']));
        $this->daf = User::factory()->role('daf')->create();
    }

    /** Billetage 1 200 000 GNF pour un solde comptable de 1 250 000 : manquant de 50 000 */
    private function etablir(User $auteur, Caisse $caisse, array $billetage = ['20000' => 50, '10000' => 20])
    {
        return $this->actingAs($auteur)->post(route('pv-arrete.store'), [
            'caisse_id' => $caisse->id, 'motif' => 'inventaire_mensuel', 'billetage' => $billetage, 'observations' => 'Inventaire de fin de mois',
        ]);
    }

    public function test_le_gerant_etablit_le_pv_de_sa_caisse(): void
    {
        $this->etablir($this->caissier, $this->conakry)->assertRedirect()->assertSessionHas('success');
        $pv = PvArrete::sole();
        $this->assertSame(['1200000.00', '1250000.00', '-50000.00'], [$pv->solde_physique, $pv->solde_comptable, $pv->ecart]);
        $this->assertSame(50, $pv->billetage['20000']);
        $this->assertSame(0, $pv->billetage['50']);
        $this->assertMatchesRegularExpression('/^PV-CKY-\d{8}-1$/', $pv->numero);

        /* Pas sur la caisse d'un autre ; il ne voit que les PV de sa caisse */
        $this->etablir($this->caissier, $this->boke)->assertRedirect();
        $this->assertSame(1, PvArrete::count());
        $pvBoke = PvArrete::create(['caisse_id' => $this->boke->id, 'numero' => 'PV-BOK-1', 'motif' => 'cloture', 'arrete_le' => now(),
            'solde_physique' => 300000, 'solde_comptable' => 300000, 'ecart' => 0, 'etabli_par' => $this->tresoriere->id]);
        $this->actingAs($this->caissier)->get(route('pv-arrete.index'))->assertInertia(fn (Assert $page) => $page->has('pvs.data', 1)->where('pvs.data.0.numero', $pv->numero));
        $this->actingAs($this->caissier)->get(route('pv-arrete.show', $pvBoke))->assertForbidden();
        $this->actingAs($this->tresoriere)->get(route('pv-arrete.index'))->assertInertia(fn (Assert $page) => $page->has('pvs.data', 2));
        $this->actingAs($this->caissier)->get(route('pv-arrete.pdf', $pv))->assertOk();
    }

    /** RG-M10-02 : trois signatures par trois personnes ; RG-M10-03 : l'écart devient un ajustement en attente du DAF */
    public function test_trois_signatures_puis_ajustement_d_inventaire(): void
    {
        $this->etablir($this->caissier, $this->conakry);
        $pv = PvArrete::sole();

        $this->actingAs($this->tresoriere)->post(route('pv-arrete.signer', $pv), ['signature' => 'gerant'])->assertSessionHasErrors('signature');
        $this->actingAs($this->caissier)->post(route('pv-arrete.signer', $pv), ['signature' => 'gerant'])->assertSessionHas('success', 'Signature enregistrée (1 / 3).');
        $this->actingAs($this->caissier)->get(route('pv-arrete.show', $pv))->assertInertia(fn (Assert $page) => $page->where('signaturesPossibles', []));

        /* Le rattachement à la caisse (caisse qui émet ses bons) ne fait pas d'un demandeur ou du Chef comptable un gérant */
        $this->chefComptable->update(['caisse_id' => $this->conakry->id]);
        $demandeur = User::factory()->create(['caisse_id' => $this->conakry->id]);
        $this->actingAs($this->chefComptable->fresh())->get(route('pv-arrete.show', $pv))
            ->assertInertia(fn (Assert $page) => $page->where('signaturesPossibles', ['chef_comptable']));
        $this->actingAs($demandeur)->get(route('pv-arrete.index'))->assertForbidden();

        /* Une même personne ne signe pas deux fois, même avec deux rôles */
        $this->caissier->ajouterRoles(['tresorerie']);
        $this->actingAs($this->caissier)->post(route('pv-arrete.signer', $pv), ['signature' => 'tresorerie'])->assertSessionHasErrors('signature');

        $this->actingAs($this->tresoriere)->post(route('pv-arrete.signer', $pv), ['signature' => 'tresorerie'])->assertSessionHas('success');
        $this->assertNull($pv->fresh()->ajustement_id);
        $this->actingAs($this->chefComptable)->post(route('pv-arrete.signer', $pv), ['signature' => 'chef_comptable'])
            ->assertSessionHas('success', "PV {$pv->numero} signé. L'écart de " . \App\Support\Format::montant(50000) . ' attend la validation du DAF.');

        $ajustement = $pv->fresh()->ajustement;
        $this->assertSame(['ajustement_inventaire', 'en_attente', '50000.00', 'sortie'], [$ajustement->type, $ajustement->statut, $ajustement->montant, $ajustement->metadata['sens']]);
        $this->assertSame(1250000.0, (float) $this->conakry->fresh()->solde);

        /* Le DAF seul valide, avec un motif ; le manquant est débité */
        $dp = User::factory()->role('directeur_pays')->create();
        $this->actingAs($dp)->post(route('mouvements-caisse.valider', $ajustement), ['commentaire' => 'Écart constaté au comptage'])->assertForbidden();
        $this->actingAs($this->daf)->post(route('mouvements-caisse.valider', $ajustement))->assertSessionHasErrors('commentaire');
        $this->actingAs($this->daf)->post(route('mouvements-caisse.valider', $ajustement), ['commentaire' => 'Erreur de rendu de monnaie constatée'])->assertSessionHas('success');
        $this->assertSame(1200000.0, (float) $this->conakry->fresh()->solde);
        $this->assertSame('valide', $ajustement->fresh()->statut);
    }

    public function test_sans_ecart_aucun_ajustement(): void
    {
        $this->etablir($this->tresoriere, $this->boke, ['20000' => 15]);
        $pv = PvArrete::sole();
        $this->assertSame('0.00', $pv->ecart);
        $gerantBoke = User::factory()->role('caissier')->create(['caisse_id' => $this->boke->id]);
        foreach ([[$gerantBoke, 'gerant'], [$this->tresoriere, 'tresorerie'], [$this->chefComptable, 'chef_comptable']] as [$signataire, $signature]) {
            $this->actingAs($signataire)->post(route('pv-arrete.signer', $pv), ['signature' => $signature])->assertSessionHas('success');
        }
        $this->assertTrue($pv->fresh()->estSigne());
        $this->assertNull($pv->fresh()->ajustement_id);
        $this->assertSame(0, MouvementCaisse::count());
    }

    /** Caisse Orange Money : solde lu sur la plateforme, pas de billetage */
    public function test_caisse_orange_money(): void
    {
        $om = Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money', 'solde' => 800000]);
        $this->actingAs($this->tresoriere)->post(route('pv-arrete.store'), ['caisse_id' => $om->id, 'motif' => 'controle_inopine'])->assertSessionHasErrors('solde_physique');
        $this->actingAs($this->tresoriere)->post(route('pv-arrete.store'), ['caisse_id' => $om->id, 'motif' => 'controle_inopine', 'solde_physique' => 810000])->assertRedirect();
        $pv = PvArrete::sole();
        $this->assertNull($pv->billetage);
        $this->assertSame('10000.00', $pv->ecart);
    }
}
