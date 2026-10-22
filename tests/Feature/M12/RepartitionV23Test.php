<?php

namespace Tests\Feature\M12;

use App\Exports\TableauBordOdmExport;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\Client;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueOdm;
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
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * Spec v2.3 : prise en charge des frais d'un ODM par lignes et par parts (RG-M12-30 à RG-M12-37, M03, M14).
 *
 * Mission de référence (annexe B.1) : du 22/09 au 26/09, 5 jours et 4 nuits par participant :
 * indemnité 5 × 250 000 = 1 250 000, hébergement 4 × 500 000 = 2 000 000, total 3 250 000.
 */
class RepartitionV23Test extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $thierno;
    private User $yacouba;
    private User $chefAtelier;
    private User $daf;
    private User $dp;
    private User $caissier;
    private Client $smd;
    private Client $cbg;

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
        CodeAnalytique::factory()->create(['code' => 'TECSMD', 'service_id' => $technique->id]);

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

        $this->smd = Client::create(['code' => 'SMD', 'raison_sociale' => 'Société Minière de Dinguiraye']);
        $this->cbg = Client::create(['code' => 'CBG', 'raison_sociale' => 'Compagnie des Bauxites de Guinée']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** ODM avec l'OR 11022219 rattaché à SMD */
    private function brouillon(array $autres = [], ?array $participants = null): OrdreMission
    {
        return OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $autres + [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'clients' => [$this->smd->id], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26',
            'ordres_reparation' => [['numero' => '11022219', 'type' => 'vente', 'client_id' => $this->smd->id]],
            'participants' => $participants ?? [['user_id' => $this->thierno->id], ['user_id' => $this->yacouba->id]],
        ])->assertCreated()->json('odm.id'));
    }

    private function enregistrer(OrdreMission $odm, array $participants)
    {
        return $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['participants' => $participants]);
    }

    private function soumettre(OrdreMission $odm)
    {
        return $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm));
    }

    private function valider(OrdreMission $odm): OrdreMission
    {
        $this->soumettre($odm)->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm))->assertSessionHas('success');
        }

        return $odm->fresh();
    }

    /** Parts d'une ligne, au format de l'écran */
    private function parts(array ...$parts): array
    {
        return ['parts' => $parts];
    }

    private function partSmd(float $pourcentage, ?string $or = '11022219', ?string $commentaire = null): array
    {
        return ['payeur' => 'client', 'client_id' => $this->smd->id, 'ordre_reparation' => $or, 'pourcentage' => $pourcentage, 'commentaire' => $commentaire];
    }

    private function partNeemba(float $pourcentage, ?string $code = null): array
    {
        return ['payeur' => 'neemba', 'pourcentage' => $pourcentage, 'code_analytique' => $code];
    }

    private function ligne(OrdreMission $odm, User $user, string $element)
    {
        return $odm->participants()->where('user_id', $user->id)->sole()->lignes()->where('element', $element)->with('parts')->sole();
    }

    private function payer(BonCaisse $bon)
    {
        $bon->update(['statut' => 'APPROUVE']);
        OtpValidation::create(['bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false]);

        return $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money', 'reference_paiement' => 'MP261017.1530.A12345']);
    }

    /* ------------------------------------------------------------------ */

    /** RG-M12-30, RG-M12-31 : lignes avec quantité et taux, part par défaut Neemba 100 % */
    public function test_lignes_de_frais_et_part_par_defaut(): void
    {
        $odm = $this->brouillon();

        $ligne = $this->ligne($odm, $this->thierno, 'indemnite');
        $this->assertEquals([5, 250000, 1250000], [(float) $ligne->quantite, (float) $ligne->taux, (float) $ligne->montant]);
        $this->assertSame([['neemba', 100.0, null]], $ligne->parts->map(fn ($p) => [$p->payeur, (float) $p->pourcentage, $p->code_analytique])->all());
        $this->assertEquals(6500000, (float) $odm->montant_neemba);
        $this->assertEquals(0, (float) $odm->montant_a_refacturer);

        $this->actingAs($this->demandeur)->get(route('odm.edit', $odm))
            ->assertInertia(fn (Assert $page) => $page
                ->where('odm.calcul.participants.0.lignes.0.element', 'indemnite')
                ->where('odm.calcul.participants.0.lignes.1.element', 'hebergement')
                ->where('odm.calcul.participants.0.lignes.0.parts.0.code_analytique_effectif', 'TECZZZ')
                ->where('odm.calcul.recapitulatif.neemba', 6500000)
                ->where('odm.clients.0.code', 'SMD')
                ->where('odm.ordres_reparation.0.client.code', 'SMD'));
    }

    /** RG-M12-31, RG-M12-34, RG-M12-37 : répartition partagée, récapitulatif par client et par OR */
    public function test_repartition_partagee_et_recapitulatif(): void
    {
        $odm = $this->brouillon();
        $this->enregistrer($odm, [
            ['user_id' => $this->thierno->id, 'lignes' => ['hebergement' => $this->parts($this->partNeemba(60), $this->partSmd(40))]],
            ['user_id' => $this->yacouba->id],
        ])->assertOk()->assertJsonPath('odm.calcul.recapitulatif.client', 800000);

        $parts = $this->ligne($odm, $this->thierno, 'hebergement')->parts;
        $this->assertEquals([1200000, 800000], $parts->map(fn ($p) => (float) $p->montant)->all());
        $odm->refresh();
        $this->assertEquals(800000, (float) $odm->montant_a_refacturer);
        $this->assertEquals(5700000, (float) $odm->montant_neemba);

        $this->actingAs($this->demandeur)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page
                ->where('odm.calcul.recapitulatif.par_client.0.client.code', 'SMD')
                ->where('odm.calcul.recapitulatif.par_client.0.par_or.0.or', '11022219')
                ->where('odm.calcul.recapitulatif.par_client.0.par_or.0.montant', 800000));
    }

    /** RG-M12-31, RG-M12-33 : répartition incomplète, client manquant, commentaire obligatoire */
    public function test_regles_de_soumission_des_parts(): void
    {
        $odm = $this->brouillon(participants: [['user_id' => $this->thierno->id, 'lignes' => [
            'hebergement' => $this->parts($this->partNeemba(60)),
            'indemnite' => $this->parts(['payeur' => 'client', 'client_id' => null, 'ordre_reparation' => null, 'pourcentage' => 100]),
        ]]]);

        $messages = collect($this->soumettre($odm)->assertStatus(422)->json('erreurs'))->pluck('message');
        $this->assertContains('Répartition incomplète : hébergement de BAH Thierno est réparti à 60 % au lieu de 100 %.', $messages);
        $this->assertContains('Indiquez le client de la part « client » : indemnité de BAH Thierno.', $messages);
        $this->assertContains('indemnité de BAH Thierno : Part client sans OR. Ajoutez un commentaire pour les valideurs.', $messages);

        /* Corrigé : le commentaire accompagne l'avertissement, visible des valideurs */
        $this->enregistrer($odm, [['user_id' => $this->thierno->id, 'lignes' => [
            'hebergement' => $this->parts($this->partNeemba(100)),
            'indemnite' => $this->parts($this->partSmd(100, null, 'Refacturé sur le contrat cadre SMD')),
        ]]])->assertOk();
        $this->soumettre($odm)->assertOk();
        $this->actingAs($this->chefAtelier)->get(route('odm.show', $odm))
            ->assertInertia(fn (Assert $page) => $page
                ->where('odm.calcul.recapitulatif.avertissements.0.message', 'Part client sans OR.')
                ->where('odm.calcul.recapitulatif.avertissements.0.commentaire', 'Refacturé sur le contrat cadre SMD'));
    }

    /** RG-M12-33 : client différent de celui de l'OR */
    public function test_client_different_de_celui_de_l_or(): void
    {
        $odm = $this->brouillon(participants: [['user_id' => $this->thierno->id, 'lignes' => [
            'indemnite' => $this->parts(['payeur' => 'client', 'client_id' => $this->cbg->id, 'ordre_reparation' => '11022219', 'pourcentage' => 100]),
        ]]]);

        $messages = collect($this->soumettre($odm)->assertStatus(422)->json('erreurs'))->pluck('message');
        $this->assertContains("indemnité de BAH Thierno : Client différent de celui de l'OR 11022219. Ajoutez un commentaire pour les valideurs.", $messages);
    }

    /** Décision Q52 : le pourcentage fait foi, le montant de la part suit la ligne */
    public function test_les_parts_suivent_les_dates(): void
    {
        $odm = $this->brouillon(participants: [['user_id' => $this->thierno->id, 'lignes' => [
            'hebergement' => $this->parts($this->partNeemba(60), $this->partSmd(40)),
        ]]]);
        $this->assertEquals(800000, (float) $this->ligne($odm, $this->thierno, 'hebergement')->parts[1]->montant);

        $this->actingAs($this->demandeur)->putJson(route('api.odm.enregistrer', $odm), ['date_retour_prevue' => '2026-09-25'])->assertOk();
        $this->assertEquals(600000, (float) $this->ligne($odm, $this->thierno, 'hebergement')->parts[1]->montant);   // 40 % de 3 nuits
    }

    /** RG-M12-35 : répartition photographiée à la soumission, modifications tracées après un rejet */
    public function test_tracabilite_de_la_repartition(): void
    {
        $odm = $this->brouillon(participants: [['user_id' => $this->thierno->id]]);
        $this->enregistrer($odm, [['user_id' => $this->thierno->id, 'lignes' => ['hebergement' => $this->parts($this->partSmd(100))]]])->assertOk();
        $this->assertSame(0, HistoriqueOdm::where('ordre_mission_id', $odm->id)->where('action', 'repartition')->count());   // Q53

        $this->soumettre($odm)->assertOk();
        $soumission = HistoriqueOdm::where('ordre_mission_id', $odm->id)->where('action', 'soumission')->sole();
        $this->assertSame('client', $soumission->metadonnees['repartition'][1]['parts'][0]['payeur']);

        $this->actingAs($this->chefAtelier)->post(route('odm.rejeter', $odm), ['motif' => 'Revoir la prise en charge de l\'hébergement'])->assertSessionHas('success');
        $this->enregistrer($odm, [['user_id' => $this->thierno->id, 'lignes' => ['hebergement' => $this->parts($this->partNeemba(50), $this->partSmd(50))]]])->assertOk();

        $trace = HistoriqueOdm::where('ordre_mission_id', $odm->id)->where('action', 'repartition')->sole();
        $this->assertSame($this->demandeur->id, $trace->utilisateur_id);
        $changement = $trace->metadonnees['changements'][0];
        $this->assertSame('hebergement', $changement['element']);
        $this->assertEquals(100, $changement['avant'][0]['pourcentage']);
        $this->assertEquals([50, 50], array_column($changement['apres'], 'pourcentage'));
    }

    /** RG-M12-34 : le caissier et le CDG consultent la répartition */
    public function test_visibilite_du_caissier_et_du_cdg(): void
    {
        $odm = $this->brouillon(participants: [['user_id' => $this->thierno->id]]);
        $this->soumettre($odm)->assertOk();

        $this->actingAs($this->caissier)->get(route('odm.show', $odm))->assertOk();
        $this->actingAs(User::where('role', 'controle_gestion')->first())->get(route('odm.show', $odm))->assertOk();
    }

    /** RG-M12-15 (v2.3) et M03 : bon au total ; le bon montre la répartition et les frais OM au prorata (RG-M12-36) */
    public function test_bon_au_total_avec_sa_repartition(): void
    {
        $odm = $this->valider($this->brouillon(participants: [['user_id' => $this->thierno->id, 'lignes' => [
            'hebergement' => $this->parts($this->partNeemba(60), $this->partSmd(40)),
        ]]]));
        $this->assertTrue($odm->a_refacturer);

        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        $bon = BonCaisse::sole();
        $this->assertEquals(3250000, (float) $bon->montant);
        $this->payer($bon)->assertSessionHas('success');
        $this->assertEquals(32500, (float) $bon->fresh()->frais_om);

        $this->actingAs($this->demandeur)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page
                ->where('repartitionOdm.neemba', 2450000)
                ->where('repartitionOdm.client', 800000)
                ->where('repartitionOdm.frais_om_client', 8000)                 // 32 500 × 800 000 / 3 250 000
                ->where('repartitionOdm.participants.0.lignes.1.parts.1.client.code', 'SMD'));
    }

    /** RG-M12-35 : prolongation, parts reprises ; la nuitée de rattrapage suit l'hébergement */
    public function test_la_prolongation_reprend_les_parts(): void
    {
        $odm = $this->valider($this->brouillon(participants: [['user_id' => $this->thierno->id, 'lignes' => [
            'hebergement' => $this->parts($this->partSmd(100)),
        ]]]));

        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $odm), ['date_retour_prevue' => '2026-09-30']);
        $segment = OrdreMission::where('segment_precedent_id', $odm->id)->sole();

        $this->assertSame('neemba', $this->ligne($segment, $this->thierno, 'indemnite')->parts[0]->payeur);
        foreach (['hebergement', 'rattrapage'] as $element) {
            $part = $this->ligne($segment, $this->thierno, $element)->parts[0];
            $this->assertSame([$this->smd->id, '11022219'], [$part->client_id, $part->ordreReparation->numero]);
            $this->assertNotSame($odm->ordresReparation()->value('id'), $part->ordre_reparation_id);   // l'OR du segment
        }
        $this->assertEquals(2000000, (float) $segment->fresh()->montant_a_refacturer);   // 3 nuits + rattrapage
    }

    /** RG-M12-35 : retour anticipé, les parts suivent les lignes recalculées au réel */
    public function test_retour_anticipe_au_prorata(): void
    {
        $odm = $this->valider($this->brouillon(participants: [['user_id' => $this->thierno->id, 'lignes' => [
            'indemnite' => $this->parts($this->partSmd(100)),
        ]]]));
        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');

        Carbon::setTestNow('2026-09-25 08:00');
        $this->actingAs($this->demandeur)->post(route('odm.cloturer', $odm), ['date_retour_reelle' => '2026-09-24'])->assertSessionHas('success');
        $this->assertEquals(750000, (float) $odm->fresh()->montant_a_refacturer);   // 3 jours d'indemnité
    }

    /** ODM extérieur : au paiement, l'indemnité au taux du jour devient le montant de la ligne et de ses parts */
    public function test_odm_exterieur_parts_au_taux_du_paiement(): void
    {
        TauxChange::create(['date_taux' => today()->subDay(), 'taux' => 14.5]);
        $odm = $this->valider($this->brouillon(
            ['type' => 'exterieur', 'hebergement_exterieur' => 'filiale', 'destinations' => ['Abidjan']],
            [['user_id' => $this->thierno->id, 'lignes' => ['indemnite' => $this->parts($this->partSmd(100))]]],
        ));
        $this->assertEquals(1595000, (float) $odm->montant_a_refacturer);   // 110 000 FCFA × 14,5

        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        TauxChange::create(['date_taux' => today(), 'taux' => 15]);
        $this->payer(BonCaisse::sole())->assertSessionHas('success');

        $this->assertEquals(1650000, (float) $odm->fresh()->montant_a_refacturer);   // × 15, taux du paiement
    }

    /** M14 : à refacturer par client et par OR ; export « Frais à refacturer », une ligne par part client */
    public function test_tableau_de_bord_et_export(): void
    {
        $odm = $this->valider($this->brouillon(participants: [['user_id' => $this->thierno->id, 'lignes' => [
            'hebergement' => $this->parts($this->partNeemba(60, 'TECSMD'), $this->partSmd(40)),
        ]]]));

        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('parClient.0.client', 'Société Minière de Dinguiraye')
                ->where('parClient.0.or', '11022219')
                ->where('parClient.0.montant', 800000)
                ->where('parClient.0.paye', 0));

        Excel::fake();
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord.export'));
        Excel::assertDownloaded('ordres-de-mission-2026-09-01.xlsx', function (TableauBordOdmExport $export) use ($odm) {
            $lignes = $export->sheets()[3]->array();

            return $lignes[0][0] === 'ODM' && count($lignes) === 2
                && $lignes[1][0] === $odm->libelle && $lignes[1][3] === 'Hébergement' && $lignes[1][4] === 'Société Minière de Dinguiraye'
                && $lignes[1][5] === '11022219' && $lignes[1][6] === 'TECZZZ' && (float) $lignes[1][7] === 800000.0;
        });
    }
}
