<?php

namespace App\Http\Controllers;

use App\Models\BonCaisse;
use App\Models\PieceJointe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Contrôleur OCR - API pour le suivi de l'analyse des pièces jointes
 * 
 * Fournit des endpoints JSON pour :
 * - Vérifier le statut OCR des pièces jointes d'un bon
 * - Récupérer les données extraites par l'OCR
 */
class OcrController extends Controller
{
    /**
     * Récupérer le statut OCR et les données extraites pour un bon de caisse
     * 
     * Retourne pour chaque pièce jointe :
     * - id, nom_fichier, ocr_statut, ocr_data
     */
    public function statut(BonCaisse $bonCaisse): JsonResponse
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        /* Vérifier l'accès : le demandeur ne voit que ses bons */
        if ($utilisateur->role === 'demandeur' && $bonCaisse->demandeur_id !== $utilisateur->id) {
            abort(403);
        }

        $pieces = $bonCaisse->piecesJointes()
            ->select(['id', 'nom_fichier', 'type_document', 'ocr_statut', 'ocr_data'])
            ->get();

        /* Déterminer si toutes les analyses sont terminées */
        $toutTermine = $pieces->every(fn($p) => in_array($p->ocr_statut, [
            PieceJointe::OCR_TERMINE,
            PieceJointe::OCR_ERREUR,
            PieceJointe::OCR_NON_APPLICABLE,
        ]));

        /* Agréger les données OCR de toutes les pièces */
        $donneesAgregees = $this->agregerDonneesOcr($pieces);

        return response()->json([
            'tout_termine' => $toutTermine,
            'pieces' => $pieces,
            'donnees_agregees' => $donneesAgregees,
        ]);
    }

    /**
     * Agréger les données OCR de plusieurs pièces jointes
     * Priorise les données des pièces avec le plus d'informations
     */
    protected function agregerDonneesOcr($pieces): array
    {
        $resultat = [];

        foreach ($pieces as $piece) {
            if ($piece->ocr_statut !== PieceJointe::OCR_TERMINE || empty($piece->ocr_data)) {
                continue;
            }

            $data = is_string($piece->ocr_data) ? json_decode($piece->ocr_data, true) : $piece->ocr_data;
            if (!is_array($data)) continue;

            /* Fusionner : garder les valeurs existantes, ajouter les nouvelles */
            foreach ($data as $champ => $valeur) {
                if (!isset($resultat[$champ]) && $valeur !== null && $valeur !== '') {
                    $resultat[$champ] = $valeur;
                }
            }
        }

        return $resultat;
    }
}
