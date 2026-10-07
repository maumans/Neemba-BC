<?php

namespace App\Services\Referentiels;

/**
 * Comparaison des libellés et des noms du classeur avec ceux de l'application :
 * sans accents, sans casse, ponctuation réduite à des espaces.
 */
class Normalisation
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ÿ' => 'y', 'œ' => 'oe',
    ];

    /** « Sangarédi » → « SANGAREDI » ; « Plafond de retrait (GNF) » → « PLAFOND DE RETRAIT GNF » */
    public static function cle(?string $texte): string
    {
        $texte = strtr(mb_strtolower((string) $texte), self::ACCENTS);

        return strtoupper(trim(preg_replace('/[^a-z0-9]+/', ' ', $texte)));
    }

    /**
     * Valeur saisie dans une cellule : une mention « (à confirmer) » la met de côté (elle n'est pas appliquée).
     *
     * @return array{valeur: ?string, a_confirmer: bool}
     */
    public static function valeur(?string $brut): array
    {
        $brut = trim((string) $brut);
        if (preg_match('/\(\s*[àa]\s+confirmer\s*\)/iu', $brut)) {
            $reste = trim(preg_replace('/\(\s*[àa]\s+confirmer\s*\)/iu', '', $brut));

            return ['valeur' => $reste === '' ? null : $reste, 'a_confirmer' => true];
        }
        if (in_array($brut, ['', '—', '-', '?'], true)) {
            return ['valeur' => null, 'a_confirmer' => false];
        }

        return ['valeur' => $brut, 'a_confirmer' => false];
    }

    /** « DIAKITE Mohamed (DAF) » → [« DIAKITE Mohamed », « DAF »] */
    public static function personne(string $texte): array
    {
        $precision = preg_match('/\(([^)]*)\)/u', $texte, $m) ? trim($m[1]) : null;

        return [trim(preg_replace('/\s*\([^)]*\)/u', '', $texte)), $precision];
    }

    /** Montant en GNF : « 20 000 000 » → 20000000 ; « Sans plafond » → null */
    public static function montant(?string $texte): ?float
    {
        $chiffres = preg_replace('/[^\d]/', '', (string) $texte);

        return $chiffres === '' ? null : (float) $chiffres;
    }
}
