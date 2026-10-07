<?php

namespace App\Services\BonCaisse;

/**
 * Qualité d'une pièce justificative (RG-BC-16, SFD §8.9).
 *
 * - PDF produit par un logiciel (texte sélectionnable) : conforme, sans contrôle de résolution (TC-BC-017).
 * - Image ou PDF scanné : résolution effective = grand côté en pixels ÷ 11,69 pouces (grand côté d'une page A4).
 *   Moins de 150 dpi : illisible (bloquant) ; 150 à 299 : qualité moyenne ; 300 et plus : conforme.
 *   Ex. TC-BC-016 : 1 100 px → 94 dpi, illisible ; 3 000 px → 257 dpi, moyenne ; 4 000 px → 342 dpi, conforme.
 * - Résolution impossible à mesurer : qualité non renseignée (aucun blocage).
 */
class QualitePiece
{
    public const CONFORME = 'conforme';
    public const MOYENNE = 'moyenne';
    public const ILLISIBLE = 'illisible';

    public const LIBELLES = [
        self::CONFORME => 'Conforme',
        self::MOYENNE => 'Qualité moyenne',
        self::ILLISIBLE => 'Illisible',
    ];

    /** Grand côté d'une page A4, en pouces */
    private const GRAND_COTE_A4 = 11.69;

    private const DPI_LISIBLE = 150;
    private const DPI_CONFORME = 300;

    /**
     * @return array{qualite: ?string, dpi: ?int}
     */
    public static function evaluer(string $cheminAbsolu, ?string $mime): array
    {
        if (!is_file($cheminAbsolu)) {
            return ['qualite' => null, 'dpi' => null];
        }
        if (str_starts_with((string) $mime, 'image/')) {
            $dimensions = @getimagesize($cheminAbsolu);

            return $dimensions ? self::depuisPixels(max($dimensions[0], $dimensions[1])) : ['qualite' => null, 'dpi' => null];
        }
        if ($mime === 'application/pdf') {
            return self::evaluerPdf($cheminAbsolu);
        }

        return ['qualite' => null, 'dpi' => null];
    }

    /**
     * @return array{qualite: string, dpi: int}
     */
    public static function depuisPixels(int $grandCote): array
    {
        $dpi = (int) round($grandCote / self::GRAND_COTE_A4);

        return ['qualite' => self::niveau($dpi), 'dpi' => $dpi];
    }

    public static function niveau(int $dpi): string
    {
        if ($dpi < self::DPI_LISIBLE) {
            return self::ILLISIBLE;
        }

        return $dpi < self::DPI_CONFORME ? self::MOYENNE : self::CONFORME;
    }

    /* ------------------------------------------------------------------ */

    private static function evaluerPdf(string $chemin): array
    {
        $contenu = (string) @file_get_contents($chemin);

        if (self::pdfContientDuTexte($chemin, $contenu)) {
            return ['qualite' => self::CONFORME, 'dpi' => null];
        }

        $grandCote = self::plusGrandeImagePdf($chemin, $contenu);

        return $grandCote ? self::depuisPixels($grandCote) : ['qualite' => null, 'dpi' => null];
    }

    /** Texte sélectionnable : une police déclarée dans le fichier, sinon pdftotext s'il est installé */
    private static function pdfContientDuTexte(string $chemin, string $contenu): bool
    {
        if (str_contains($contenu, '/Font')) {
            return true;
        }

        $pdftotext = self::executable('pdftotext');
        if ($pdftotext === null) {
            return false;
        }
        exec(escapeshellarg($pdftotext) . ' -q -l 3 ' . escapeshellarg($chemin) . ' - 2>' . self::nul(), $sortie, $code);

        return $code === 0 && trim(implode('', $sortie)) !== '';
    }

    /** Grand côté (pixels) de la plus grande image d'un PDF scanné : pdfimages s'il est installé, sinon lecture du fichier */
    private static function plusGrandeImagePdf(string $chemin, string $contenu): ?int
    {
        $plusGrand = 0;

        $pdfimages = self::executable('pdfimages');
        if ($pdfimages !== null) {
            exec(escapeshellarg($pdfimages) . ' -list ' . escapeshellarg($chemin) . ' 2>' . self::nul(), $lignes, $code);
            foreach ($code === 0 ? array_slice($lignes, 2) : [] as $ligne) {
                /* page num type width height … */
                $colonnes = preg_split('/\s+/', trim($ligne));
                if (count($colonnes) > 4 && ctype_digit($colonnes[3]) && ctype_digit($colonnes[4])) {
                    $plusGrand = max($plusGrand, (int) $colonnes[3], (int) $colonnes[4]);
                }
            }
        }

        if ($plusGrand === 0 && preg_match_all('/\/Subtype\s*\/Image/', $contenu, $occurrences, PREG_OFFSET_CAPTURE)) {
            foreach ($occurrences[0] as [, $position]) {
                $dictionnaire = substr($contenu, max(0, $position - 300), 600);
                if (preg_match('/\/Width\s+(\d+)/', $dictionnaire, $largeur) && preg_match('/\/Height\s+(\d+)/', $dictionnaire, $hauteur)) {
                    $plusGrand = max($plusGrand, (int) $largeur[1], (int) $hauteur[1]);
                }
            }
        }

        return $plusGrand ?: null;
    }

    private static function executable(string $nom): ?string
    {
        $commande = PHP_OS_FAMILY === 'Windows' ? "where {$nom} 2>nul" : "command -v {$nom} 2>/dev/null";
        exec($commande, $sortie, $code);

        return $code === 0 && !empty($sortie) ? trim($sortie[0]) : null;
    }

    private static function nul(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
    }
}
