<?php

namespace App\Support;

use App\Models\Parametre;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Heures ouvrées (spec v2.3, §6.7) : les délais de visa courent pendant les heures de bureau, du lundi au vendredi,
 * de 8 h à 17 h par défaut, hors jours fériés (paramètre « jours_feries », voir {@see JoursOuvres}).
 *
 * Un bon soumis le vendredi à 16 h avec un délai de 4 h est donc attendu le lundi à 11 h, et non le samedi matin.
 * Les bornes de la journée sont les paramètres « heure_ouverture » et « heure_fermeture ».
 */
final class HeuresOuvrees
{
    /** Garde-fou : une échéance ne se cherche pas au-delà d'un an de jours fériés consécutifs */
    private const JOURS_MAX = 400;

    /** Bornes de la journée de travail, en minutes depuis minuit */
    public static function bornes(): array
    {
        $ouverture = self::enMinutes(Parametre::valeur('heure_ouverture', '08:00'), 8 * 60);
        $fermeture = self::enMinutes(Parametre::valeur('heure_fermeture', '17:00'), 17 * 60);

        return $fermeture > $ouverture ? [$ouverture, $fermeture] : [8 * 60, 17 * 60];
    }

    /** Durée ouvrée d'une journée, en minutes */
    public static function minutesParJour(): int
    {
        [$ouverture, $fermeture] = self::bornes();

        return $fermeture - $ouverture;
    }

    /**
     * Échéance située $heures heures ouvrées après $depart.
     *
     * Un départ hors des heures de bureau (soir, week-end, jour férié) est d'abord ramené à la prochaine ouverture :
     * le délai ne court pas pendant que les bureaux sont fermés.
     */
    public static function ajouter(CarbonInterface $depart, float $heures): CarbonImmutable
    {
        $restant = (int) round(max(0.0, $heures) * 60);
        $instant = self::prochaineOuverture(CarbonImmutable::instance($depart));
        [, $fermeture] = self::bornes();

        for ($jours = 0; $jours < self::JOURS_MAX; $jours++) {
            $finJournee = self::aLHeure($instant, $fermeture);
            $disponible = (int) $instant->diffInMinutes($finJournee, false);
            if ($restant <= $disponible) {
                return $instant->addMinutes($restant);
            }
            $restant -= $disponible;
            $instant = self::prochaineOuverture($finJournee->addMinute());
        }

        return $instant;
    }

    /** Le délai est-il écoulé ? (échéance atteinte ou dépassée) */
    public static function echu(CarbonInterface $depart, float $heures): bool
    {
        return self::ajouter($depart, $heures)->isPast();
    }

    /**
     * Premier instant ouvré à partir de $instant : lui-même s'il tombe dans les heures de bureau d'un jour ouvré,
     * sinon l'ouverture du prochain jour ouvré.
     */
    public static function prochaineOuverture(CarbonInterface $instant): CarbonImmutable
    {
        [$ouverture, $fermeture] = self::bornes();
        $courant = CarbonImmutable::instance($instant);

        for ($jours = 0; $jours < self::JOURS_MAX; $jours++) {
            if (JoursOuvres::estOuvre($courant)) {
                if ($courant->lt(self::aLHeure($courant, $ouverture))) {
                    return self::aLHeure($courant, $ouverture);
                }
                if ($courant->lt(self::aLHeure($courant, $fermeture))) {
                    return $courant;
                }
            }
            $courant = self::aLHeure($courant->addDay(), $ouverture);
        }

        return $courant;
    }

    /** Même jour que $jour, à $minutes minutes après minuit */
    private static function aLHeure(CarbonInterface $jour, int $minutes): CarbonImmutable
    {
        return CarbonImmutable::instance($jour)->startOfDay()->addMinutes($minutes);
    }

    /** Paramètre « HH:MM » ou nombre d'heures (« 8 », « 8,5 ») ramené à des minutes depuis minuit */
    private static function enMinutes(mixed $valeur, int $defaut): int
    {
        $valeur = trim((string) $valeur);
        if (preg_match('/^(\d{1,2})[:h](\d{2})$/', $valeur, $m)) {
            $minutes = (int) $m[1] * 60 + (int) $m[2];

            return $minutes >= 0 && $minutes <= 24 * 60 ? $minutes : $defaut;
        }
        if (is_numeric(str_replace(',', '.', $valeur))) {
            $minutes = (int) round((float) str_replace(',', '.', $valeur) * 60);

            return $minutes >= 0 && $minutes <= 24 * 60 ? $minutes : $defaut;
        }

        return $defaut;
    }
}
