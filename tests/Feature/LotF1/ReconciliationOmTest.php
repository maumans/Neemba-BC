<?php

namespace Tests\Feature\LotF1;

use App\Models\Caisse;
use App\Models\Notification;
use App\Models\ReconciliationOm;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Shared\Date as DateExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Lot F1c (spec v2.4, RG-M08-13, §5.4, SC-35) : réconciliation hebdomadaire de la caisse Orange Money entre le brouillard et
 * le relevé de la plateforme, validée par la trésorière, le Chef comptable et le DAF.
 */
class ReconciliationOmTest extends TestCase
{
    use RefreshDatabase;

    private Caisse $om;
    private User $tresoriere;
    private User $chefComptable;
    private User $daf;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        /* Mercredi 21/10/2026 : la semaine écoulée va du lundi 12 au dimanche 18 */
        $this->travelTo(Carbon::parse('2026-10-21 10:00'));
        $this->om = Caisse::factory()->orangeMoney()->create(['code' => 'CKY-OM', 'libelle' => 'Caisse Orange Money', 'seuil_alerte' => 0]);
        $this->om->crediter(10000000, 'solde_initial', ['date_ecriture' => Carbon::parse('2026-10-01 08:00')]);
        $this->om->crediter(5000000, 'approvisionnement', ['date_ecriture' => Carbon::parse('2026-10-12 09:00'), 'libelle' => 'Virement bancaire']);
        $this->om->debiter(1200000, 'paiement_bon', ['date_ecriture' => Carbon::parse('2026-10-13 11:00'), 'libelle' => 'Bon BD-CKY-2026-0101']);
        $this->om->debiter(300000, 'paiement_bon', ['date_ecriture' => Carbon::parse('2026-10-14 15:00'), 'libelle' => 'Bon BD-CKY-2026-0102']);
        $this->om->debiter(100000, 'paiement_bon', ['date_ecriture' => Carbon::parse('2026-10-20 10:00'), 'libelle' => 'Hors période']);
        $this->tresoriere = tap(User::factory()->create(['prenom' => 'Mariamagbè', 'name' => 'CAMARA']), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $this->chefComptable = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['chef_comptable']));
        $this->daf = User::factory()->role('daf')->create();
    }

    /** Relevé exporté en CSV « à la française » : point-virgule, débit / crédit, montants avec espaces */
    private function releveCsv(): UploadedFile
    {
        $contenu = "Relevé Orange Money Business\n"
            . "Date;Référence;Libellé;Débit;Crédit;Solde\n"
            . "11/10/2026 18:00;TX000;Hors période;;250 000;10 250 000\n"
            . "12/10/2026 09:05;TX001;Dépôt banque;;5 000 000;15 000 000\n"
            . "14/10/2026 08:30;TX002;Transfert 622000001;1 200 000;;13 800 000\n"
            . "15/10/2026 10:00;TX003;Transfert 622000002;450 000;;13 350 000\n";

        return UploadedFile::fake()->createWithContent('releve.csv', $contenu);
    }

    private function etablir(User $u, array $champs = [])
    {
        return $this->actingAs($u)->post(route('reconciliations-om.store'), $champs + [
            'caisse_id' => $this->om->id, 'semaine' => '2026-10-14', 'releve' => $this->releveCsv(),
        ]);
    }

    public function test_rapprochement_du_brouillard_et_du_releve(): void
    {
        $this->etablir($this->tresoriere)->assertRedirect()->assertSessionHasNoErrors();
        $r = ReconciliationOm::sole();
        $this->assertSame(['ROM-CKY-2026-S42', '2026-10-12', '2026-10-18'], [$r->numero, $r->periode_debut->toDateString(), $r->periode_fin->toDateString()]);
        $this->assertSame(['10000000.00', '13500000.00', '13350000.00', '-150000.00'], [$r->solde_brouillard_debut, $r->solde_brouillard_fin, $r->solde_releve_fin, $r->ecart]);
        /* Dépôt et transfert de 1 200 000 (un jour d'écart) rapprochés ; restent 450 000 au relevé et 300 000 au brouillard */
        $this->assertSame(2, $r->lignes_rapprochees);
        $this->assertSame([450000.0], collect($r->lignes_releve)->whereNull('ecriture_id')->pluck('montant')->map(fn ($m) => (float) $m)->all());
        $this->assertSame(['Bon BD-CKY-2026-0102'], collect($r->ecritures_non_rapprochees)->pluck('libelle')->all());
        $this->assertTrue(Notification::where('destinataire_id', $this->chefComptable->id)->where('type', 'reconciliation_om')->exists());

        $this->actingAs($this->daf)->get(route('reconciliations-om.show', $r))->assertInertia(fn (Assert $page) => $page
            ->component('Caisses/ReconciliationOm/Show')
            ->has('reconciliation.releve_non_rapproche', 1)
            ->has('reconciliation.brouillard_non_rapproche', 1)
            ->where('reconciliation.releve_rapproche.0.ecriture', 'Virement bancaire')
            ->where('signaturesPossibles', ['daf']));
    }

    public function test_trois_signatures_puis_verrouillage(): void
    {
        $this->etablir($this->tresoriere);
        $r = ReconciliationOm::sole();
        $this->actingAs($this->chefComptable)->post(route('reconciliations-om.signer', $r), ['signature' => 'tresorerie'])->assertSessionHasErrors('signature');
        $this->actingAs($this->chefComptable)->post(route('reconciliations-om.signer', $r), ['signature' => 'chef_comptable'])->assertSessionHasNoErrors();

        /* Refaite avant validation : les signatures repartent de zéro */
        $this->etablir($this->tresoriere, ['solde_releve' => 13500000])->assertSessionHasNoErrors();
        $r->refresh();
        $this->assertSame([0, '0.00', 1], [$r->nombreSignatures(), $r->ecart, ReconciliationOm::count()]);

        foreach (['tresorerie' => $this->tresoriere, 'chef_comptable' => $this->chefComptable, 'daf' => $this->daf] as $signature => $u) {
            $this->actingAs($u)->post(route('reconciliations-om.signer', $r), ['signature' => $signature])->assertSessionHasNoErrors();
        }
        $this->assertSame('validee', $r->fresh()->statut);
        $this->assertTrue(Notification::where('destinataire_id', $this->tresoriere->id)->where('titre', 'Réconciliation Orange Money validée')->exists());
        $this->etablir($this->tresoriere)->assertSessionHasErrors('semaine');

        /* Une même personne ne signe qu'une fois */
        $double = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['tresorerie', 'chef_comptable']));
        $this->travelTo(Carbon::parse('2026-10-28 10:00'));
        $this->etablir($double, ['semaine' => '2026-10-21', 'solde_releve' => 13400000])->assertSessionHasNoErrors();
        $autre = ReconciliationOm::latest('id')->first();
        $this->actingAs($double)->post(route('reconciliations-om.signer', $autre), ['signature' => 'tresorerie']);
        $this->actingAs($double)->post(route('reconciliations-om.signer', $autre), ['signature' => 'chef_comptable'])->assertSessionHasErrors('signature');
    }

    public function test_refus_et_releve_excel(): void
    {
        $especes = Caisse::factory()->create(['site_id' => Site::factory()->conakry()->create()->id]);
        $this->etablir($this->tresoriere, ['caisse_id' => $especes->id])->assertSessionHasErrors('general');
        $this->etablir($this->chefComptable)->assertSessionHasErrors('general');
        $this->etablir($this->tresoriere, ['semaine' => '2026-10-26'])->assertSessionHasErrors('semaine');
        $this->etablir($this->tresoriere, ['releve' => UploadedFile::fake()->createWithContent('releve.csv', "Jour;Valeur\n12/10/2026;5000\n")])
            ->assertSessionHasErrors('releve');
        /* Sans colonne « Solde » : le solde de fin du relevé est demandé */
        $this->etablir($this->tresoriere, ['releve' => UploadedFile::fake()->createWithContent('releve.csv', "Date;Montant\n12/10/2026;5000000\n")])
            ->assertSessionHasErrors(['solde_releve' => __('MSG-APP-104')]);
        $this->actingAs(User::factory()->role('caissier')->create())->get(route('reconciliations-om.index'))->assertForbidden();

        /* Excel : dates au format Excel, montant signé */
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->fromArray([
            ['Date transaction', 'ID transaction', 'Montant (GNF)', 'Solde'],
            [DateExcel::PHPToExcel(Carbon::parse('2026-10-12 09:05')), 'TX001', 5000000, 15000000],
            [DateExcel::PHPToExcel(Carbon::parse('2026-10-13 12:00')), 'TX002', -1200000, 13800000],
            [DateExcel::PHPToExcel(Carbon::parse('2026-10-14 16:00')), 'TX003', -300000, 13500000],
        ]);
        $chemin = tempnam(sys_get_temp_dir(), 'rom') . '.xlsx';
        (new Xlsx($classeur))->save($chemin);
        $this->etablir($this->tresoriere, ['releve' => new UploadedFile($chemin, 'releve.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $r = ReconciliationOm::sole();
        $this->assertSame([3, [], '0.00'], [$r->lignes_rapprochees, $r->ecritures_non_rapprochees, $r->ecart]);
    }

    /** Rappel du lundi : réconciliation absente (trésorerie), puis signatures manquantes */
    public function test_rappel_hebdomadaire(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 09:00'));
        $this->artisan('caisse:rappeler-reconciliation-om')->assertSuccessful();
        $this->assertSame(1, Notification::where('destinataire_id', $this->tresoriere->id)->where('type', 'reconciliation_om')->count());
        $this->assertSame(0, Notification::where('destinataire_id', $this->daf->id)->where('type', 'reconciliation_om')->count());

        $this->etablir($this->tresoriere);
        $r = ReconciliationOm::sole();
        $this->actingAs($this->tresoriere)->post(route('reconciliations-om.signer', $r), ['signature' => 'tresorerie']);
        $this->artisan('caisse:rappeler-reconciliation-om')->assertSuccessful();
        $rappels = fn (User $u) => Notification::where('destinataire_id', $u->id)->where('titre', 'Réconciliation Orange Money en attente')->count();
        /* La trésorière a signé : le rappel va au Chef comptable et au DAF */
        $this->assertSame([1, 1, 1], [$rappels($this->tresoriere), $rappels($this->chefComptable), $rappels($this->daf)]);
    }
}
