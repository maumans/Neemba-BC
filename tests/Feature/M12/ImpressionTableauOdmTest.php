<?php

namespace Tests\Feature\M12;

use App\Exports\TableauBordOdmExport;
use App\Models\CodeAnalytique;
use App\Models\OrdreMission;
use App\Models\Service;
use App\Models\User;
use App\Services\Odm\PresentationOdm;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * M12-7 — Impression de l'ODM (RG-M12-23) et tableau de bord du DAF (US-14, RG-M12-15) ; SC-28.
 */
class ImpressionTableauOdmTest extends TestCase
{
    use RefreshDatabase;

    private User $demandeur;
    private User $thierno;
    private User $chefAtelier;
    private User $daf;
    private User $dp;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');

        \App\Models\Site::factory()->conakry()->create();
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'libelle' => 'Atelier', 'service_id' => $technique->id]);
        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique']);
        $this->thierno = User::factory()->create(['name' => 'BAH', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre', 'matricule' => '20865']);
        $this->chefAtelier = User::factory()->create(['name' => 'BANGOURA', 'prenom' => 'Thomas', 'service' => 'Technique']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        $this->daf = User::factory()->role('daf')->create(['name' => 'DIAKITE', 'prenom' => 'Mohamed']);
        $this->dp = User::factory()->role('directeur_pays')->create(['name' => 'LO', 'prenom' => 'Mamadou']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function odmValide(array $autres = []): OrdreMission
    {
        $odm = OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), $autres + [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => true, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'clients' => [\App\Models\Client::firstOrCreate(['code' => 'SMD'], ['raison_sociale' => 'Société Minière de Dinguiraye'])->id], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26',
            'prise_en_charge' => 'neemba', 'participants' => [['user_id' => $this->thierno->id]],
            'ordres_reparation' => [['numero' => '11022219', 'type' => 'garantie']],
        ])->json('odm.id'));
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm));
        }

        return $odm->fresh();
    }

    private function htmlPdf(OrdreMission $odm): string
    {
        return view('exports.odm-pdf', ['odm' => PresentationOdm::pdf($odm), 'edite_le' => '01/09/2026 08:00'])->render();
    }

    /** RG-M12-23 : ordre de mission sur le modèle papier de Neemba, avec les visas horodatés */
    public function test_le_pdf_de_l_ordre_de_mission(): void
    {
        $odm = $this->odmValide();

        $this->actingAs($this->demandeur)->get(route('odm.pdf', $odm))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs(User::factory()->create(['service' => 'Logistique']))->get(route('odm.pdf', $odm))->assertForbidden();

        $html = $this->htmlPdf($odm);
        foreach ([
            'ORDRE DE MISSION', 'INDEMNITÉS N°1/AT/26 du 01/09/2026', 'Autorisons la(es) personne(s) suivante(s) à se rendre en mission à :',
            'Km 10 – Route du Niger', 'Kouroussa', 'Société Minière de Dinguiraye (SMD)', '11022219 (Garantie)',
            'BAH', 'Thierno', '20865', 'Indemnité de repas', 'Indemnité de déplacement', '125 000 / jour', '500 000 / nuit',
            '3 250 000', 'Entièrement à la charge de Neemba Guinée', 'Barèmes figés à la validation',
            'Arrêté à la somme de <em>trois millions deux cent cinquante mille francs guinéens</em>.',
            'Le Demandeur', 'Philippe KOLIE', "Chef d'atelier, chef d'équipe ou suppléant", 'Thomas BANGOURA',
            'Directeur Administratif et Financier', 'Mohamed DIAKITE', 'Directeur Pays', 'Mamadou LO', 'Visé', 'Signature / cachet',
        ] as $texte) {
            $this->assertStringContainsString(str_replace(['&lt;em&gt;', '&lt;/em&gt;'], ['<em>', '</em>'], e($texte)), str_replace(\App\Support\Format::ESPACE_INSECABLE, ' ', $html), $texte);
        }
        /* Validé : pas de filigrane ; rubrique SERVICE cochée pour le service Technique */
        $this->assertStringNotContainsString('class="filigrane"', $html);
        $this->assertSame('SERVICE', PresentationOdm::pdf($odm)['rubrique']);
    }

    /** PDF : rubrique « Service concerné » du formulaire papier selon le service émetteur */
    public function test_la_rubrique_service_concerne_du_pdf(): void
    {
        $odm = $this->odmValide();

        foreach (['Aftermarket' => 'SERVICE', 'Location' => 'LOCATION', 'Logistique' => 'LOGISTIQUE', 'DAF' => 'ADMINISTRATION', 'Direction' => 'ADMINISTRATION'] as $service => $rubrique) {
            $odm->forceFill(['service' => $service])->save();
            $this->assertSame($rubrique, PresentationOdm::pdf($odm->fresh())['rubrique'], $service);
        }
        $this->assertStringContainsString('choix choix-on"><span class="case case-on">✓</span> ADMINISTRATION', $this->htmlPdf($odm->fresh()));
    }

    /** PDF : prise en charge partagée entre Neemba et un client (spec v2.3, RG-M12-31, RG-M12-34) */
    public function test_la_prise_en_charge_mixte_dans_le_pdf(): void
    {
        $smd = \App\Models\Client::firstOrCreate(['code' => 'SMD'], ['raison_sociale' => 'Société Minière de Dinguiraye']);
        $moitie = ['parts' => [
            ['payeur' => 'neemba', 'pourcentage' => 50],
            ['payeur' => 'client', 'client_id' => $smd->id, 'ordre_reparation' => '11022219', 'pourcentage' => 50],
        ]];
        $odm = $this->odmValide(['participants' => [['user_id' => $this->thierno->id, 'lignes' => ['indemnite' => $moitie, 'hebergement' => $moitie]]]]);

        $lignes = collect(PresentationOdm::pdf($odm)['prise_en_charge'])->keyBy('libelle');
        $this->assertSame([true, true, 312500.0, 312500.0, ['SMD']], [$lignes['Indemnité de repas']['neemba'], $lignes['Indemnité de repas']['client'],
            $lignes['Indemnité de repas']['montant_neemba'], $lignes['Indemnité de repas']['montant_client'], $lignes['Indemnité de repas']['clients']]);
        $this->assertSame(1000000.0, $lignes['Hébergement']['montant_client']);

        $html = str_replace(\App\Support\Format::ESPACE_INSECABLE, ' ', $this->htmlPdf($odm));
        foreach (['<span class="code-client">SMD</span>', '<td>dont Neemba Guinée</td><td class="droite">1 625 000</td>', 'dont SMD · OR 11022219'] as $texte) {
            $this->assertStringContainsString($texte, $html, $texte);
        }
    }

    /** PDF : filigrane tant que l'ODM n'est pas validé */
    public function test_le_filigrane_d_un_odm_non_valide(): void
    {
        $odm = OrdreMission::findOrFail($this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => true, 'but' => 'Visite',
            'destinations' => ['Boké'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-23',
            'participants' => [['user_id' => $this->thierno->id]],
        ])->json('odm.id'));

        $html = $this->htmlPdf($odm);
        $this->assertStringContainsString('<div class="filigrane">BROUILLON</div>', $html);
        $this->assertStringContainsString('INDEMNITÉS — brouillon', $html);
        $this->assertStringContainsString('Non soumis', $html);
        $this->actingAs($this->demandeur)->get(route('odm.pdf', $odm))->assertOk();
    }

    /** US-14 : tableau de bord du DAF, missions en cours, dérogations, ODM à refacturer (SC-28) */
    public function test_le_tableau_de_bord_du_daf(): void
    {
        $neemba = $this->odmValide();
        $partClient = ['parts' => [['payeur' => 'client', 'client_id' => \App\Models\Client::firstOrCreate(['code' => 'SMD'], ['raison_sociale' => 'Société Minière de Dinguiraye'])->id, 'ordre_reparation' => '11022219', 'pourcentage' => 100]]];
        $client = $this->odmValide(['date_depart' => '2026-10-01', 'date_retour_prevue' => '2026-10-02',
            'participants' => [['user_id' => $this->thierno->id, 'lignes' => ['indemnite' => $partClient, 'hebergement' => $partClient]]]]);
        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $neemba), ['date_retour_prevue' => '2026-09-30', 'motif_prolongation' => 'Travaux non terminés sur le site']);

        $this->actingAs($this->demandeur)->get(route('odm.tableau-de-bord'))->assertForbidden();
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord'))
            ->assertInertia(fn (Assert $page) => $page->component('Odm/TableauDeBord')
                ->where('indicateurs.missions_en_cours', 2)
                ->where('indicateurs.cout_en_cours', 4250000)          // 3 250 000 + 1 000 000 (le segment prolongé en brouillon ne compte pas)
                ->where('indicateurs.a_refacturer', 1)
                ->where('indicateurs.montant_a_refacturer', 1000000)
                ->where('missionsEnCours.0.numero', 'N°1/AT/26')
                ->where('missionsEnCours.0.jours', 5)
                ->where('aRefacturer.0.libelle', 'N°2/AT/26')
                ->where('aRefacturer.0.or', '11022219 (Garantie)')
                ->where('aRefacturer.0.clients', 'Société Minière de Dinguiraye')
                ->where('parClient.0.client', 'Société Minière de Dinguiraye')
                ->where('parClient.0.or', '11022219')
                ->where('parClient.0.montant', 1000000));
        $this->actingAs($this->daf)->get(route('odm.index'))->assertInertia(fn (Assert $page) => $page->where('peutVoirTableauDeBord', true));

        Excel::fake();
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord.export'));
        Excel::assertDownloaded('ordres-de-mission-2026-09-01.xlsx', fn (TableauBordOdmExport $export) => count($export->sheets()) === 5   // spec v2.4 : onglet « OR à régulariser »
            && $export->sheets()[0]->array()[1][0] === 'N°1/AT/26');

        /* Une mission clôturée n'est plus en cours ; elle reste à refacturer */
        Carbon::setTestNow('2026-10-03 08:00');
        $this->actingAs($this->demandeur)->post(route('odm.cloturer', $client), ['date_retour_reelle' => '2026-10-02'])->assertSessionHas('success');
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord'))
            ->assertInertia(fn (Assert $page) => $page->where('indicateurs.missions_en_cours', 1)->where('indicateurs.a_refacturer', 1));
    }
}
