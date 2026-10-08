<?php

namespace App\Support;

use App\Models\Parametre;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jours ouvrés (spec v2.2) : du lundi au vendredi, hors jours fériés du paramètre « jours_feries ».
 *
 * Sert à l'échéance des bons provisoires (RG-M07-02 : 3 jours ouvrés après le retour de mission, 2 jours ouvrés
 * après le décaissement sinon) et au rappel des missions longues (RG-M12-28).
 *
 * Le paramètre liste des dates « MM-JJ » (fête à date fixe, chaque année) ou « AAAA-MM-JJ » (fête mobile).
 */
final class JoursOuvres
{
    public static function estOuvre(CarbonInterface $jour): bool
    {
        if ($jour->isWeekend()) {
            return false;
        }

        $feries = self::feries();

        return !in_array($jour->format('m-d'), $feries['fixes'], true)
            && !in_array($jour->format('Y-m-d'), $feries['dates'], true);
    }

    /**
     * Date située $nombre jours ouvrés après $depart (le jour de départ ne compte pas).
     * Avec 0, le jour ouvré qui suit ou égale $depart.
     */
    public static function ajouter(CarbonInterface $depart, int $nombre): CarbonImmutable
    {
        $jour = CarbonImmutable::instance($depart)->startOfDay();
        if ($nombre <= 0) {
            while (!self::estOuvre($jour)) {
                $jour = $jour->addDay();
            }

            return $jour;
        }

        while ($nombre > 0) {
            $jour = $jour->addDay();
            if (self::estOuvre($jour)) {
                $nombre--;
            }
        }

        return $jour;
    }

    /**
     * Date située $nombre jours ouvrés avant $fin (le jour de fin ne compte pas).
     */
    public static function retrancher(CarbonInterface $fin, int $nombre): CarbonImmutable
    {
        $jour = CarbonImmutable::instance($fin)->startOfDay();
        while ($nombre > 0) {
            $jour = $jour->subDay();
            if (self::estOuvre($jour)) {
                $nombre--;
            }
        }

        return $jour;
    }

    /**
     * Jours fériés du paramètre, validés : les entrées mal formées sont ignorées.
     *
     * @return array{fixes: string[], dates: string[]}
     */
    public static function feries(): array
    {
        $fixes = [];
        $dates = [];
        foreach (self::morceaux((string) Parametre::valeur('jours_feries', '')) as $morceau) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $morceau) && self::dateValide($morceau)) {
                $dates[] = $morceau;
            } elseif (preg_match('/^\d{2}-\d{2}$/', $morceau) && self::dateValide("2024-{$morceau}")) {
                $fixes[] = $morceau;
            }
        }

        return ['fixes' => $fixes, 'dates' => $dates];
    }

    /**
     * Entrées invalides d'une liste de jours fériés (contrôle à la saisie du paramètre).
     *
     * @return string[]
     */
    public static function entreesInvalides(string $liste): array
    {
        return array_values(array_filter(self::morceaux($liste), fn (string $morceau) => !(
            (preg_match('/^\d{4}-\d{2}-\d{2}$/', $morceau) && self::dateValide($morceau))
            || (preg_match('/^\d{2}-\d{2}$/', $morceau) && self::dateValide("2024-{$morceau}"))
        )));
    }

    /** @return string[] */
    private static function morceaux(string $liste): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $liste)), fn ($m) => $m !== ''));
    }

    /** 2024 est bissextile : « 02-29 » reste admis comme date fixe */
    private static function dateValide(string $date): bool
    {
        [$annee, $mois, $jour] = array_map('intval', explode('-', $date));

        return checkdate($mois, $jour, $annee);
    }
}
