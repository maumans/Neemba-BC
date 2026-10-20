<?php

namespace Tests\Feature\LotF2;

use App\Exceptions\ErreurMetier;
use App\Mail\ReportingCaisseQuotidien;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\ClotureCaisse;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use App\Models\Validation;
use App\Services\Caisse\ClotureJournaliere;
use App\Services\Caisse\ReportingCaisse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Lot F2a (spec v2.4, RG-M09-01 à RG-M09-05) : clôture journalière par caisse, journée close verrouillée, rapport
 * « REPORTING CAISSE » (contrôle avec la veille, encaissements, décaissements, onglet « Détail »), envoi avant 8 h.
 */
class ClotureJournaliereTest extends TestCase
{
    use RefreshDatabase;

    private Caisse $conakry;
    private User $caissier;
    private User $tresoriere;
    private BonCaisse $bon;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        /* Mercredi 21/10/2026 */
        $this->travelTo(Carbon::parse('2026-10-21 10:00'));
        Parametre::majValeur('clotures_caisse_depuis', '2026-10-01');
        $this->conakry = Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => Site::factory()->conakry()->create()->id,
            'seuil_alerte' => 0]);
        $this->caissier = User::factory()->role('caissier')->create(['prenom' => 'Youssouf', 'name' => 'TOURE', 'caisse_id' => $this->conakry->id]);
        $this->tresoriere = tap(User::factory()->create(['prenom' => 'Mariamagbè', 'name' => 'CAMARA']), fn (User $u) => $u->ajouterRoles(['tresorerie']));
        $chef = User::factory()->role('responsable_service')->create(['prenom' => 'Thierry', 'name' => 'GOMIS']);

        $this->conakry->crediter(10000000, 'solde_initial', ['date_ecriture' => Carbon::parse('2026-10-19 08:00')]);
        $this->bon = BonCaisse::factory()->approuve()->create(['montant' => 1200000, 'motif' => 'Achat de pièces pour le groupe électrogène',
            'beneficiaire' => 'Souadou BARRY', 'service' => 'Aftermarket', 'mode_paiement_effectif' => 'especes']);
        Validation::create(['bon_caisse_id' => $this->bon->id, 'niveau' => 1, 'role' => 'responsable_service', 'statut' => 'approuve', 'version' => 1,
            'validateur_id' => $chef->id, 'date_attribution' => Carbon::parse('2026-10-20 08:00'), 'date_validation' => Carbon::parse('2026-10-20 10:30')]);
        $this->conakry->debiter(1200000, 'paiement_bon', ['bon_caisse_id' => $this->bon->id, 'date_ecriture' => Carbon::parse('2026-10-20 11:00')]);
        $this->conakry->crediter(500000, 'encaissement_client', ['piece' => 'REC-CKY-2026-00001', 'libelle' => 'Encaissement client CBG',
            'date_ecriture' => Carbon::parse('2026-10-20 15:00')]);
    }

    public function test_cloture_automatique_et_rapport_de_la_journee(): void
    {
        $this->artisan('caisse:cloturer-journees', ['--date' => '2026-10-20'])->assertSuccessful();
        $this->assertSame(['2026-10-19', '2026-10-20'], ClotureCaisse::orderBy('date_journee')->get()->map(fn ($c) => $c->date_journee->toDateString())->all());
        $c = ClotureCaisse::whereDate('date_journee', '2026-10-20')->sole();
        $this->assertSame(['10000000.00', '500000.00', '1200000.00', '9300000.00', '10000000.00', '0.00', 2, true],
            [$c->solde_initial, $c->total_encaissements, $c->total_decaissements, $c->solde_final, $c->solde_veille, $c->ecart_controle, $c->nombre_operations, $c->automatique]);

        /* RG-M09-01, RG-M09-05 : libellé = motif du bon, n° de pièce = n° du bon ; détail avec valideurs et délais */
        $j = ReportingCaisse::journee($this->conakry, Carbon::parse('2026-10-20'));
        $this->assertSame([$this->bon->numero, 'Achat de pièces pour le groupe électrogène'], [$j['decaissements'][0]['piece'], $j['decaissements'][0]['libelle']]);
        $this->assertSame('REC-CKY-2026-00001', $j['encaissements'][0]['piece']);
        $this->assertSame(['valideur' => 'Thierry GOMIS', 'delai' => 2.5], $j['detail'][0]['etapes']['responsable_service']);
        $this->assertNull($j['detail'][0]['etapes']['daf']);

        /* Rien n'est refait au passage suivant */
        $this->artisan('caisse:cloturer-journees', ['--date' => '2026-10-20'])->assertSuccessful();
        $this->assertSame(2, ClotureCaisse::count());

        /* Pas de clôture après coup avant la mise en service */
        Parametre::majValeur('clotures_caisse_depuis', '2026-10-21');
        $boke = Caisse::factory()->create();
        $boke->crediter(1000, 'solde_initial', ['date_ecriture' => Carbon::parse('2026-10-15 08:00')]);
        $this->artisan('caisse:cloturer-journees')->assertSuccessful();
        $this->assertSame(['2026-10-21'], ClotureCaisse::where('caisse_id', $boke->id)->get()->map(fn ($c) => $c->date_journee->toDateString())->all());
    }

    /** RG-M09-03 : aucune opération sur une journée close ; seul le gérant clôture, une fois */
    public function test_journee_close_verrouillee(): void
    {
        ClotureJournaliere::cloturerEnAttente(Carbon::parse('2026-10-20'));
        try {
            $this->conakry->fresh()->crediter(1000, 'approvisionnement', ['date_ecriture' => Carbon::parse('2026-10-20 18:00')]);
            $this->fail('Écriture acceptée sur une journée close');
        } catch (ErreurMetier $e) {
            $this->assertSame('MSG-APP-105', $e->messageCle);
        }

        $autre = User::factory()->role('caissier')->create(['caisse_id' => Caisse::factory()->create()->id]);
        $this->actingAs($autre)->post(route('clotures-caisse.store'), ['caisse_id' => $this->conakry->id])->assertSessionHasErrors('general');
        $this->conakry->fresh()->debiter(100000, 'paiement_bon');
        $this->actingAs($this->caissier)->post(route('clotures-caisse.store'), ['caisse_id' => $this->conakry->id, 'observations' => 'RAS'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['9200000.00', false, $this->caissier->id], [ClotureCaisse::whereDate('date_journee', '2026-10-21')->sole()->solde_final,
            ClotureCaisse::whereDate('date_journee', '2026-10-21')->sole()->automatique, ClotureCaisse::whereDate('date_journee', '2026-10-21')->sole()->cloture_par]);
        $this->actingAs($this->caissier)->post(route('clotures-caisse.store'), ['caisse_id' => $this->conakry->id])->assertSessionHasErrors('date');

        /* Le paiement suivant est refusé jusqu'au lendemain */
        $this->expectException(ErreurMetier::class);
        $this->conakry->fresh()->debiter(1000, 'paiement_bon');
    }

    /** RG-M09-02 : solde initial ≠ solde final de la veille → écart */
    public function test_ecart_avec_la_veille(): void
    {
        ClotureJournaliere::cloturerEnAttente(Carbon::parse('2026-10-19'));
        ClotureCaisse::whereDate('date_journee', '2026-10-19')->update(['solde_final' => 9900000]);
        ClotureJournaliere::cloturerEnAttente(Carbon::parse('2026-10-20'));
        $this->assertSame('100000.00', ClotureCaisse::whereDate('date_journee', '2026-10-20')->sole()->ecart_controle);

        $this->actingAs($this->caissier)->get(route('clotures-caisse.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Caisses/Clotures/Index')
            ->where('caisses.0.peut_cloturer', true)
            ->where('clotures.data.0.ecart', 100000));
        $this->actingAs(User::factory()->create())->get(route('clotures-caisse.index'))->assertForbidden();
    }

    /** RG-M09-01 : PDF et Excel (une feuille par jour, onglet « Détail », ligne « Écart ») */
    public function test_rapport_pdf_et_excel(): void
    {
        ClotureJournaliere::cloturerEnAttente(Carbon::parse('2026-10-20'));
        $parametres = ['caisse' => $this->conakry->id, 'du' => '2026-10-19', 'au' => '2026-10-20'];
        $pdf = $this->actingAs($this->caissier)->get(route('clotures-caisse.reporting', ['format' => 'pdf'] + $parametres));
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));

        $excel = $this->actingAs($this->caissier)->get(route('clotures-caisse.reporting', ['format' => 'excel'] + $parametres));
        $excel->assertOk();
        $chemin = tempnam(sys_get_temp_dir(), 'rep') . '.xlsx';
        file_put_contents($chemin, $excel->getContent());
        $classeur = IOFactory::load($chemin);
        $this->assertSame(['19-10-2026 CKY-ESP', '20-10-2026 CKY-ESP', 'Détail'], $classeur->getSheetNames());
        $feuille = $classeur->getSheet(1)->toArray();
        $this->assertSame('REPORTING CAISSE', $feuille[0][0]);
        $this->assertContains(['Écart', 0], array_map(fn ($l) => [$l[0], is_numeric($l[1]) ? (int) $l[1] : $l[1]], $feuille));
        $this->assertSame($this->bon->numero, $classeur->getSheetByName('Détail')->getCell('C2')->getValue());

        /* Caisse non visible : refus */
        $boke = Caisse::factory()->create();
        $this->actingAs($this->caissier)->get(route('clotures-caisse.reporting', ['format' => 'pdf', 'caisse' => $boke->id, 'du' => '2026-10-20', 'au' => '2026-10-20']))
            ->assertForbidden();
    }

    /** RG-M09-04 : envoi aux destinataires de la caisse ; à défaut gestionnaire et trésorerie ; pas d'envoi sans opération */
    public function test_envoi_du_rapport_par_caisse(): void
    {
        Mail::fake();
        $chef = tap(User::factory()->create(), fn (User $u) => $u->ajouterRoles(['chef_comptable']));
        $this->conakry->update(['destinataires_rapport' => [$chef->id, $this->tresoriere->id]]);
        $boke = Caisse::factory()->create(['code' => 'BOK-ESP', 'libelle' => 'Caisse Boké']);
        $gestionnaire = User::factory()->create();
        $boke->update(['gestionnaire_id' => $gestionnaire->id]);
        $boke->crediter(800000, 'solde_initial', ['date_ecriture' => Carbon::parse('2026-10-19 08:00')]);
        $boke->debiter(50000, 'paiement_bon', ['date_ecriture' => Carbon::parse('2026-10-20 09:00')]);
        $sansOperation = Caisse::factory()->create();

        $this->artisan('rapports:envoyer-quotidien', ['--date' => '2026-10-20'])->assertSuccessful();
        Mail::assertSent(ReportingCaisseQuotidien::class, 2);
        Mail::assertSent(ReportingCaisseQuotidien::class, fn ($m) => $m->cloture->caisse_id === $this->conakry->id
            && $m->hasTo($chef->email) && $m->hasTo($this->tresoriere->email) && !$m->hasTo($this->caissier->email));
        Mail::assertSent(ReportingCaisseQuotidien::class, fn ($m) => $m->cloture->caisse_id === $boke->id
            && $m->hasTo($gestionnaire->email) && $m->hasTo($this->tresoriere->email));
        $this->assertNotNull(ClotureCaisse::where('caisse_id', $this->conakry->id)->whereDate('date_journee', '2026-10-20')->sole()->envoye_le);
        $this->assertNull(ClotureCaisse::where('caisse_id', $sansOperation->id)->first()?->envoye_le);

        /* Pièces jointes : PDF et Excel */
        $mail = new ReportingCaisseQuotidien(ClotureCaisse::where('caisse_id', $this->conakry->id)->whereDate('date_journee', '2026-10-20')->sole());
        $this->assertSame(['reporting-caisse-CKY-ESP-2026-10-20.pdf', 'reporting-caisse-CKY-ESP-2026-10-20.xlsx'], array_map(fn ($a) => $a->as, $mail->attachments()));
        $mail->assertSeeInHtml('9 300 000 GNF');

        /* Pas de second envoi */
        $this->artisan('rapports:envoyer-quotidien', ['--date' => '2026-10-20'])->assertSuccessful();
        Mail::assertSent(ReportingCaisseQuotidien::class, 2);
    }
}
