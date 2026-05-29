<?php

namespace App\Jobs;

use App\Models\PieceJointe;
use App\Services\ClassificationDocumentService;
use App\Services\DocumentAnalyseService;
use App\Services\OcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job de traitement OCR d'une pièce jointe
 * 
 * Flux :
 * 1. Extraction du texte brut (OcrService)
 * 2. Analyse intelligente du texte (DocumentAnalyseService)
 * 3. Sauvegarde des résultats en base
 * 
 * Ce job est non-bloquant : en cas d'erreur, il met à jour le statut
 * OCR de la pièce jointe sans bloquer le processus du bon de caisse.
 * 
 * Principe : OCR = assistance, jamais obligation.
 */
class ProcessPieceJointeOcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Nombre maximum de tentatives */
    public int $tries = 2;

    /** Timeout en secondes */
    public int $timeout = 120;

    /**
     * Créer une nouvelle instance du job
     */
    public function __construct(
        protected int $pieceJointeId
    ) {}

    /**
     * Exécuter le job
     */
    public function handle(OcrService $ocrService, DocumentAnalyseService $analyseService): void
    {
        $pieceJointe = PieceJointe::find($this->pieceJointeId);

        if (!$pieceJointe) {
            Log::warning("ProcessPieceJointeOcrJob : pièce jointe #{$this->pieceJointeId} introuvable.");
            return;
        }

        /* Vérifier que l'OCR n'a pas déjà été traité */
        if ($pieceJointe->ocr_statut === PieceJointe::OCR_TERMINE) {
            return;
        }

        /* Marquer comme en cours de traitement */
        $pieceJointe->update(['ocr_statut' => PieceJointe::OCR_EN_COURS]);

        try {
            /* Étape 1 : Extraction du texte brut */
            $texteBrut = $ocrService->extraireTexte(
                $pieceJointe->chemin_fichier,
                $pieceJointe->mime_type
            );

            if (empty(trim($texteBrut))) {
                Log::info("ProcessPieceJointeOcrJob : aucun texte extrait pour la pièce #{$this->pieceJointeId}");
                $pieceJointe->update([
                    'ocr_statut' => PieceJointe::OCR_NON_APPLICABLE,
                    'ocr_texte_brut' => '',
                    'ocr_data' => null,
                ]);
                return;
            }

            /* Étape 2 : Analyse intelligente */
            $donneesExtraites = $analyseService->analyser($texteBrut);

            /* Étape 3 : Sauvegarde des résultats */
            $pieceJointe->update([
                'ocr_statut' => PieceJointe::OCR_TERMINE,
                'ocr_texte_brut' => $texteBrut,
                'ocr_data' => !empty($donneesExtraites) ? $donneesExtraites : null,
            ]);

            Log::info("ProcessPieceJointeOcrJob : analyse terminée pour la pièce #{$this->pieceJointeId}", [
                'champs_trouves' => array_keys($donneesExtraites),
            ]);

            /* Étape 4 : Classification IA automatique */
            try {
                $classificationService = app(ClassificationDocumentService::class);
                $classificationService->classifierEtAppliquer($pieceJointe);
            } catch (\Throwable $classifErr) {
                Log::warning("ProcessPieceJointeOcrJob : classification IA échouée pour la pièce #{$this->pieceJointeId} — {$classifErr->getMessage()}");
            }

            /* Étape 5 : Indexation full-text */
            $pieceJointe->construireTexteIndexable();

            /* Étape 6 : Contrôle qualité DPI (images uniquement) */
            $pieceJointe->verifierQualiteDpi();

        } catch (\Throwable $e) {
            Log::error("ProcessPieceJointeOcrJob : erreur pour la pièce #{$this->pieceJointeId} — {$e->getMessage()}");

            $pieceJointe->update([
                'ocr_statut' => PieceJointe::OCR_ERREUR,
                'ocr_texte_brut' => $texteBrut ?? null,
            ]);
        }
    }

    /**
     * Gérer un échec définitif du job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessPieceJointeOcrJob : échec définitif pour la pièce #{$this->pieceJointeId} — {$exception->getMessage()}");

        $pieceJointe = PieceJointe::find($this->pieceJointeId);
        if ($pieceJointe) {
            $pieceJointe->update(['ocr_statut' => PieceJointe::OCR_ERREUR]);
        }
    }
}
