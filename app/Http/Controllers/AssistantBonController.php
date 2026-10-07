<?php

namespace App\Http\Controllers;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\PieceJointe;
use App\Models\User;
use App\Services\BonCaisse\AnnulerBon;
use App\Services\BonCaisse\CircuitPrevisionnel;
use App\Services\BonCaisse\ControlesBon;
use App\Services\BonCaisse\EnregistrementBon;
use App\Services\BonCaisse\PiecesBon;
use App\Services\BonCaisse\ReglesSaisie;
use App\Services\BonCaisse\SoumettreBon;
use App\Services\LectureTicket\LectureTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API JSON de l'assistant « Nouveau bon de caisse » (SFD M03, §5.7).
 *
 * Toutes les règles sont contrôlées ici : un appel direct qui contourne l'écran est refusé
 * de la même façon (SFD §1.2).
 */
class AssistantBonController extends Controller
{
    /** Taille maximale d'un fichier, en Ko (RG-BC-17) */
    private const TAILLE_MAX_FICHIER_KO = 10240;

    /**
     * POST /api/v1/bons — premier « Suivant » ou « Brouillon » : le brouillon est créé (RG-BC-01).
     * Paramètre `etape` : vérifier aussi que cette étape est complète (« Suivant »).
     */
    public function creer(Request $request): JsonResponse
    {
        Gate::authorize('create', BonCaisse::class);

        $saisie = $this->saisie($request);
        $bon = EnregistrementBon::creer($request->user(), $saisie);

        return response()->json($this->reponse($bon, $this->erreursEtape($bon, $request->integer('etape'))), 201);
    }

    /**
     * PATCH /api/v1/bons/{bon} — enregistrement à chaque changement d'étape ou « Brouillon » (RG-BC-25).
     */
    public function enregistrer(Request $request, BonCaisse $bonCaisse): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);

        EnregistrementBon::appliquer($bonCaisse, $this->saisie($request));

        return response()->json($this->reponse($bonCaisse, $this->erreursEtape($bonCaisse, $request->integer('etape'))));
    }

    /** GET /api/v1/bons/{bon}/controles — écran Contrôle (US-BC-10) */
    public function controles(BonCaisse $bonCaisse): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);

        $controles = ControlesBon::executer($bonCaisse);

        return response()->json([
            'controles' => $controles,
            'soumission_possible' => empty(ControlesBon::bloquants($controles)),
            'circuit' => CircuitPrevisionnel::pour($bonCaisse),
        ]);
    }

    /** POST /api/v1/bons/{bon}/soumettre — en-tête Idempotency-Key (RG-BC-27) */
    public function soumettre(Request $request, BonCaisse $bonCaisse): JsonResponse
    {
        Gate::authorize('soumettre', $bonCaisse);

        [$bon, $controles] = SoumettreBon::executer($bonCaisse, $request->user(), $request->header('Idempotency-Key'));

        /* MSG-BC-032 affiché sur la fiche du bon */
        session()->flash('success', __('MSG-BC-032', ['numero' => $bon->numero]));

        return response()->json([
            'id' => $bon->id,
            'numero' => $bon->numero,
            'statut' => $bon->statut,
            'version' => $bon->version,
            'montant' => (int) round((float) $bon->montant),
            'montant_lettres' => $bon->montant_lettres,
            'caisse' => $bon->caisse ? ['code' => $bon->caisse->code, 'libelle' => $bon->caisse->libelle] : null,
            'circuit' => CircuitPrevisionnel::pour($bon),
            'controles' => array_values(array_filter($controles, fn ($c) => $c['niveau'] !== ControlesBon::OK)),
        ]);
    }

    /** POST /api/v1/bons/{bon}/annuler — US-BC-15 */
    public function annuler(Request $request, BonCaisse $bonCaisse): JsonResponse
    {
        Gate::authorize('annuler', $bonCaisse);

        $request->validate(
            ['motif' => ['required', 'string', 'min:10', 'max:1000']],
            ['motif.required' => __('MSG-BC-001'), 'motif.min' => __('MSG-BC-002')],
        );

        $bon = AnnulerBon::executer($bonCaisse, $request->user(), $request->input('motif'));
        session()->flash('success', 'Bon annulé.');

        return response()->json(['id' => $bon->id, 'statut' => $bon->statut]);
    }

    /* ------------------------------------------------------------------
     * Pièces (étape 4)
     * ------------------------------------------------------------------ */

    /** POST /api/v1/bons/{bon}/pieces — un fichier, envoyé dès son dépôt (RG-BC-17) */
    public function ajouterPiece(Request $request, BonCaisse $bonCaisse): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        $this->validerFichier($request, ['type_document' => ['nullable', Rule::in(PieceJointe::TYPES_PIECE_ASSISTANT)]]);

        $piece = PiecesBon::ajouter($bonCaisse, $request->file('fichier'), $request->input('type_document'), $request->user());

        return response()->json(['piece' => self::piece($piece->fresh(), $bonCaisse)], 201);
    }

    /** POST /api/v1/bons/{bon}/pieces/{piece}/remplacer — nouvelle version d'une pièce déjà soumise (E-03.6) */
    public function remplacerPiece(Request $request, BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        $this->verifierPiece($bonCaisse, $piece);
        $this->validerFichier($request);

        $nouvelle = PiecesBon::remplacer($bonCaisse, $piece, $request->file('fichier'), $request->user());

        return response()->json(['piece' => self::piece($nouvelle->fresh(), $bonCaisse), 'remplacee' => $piece->id], 201);
    }

    /** PATCH /api/v1/bons/{bon}/pieces/{piece} — type de la pièce (RG-BC-18) */
    public function typerPiece(Request $request, BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        $this->verifierPiece($bonCaisse, $piece);

        $request->validate(['type_document' => ['required', Rule::in(PieceJointe::TYPES_PIECE_ASSISTANT)]], [
            'type_document.*' => __('MSG-BC-016'),
        ]);
        PiecesBon::typer($piece, $request->input('type_document'));

        return response()->json(['piece' => self::piece($piece->fresh(), $bonCaisse)]);
    }

    /** DELETE /api/v1/bons/{bon}/pieces/{piece} — pièce jamais soumise seulement (E-03.6) */
    public function supprimerPiece(BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        $this->verifierPiece($bonCaisse, $piece);

        PiecesBon::supprimer($bonCaisse, $piece);

        return response()->json(['supprimee' => true]);
    }

    /** PATCH /api/v1/bons/{bon}/pieces/{piece}/doublon — RG-BC-19 : confirmation et justification (TC-BC-018) */
    public function confirmerDoublon(Request $request, BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        $this->verifierPiece($bonCaisse, $piece);
        abort_if($piece->doublon_de_id === null, 404);

        $request->validate([
            'confirme' => ['accepted'],
            'justification' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'confirme.accepted' => __('MSG-APP-009'),
            'justification.required' => __('MSG-BC-001'),
            'justification.min' => __('MSG-BC-005'),
        ]);
        PiecesBon::confirmerDoublon($piece, $request->input('justification'));

        return response()->json(['piece' => self::piece($piece->fresh(), $bonCaisse)]);
    }

    /** GET /api/v1/bons/{bon}/pieces/{piece}/lecture — suivi de la lecture d'un ticket (interrogé par l'écran) */
    public function lecture(BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        $this->verifierPiece($bonCaisse, $piece);

        return response()->json(['piece' => self::piece($piece, $bonCaisse)]);
    }

    /** POST /api/v1/bons/{bon}/pieces/{piece}/lecture — « Valider la lecture » (RG-BC-21) */
    public function validerLecture(Request $request, BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        $this->verifierPiece($bonCaisse, $piece);
        $lecture = $piece->lectureTicket ?? abort(404);

        LectureTickets::valider($lecture, (array) $request->input('valeurs', []), (array) $request->input('confirmes', []), $request->user());

        return response()->json(['piece' => self::piece($piece->fresh(), $bonCaisse)]);
    }

    private function verifierPiece(BonCaisse $bon, PieceJointe $piece): void
    {
        abort_unless($piece->bon_caisse_id === $bon->id, 404);
    }

    /** PDF, JPG ou PNG vérifiés sur le contenu réel du fichier, 10 Mo au plus (RG-BC-17, MSG-BC-021) */
    private function validerFichier(Request $request, array $autres = []): void
    {
        $request->validate([
            'fichier' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:' . self::TAILLE_MAX_FICHIER_KO],
        ] + $autres, [
            'fichier.*' => __('MSG-BC-021'),
        ]);
    }

    /* ------------------------------------------------------------------
     * Référentiels
     * ------------------------------------------------------------------ */

    /** GET /api/v1/referentiels/beneficiaires?q= — utilisateurs actifs, dès 2 caractères (E-03.4) */
    public function beneficiaires(Request $request): JsonResponse
    {
        $recherche = trim((string) $request->query('q'));
        if (mb_strlen($recherche) < 2) {
            return response()->json(['resultats' => []]);
        }

        $resultats = User::actifs()
            ->where(fn ($q) => $q->where('name', 'like', "%{$recherche}%")
                ->orWhere('prenom', 'like', "%{$recherche}%")
                ->orWhere('matricule', 'like', "%{$recherche}%"))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (User $u) => self::beneficiaire($u));

        return response()->json(['resultats' => $resultats]);
    }

    /** GET /api/v1/referentiels/caisse-payeuse?site=&mode= — RG-BC-11, RG-BC-12 */
    public function caissePayeuse(Request $request): JsonResponse
    {
        $caisse = Caisse::payeusePour((string) $request->query('site'), $request->query('mode'));

        return response()->json(['caisse' => $caisse ? [
            'code' => $caisse->code,
            'libelle' => $caisse->libelle,
            'type' => $caisse->type,
            'plafond_retrait' => $caisse->plafond_retrait !== null ? (float) $caisse->plafond_retrait : null,
        ] : null]);
    }

    /* ------------------------------------------------------------------ */

    /** « NOM Prénom — matricule — service » (E-03.4) */
    public static function beneficiaire(User $utilisateur): array
    {
        return [
            'id' => $utilisateur->id,
            'nom_complet' => $utilisateur->nom_complet,
            'libelle' => trim(mb_strtoupper($utilisateur->name) . ' ' . $utilisateur->prenom)
                . ($utilisateur->matricule ? " — {$utilisateur->matricule}" : '')
                . ($utilisateur->service ? " — {$utilisateur->service}" : ''),
            'telephone' => ReglesSaisie::normaliserTelephone($utilisateur->telephone),
        ];
    }

    /** Saisie de l'assistant : champs connus, formats vérifiés (RG-BC-25) */
    private function saisie(Request $request): array
    {
        $saisie = $request->only(ReglesSaisie::champs());
        if (array_key_exists('montant', $saisie) && is_string($saisie['montant'])) {
            // séparateurs de milliers retirés ; « 12,5 » ou « abc » restent refusés (MSG-BC-003)
            $montant = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $saisie['montant']);
            $saisie['montant'] = $montant === '' ? null : $montant;
        }
        if (array_key_exists('telephone_beneficiaire', $saisie)) {
            $saisie['telephone_beneficiaire'] = ReglesSaisie::normaliserTelephone($saisie['telephone_beneficiaire']);
        }

        $erreurs = ReglesSaisie::erreursFormat($saisie);
        if ($erreurs->isNotEmpty()) {
            throw ValidationException::withMessages($erreurs->toArray());
        }

        return $saisie;
    }

    /** Erreurs de l'étape quittée par « Suivant » (vide si l'étape est complète) */
    private function erreursEtape(BonCaisse $bon, int $etape): array
    {
        if ($etape >= 1 && $etape <= 3) {
            return ReglesSaisie::erreursCompletes(ReglesSaisie::donneesDu($bon->fresh()), [$etape])->toArray();
        }
        if ($etape === 4 && ($cle = ReglesSaisie::erreurPieces($bon))) {
            return ['pieces' => [__($cle)]];
        }

        return [];
    }

    private function reponse(BonCaisse $bon, array $erreurs = []): array
    {
        return ['bon' => self::bon($bon->fresh(['caisse'])), 'erreurs' => (object) $erreurs];
    }

    /** Bon au format de l'assistant */
    public static function bon(BonCaisse $bon): array
    {
        $bon->loadMissing(['piecesActives.lectureTicket', 'piecesActives.doublonDe.bonCaisse', 'caisse', 'beneficiaireUtilisateur']);

        return ReglesSaisie::donneesDu($bon) + [
            'id' => $bon->id,
            'numero' => $bon->numero,
            'statut' => $bon->statut,
            'version' => $bon->version,
            'montant_lettres' => $bon->montant_lettres,
            'caisse' => $bon->caisse ? ['libelle' => $bon->caisse->libelle, 'plafond_retrait' => $bon->caisse->plafond_retrait !== null ? (float) $bon->caisse->plafond_retrait : null] : null,
            'beneficiaire_libelle' => $bon->beneficiaireUtilisateur ? self::beneficiaire($bon->beneficiaireUtilisateur)['libelle'] : null,
            'commentaire_rejet' => $bon->commentaire_rejet,
            'pieces' => $bon->piecesActives->map(fn (PieceJointe $p) => self::piece($p, $bon))->values()->all(),
        ];
    }

    /** Pièce au format de l'assistant : qualité, doublon, lecture du ticket */
    public static function piece(PieceJointe $piece, BonCaisse $bon): array
    {
        $doublon = null;
        if ($piece->doublon_de_id !== null) {
            $valeurs = ControlesBon::valeursDoublon($piece);
            $doublon = $valeurs + [
                'message' => ErreurMetier::texte('MSG-BC-019', $valeurs),
                'confirme' => $piece->doublon_confirme,
                'justification' => $piece->justification_doublon,
            ];
        }

        $lecture = $piece->type_document === 'recu_carburant' ? $piece->lectureTicket : null;
        if ($lecture) {
            LectureTickets::actualiser($lecture);
        }

        return [
            'id' => $piece->id,
            'nom_fichier' => $piece->nom_fichier,
            'taille' => (int) $piece->taille,
            'mime_type' => $piece->mime_type,
            'type_document' => $piece->type_document,
            'version' => (int) $piece->version,
            'qualite' => $piece->qualite,
            'dpi' => $piece->dpi_detecte,
            'supprimable' => PiecesBon::supprimable($bon, $piece),
            'doublon' => $doublon,
            'lecture' => $lecture ? [
                'statut' => $lecture->statut,
                'lecteur' => $lecture->lecteur,
                'valeurs_lues' => $lecture->valeurs_lues,
                'confiances' => $lecture->confiances,
                'valeurs' => $lecture->valeurs_validees ?? $lecture->valeurs_lues,
                'champs_corriges' => $lecture->champs_corriges,
                'avertissements' => LectureTickets::avertissements($lecture, $bon),
            ] : null,
            /* Adresse relative, comme la fiche du bon : indépendante d'APP_URL */
            'url' => parse_url(Storage::disk('public')->url($piece->chemin_fichier), PHP_URL_PATH),
        ];
    }
}
