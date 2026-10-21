<?php

namespace Tests\Feature\M16;

use App\Exports\BonsCaisseExport;
use App\Exports\EcrituresComptablesExport;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\Client;
use App\Models\CodeAnalytique;
use App\Models\ExportComptable;
use App\Models\HistoriqueOdm;
use App\Models\Imputation;
use App\Models\Notification;
use App\Models\OrdreMission;
use App\Models\OtpValidation;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptabilite\ExportEcritures;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * M16 — Imputation comptable et export comptable (spec v2.3 §8.11, RG-M16-01 à RG-M16-06),
 * et M14 — export Excel des bons au format opérationnel (§8.9).
 */
class ImputationComptableTest extends TestCase
{
    use RefreshDatabase;

    private User $comptable;
    private User $chefComptable;
    private User $demandeur;
    private User $thierno;
    private User $chefAtelier;
    private User $daf;
    private User $dp;
    private User $caissier;
    private Client $smd;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 08:00');   // un mardi
        Parametre::majValeur('seuil_validation_dp', '1500000');
        foreach (['code_journal_especes' => 'CE01', 'code_journal_orange_money' => 'OM01', 'compte_frais_om' => '627800',
            'compte_odm_indemnite' => '625610', 'compte_odm_hebergement' => '625620', 'compte_frais_refacturables' => '467100'] as $cle => $valeur) {
            Parametre::majValeur($cle, $valeur);
        }

        $conakry = Site::factory()->conakry()->create();
        Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money Conakry', 'site_id' => $conakry->id])
            ->crediter(50000000, 'approvisionnement');
        Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id]);
        $technique = Service::create(['nom' => 'Technique', 'prefixe_odm' => 'AT']);
        CodeAnalytique::factory()->create(['code' => 'TECZZZ', 'service_id' => $technique->id]);

        $this->comptable = User::factory()->create(['name' => 'SOW', 'prenom' => 'Aminata']);
        $this->comptable->ajouterRoles(['comptable']);
        $this->chefComptable = User::factory()->create(['name' => 'BAH', 'prenom' => 'Mamadou Alpha']);
        $this->chefComptable->ajouterRoles(['chef_comptable']);
        $this->demandeur = User::factory()->create(['name' => 'KOLIE', 'prenom' => 'Philippe', 'service' => 'Technique']);
        $this->thierno = User::factory()->create(['name' => 'BAH', 'prenom' => 'Thierno', 'service' => 'Technique', 'statut_cadre' => 'non_cadre', 'numero_om' => '622334455']);
        $this->chefAtelier = User::factory()->create(['service' => 'Technique']);
        $this->chefAtelier->ajouterRoles(['chef_atelier']);
        User::factory()->role('responsable_service')->create(['service' => 'Technique']);
        User::factory()->role('controle_gestion')->create();
        $this->daf = User::factory()->role('daf')->create();
        $this->dp = User::factory()->role('directeur_pays')->create();
        $this->caissier = User::factory()->role('caissier')->create();
        $this->smd = Client::create(['code' => 'SMD', 'raison_sociale' => 'Société Minière de Dinguiraye']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bonPaye(array $valeurs = []): BonCaisse
    {
        return BonCaisse::factory()->statut('PAYE')->create($valeurs + [
            'numero' => 'BC-2026-0101', 'montant' => 500000, 'montant_verse' => 500000, 'mode_paiement' => 'especes',
            'mode_paiement_effectif' => 'especes', 'code_analytique' => 'TECZZZ', 'date_paiement' => now()->subHour(),
        ]);
    }

    private function enregistrer(BonCaisse $bon, array $lignes, bool $valider = true, ?string $motif = null, ?User $auteur = null)
    {
        return $this->actingAs($auteur ?? $this->comptable)->put(route('comptabilite.imputation.enregistrer', $bon), ['lignes' => $lignes, 'valider' => $valider, 'motif' => $motif]);
    }

    /** Bon d'un ODM : hébergement réparti 60 % Neemba / 40 % SMD, payé en Orange Money */
    private function bonOdmPaye(): BonCaisse
    {
        $partSmd = ['payeur' => 'client', 'client_id' => $this->smd->id, 'ordre_reparation' => '11022219', 'pourcentage' => 40];
        $id = $this->actingAs($this->demandeur)->postJson(route('api.odm.creer'), [
            'service' => 'Technique', 'code_analytique' => 'TECZZZ', 'technique' => false, 'but' => 'Dépannage d\'une chargeuse chez SMD',
            'destinations' => ['Kouroussa'], 'clients' => [$this->smd->id], 'date_depart' => '2026-09-22', 'date_retour_prevue' => '2026-09-26',
            'ordres_reparation' => [['numero' => '11022219', 'type' => 'vente', 'client_id' => $this->smd->id]],
            'participants' => [['user_id' => $this->thierno->id, 'lignes' => ['hebergement' => ['parts' => [['payeur' => 'neemba', 'pourcentage' => 60], $partSmd]]]]],
        ])->assertCreated()->json('odm.id');
        $odm = OrdreMission::findOrFail($id);
        $this->actingAs($this->demandeur)->postJson(route('api.odm.soumettre', $odm))->assertOk();
        foreach ([$this->chefAtelier, $this->daf, $this->dp] as $valideur) {
            $this->actingAs($valideur)->post(route('odm.viser', $odm))->assertSessionHas('success');
        }
        $this->actingAs($this->demandeur)->post(route('odm.generer-bons', $odm))->assertSessionHas('success');
        $bon = BonCaisse::where('odm_id', $odm->id)->sole();
        $bon->update(['statut' => 'APPROUVE']);
        OtpValidation::create(['bon_caisse_id' => $bon->id, 'code' => '123456', 'telephone' => '622000000',
            'expires_at' => now()->addMinutes(5), 'verified_at' => now(), 'is_used' => false]);
        $this->actingAs($this->caissier)->post(route('bons-caisse.payer', $bon), ['mode_paiement_effectif' => 'orange_money', 'reference_paiement' => 'MP261017.1530.A12345'])->assertSessionHas('success');

        return $bon->fresh();
    }

    /* ------------------------------------------------------------------ */

    public function test_droits_et_liste_des_bons_a_imputer(): void
    {
        $bon = $this->bonPaye();
        BonCaisse::factory()->statut('APPROUVE')->create(['numero' => 'BC-2026-0102']);   // pas encore payé

        $this->actingAs($this->demandeur)->get(route('comptabilite.index'))->assertForbidden();
        $this->actingAs($this->comptable)->get(route('comptabilite.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Comptabilite/Index')
                ->where('compteurs.a_imputer', 1)
                ->where('lignes.data.0.numero', 'BC-2026-0101')
                ->where('lignes.data.0.code_journal', 'CE01'));
        $this->actingAs($this->comptable)->get(route('comptabilite.imputation', BonCaisse::where('numero', 'BC-2026-0102')->sole()))
            ->assertSessionHas('error', "Ce bon n'est pas encore payé : il ne s'impute qu'après paiement.");
        $this->actingAs($this->comptable)->get(route('bons-caisse.show', $bon))
            ->assertInertia(fn (Assert $page) => $page->where('imputation.statut_label', 'À imputer'));
    }

    /** RG-M16-01 : compte obligatoire, somme égale au montant versé */
    public function test_bon_ordinaire_imputation_et_controles(): void
    {
        $bon = $this->bonPaye();
        $this->actingAs($this->comptable)->get(route('comptabilite.imputation', $bon))
            ->assertInertia(fn (Assert $page) => $page->component('Comptabilite/Imputation')
                ->where('imputation.lignes.0.compte', '')
                ->where('imputation.lignes.0.montant', 500000)
                ->where('bon.code_journal', 'CE01'));

        $ligne = ['compte' => '', 'libelle' => 'Achat de fournitures', 'montant' => 400000, 'code_analytique' => 'TECZZZ'];
        $this->enregistrer($bon, [$ligne])->assertSessionHasErrors(['lignes' => 'Ligne 1 : indiquez le compte comptable.']);
        $this->assertSame('brouillon', $bon->imputation->statut);

        $this->enregistrer($bon, [['compte' => '606100'] + $ligne])
            ->assertSessionHasErrors(['lignes' => 'La somme des lignes (' . \App\Support\Format::montant(400000) . ') doit être égale au montant versé (' . \App\Support\Format::montant(500000) . ').']);

        $this->enregistrer($bon, [['compte' => '606100', 'montant' => 300000] + $ligne, ['compte' => '606200', 'libelle' => 'Transport', 'montant' => 200000]])
            ->assertRedirect(route('comptabilite.index'))->assertSessionHas('success', 'Imputation du bon BC-2026-0101 validée.');
        $imputation = $bon->fresh()->imputation;
        $this->assertSame(['validee', 'CE01', $this->comptable->id], [$imputation->statut, $imputation->code_journal, $imputation->valide_par_id]);
        $this->assertEquals([300000, 200000], $imputation->lignes->pluck('montant')->map(fn ($m) => (float) $m)->all());
        $this->assertTrue($bon->historiqueActions()->where('action', 'imputation')->exists());
    }

    /** RG-M16-02 : BP au 421100, frais OM au compte paramétré */
    public function test_bp_et_frais_orange_money_pre_remplis(): void
    {
        $bon = $this->bonPaye(['type_bon' => 'BP', 'montant' => 1000000, 'frais_om' => 10000, 'montant_verse' => 1010000, 'mode_paiement_effectif' => 'orange_money']);

        $this->actingAs($this->comptable)->get(route('comptabilite.imputation', $bon));
        $lignes = $bon->fresh()->imputation->lignes;
        $this->assertSame([['421100', 1000000.0], ['627800', 10000.0]], $lignes->map(fn ($l) => [$l->compte, (float) $l->montant])->all());
        $this->assertSame('OM01', $bon->fresh()->imputation->code_journal);
    }

    /** RG-M16-05, RG-M16-06 : une ligne par part ; correction de la répartition avec motif, reportée sur l'ODM */
    public function test_bon_odm_une_ligne_par_part_et_correction(): void
    {
        $bon = $this->bonOdmPaye();
        $this->assertEquals(3282500, (float) $bon->montant_verse);   // 3 250 000 + 1 % de frais OM

        /* L'écran reçoit le détail de l'ODM (identifiant pour le lien), pas un simple indicateur */
        $this->actingAs($this->comptable)->get(route('comptabilite.imputation', $bon))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('bon.odm.id', $bon->odm_id)->has('bon.odm.libelle'));
        $lignes = $bon->fresh()->imputation->lignes;
        $this->assertSame([
            ['625610', 1250000.0, 'neemba', null],
            ['625620', 1200000.0, 'neemba', null],
            ['467100', 800000.0, 'client', '11022219'],
            ['627800', 24500.0, 'neemba', null],     // frais OM au prorata (RG-M12-36) : 32 500 × 2 450 000 / 3 250 000
            ['467100', 8000.0, 'client', null],
        ], $lignes->map(fn ($l) => [$l->compte, (float) $l->montant, $l->payeur, $l->ordre_reparation])->all());

        /* Correction : hébergement 50 / 50, sans motif puis avec motif */
        $saisie = $lignes->map(fn ($l) => $l->only(['nature', 'compte', 'libelle', 'montant', 'code_analytique', 'payeur', 'client_id', 'ordre_reparation', 'ligne_frais_id']))->all();
        $saisie[1]['montant'] = 1000000;
        $saisie[2]['montant'] = 1000000;
        $this->enregistrer($bon, $saisie)->assertSessionHasErrors(['motif' => "La répartition de l'ordre de mission est corrigée : indiquez le motif de la correction."]);
        $this->enregistrer($bon, $saisie, true, 'Contrat SMD : la moitié de l\'hébergement est refacturée')->assertSessionHas('success');

        $odm = OrdreMission::findOrFail($bon->odm_id);
        $this->assertEquals(1000000, (float) $odm->montant_a_refacturer);
        $parts = $odm->lignesFrais()->where('element', 'hebergement')->sole()->parts;
        $this->assertEquals([50, 50], $parts->map(fn ($p) => (float) $p->pourcentage)->all());
        $trace = HistoriqueOdm::where('ordre_mission_id', $odm->id)->where('action', 'repartition')->sole();
        $this->assertSame(['imputation', 'Contrat SMD : la moitié de l\'hébergement est refacturée'], [$trace->metadonnees['source'], $trace->metadonnees['motif']]);
        $this->assertEquals([60, 40], array_column($trace->metadonnees['changements'][0]['avant'], 'pourcentage'));
    }

    /** RG-M16-03, RG-M16-06 : l'export emporte les imputations validées, qui ne se modifient plus */
    /** Bon marqué « issu d'un ODM » sans ODM rattaché (données anciennes) : l'écran s'ouvre, sans lien vers l'ODM */
    public function test_bon_odm_sans_odm_rattache(): void
    {
        $bon = $this->bonOdmPaye();
        $bon->update(['odm_id' => null]);
        $this->actingAs($this->comptable)->get(route('comptabilite.imputation', $bon))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('bon.odm', null));
    }

    public function test_export_des_ecritures(): void
    {
        $bon = $this->bonPaye();
        $this->actingAs($this->comptable)->get(route('comptabilite.imputation', $bon));
        $this->enregistrer($bon, [['compte' => '606100', 'libelle' => 'Achat de fournitures', 'montant' => 500000, 'code_analytique' => 'TECZZZ']])->assertSessionHas('success');

        Excel::fake();
        $this->actingAs($this->comptable)->post(route('comptabilite.exporter'), ['du' => '2026-09-01', 'au' => '2026-09-01', 'format' => 'csv'])->assertOk();
        $export = ExportComptable::sole();
        Excel::assertDownloaded(ExportEcritures::nomFichier($export, 'csv'), fn (EcrituresComptablesExport $e) => $e->getCsvSettings()['delimiter'] === ';');
        $this->assertSame('exportee', $bon->fresh()->imputation->statut);
        $this->assertSame([ExportEcritures::COLONNES, ['01/09/2026', 'BC-2026-0101', '606100', 'Achat de fournitures', 500000.0, 'CE01', 'TECZZZ', 'Conakry', 'NGU', '', '', '']],
            ExportEcritures::lignes($export));

        $this->enregistrer($bon, [['compte' => '606200', 'libelle' => 'Autre', 'montant' => 500000]])
            ->assertSessionHas('error', 'Cette imputation a été exportée : elle ne peut plus être modifiée.');
        $this->actingAs($this->comptable)->post(route('comptabilite.exporter'), ['du' => '2026-09-01', 'au' => '2026-09-01', 'format' => 'xlsx'])
            ->assertSessionHas('error', 'Aucune écriture imputée à exporter sur cette période.');
    }

    /** RG-M16-04 : alerte au Chef comptable à J+1 ouvré */
    public function test_alerte_des_bons_non_imputes(): void
    {
        $this->bonPaye(['date_paiement' => '2026-08-28 10:00']);   // vendredi : J+1 ouvré = lundi 31/08, dépassé le mardi 01/09
        $this->bonPaye(['numero' => 'BC-2026-0103', 'date_paiement' => '2026-09-01 07:00']);

        $this->artisan('comptabilite:alerter-non-imputes')->expectsOutputToContain('1 bon(s) en retard')->assertSuccessful();
        $alerte = Notification::where('destinataire_id', $this->chefComptable->id)->where('type', 'imputation_en_retard')->sole();
        $this->assertStringContainsString('BC-2026-0101', $alerte->message);
        $this->assertStringNotContainsString('BC-2026-0103', $alerte->message);
    }

    /** M14 : export Excel des bons ; une ligne par part de prise en charge pour un bon d'ODM */
    public function test_export_excel_des_bons(): void
    {
        $bon = $this->bonOdmPaye();
        $this->actingAs($this->demandeur)->get(route('bons-caisse.export'))->assertForbidden();

        Excel::fake();
        $this->actingAs(User::factory()->role('administrateur')->create())->get(route('bons-caisse.export'))->assertOk();   // vision globale
        Excel::assertDownloaded('bons-de-caisse-2026-09-01.xlsx', function (BonsCaisseExport $export) use ($bon) {
            $lignes = collect($export->array());
            $duBon = $lignes->filter(fn ($l) => $l[0] === $bon->numero)->values();
            $entetes = $lignes->first();
            $col = fn (string $nom) => array_search($nom, $entetes, true);

            return $duBon->count() === 3
                && $duBon[2][$col('Payeur')] === 'Client'
                && $duBon[2][$col('Client')] === 'Société Minière de Dinguiraye'
                && $duBon[2][$col('OR de la part')] === '11022219'
                && (float) $duBon[2][$col('Montant de la part (GNF)')] === 800000.0
                && (float) $duBon[0][$col('Montant versé (GNF)')] === 3282500.0;
        });
    }
}
