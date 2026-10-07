<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Conventions d'affichage communes (SFD §1.4), côté serveur : PDF, e-mails, libellés *_format.
 *
 * - Montants : séparateur de milliers espace insécable, sans décimale, suivi de « GNF »
 * - Dates : JJ/MM/AAAA ; date-heure JJ/MM/AAAA HH:MM (fuseau Africa/Conakry)
 * - Durées : « 2 h 15 min » sous 24 h ; « 3 j 4 h » au-delà
 *
 * Mêmes règles côté écran : resources/js/utils/format.js.
 */
class Format
{
    public const ESPACE_INSECABLE = "\u{00A0}";

    public const FUSEAU_HORAIRE = 'Africa/Conakry';

    /** Nombre entier avec séparateur de milliers : 1 500 000 */
    public static function nombre(float|int|string|null $valeur): string
    {
        return number_format((float) ($valeur ?? 0), 0, ',', self::ESPACE_INSECABLE);
    }

    /** Montant en GNF : 1 500 000 GNF */
    public static function montant(float|int|string|null $valeur): string
    {
        return self::nombre($valeur) . self::ESPACE_INSECABLE . 'GNF';
    }

    /** Date : 05/10/2026 (« — » si absente) */
    public static function date(CarbonInterface|string|null $valeur): string
    {
        $date = self::enDate($valeur);

        return $date ? $date->format('d/m/Y') : '—';
    }

    /** Date et heure : 05/10/2026 14:30 (« — » si absente) */
    public static function dateHeure(CarbonInterface|string|null $valeur): string
    {
        $date = self::enDate($valeur);

        return $date ? $date->format('d/m/Y H:i') : '—';
    }

    /** Durée en minutes : « 45 min », « 2 h 15 min », « 3 j 4 h » */
    public static function duree(int|float|null $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        $total = max(0, (int) floor($minutes));
        $nbsp = self::ESPACE_INSECABLE;

        if ($total < 60) {
            return "{$total}{$nbsp}min";
        }

        if ($total < 24 * 60) {
            $heures = intdiv($total, 60);
            $reste = $total % 60;

            return $reste ? "{$heures}{$nbsp}h {$reste}{$nbsp}min" : "{$heures}{$nbsp}h";
        }

        $jours = intdiv($total, 24 * 60);
        $heures = intdiv($total % (24 * 60), 60);

        return $heures ? "{$jours}{$nbsp}j {$heures}{$nbsp}h" : "{$jours}{$nbsp}j";
    }

    /** Durée écoulée entre deux dates (par défaut jusqu'à maintenant), formatée */
    public static function dureeEntre(CarbonInterface|string|null $debut, CarbonInterface|string|null $fin = null): string
    {
        $dateDebut = self::enDate($debut);
        if (!$dateDebut) {
            return '—';
        }

        $dateFin = self::enDate($fin) ?? now();

        return self::duree($dateDebut->diffInMinutes($dateFin, false));
    }

    /**
     * Texte pour SMS : les espaces insécables sont remplacés par des espaces simples
     * (sinon le SMS passe en encodage Unicode : 70 caractères par message au lieu de 160).
     */
    public static function pourSms(string $texte): string
    {
        return str_replace([self::ESPACE_INSECABLE, "\u{202F}"], ' ', $texte);
    }

    private static function enDate(CarbonInterface|string|null $valeur): ?CarbonInterface
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        $date = $valeur instanceof CarbonInterface ? $valeur->copy() : Carbon::parse($valeur);

        return $date->setTimezone(self::FUSEAU_HORAIRE);
    }
}
