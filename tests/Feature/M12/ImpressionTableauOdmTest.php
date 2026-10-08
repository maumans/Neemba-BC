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
            'destinations' => ['Kouroussa'], 'clients' => ['SMD'], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26',
            'prise_en_charge' => 'neemba', 'participants' => [['user_id' => $this->thierno->id]],
            'ordres_reparation' => [['numero' => '11022219', 'type' => 'garantie']],
        ])->json('odm.id'));
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm));
        }

        return $odm->fresh();
    }

    /** RG-M12-23 : ordre de mission et fiche d'indemnités, avec les visas horodatés */
    public function test_le_pdf_de_l_ordre_de_mission(): void
    {
        $odm = $this->odmValide();

        $this->actingAs($this->demandeur)->get(route('odm.pdf', $odm))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs(User::factory()->create(['service' => 'Logistique']))->get(route('odm.pdf', $odm))->assertForbidden();

        $html = view('exports.odm-pdf', [
            'odm' => PresentationOdm::detail($odm) + ['entite' => null],
            'etapes' => PresentationOdm::etapes($odm),
            'edite_le' => '01/09/2026 08:00',
        ])->render();

        foreach ([
            'ORDRE DE MISSION — AUTORISATION DE CIRCULER', "FICHE D'INDEMNITÉS DE MISSION", 'N°1/AT/26', 'BAH Thierno', '20865',
            '11022219 (Garantie)', 'Indemnité de repas', 'Indemnité de déplacement', 'Calcul par participant (figé à la validation)',
            'Arrêté à la somme de trois millions deux cent cinquante mille francs guinéens.',
            "Chef d'atelier / chef d'équipe", 'Thomas BANGOURA', 'Mohamed DIAKITE', 'Mamadou LO', 'Visé',
        ] as $texte) {
            $this->assertStringContainsString(e($texte), $html, $texte);
        }
    }

    /** US-14 : tableau de bord du DAF, missions en cours, dérogations, ODM à refacturer (SC-28) */
    public function test_le_tableau_de_bord_du_daf(): void
    {
        $neemba = $this->odmValide();
        $client = $this->odmValide(['prise_en_charge' => 'client', 'date_depart' => '2026-10-01', 'date_retour_prevue' => '2026-10-02']);
        $this->actingAs($this->demandeur)->post(route('odm.prolonger', $neemba), ['date_retour_prevue' => '2026-09-30']);

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
                ->where('aRefacturer.0.clients', 'SMD'));
        $this->actingAs($this->daf)->get(route('odm.index'))->assertInertia(fn (Assert $page) => $page->where('peutVoirTableauDeBord', true));

        Excel::fake();
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord.export'));
        Excel::assertDownloaded('ordres-de-mission-2026-09-01.xlsx', fn (TableauBordOdmExport $export) => count($export->sheets()) === 3
            && $export->sheets()[0]->array()[1][0] === 'N°1/AT/26');

        /* Une mission clôturée n'est plus en cours ; elle reste à refacturer */
        $this->actingAs($this->demandeur)->post(route('odm.cloturer', $client), ['date_retour_reelle' => '2026-10-02'])->assertSessionHas('success');
        $this->actingAs($this->daf)->get(route('odm.tableau-de-bord'))
            ->assertInertia(fn (Assert $page) => $page->where('indicateurs.missions_en_cours', 1)->where('indicateurs.a_refacturer', 1));
    }
}
