<?php

namespace App\Http\Controllers;

use App\Exceptions\ErreurMetier;
use App\Jobs\ProcessPieceJointeOcrJob;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\PieceJointe;
use App\Models\User;
use App\Services\BonCaisse\AnnulerBon;
use App\Services\BonCaisse\CircuitPrevisionnel;
use App\Services\BonCaisse\ControlesBon;
use App\Services\BonCaisse\EnregistrementBon;
use App\Services\BonCaisse\ReglesSaisie;
use App\Services\BonCaisse\SoumettreBon;
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
    /** Taille maximale d'un fichier (Ko), nombre et poids total des pièces d'un bon (RG-BC-17) */
    private const TAILLE_MAX_FICHIER_KO = 10240;
    private const NOMBRE_MAX_PIECES = 20;
    private const POIDS_MAX_BON = 50 * 1024 * 1024;

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

    /** POST /api/v1/bons/{bon}/pieces — un fichier, envoyé dès son dépôt */
    public function ajouterPiece(Request $request, BonCaisse $bonCaisse): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);

        $request->validate([
            'fichier' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:' . self::TAILLE_MAX_FICHIER_KO],
            'type_document' => ['nullable', Rule::in(PieceJointe::TYPES_PIECE_ASSISTANT)],
        ], [
            'fichier.*' => __('MSG-BC-021'),
        ]);

        $fichier = $request->file('fichier');
        $pieces = $bonCaisse->piecesJointes()->get();
        if ($pieces->count() >= self::NOMBRE_MAX_PIECES || $pieces->sum('taille') + $fichier->getSize() > self::POIDS_MAX_BON) {
            throw new ErreurMetier('LIMITE_PIECES', 'MSG-APP-003', [], 'RG-BC-17', 'fichier');
        }

        $piece = PieceJointe::create([
            'bon_caisse_id' => $bonCaisse->id,
            'type_document' => $request->input('type_document'),
            'nom_fichier' => $fichier->getClientOriginalName(),
            'chemin_fichier' => $fichier->store('pieces_jointes/' . $bonCaisse->id, 'public'),
            'taille' => $fichier->getSize(),
            'mime_type' => $fichier->getMimeType(),
            /* RG-BC-19 : empreinte du fichier, pour repérer une pièce déjà jointe à un autre bon */
            'checksum' => hash_file('sha256', $fichier->getRealPath()),
        ]);

        ProcessPieceJointeOcrJob::dispatch($piece->id);
        $bonCaisse->enregistrerAjoutPieceJointe($piece->nom_fichier, $request->user()->id);

        return response()->json(['piece' => $this->piece($piece)], 201);
    }

    /** PATCH /api/v1/bons/{bon}/pieces/{piece} — type de la pièce (RG-BC-18) */
    public function typerPiece(Request $request, BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        abort_unless($piece->bon_caisse_id === $bonCaisse->id, 404);

        $request->validate(['type_document' => ['required', Rule::in(PieceJointe::TYPES_PIECE_ASSISTANT)]], [
            'type_document.*' => __('MSG-BC-016'),
        ]);
        $piece->update(['type_document' => $request->input('type_document')]);

        return response()->json(['piece' => $this->piece($piece)]);
    }

    /** DELETE /api/v1/bons/{bon}/pieces/{piece} — avant soumission, suppression réelle (E-03.6) */
    public function supprimerPiece(BonCaisse $bonCaisse, PieceJointe $piece): JsonResponse
    {
        Gate::authorize('modifier', $bonCaisse);
        abort_unless($piece->bon_caisse_id === $bonCaisse->id, 404);
        if ($bonCaisse->statut !== 'BROUILLON') {
            throw new ErreurMetier('PIECE_NON_SUPPRIMABLE', 'MSG-APP-004', [], null, 'pieces', 409);
        }

        Storage::disk('public')->delete($piece->chemin_fichier);
        $piece->delete();

        return response()->json(['supprimee' => true]);
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
        return ['bon' => self::bon($bon->fresh(['piecesJointes', 'caisse'])), 'erreurs' => (object) $erreurs];
    }

    /** Bon au format de l'assistant */
    public static function bon(BonCaisse $bon): array
    {
        return ReglesSaisie::donneesDu($bon) + [
            'id' => $bon->id,
            'numero' => $bon->numero,
            'statut' => $bon->statut,
            'version' => $bon->version,
            'montant_lettres' => $bon->montant_lettres,
            'caisse' => $bon->caisse ? ['libelle' => $bon->caisse->libelle, 'plafond_retrait' => $bon->caisse->plafond_retrait !== null ? (float) $bon->caisse->plafond_retrait : null] : null,
            'beneficiaire_libelle' => $bon->beneficiaireUtilisateur ? self::beneficiaire($bon->beneficiaireUtilisateur)['libelle'] : null,
            'commentaire_rejet' => $bon->commentaire_rejet,
            'pieces' => $bon->piecesJointes->map(fn (PieceJointe $p) => (new self())->piece($p))->values()->all(),
        ];
    }

    private function piece(PieceJointe $piece): array
    {
        return [
            'id' => $piece->id,
            'nom_fichier' => $piece->nom_fichier,
            'taille' => (int) $piece->taille,
            'mime_type' => $piece->mime_type,
            'type_document' => $piece->type_document,
            'qualite_ok' => $piece->qualite_ok,
            'url' => Storage::disk('public')->url($piece->chemin_fichier),
        ];
    }
}
