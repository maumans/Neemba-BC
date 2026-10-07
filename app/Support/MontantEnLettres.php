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

    /** Mots d'un nombre écrit en lettres, sans accent (lecture d'un ticket) */
    private const VALEURS_LUES = [
        'zero' => 0, 'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5, 'six' => 6,
        'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10, 'onze' => 11, 'douze' => 12, 'treize' => 13,
        'quatorze' => 14, 'quinze' => 15, 'seize' => 16, 'trente' => 30, 'quarante' => 40, 'cinquante' => 50,
        'soixante' => 60,
    ];

    /**
     * Montant écrit en lettres → nombre (RG-BC-22 : comparer les montants en chiffres et en lettres d'un ticket).
     * Tolère majuscules, accents absents, traits d'union ou espaces, mots de devise ; null si aucun nombre n'est reconnu.
     * Ex. « CINQ CENT QUATRE VINGT DIX SEPT MILLE FRANCS GUINEENS » → 597 000.
     */
    public static function lire(?string $texte): ?int
    {
        $texte = strtr(mb_strtolower((string) $texte), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'ç' => 'c']);
        $mots = preg_split('/[^a-z]+/', $texte, -1, PREG_SPLIT_NO_EMPTY);

        $total = 0;
        $groupe = 0;
        $reconnu = false;
        $precedent = null;

        foreach ($mots as $mot) {
            if (array_key_exists($mot, self::VALEURS_LUES)) {
                $groupe += self::VALEURS_LUES[$mot];
            } elseif (in_array($mot, ['vingt', 'vingts'], true)) {
                /* « quatre-vingt » : le 4 déjà compté devient 80 */
                $groupe += $precedent === 'quatre' ? 76 : 20;
            } elseif (in_array($mot, ['cent', 'cents'], true)) {
                $groupe = max($groupe, 1) * 100;
            } elseif (in_array($mot, ['mille', 'mil'], true)) {
                $total += max($groupe, 1) * 1_000;
                $groupe = 0;
            } elseif (in_array($mot, ['million', 'millions'], true)) {
                $total += max($groupe, 1) * 1_000_000;
                $groupe = 0;
            } elseif (in_array($mot, ['milliard', 'milliards'], true)) {
                $total += max($groupe, 1) * 1_000_000_000;
                $groupe = 0;
            } else {
                $precedent = $mot;
                continue;   // « et », « de », « francs », « guinéens »…
            }
            $reconnu = true;
            $precedent = $mot;
        }

        return $reconnu ? $total + $groupe : null;
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
