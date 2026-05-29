<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service OCR - Extraction de texte depuis des documents
 * 
 * Extrait le texte brut des pièces jointes (PDF, images).
 * Utilise Tesseract OCR pour les images et pdftotext pour les PDF.
 * 
 * En cas d'absence des outils, retourne gracieusement une chaîne vide
 * (OCR = assistance, jamais obligation).
 */
class OcrService
{
    /**
     * Extraire le texte d'un fichier stocké
     * 
     * @param string $cheminFichier Chemin relatif dans le storage
     * @param string $mimeType Type MIME du fichier
     * @param string $disk Disque de stockage (public pour les pièces jointes, local pour les fichiers temporaires)
     * @return string Texte extrait (vide si échec)
     */
    public function extraireTexte(string $cheminFichier, string $mimeType, string $disk = 'public'): string
    {
        try {
            $cheminAbsolu = Storage::disk($disk)->path($cheminFichier);

            if (!file_exists($cheminAbsolu)) {
                Log::warning("OcrService : fichier introuvable — {$cheminFichier} (disk: {$disk})");
                return '';
            }

            /* Extraction selon le type MIME */
            if (str_contains($mimeType, 'pdf')) {
                return $this->extraireTextePdf($cheminAbsolu);
            }

            if (str_starts_with($mimeType, 'image/')) {
                return $this->extraireTexteImage($cheminAbsolu);
            }

            Log::info("OcrService : type MIME non supporté pour OCR — {$mimeType}");
            return '';

        } catch (\Throwable $e) {
            Log::error("OcrService : erreur lors de l'extraction — {$e->getMessage()}");
            return '';
        }
    }

    /**
     * Extraire le texte d'un fichier PDF
     * 
     * Tente d'abord pdftotext (poppler-utils), puis une extraction basique PHP.
     */
    protected function extraireTextePdf(string $cheminAbsolu): string
    {
        /* Tentative avec pdftotext (meilleur résultat) */
        $pdftotextPath = $this->trouverExecutable('pdftotext');
        if ($pdftotextPath) {
            $output = [];
            $returnCode = 0;
            exec(sprintf('%s -layout %s -', escapeshellarg($pdftotextPath), escapeshellarg($cheminAbsolu)), $output, $returnCode);

            if ($returnCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        /* Fallback : extraction basique du texte dans le PDF */
        return $this->extraireTextePdfBasique($cheminAbsolu);
    }

    /**
     * Extraction basique du texte d'un PDF sans outils externes
     * Lit les streams de texte du PDF directement
     */
    protected function extraireTextePdfBasique(string $cheminAbsolu): string
    {
        try {
            $contenu = file_get_contents($cheminAbsolu);
            if ($contenu === false) return '';

            $texte = '';

            /* Chercher les objets de texte dans le PDF */
            if (preg_match_all('/\((.*?)\)/', $contenu, $matches)) {
                foreach ($matches[1] as $match) {
                    /* Filtrer les caractères non-textuels */
                    $clean = preg_replace('/[^\x20-\x7E\xC0-\xFF]/', '', $match);
                    if (strlen($clean) > 2) {
                        $texte .= $clean . ' ';
                    }
                }
            }

            return trim($texte);
        } catch (\Throwable $e) {
            Log::warning("OcrService : extraction PDF basique échouée — {$e->getMessage()}");
            return '';
        }
    }

    /**
     * Extraire le texte d'une image via Tesseract OCR
     */
    protected function extraireTexteImage(string $cheminAbsolu): string
    {
        $tesseractPath = $this->trouverExecutable('tesseract');
        if (!$tesseractPath) {
            Log::info('OcrService : Tesseract non disponible, OCR image impossible.');
            return '';
        }

        $output = [];
        $returnCode = 0;

        /* Exécuter Tesseract avec langue française */
        exec(sprintf(
            '%s %s stdout -l fra+eng 2>/dev/null',
            escapeshellarg($tesseractPath),
            escapeshellarg($cheminAbsolu)
        ), $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        /* Réessayer sans spécifier la langue */
        exec(sprintf(
            '%s %s stdout 2>/dev/null',
            escapeshellarg($tesseractPath),
            escapeshellarg($cheminAbsolu)
        ), $output, $returnCode);

        return ($returnCode === 0) ? implode("\n", $output) : '';
    }

    /**
     * Trouver un exécutable dans le PATH du système
     */
    protected function trouverExecutable(string $nom): ?string
    {
        /* Sur Windows */
        if (PHP_OS_FAMILY === 'Windows') {
            exec("where {$nom} 2>nul", $output, $code);
            return ($code === 0 && !empty($output)) ? trim($output[0]) : null;
        }

        /* Sur Linux/Mac */
        exec("which {$nom} 2>/dev/null", $output, $code);
        return ($code === 0 && !empty($output)) ? trim($output[0]) : null;
    }
}
