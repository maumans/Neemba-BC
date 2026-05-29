<?php

namespace App\Http\Controllers;

use App\Services\DocumentAnalyseService;
use App\Services\OcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Contrôleur d'Analyse OCR instantanée
 * 
 * Analyse un fichier uploadé temporairement et retourne les données extraites.
 * Utilisé lors de la création/édition d'un bon de caisse pour pré-remplir
 * les champs du formulaire.
 * 
 * Principe : OCR = assistance, jamais obligation.
 * En cas d'erreur, retourne un JSON vide sans bloquer le processus.
 */
class OcrAnalyseController extends Controller
{
    /**
     * Analyser un fichier uploadé et retourner les données structurées
     */
    public function analyser(Request $request, OcrService $ocrService, DocumentAnalyseService $analyseService): JsonResponse
    {
        $request->validate([
            'fichier' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        try {
            $fichier = $request->file('fichier');

            /* Stocker temporairement le fichier */
            $cheminTemp = $fichier->store('temp_ocr', 'local');

            /* Étape 1 : Extraction du texte (disk local pour les fichiers temporaires) */
            $texteBrut = $ocrService->extraireTexte($cheminTemp, $fichier->getMimeType(), 'local');

            /* Supprimer le fichier temporaire */
            Storage::disk('local')->delete($cheminTemp);

            if (empty(trim($texteBrut))) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Aucun texte n\'a pu être extrait du document.',
                ]);
            }

            /* Étape 2 : Analyse intelligente */
            $donneesExtraites = $analyseService->analyser($texteBrut);

            return response()->json([
                'success' => true,
                'data' => $donneesExtraites,
                'message' => !empty($donneesExtraites)
                    ? 'Analyse terminée — ' . count($donneesExtraites) . ' information(s) extraite(s).'
                    : 'Analyse terminée — aucune information identifiée.',
            ]);

        } catch (\Throwable $e) {
            Log::error("OcrAnalyseController : erreur — {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'data' => [],
                'message' => 'L\'analyse du document a échoué. Vous pouvez continuer sans OCR.',
            ]);
        }
    }
}
