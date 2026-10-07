<?php

namespace App\Services\LectureTicket;

use App\Models\PieceJointe;
use App\Services\OcrService;

/**
 * Lecteur local : Tesseract sur le serveur, puis repérage des champs dans le texte. Aucune donnée ne quitte
 * le serveur. Peu fiable sur les tickets manuscrits : les confiances restent modestes, l'utilisateur vérifie tout.
 *
 * Activé par LECTEUR_TICKETS=local (Tesseract installé : apt install tesseract-ocr tesseract-ocr-fra).
 */
class LecteurLocal implements LecteurTicket
{
    public function __construct(private OcrService $ocr) {}

    public function nom(): string
    {
        return 'local';
    }

    public function lire(PieceJointe $piece): ?array
    {
        $texte = $this->ocr->extraireTexte($piece->chemin_fichier, (string) $piece->mime_type);

        return trim($texte) === '' ? null : self::analyser($texte);
    }

    /**
     * Champs d'un ticket repérés dans un texte : valeur et confiance (0 si absent).
     *
     * @return array{valeurs: array<string, mixed>, confiances: array<string, int>}
     */
    public static function analyser(string $texte): array
    {
        $valeurs = array_fill_keys(['station', 'date', 'litres', 'montant', 'montant_lettres', 'immatriculation'], null);
        $confiances = array_fill_keys(array_keys($valeurs), 0);
        $lignes = array_values(array_filter(array_map('trim', preg_split('/\R/', $texte))));

        foreach ($lignes as $ligne) {
            if ($valeurs['station'] === null && preg_match('/\b(station|total|shell|star\s*oil|vivo|engen|petro\w*)\b/i', $ligne)) {
                [$valeurs['station'], $confiances['station']] = [mb_substr($ligne, 0, 80), 60];
            }
            if ($valeurs['montant_lettres'] === null && preg_match('/\b(mille|million|francs?)\b/i', $ligne) && !preg_match('/\d{3}/', $ligne)) {
                [$valeurs['montant_lettres'], $confiances['montant_lettres']] = [$ligne, 55];
            }
        }

        if (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/', $texte, $m)) {
            $annee = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            if (checkdate((int) $m[2], (int) $m[1], $annee)) {
                [$valeurs['date'], $confiances['date']] = [sprintf('%04d-%02d-%02d', $annee, $m[2], $m[1]), 75];
            }
        }

        if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:l\b|litres?\b|ltrs?\b)/i', $texte, $m)
            || preg_match('/(?:qt[eé]|quantit[eé]|volume|litres?)\s*[:=]?\s*(\d+(?:[.,]\d{1,2})?)/i', $texte, $m)) {
            [$valeurs['litres'], $confiances['litres']] = [(float) str_replace(',', '.', $m[1]), 65];
        }

        if (preg_match('/(?:montant|total|net\s*[àa]\s*payer|somme)\D{0,15}?(\d{1,3}(?:[\s.]\d{3})+|\d{4,})/i', $texte, $m)) {
            [$valeurs['montant'], $confiances['montant']] = [(int) preg_replace('/\D/', '', $m[1]), 70];
        } elseif (preg_match_all('/\b\d{1,3}(?:[\s.]\d{3})+\b|\b\d{5,}\b/', $texte, $nombres)) {
            $plusGrand = max(array_map(fn ($n) => (int) preg_replace('/\D/', '', $n), $nombres[0]));
            [$valeurs['montant'], $confiances['montant']] = [$plusGrand, 45];
        }

        if (preg_match('/(?:immat\w*|plaque|v[eé]hicule)\s*[:.]?\s*([A-Z]{1,3}[\s\-]?\d{3,4}(?:[\s\-]?[A-Z0-9]{1,3})?)/i', $texte, $m)) {
            [$valeurs['immatriculation'], $confiances['immatriculation']] = [strtoupper(trim($m[1])), 70];
        } elseif (preg_match('/\b([A-Z]{2}[\s\-]\d{4}(?:[\s\-][A-Z0-9]{1,2})?)\b/', $texte, $m)) {
            [$valeurs['immatriculation'], $confiances['immatriculation']] = [trim($m[1]), 45];
        }

        return ['valeurs' => $valeurs, 'confiances' => $confiances];
    }
}
