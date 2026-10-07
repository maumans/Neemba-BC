<?php

namespace Tests\Feature\M03;

use App\Jobs\ProcessPieceJointeOcrJob;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\LectureTicket;
use App\Models\PieceJointe;
use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use App\Services\LectureTicket\LecteurLocal;
use App\Services\LectureTicket\LecteurTicket;
use App\Support\MontantEnLettres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Module M03 — pièces justificatives et lecture des tickets carburant (US-BC-08, US-BC-09, RG-BC-15 à RG-BC-23).
 */
class PiecesEtTicketsTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    private User $souadou;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        /* La chaîne d'archivage (OCR, classification) n'est pas l'objet de ces tests ; la lecture des tickets, si */
        Queue::fake([ProcessPieceJointeOcrJob::class]);

        $conakry = Site::factory()->conakry()->create();
        Caisse::factory()->create(['code' => 'CKY-ESP', 'libelle' => 'Caisse principale Conakry', 'site_id' => $conakry->id,
            'plafond_retrait' => 20000000, 'solde' => 30000000]);
        $aftermarket = Service::factory()->create(['nom' => 'Aftermarket']);
        CodeAnalytique::factory()->create(['code' => 'ADAZZZ', 'libelle' => 'Aftermarket', 'service_id' => $aftermarket->id]);

        $this->souadou = User::factory()->create([
            'prenom' => 'Souadou', 'name' => 'BARRY', 'site' => 'Conakry', 'service' => 'Aftermarket', 'telephone' => '622461261',
        ]);
        User::factory()->role('responsable_service')->create(['service' => 'Aftermarket']);
        User::factory()->role('controle_gestion')->create();
        User::factory()->role('daf')->create();
    }

    /* ------------------------------------------------------------------
     * RG-BC-16 — qualité
     * ------------------------------------------------------------------ */

    /** TC-BC-016 : 1 100 px → illisible ; 3 000 px → qualité moyenne ; 4 000 px → conforme */
    public function test_la_qualite_d_une_photo_depend_de_son_grand_cote(): void
    {
        $bon = $this->bon();

        foreach ([[1100, 'illisible', 94], [3000, 'moyenne', 257], [4000, 'conforme', 342]] as [$pixels, $qualite, $dpi]) {
            $this->deposer($bon, UploadedFile::fake()->image("facture_{$pixels}.jpg", $pixels, 600), 'facture')
                ->assertJsonPath('piece.qualite', $qualite)
                ->assertJsonPath('piece.dpi', $dpi);
        }
    }

    /** TC-BC-017 : PDF produit par un logiciel → conforme, sans contrôle de résolution */
    public function test_un_pdf_avec_du_texte_est_conforme_sans_controle_de_resolution(): void
    {
        $this->deposer($this->bon(), UploadedFile::fake()->createWithContent('bon_travail_11022219.pdf', $this->pdfTexte()), 'bon_travail')
            ->assertJsonPath('piece.qualite', 'conforme')
            ->assertJsonPath('piece.dpi', null)
            /* adresse relative : l'aperçu s'affiche quel que soit APP_URL */
            ->assertJsonPath('piece.url', fn (string $url) => str_starts_with($url, '/storage/pieces_jointes/'));
    }

    /** Une facture illisible ne compte pas comme justificatif et bloque la soumission */
    public function test_un_scan_illisible_ne_compte_pas_et_bloque_la_soumission(): void
    {
        $bon = $this->bon();
        $this->deposer($bon, UploadedFile::fake()->createWithContent('facture_scannee.pdf', $this->pdfScanne(1100, 800)), 'facture')
            ->assertJsonPath('piece.qualite', 'illisible');

        $this->etape4($bon)->assertJsonPath('erreurs.pieces.0', __('MSG-BC-017'));
        $controles = $this->controles($bon);
        $this->assertSame('bloquant', $controles[5]['niveau']);
        $this->assertSame('MSG-BC-018', $controles[5]['message_cle']);
    }

    public function test_une_piece_de_qualite_moyenne_est_signalee_sans_bloquer(): void
    {
        $bon = $this->bon();
        $this->deposer($bon, UploadedFile::fake()->image('facture.jpg', 3000, 2000), 'facture');

        $reponse = $this->actingAs($this->souadou)->getJson(route('api.bons.controles', $bon))->assertJsonPath('soumission_possible', true);
        $this->assertSame('avertissement', $reponse->json('controles.4.niveau'));
    }

    /* ------------------------------------------------------------------
     * RG-BC-19 — pièce déjà utilisée
     * ------------------------------------------------------------------ */

    /** TC-BC-018 */
    public function test_une_piece_deja_jointe_a_un_autre_bon_doit_etre_confirmee_et_justifiee(): void
    {
        $premier = $this->bon();
        $this->deposer($premier, UploadedFile::fake()->createWithContent('facture_batterie.pdf', $this->pdfTexte('Facture 4512')), 'facture');
        DB::table('bons_caisse')->where('id', $premier->id)->update(['numero' => 'BC-2026-0011', 'statut' => 'PAYE', 'date_soumission' => '2026-10-02 09:00:00']);

        $second = $this->bon();
        $piece = $this->deposer($second, UploadedFile::fake()->createWithContent('facture_copie.pdf', $this->pdfTexte('Facture 4512')), 'facture')
            ->assertJsonPath('piece.doublon.numero', 'BC-2026-0011')
            ->assertJsonPath('piece.doublon.confirme', false)
            ->assertJsonPath('piece.doublon.message', "Cette pièce a déjà été jointe au bon BC-2026-0011 du 02/10/2026. "
                . "Confirmez qu'elle concerne une autre dépense et expliquez pourquoi.")
            ->json('piece.id');

        $controles = $this->controles($second);
        $this->assertSame('bloquant', $controles[6]['niveau']);
        $this->assertSame('MSG-BC-019', $controles[6]['message_cle']);

        $url = route('api.bons.pieces.doublon', [$second, $piece]);
        $this->actingAs($this->souadou)->patchJson($url, ['justification' => 'Deuxième batterie, même fournisseur'])
            ->assertStatus(422)->assertJsonPath('errors.confirme.0', __('MSG-APP-009'));
        $this->actingAs($this->souadou)->patchJson($url, ['confirme' => true, 'justification' => 'Même'])
            ->assertStatus(422)->assertJsonPath('errors.justification.0', __('MSG-BC-005'));
        $this->actingAs($this->souadou)->patchJson($url, ['confirme' => true, 'justification' => 'Deuxième batterie, même fournisseur'])
            ->assertOk()->assertJsonPath('piece.doublon.confirme', true);

        $this->assertSame('avertissement', $this->controles($second)[6]['niveau']);

        /* Bandeau de la fiche, visible du contrôle de gestion */
        $this->actingAs($this->souadou)->get(route('bons-caisse.show', $second))
            ->assertInertia(fn ($page) => $page
                ->where('bonCaisse.pieces_jointes.0.doublon_de.bon_caisse.numero', 'BC-2026-0011')
                ->where('bonCaisse.pieces_jointes.0.justification_doublon', 'Deuxième batterie, même fournisseur'));
    }

    public function test_une_piece_d_un_bon_annule_n_est_pas_un_doublon(): void
    {
        $annule = $this->bon();
        $this->deposer($annule, UploadedFile::fake()->createWithContent('facture.pdf', $this->pdfTexte('Facture 77')), 'facture');
        DB::table('bons_caisse')->where('id', $annule->id)->update(['statut' => 'ANNULE']);

        $this->deposer($this->bon(), UploadedFile::fake()->createWithContent('facture.pdf', $this->pdfTexte('Facture 77')), 'facture')
            ->assertJsonPath('piece.doublon', null);
    }

    /* ------------------------------------------------------------------
     * US-BC-09 — lecture des tickets
     * ------------------------------------------------------------------ */

    /** Lecteur par défaut (décision Q8) : champs vides à remplir ; TC-BC-023 : tant que la lecture n'est pas validée, pas de soumission */
    public function test_sans_lecteur_automatique_le_ticket_se_saisit_et_doit_etre_valide(): void
    {
        $bon = $this->bon(['categorie_depense' => 'carburant', 'vehicule' => 'BE 3424', 'montant' => 597000]);
        $piece = $this->deposer($bon, UploadedFile::fake()->image('ticket.jpg', 4000, 1800), 'recu_carburant')
            ->assertJsonPath('piece.lecture.statut', 'indisponible')
            ->assertJsonPath('piece.lecture.valeurs', null)
            ->json('piece.id');

        $this->actingAs($this->souadou)->getJson(route('api.bons.controles', $bon))
            ->assertJsonPath('soumission_possible', false)
            ->assertJsonPath('controles.6.niveau', 'bloquant')
            ->assertJsonPath('controles.6.message_cle', 'MSG-APP-005');

        $url = route('api.bons.pieces.lecture.valider', [$bon, $piece]);
        $this->actingAs($this->souadou)->postJson($url, ['valeurs' => $this->ticket(), 'confirmes' => ['station', 'date']])
            ->assertStatus(422)->assertJsonPath('message_cle', 'MSG-APP-008');
        $this->actingAs($this->souadou)->postJson($url, ['valeurs' => ['date' => now()->addDay()->toDateString()] + $this->ticket(), 'confirmes' => LectureTicket::CHAMPS])
            ->assertStatus(422)->assertJsonPath('errors.date.0', __('MSG-APP-006'));
        $this->actingAs($this->souadou)->postJson($url, ['valeurs' => $this->ticket(), 'confirmes' => LectureTicket::CHAMPS])
            ->assertOk()
            ->assertJsonPath('piece.lecture.statut', 'validee')
            ->assertJsonPath('piece.lecture.avertissements', []);

        $this->actingAs($this->souadou)->getJson(route('api.bons.controles', $bon))
            ->assertJsonPath('soumission_possible', true)
            ->assertJsonPath('controles.6.niveau', 'ok');
    }

    /** TC-BC-020, TC-BC-021 : prix au litre comparé au prix de référence (12 000 GNF/L, ± 10 %) */
    public function test_un_prix_au_litre_atypique_est_signale(): void
    {
        $bon = $this->bon(['categorie_depense' => 'carburant', 'vehicule' => 'BE 3424', 'montant' => 600000]);

        $normal = $this->ticketValide($bon, ['montant' => 597000, 'litres' => 49.75, 'montant_lettres' => null]);
        $this->assertSame([], $normal->json('piece.lecture.avertissements'));

        $cher = $this->ticketValide($bon, ['montant' => 600000, 'litres' => 40, 'montant_lettres' => null]);
        $cher->assertJsonPath('piece.lecture.avertissements.0.message_cle', 'MSG-BC-022')
            ->assertJsonPath('piece.lecture.avertissements.0.message',
                'Prix au litre inhabituel : 15' . self::NBSP . '000 GNF/L (référence 12' . self::NBSP . '000 GNF/L). Vérifiez le montant et les litres.');
    }

    /** TC-BC-022 : 696 000 + 864 000 + 816 000 pour un bon de 2 500 000 → écart 124 000 */
    public function test_le_total_des_tickets_est_compare_au_montant_du_bon(): void
    {
        $bon = $this->bon(['categorie_depense' => 'carburant', 'vehicule' => 'BE 3424', 'montant' => 2500000]);
        foreach ([[696000, 58], [864000, 72], [816000, 68]] as [$montant, $litres]) {
            $this->ticketValide($bon, ['montant' => $montant, 'litres' => $litres, 'montant_lettres' => null]);
        }

        $controle = $this->controles($bon)[7];
        $this->assertSame('avertissement', $controle['niveau']);
        $this->assertSame('TICKETS_TOTAL', $controle['code']);
        $this->assertSame(['total' => 2376000, 'montant' => 2500000, 'ecart' => 124000], $controle['valeurs']);
        $this->assertStringStartsWith('Le total des tickets (2' . self::NBSP . '376' . self::NBSP . '000 GNF) diffère du montant du bon', $controle['message']);
    }

    /** RG-BC-22 : montant en chiffres et en lettres, immatriculation et véhicule du bon */
    public function test_les_incoherences_d_un_ticket_sont_signalees(): void
    {
        $bon = $this->bon(['categorie_depense' => 'carburant', 'vehicule' => 'BE 3424', 'montant' => 597000]);

        $this->ticketValide($bon, ['montant_lettres' => 'CINQ CENT QUATRE VINGT DIX SEPT MILLE FRANCS GUINEENS'])
            ->assertJsonPath('piece.lecture.avertissements', []);

        $avertissements = $this->ticketValide($bon, ['montant_lettres' => 'cinq cent mille francs', 'immatriculation' => 'RC-1234-A'])
            ->json('piece.lecture.avertissements');
        $this->assertSame(['MSG-BC-023', 'MSG-BC-026'], array_column($avertissements, 'message_cle'));
        $this->assertSame("L'immatriculation lue (RC-1234-A) est différente du véhicule du bon (BE 3424).", $avertissements[1]['message']);
    }

    /** RG-BC-21 : les valeurs lues ne sont retenues qu'après confirmation ; les corrections sont conservées */
    public function test_les_corrections_de_la_lecture_sont_conservees(): void
    {
        $this->app->bind(LecteurTicket::class, fn () => new class implements LecteurTicket {
            public function nom(): string
            {
                return 'essai';
            }

            public function lire(PieceJointe $piece): ?array
            {
                return [
                    'valeurs' => ['station' => 'TOTAL KALOUM', 'date' => '2026-09-18', 'litres' => '49,75', 'montant' => '597 000',
                        'montant_lettres' => 'cinq cent quatre-vingt-dix-sept mille', 'immatriculation' => 'be 3424'],
                    'confiances' => ['station' => 92, 'date' => 88, 'litres' => 61, 'montant' => 95, 'montant_lettres' => 70, 'immatriculation' => 55],
                ];
            }
        });
        $bon = $this->bon(['categorie_depense' => 'carburant', 'vehicule' => 'BE 3424', 'montant' => 597000]);

        $piece = $this->deposer($bon, UploadedFile::fake()->image('ticket.jpg', 4000, 1800), 'recu_carburant')
            ->assertJsonPath('piece.lecture.statut', 'terminee')
            ->assertJsonPath('piece.lecture.valeurs.montant', 597000)
            ->assertJsonPath('piece.lecture.valeurs.immatriculation', 'BE 3424')
            ->assertJsonPath('piece.lecture.confiances.litres', 61)
            ->json('piece.id');

        $this->actingAs($this->souadou)->postJson(route('api.bons.pieces.lecture.valider', [$bon, $piece]), [
            'valeurs' => ['station' => 'TOTAL KALOUM', 'date' => '2026-09-18', 'litres' => 49.5, 'montant' => 597000,
                'montant_lettres' => 'cinq cent quatre-vingt-dix-sept mille', 'immatriculation' => 'BE 3424'],
            'confirmes' => LectureTicket::CHAMPS,
        ])->assertOk()->assertJsonPath('piece.lecture.champs_corriges', ['litres']);

        $lecture = LectureTicket::firstWhere('piece_jointe_id', $piece);
        $this->assertSame(49.75, $lecture->valeurs_lues['litres']);
        $this->assertSame(49.5, $lecture->valeurs_validees['litres']);
        $this->assertSame($this->souadou->id, $lecture->validee_par);
    }

    /** RG-BC-20 : au-delà de 60 secondes, la lecture est abandonnée (saisie manuelle) */
    public function test_une_lecture_trop_longue_est_abandonnee(): void
    {
        $bon = $this->bon(['categorie_depense' => 'carburant', 'vehicule' => 'BE 3424']);
        $piece = $this->deposer($bon, UploadedFile::fake()->image('ticket.jpg', 4000, 1800), 'facture')->json('piece.id');
        LectureTicket::create(['piece_jointe_id' => $piece, 'statut' => 'en_cours', 'lecteur' => 'local', 'demarree_le' => now()->subSeconds(61)]);
        PieceJointe::whereKey($piece)->update(['type_document' => 'recu_carburant']);

        $this->actingAs($this->souadou)->getJson(route('api.bons.pieces.lecture', [$bon, $piece]))
            ->assertJsonPath('piece.lecture.statut', 'indisponible');
    }

    /* ------------------------------------------------------------------
     * Suppression et nouvelle version (E-03.6)
     * ------------------------------------------------------------------ */

    public function test_une_piece_deja_soumise_se_remplace_par_une_nouvelle_version(): void
    {
        $bon = $this->bon();
        $ancienne = $this->deposer($bon, UploadedFile::fake()->image('facture.jpg', 4000, 3000), 'facture')->json('piece.id');
        $this->actingAs($this->souadou)->postJson(route('api.bons.soumettre', $bon), [], ['Idempotency-Key' => 'cle-p1'])->assertOk();
        DB::table('bons_caisse')->where('id', $bon->id)->update(['statut' => 'REJETE']);
        $this->travel(1)->minutes();

        $this->actingAs($this->souadou)->deleteJson(route('api.bons.pieces.supprimer', [$bon, $ancienne]))
            ->assertStatus(409)->assertJsonPath('message_cle', 'MSG-APP-004');

        $nouvelle = $this->actingAs($this->souadou)
            ->postJson(route('api.bons.pieces.remplacer', [$bon, $ancienne]), ['fichier' => UploadedFile::fake()->image('facture_nette.jpg', 4000, 3000)])
            ->assertCreated()
            ->assertJsonPath('piece.version', 2)
            ->assertJsonPath('piece.type_document', 'facture')
            ->json('piece.id');

        $this->assertSame($nouvelle, PieceJointe::find($ancienne)->remplacee_par_id);
        $this->actingAs($this->souadou)->getJson(route('bons-caisse.edit', $bon));
        $this->assertSame([$nouvelle], $bon->piecesActives()->pluck('id')->all());

        /* Une pièce ajoutée pendant la correction n'a jamais été soumise : elle se supprime */
        $ajoutee = $this->deposer($bon, UploadedFile::fake()->image('recu.jpg', 4000, 3000), 'recu')
            ->assertJsonPath('piece.supprimable', true)->json('piece.id');
        $this->actingAs($this->souadou)->deleteJson(route('api.bons.pieces.supprimer', [$bon, $ajoutee]))->assertOk();
    }

    /* ------------------------------------------------------------------
     * Lecture d'un montant en lettres et d'un ticket
     * ------------------------------------------------------------------ */

    public function test_un_montant_ecrit_en_lettres_est_relu(): void
    {
        $cas = json_decode(file_get_contents(base_path('tests/fixtures/montants_lettres.json')), true)['cas'];
        foreach ($cas as [$montant, $lettres]) {
            $this->assertSame($montant, MontantEnLettres::lire($lettres), $lettres);
        }
        $this->assertSame(597000, MontantEnLettres::lire('CINQ CENT QUATRE VINGT DIX SEPT MILLE FRANCS GUINEENS'));
        $this->assertSame(864000, MontantEnLettres::lire('huit cent soixante-quatre mille GNF'));
        $this->assertNull(MontantEnLettres::lire('francs guinéens'));
    }

    public function test_le_lecteur_local_repere_les_champs_d_un_ticket(): void
    {
        $lecture = LecteurLocal::analyser(<<<'TICKET'
            STATION TOTAL KALOUM
            Date : 18/09/2026
            Gasoil 49,75 L
            Montant : 597 000 GNF
            Cinq cent quatre-vingt-dix-sept mille francs
            Immatriculation : BE 3424
            TICKET);

        $this->assertSame([
            'station' => 'STATION TOTAL KALOUM', 'date' => '2026-09-18', 'litres' => 49.75, 'montant' => 597000,
            'montant_lettres' => 'Cinq cent quatre-vingt-dix-sept mille francs', 'immatriculation' => 'BE 3424',
        ], $lecture['valeurs']);
        $this->assertSame(70, $lecture['confiances']['montant']);
    }

    /* ------------------------------------------------------------------
     * Outils
     * ------------------------------------------------------------------ */

    /** Brouillon complet (étapes 1 à 3) */
    private function bon(array $depense = []): BonCaisse
    {
        $id = $this->actingAs($this->souadou)->postJson(route('api.bons.creer'), $depense + [
            'type_bon' => 'BD', 'code_analytique' => 'ADAZZZ',
            'motif' => 'Achat de carburant pour le groupe électrogène', 'categorie_depense' => 'fournitures',
            'montant' => 450000, 'mode_paiement' => 'especes',
        ])->assertCreated()->json('bon.id');

        return BonCaisse::findOrFail($id);
    }

    private function deposer(BonCaisse $bon, UploadedFile $fichier, ?string $type): TestResponse
    {
        return $this->actingAs($this->souadou)
            ->postJson(route('api.bons.pieces.ajouter', $bon), array_filter(['fichier' => $fichier, 'type_document' => $type]))
            ->assertCreated();
    }

    /** Un ticket déposé puis validé */
    private function ticketValide(BonCaisse $bon, array $valeurs = []): TestResponse
    {
        static $numero = 0;
        $numero++;
        $piece = $this->deposer($bon, UploadedFile::fake()->image("ticket_{$numero}.jpg", 4000, 1800 + $numero), 'recu_carburant')->json('piece.id');

        return $this->actingAs($this->souadou)->postJson(route('api.bons.pieces.lecture.valider', [$bon, $piece]), [
            'valeurs' => $valeurs + $this->ticket(),
            'confirmes' => LectureTicket::CHAMPS,
        ])->assertOk();
    }

    private function ticket(): array
    {
        return [
            'station' => 'TOTAL KALOUM', 'date' => '2026-09-18', 'litres' => 49.75, 'montant' => 597000,
            'montant_lettres' => 'cinq cent quatre-vingt-dix-sept mille francs guinéens', 'immatriculation' => 'BE 3424',
        ];
    }

    private function etape4(BonCaisse $bon): TestResponse
    {
        return $this->actingAs($this->souadou)->patchJson(route('api.bons.enregistrer', $bon), ['etape' => 4])->assertOk();
    }

    /** Contrôles indexés par leur numéro */
    private function controles(BonCaisse $bon): array
    {
        $controles = $this->actingAs($this->souadou)->getJson(route('api.bons.controles', $bon))->assertOk()->json('controles');

        return array_column($controles, null, 'numero');
    }

    /** PDF produit par un logiciel : une police et du texte */
    private function pdfTexte(string $texte = 'Bon de travail OR 11022219'): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>endobj\n"
            . "4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            . "5 0 obj<</Length 44>>stream\nBT /F1 12 Tf 72 720 Td ({$texte}) Tj ET\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** PDF scanné : une seule image, sans texte */
    private function pdfScanne(int $largeur, int $hauteur): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</XObject<</Im1 4 0 R>>>>/Contents 5 0 R>>endobj\n"
            . "4 0 obj<</Type/XObject/Subtype/Image/Width {$largeur}/Height {$hauteur}/ColorSpace/DeviceGray/BitsPerComponent 8/Length 1>>stream\n0\nendstream endobj\n"
            . "5 0 obj<</Length 30>>stream\nq 595 0 0 842 0 0 cm /Im1 Do Q\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
