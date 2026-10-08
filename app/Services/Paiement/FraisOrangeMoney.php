<?php

namespace App\Services\Paiement;

use App\Models\Parametre;

/**
 * Frais Orange Money (spec v2.2, §6.6) : frais = arrondi_sup(total × taux(palier(total))).
 *
 * - Le palier se détermine sur le total du bon, pas par tranche.
 * - Les frais s'ajoutent au montant versé : montant versé = total + frais. Le seuil du visa DP porte sur la dépense, hors frais.
 * - Hors paliers (≤ 100 000 GNF ou > 15 000 000 GNF par défaut, PO-01), pas de calcul : les frais sont saisis au paiement.
 *
 * Exemples de la spec : 3 250 000 → 32 500 → 3 282 500 ; 7 000 000 → 56 000 → 7 056 000 ; 8 250 000 → 66 000 → 8 316 000.
 */
final class FraisOrangeMoney
{
    public const PALIERS_DEFAUT = [
        ['de' => 100001, 'a' => 5000000, 'taux' => 1],
        ['de' => 5000001, 'a' => 15000000, 'taux' => 0.8],
    ];

    /**
     * Frais calculés pour ce total, ou null hors paliers (frais à saisir au paiement).
     * $paliers : grille figée (ODM validé, RG-M12-25) ; à défaut, celle du paramètre.
     *
     * @return array{frais: int, taux: float, montant_verse: float}|null
     */
    public static function calculer(float $total, ?array $paliers = null): ?array
    {
        $palier = collect($paliers ?? self::paliers())->first(fn (array $p) => $total >= $p['de'] && $total <= $p['a']);
        if (!$palier) {
            return null;
        }

        /* Taux en points de base pour rester en calcul entier : 0,8 % → 80 */
        $pointsDeBase = (int) round($palier['taux'] * 100);
        $frais = (int) ceil(round($total * $pointsDeBase / 10000, 6));

        return ['frais' => $frais, 'taux' => (float) $palier['taux'], 'montant_verse' => $total + $frais];
    }

    /**
     * Paliers du paramètre « frais_om_paliers », triés ; ceux du §6.6 si le paramètre est absent ou illisible.
     *
     * @return array<int, array{de: float, a: float, taux: float}>
     */
    public static function paliers(): array
    {
        $paliers = Parametre::valeur('frais_om_paliers');
        if (!is_array($paliers) || self::erreurPaliers($paliers) !== null) {
            $paliers = self::PALIERS_DEFAUT;
        }

        return collect($paliers)
            ->map(fn (array $p) => ['de' => (float) $p['de'], 'a' => (float) $p['a'], 'taux' => (float) $p['taux']])
            ->sortBy('de')
            ->values()
            ->all();
    }

    /** Contrôle d'une grille saisie dans le paramétrage : null si elle est valable */
    public static function erreurPaliers(mixed $paliers): ?string
    {
        if (!is_array($paliers) || $paliers === [] || !array_is_list($paliers)) {
            return 'La grille doit être une liste de paliers : [{"de":100001,"a":5000000,"taux":1}, …].';
        }

        $precedent = null;
        foreach (collect($paliers)->sortBy(fn ($p) => is_array($p) ? ($p['de'] ?? 0) : 0) as $palier) {
            if (!is_array($palier) || !isset($palier['de'], $palier['a'], $palier['taux'])
                || !is_numeric($palier['de']) || !is_numeric($palier['a']) || !is_numeric($palier['taux'])) {
                return 'Chaque palier indique « de », « a » (GNF) et « taux » (%).';
            }
            if ($palier['de'] < 0 || $palier['a'] < $palier['de'] || $palier['taux'] <= 0 || $palier['taux'] > 100) {
                return 'Palier incohérent : « de » ≤ « a » et un taux compris entre 0 et 100 %.';
            }
            if ($precedent !== null && $palier['de'] <= $precedent['a']) {
                return 'Deux paliers se chevauchent.';
            }
            $precedent = $palier;
        }

        return null;
    }
}
