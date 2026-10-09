<?php

namespace Tests\Feature\M12;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\OrdreMission;
use App\Models\OtpValidation;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\TauxChange;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Prise en charge des frais d'un ODM ligne par ligne, pour chaque participant (Q49 à Q51).
 *
 * Mission de référence (annexe B.1) : du 22/09 au 26/09, 5 jours et 4 nuits par participant,
 * soit 625 000 + 625 000 d'indemnités et 2 000 000 d'hébergement (3 250 000).
 */
class PriseEnChargeOdmTest extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $thierno;
    private User $yacouba;
    private User $chefAtelier;
    private User $daf;
    private User $dp;
    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');
        Parametre::majValeur('seuil_validation_dp', '1500000');

        $conakry = Site::factory()->conakry()->create();
        Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $conakry->id])
            ->crediter(50000000, 'approvisionnement');
        Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id]);
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);

        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique']);
        $this->thierno = User::factory()->create(['name' => 'BAH', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre', 'numero_om' => '622334455']);
        $this->yacouba = User::factory()->create(['name' => 'BARRY', 'prenom' => 'Yacouba', 'service' => 'Technique', 'statut_cadre' => 'cadre', 'numero_om' => '622112233']);
        $this->chefAtelier = User::factory()->create(['service' => 'Technique']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        User::factory()->role('responsable_service')->create(['service' => 'Technique']);
        User::factory()->role('controle_gestion')->create();
        $this->daf = User::factory()->role('daf')->create();
        $this->dp = User::factory()->role('directeur_pays')->create();
        $this->caissier = User::factory()->role('caissier')->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function brouillon(array $autres = [], ?array $participants = null): OrdreMission
    {
        return OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $autres + [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'clients' => ['SMD'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26',
            'prise_en_charge' => 'neemba',
            'participants' => $participants ?? [['user_id' => $this->thierno->id], ['user_id' => $this->yacouba->id]],
        ])->assertCreated()->json('odm.id'));
    }

    private function valider(OrdreMission $odm): OrdreMission
    {
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm))->assertSessionHas('success');
        }

        return $odm->fresh();
    }

    private function participant(OrdreMission $odm, User $user)
    {
        return $odm->participants()->where('user_id', $user->id)->sole();
    }

    /* ------------------------------------------------------------------ */

    public function test_chaque_ligne_a_sa_prise_en_charge_et_l_entete_devient_mixte(): void
    {
        $odm = $this->brouillon(participants: [
            ['user_id' => $this->thierno->id, 'prises_en_charge' => ['hebergement' => 'client_direct']],
            ['user_id' => $this->yacouba->id],
        ]);

        $thierno = $this->participant($odm, $this->thierno);
        $this->assertEquals(3250000, (float) $thierno->total);                 // coût complet de la mission
        $this->assertEquals(1250000, (float) $thierno->montant_bon);           // indemnités seulement
        $this->assertEquals(2000000, (float) $thierno->montant_client_direct);
        $this->assertEquals(3250000, (float) $this->participant($odm, $this->yacouba)->montant_bon);
        $this->assertSame('mixte', $odm->prise_en_charge);

        $this->actingAs($this->demandeur)->get(route('odm.edit', $odm))
            ->assertInertia(fn (Assert $page) => $page
                ->where('odm.calcul.participants.0.lignes.2.cle', 'hebergement')
                ->where('odm.calcul.participants.0.lignes.2.prise_en_charge', 'client_direct')
                ->where('odm.calcul.participants.0.lignes.3.sans_objet', true)       // pas de nuitée de rattrapage
                ->where('odm.calcul.participants.0.frais_om', 12500)                  // 1 % de ce que Neemba verse
                ->where('odm.calcul.montant_client_direct', 2000000)
                ->where('odm.participants.0.prises_en_charge.hebergement', 'client_direct'));

        /* Retour à « tout Neemba » : l'en-tête suit les lignes */
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['participants' => [
            ['user_id' => $this->thierno->id, 'prises_en_charge' => ['hebergement' => 'neemba']],
            ['user_id' => $this->yacouba->id],
        ]])->assertOk();
        $this->assertSame('neemba', $odm->fresh()->prise_en_charge);
    }

    public function test_le_choix_de_l_entete_vaut_pour_les_nouveaux_participants(): void
    {
        $odm = $this->brouillon(['prise_en_charge' => 'client', 'mode_client' => 'avance'], []);
        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['participants' => [['user_id' => $this->thierno->id]]])->assertOk();

        $odm->refresh();
        $this->assertSame(['client', 'avance'], [$odm->prise_en_charge, $odm->mode_client]);
        $this->assertSame('client_avance', $this->participant($odm, $this->thierno)->prises_en_charge['hebergement']);
        $this->assertEquals(3250000, (float) $odm->montant_a_refacturer);
    }

    public function test_une_ligne_du_client_exige_le_client(): void
    {
        $odm = $this->brouillon(['clients' => []], [['user_id' => $this->thierno->id, 'prises_en_charge' => ['indemnite_1' => 'client_avance']]]);

        $erreurs = collect($this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertStatus(422)->json('erreurs'));
        $this->assertSame('Indiquez le client qui prend en charge les frais.', $erreurs->firstWhere('champ', 'clients')['message']);

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['participants' => [
            ['user_id' => $this->thierno->id, 'prises_en_charge' => ['indemnite_1' => 'fournisseur']],
        ]])->assertStatus(422);
    }

    public function test_les_bons_excluent_les_frais_payes_directement_par_le_client(): void
    {
        $odm = $this->valider($this->brouillon(participants: [
            ['user_id' => $this->thierno->id, 'prises_en_charge' => ['hebergement' => 'client_direct']],
            ['user_id' => $this->yacouba->id, 'prises_en_charge' => array_fill_keys(['indemnite_1', 'indemnite_2', 'hebergement', 'rattrapage'], 'client_direct')],
        ]));
        $this->assertFalse($odm->a_refacturer);

        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page
                ->where('generation.reste_a_generer', 1)
                ->where('generation.participants.1.sans_bon', true)
                ->where('odm.resume_prise_en_charge', '4 lignes sur 6 à la charge du client'));

        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        $bon = BonCaisse::sole();
        $this->assertSame($this->thierno->id, $bon->beneficiaire_id);
        $this->assertEquals(1250000, (float) $bon->montant);
        $this->assertStringContainsString('payés par le client)', $bon->motif);
        $this->assertSame('BONS_GENERES', $odm->fresh()->statut);
    }

    public function test_les_lignes_avancees_sont_a_refacturer(): void
    {
        $avance = ['indemnite_1' => 'client_avance', 'indemnite_2' => 'client_avance'];
        $odm = $this->valider($this->brouillon(participants: [
            ['user_id' => $this->thierno->id, 'prises_en_charge' => $avance],
            ['user_id' => $this->yacouba->id, 'prises_en_charge' => $avance],
        ]));

        $this->assertTrue($odm->a_refacturer);
        $this->assertEquals(2500000, (float) $odm->montant_a_refacturer);    // 2 × 1 250 000 d'indemnités

        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        $this->assertEquals([3250000, 3250000], BonCaisse::orderBy('id')->pluck('montant')->map(fn ($m) => (float) $m)->all());

        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('indicateurs.montant_a_refacturer', 2500000)
                ->where('aRefacturer.0.montant', 2500000)
                ->where('aRefacturer.0.total', 6500000));
    }

    public function test_odm_exterieur_indemnite_payee_par_le_client(): void
    {
        TauxChange::create(['date_taux' => today(), 'taux' => 15]);
        $odm = $this->valider($this->brouillon(
            ['type' => 'exterieur', 'hebergement_exterieur' => 'avant_depart', 'destinations' => ['Abidjan']],
            [['user_id' => $this->thierno->id, 'hebergement_facture' => 900000, 'prises_en_charge' => ['indemnite' => 'client_direct']]],
        ));

        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        $bon = BonCaisse::sole();
        $this->assertEquals(900000, (float) $bon->montant);              // la facture seule
        $this->assertEquals(0, (float) $bon->montant_fcfa);              // indemnité hors bon : rien à convertir au paiement
        $this->assertEquals(900000, (float) $bon->montant_gnf_fixe);
    }

    public function test_le_trop_percu_porte_sur_ce_que_neemba_a_verse(): void
    {
        $odm = $this->valider($this->brouillon(participants: [
            ['user_id' => $this->thierno->id, 'prises_en_charge' => ['hebergement' => 'client_direct']],
        ]));
        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        $bon = BonCaisse::sole();
        $bon->update(['statut' => 'APPROUVE']);
        OtpValidation::create(['bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false]);
        $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money'])->assertSessionHas('success');

        /* Retour le 24/09 : 3 jours d'indemnités au lieu de 5 ; l'hébergement, payé par le client, n'entre pas en compte */
        $this->actingAs($this->demandeur)->post(route('odm.cloturer', $odm), ['date_retour_reelle' => '2026-09-24'])->assertSessionHas('success');
        $this->assertEquals(500000, (float) $this->participant($odm, $this->thierno)->trop_percu);
    }

    public function test_la_prolongation_reprend_les_choix(): void
    {
        $odm = $this->valider($this->brouillon(participants: [
            ['user_id' => $this->thierno->id, 'prises_en_charge' => ['hebergement' => 'client_direct', 'indemnite_1' => 'client_avance']],
        ]));

        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), ['date_retour_prevue' => '2026-09-30']);
        $segment = OrdreMission::where('segment_precedent_id', $odm->id)->sole();
        $prises = $this->participant($segment, $this->thierno)->prises_en_charge;

        $this->assertSame('client_avance', $prises['indemnite_1']);
        $this->assertSame('client_direct', $prises['hebergement']);
        $this->assertSame('client_direct', $prises['rattrapage']);       // la nuitée suit l'hébergement
        /* Du 27/09 au 30/09 : 3 nuits (1 500 000) et la nuitée de rattrapage (500 000), payées par le client */
        $this->assertEquals(2000000, (float) $this->participant($segment, $this->thierno)->montant_client_direct);
    }
}
