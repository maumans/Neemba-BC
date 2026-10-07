<?php

namespace App\Support;

/**
 * Montant en lettres (RG-BC-08, ANO-01) — valeur de référence, enregistrée sur le bon.
 *
 * - trait d'union sous cent (« quatre-vingt-deux »), « et » pour 21, 31… 71 ;
 * - « cent » et « vingt » prennent un s quand ils terminent le nombre ou précèdent
 *   million(s) / milliard(s), jamais devant « mille » (« deux cent mille », « deux cents millions ») ;
 * - « de » quand le montant se termine par million(s) / milliard(s) (« un million de francs guinéens »).
 *
 * Même algorithme côté écran : resources/js/utils/nombreEnLettres.js.
 * Cas de référence : tests/fixtures/montants_lettres.json.
 */
class MontantEnLettres
{
    private const UNITES = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
        'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

    private const DIZAINES = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

    /** Montant en GNF en lettres : « un million deux cent mille francs guinéens » */
    public static function convertir(float|int|string $montant): string
    {
        $n = (int) floor(abs((float) $montant));

        $de = $n >= 1_000_000 && $n % 1_000_000 === 0 ? ' de' : '';
        $devise = $n < 2 ? 'franc guinéen' : 'francs guinéens';

        return self::nombreEnMots($n) . $de . ' ' . $devise;
    }

    /** Nombre entier positif en lettres (sans devise) */
    public static function nombreEnMots(int $n): string
    {
        if ($n === 0) {
            return 'zéro';
        }

        $milliards = intdiv($n, 1_000_000_000);
        $millions = intdiv($n % 1_000_000_000, 1_000_000);
        $milliers = intdiv($n % 1_000_000, 1_000);
        $unites = $n % 1_000;
        $mots = [];

        if ($milliards > 0) {
            $mots[] = $milliards === 1 ? 'un milliard' : self::centaine($milliards, true) . ' milliards';
        }
        if ($millions > 0) {
            $mots[] = $millions === 1 ? 'un million' : self::centaine($millions, true) . ' millions';
        }
        if ($milliers > 0) {
            $mots[] = $milliers === 1 ? 'mille' : self::centaine($milliers, false) . ' mille';
        }
        if ($unites > 0) {
            $mots[] = self::centaine($unites, true);
        }

        return implode(' ', $mots);
    }

    /** Nombre de 1 à 999 ; $pluriel = false devant « mille » (cent et vingt invariables) */
    private static function centaine(int $n, bool $pluriel): string
    {
        $centaines = intdiv($n, 100);
        $reste = $n % 100;
        $mots = [];

        if ($centaines > 0) {
            $cent = $centaines === 1 ? 'cent' : self::UNITES[$centaines] . ' cent';
            $mots[] = $reste === 0 && $centaines > 1 && $pluriel ? $cent . 's' : $cent;
        }

        if ($reste > 0) {
            $mots[] = self::dizaine($reste, $pluriel);
        }

        return implode(' ', $mots);
    }

    /** Nombre de 1 à 99 */
    private static function dizaine(int $n, bool $pluriel): string
    {
        if ($n < 20) {
            return self::UNITES[$n];
        }

        $rang = intdiv($n, 10);
        $unite = $n % 10;

        /* 70-79 et 90-99 : soixante-dix…, quatre-vingt-dix… */
        if ($rang === 7 || $rang === 9) {
            $base = self::DIZAINES[$rang];
            $sousNombre = $n - ($rang === 7 ? 60 : 80);

            return $sousNombre === 11 && $rang === 7 ? "{$base} et onze" : "{$base}-" . self::UNITES[$sousNombre];
        }

        if ($unite === 0) {
            return $rang === 8 && $pluriel ? 'quatre-vingts' : self::DIZAINES[$rang];
        }

        return $unite === 1 && $rang !== 8
            ? self::DIZAINES[$rang] . ' et un'
            : self::DIZAINES[$rang] . '-' . self::UNITES[$unite];
    }
}
