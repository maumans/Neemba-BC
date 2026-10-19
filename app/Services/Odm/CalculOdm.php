<?php

namespace App\Services\Odm;

use App\Services\Paiement\FraisOrangeMoney;
use Carbon\CarbonInterface;

/**
 * Calcul des indemnités d'un ODM (spec v2.2, §7.3, RG-M12-07 à RG-M12-10, RG-M12-18, RG-M12-19 ; annexe B).
 *
 * Calcul pur : il reçoit les barèmes en paramètre et ne lit rien en base, pour être rejoué à l'identique
 * avec les barèmes figés à la validation (RG-M12-25). Le serveur fait foi : l'écran affiche ce calcul.
 *
 * | Élément     | Intérieur                                         | Extérieur                                              |
 * |-------------|---------------------------------------------------|--------------------------------------------------------|
 * | Jours       | retour − départ + 1                               | idem                                                   |
 * | Nuits       | jours − 1 ; 0 sur base vie                        | jours − 1, sans objet si la filiale d'accueil héberge  |
 * | Rattrapage  | prolongation : + 1 nuit du segment précédent      | idem (compté, l'hébergement suit la facture)           |
 * | Indemnité   | jours × 250 000, en 2 lignes égales               | jours × 22 000 / 34 000 FCFA × taux → GNF              |
 * | Hébergement | nuits × 500 000                                   | filiale : 0 ; avant le départ : facture ; au retour : 0 |
 *
 * Spec v2.3 : le calcul produit des lignes de frais (RG-M12-30), réparties en parts de prise en charge
 * (RG-M12-31). Le pourcentage d'une part fait foi ; son montant découle de la ligne (décision Q52).
 */
final class CalculOdm
{
    /**
     * Barèmes en vigueur à une date (aujourd'hui par défaut) pour l'entité de l'ODM, enregistrés avec l'ODM à sa
     * soumission puis à sa validation (RG-M12-07, RG-M12-25). Spec v2.4, RG-M02-10 : barèmes datés du référentiel.
     */
    public static function baremesEnVigueur(?CarbonInterface $date = null, ?int $entite = null): array
    {
        return \App\Models\BaremeOdm::tous($date, $entite) + ['frais_om_paliers' => FraisOrangeMoney::paliers()];
    }

    /** Jours de mission : le jour du départ et celui du retour comptent (0 si les dates sont incohérentes) */
    public static function jours(?CarbonInterface $depart, ?CarbonInterface $retour): int
    {
        if (!$depart || !$retour) {
            return 0;
        }
        $depart = $depart->copy()->startOfDay();
        $retour = $retour->copy()->startOfDay();

        return $retour->lessThan($depart) ? 0 : (int) $depart->diffInDays($retour) + 1;
    }

    /**
     * Calcul d'un participant sur un segment.
     *
     * @param array{base_vie?: bool, statut_cadre?: ?string, hebergement_facture?: float|int|string|null, rattrapage?: bool} $participant
     *        « rattrapage » : prolongation, et le participant n'était pas logé sur base vie au segment précédent (RG-M12-18)
     * @param array{type: string, jours: int, hebergement_exterieur?: ?string, taux?: float|int|string|null} $segment
     *        « taux » : GNF pour 1 FCFA (ODM extérieur) ; sans taux, l'indemnité extérieure n'est pas convertie
     */
    public static function participant(array $participant, array $segment, array $baremes): array
    {
        $jours = max(0, (int) $segment['jours']);
        $baseVie = (bool) ($participant['base_vie'] ?? false);
        $rattrapage = !empty($participant['rattrapage']) && $jours > 0;

        $resultat = ($segment['type'] ?? 'interieur') === 'exterieur'
            ? self::exterieur($participant, $segment, $baremes, $jours, $rattrapage)
            : self::interieur($baseVie, $jours, $rattrapage, $baremes);
        $resultat['lignes'] = self::lignesFrais($segment['type'] ?? 'interieur', $resultat, $segment['hebergement_exterieur'] ?? null, $baremes);

        return $resultat;
    }

    private static function interieur(bool $baseVie, int $jours, bool $rattrapage, array $baremes): array
    {
        /* Intérieur : 2 lignes égales de 125 000 × jours (RG-M12-08) ; hébergement nul sur base vie (RG-M12-09) */
        $nuits = $baseVie || $jours === 0 ? 0 : $jours - 1;
        $nuitRattrapage = $rattrapage && !$baseVie ? 1 : 0;
        $indemnite = $jours * $baremes['indemnite_journaliere'];
        $ligne1 = $jours * intdiv($baremes['indemnite_journaliere'], 2);
        $hebergement = $nuits * $baremes['hebergement_nuit'];
        $montantRattrapage = $nuitRattrapage * $baremes['hebergement_nuit'];

        return self::resultat($jours, $nuits, $nuitRattrapage, null, $indemnite, $ligne1, $hebergement, $montantRattrapage, $baremes);
    }

    private static function exterieur(array $participant, array $segment, array $baremes, int $jours, bool $rattrapage): array
    {
        $mode = $segment['hebergement_exterieur'] ?? null;

        /* Hébergé par la filiale d'accueil : nuits sans objet (RG-M12-26) */
        $nuits = $mode === 'filiale' || $jours === 0 ? 0 : $jours - 1;
        $nuitRattrapage = $rattrapage && $mode !== 'filiale' ? 1 : 0;

        $statut = $participant['statut_cadre'] ?? null;
        $bareme = match ($statut) {
            'cadre' => $baremes['bareme_fcfa_cadre'],
            'non_cadre' => $baremes['bareme_fcfa_non_cadre'],
            default => null,   // statut inconnu : MSG-M12-04 à la soumission
        };
        $indemniteFcfa = $bareme === null ? null : $jours * $bareme;
        $taux = isset($segment['taux']) && is_numeric($segment['taux']) && (float) $segment['taux'] > 0 ? (float) $segment['taux'] : null;
        $indemnite = $indemniteFcfa !== null && $taux !== null ? (int) round($indemniteFcfa * $taux) : null;

        /* Facture payée avant le départ : ajoutée au bon du participant (décision Q24) ; au retour : BD complémentaire à la clôture */
        $hebergement = $mode === 'avant_depart' && is_numeric($participant['hebergement_facture'] ?? null)
            ? (float) $participant['hebergement_facture']
            : 0;

        return self::resultat($jours, $nuits, $nuitRattrapage, $indemniteFcfa, $indemnite, null, $hebergement, 0, $baremes);
    }

    private static function resultat(
        int $jours, int $nuits, int $nuitRattrapage, ?int $indemniteFcfa, ?int $indemnite, ?int $ligne1,
        float|int $hebergement, int $rattrapage, array $baremes,
    ): array {
        $total = $indemnite === null ? null : $indemnite + $hebergement + $rattrapage;
        $frais = $total === null ? null : FraisOrangeMoney::calculer($total, $baremes['frais_om_paliers'] ?? null);

        return [
            'jours' => $jours,
            'nuits' => $nuits,
            'nuit_rattrapage' => $nuitRattrapage,
            'indemnite_fcfa' => $indemniteFcfa,
            'indemnite' => $indemnite,
            'indemnite_ligne_1' => $ligne1,
            'indemnite_ligne_2' => $ligne1 === null ? null : $indemnite - $ligne1,
            'hebergement' => $hebergement,
            'rattrapage' => $rattrapage,
            'total' => $total,
            /* Pour information : frais si le caissier retient Orange Money (null hors paliers) */
            'frais_om' => $frais['frais'] ?? null,
            'montant_verse_om' => $frais['montant_verse'] ?? null,
        ];
    }

    /**
     * RG-M12-30 : lignes de frais d'un participant sur un segment, avec quantité, taux et montant.
     * - indemnité : jours × indemnité journalière ; à l'étranger, jours × barème FCFA (devise FCFA), montant en GNF au taux ;
     * - hébergement : nuits × prix de la nuit (intérieur) ;
     * - nuitée de rattrapage : 1 × prix de la nuit ;
     * - hébergement à l'étranger payé en GNF : la facture (avant le départ), ou montant connu à la clôture (au retour).
     * Une ligne sans objet (montant nul : base vie, filiale d'accueil, pas de rattrapage) n'est pas produite.
     *
     * @return array<string, array{quantite: float, taux: ?float, devise: string, montant: ?float}>
     */
    public static function lignesFrais(string $type, array $resultat, ?string $hebergementExterieur, array $baremes): array
    {
        $lignes = [];
        if ($resultat['jours'] > 0) {
            $lignes['indemnite'] = $type === 'exterieur'
                ? ['quantite' => (float) $resultat['jours'], 'taux' => $resultat['indemnite_fcfa'] !== null ? $resultat['indemnite_fcfa'] / $resultat['jours'] : null,
                    'devise' => 'FCFA', 'montant' => $resultat['indemnite'] !== null ? (float) $resultat['indemnite'] : null]
                : ['quantite' => (float) $resultat['jours'], 'taux' => (float) $baremes['indemnite_journaliere'], 'devise' => 'GNF', 'montant' => (float) $resultat['indemnite']];
        }
        if ($type === 'exterieur') {
            if ($hebergementExterieur === 'avant_depart' && (float) $resultat['hebergement'] > 0) {
                $lignes['hebergement_exterieur'] = ['quantite' => 1.0, 'taux' => (float) $resultat['hebergement'], 'devise' => 'GNF', 'montant' => (float) $resultat['hebergement']];
            }
            if ($hebergementExterieur === 'au_retour' && $resultat['jours'] > 0) {
                $lignes['hebergement_exterieur'] = ['quantite' => 1.0, 'taux' => null, 'devise' => 'GNF', 'montant' => null];
            }

            return $lignes;
        }
        if ($resultat['nuits'] > 0) {
            $lignes['hebergement'] = ['quantite' => (float) $resultat['nuits'], 'taux' => (float) $baremes['hebergement_nuit'], 'devise' => 'GNF', 'montant' => (float) $resultat['hebergement']];
        }
        if ($resultat['nuit_rattrapage'] > 0) {
            $lignes['rattrapage'] = ['quantite' => (float) $resultat['nuit_rattrapage'], 'taux' => (float) $baremes['hebergement_nuit'], 'devise' => 'GNF', 'montant' => (float) $resultat['rattrapage']];
        }

        return $lignes;
    }

    /**
     * RG-M12-31 : montant de chaque part d'une ligne, d'après son pourcentage (décision Q52). Quand les pourcentages
     * font 100 %, la part la plus forte reçoit l'arrondi : la somme des parts égale toujours la ligne.
     *
     * @param array<int, float|int|string> $pourcentages
     * @return array<int, ?float> montants, dans l'ordre des parts (null si le montant de la ligne est inconnu)
     */
    public static function repartirLigne(?float $montant, array $pourcentages): array
    {
        if ($montant === null) {
            return array_map(fn () => null, $pourcentages);
        }
        $montants = array_map(fn ($p) => round($montant * (float) $p / 100), $pourcentages);
        if ($montants !== [] && abs(array_sum(array_map('floatval', $pourcentages)) - 100) < 0.001) {
            $plusForte = array_keys($pourcentages, max($pourcentages))[0];
            $montants[$plusForte] += round($montant) - array_sum($montants);
        }

        return array_map('floatval', $montants);
    }

    /** Pourcentage d'une part saisie en montant (4 décimales) */
    public static function pourcentageDe(float $montantPart, float $montantLigne): float
    {
        return $montantLigne > 0 ? round($montantPart / $montantLigne * 100, 4) : 0.0;
    }

    /**
     * RG-M12-34, RG-M12-37 : récapitulatif d'un ensemble de parts — Neemba, clients, et par client puis par OR.
     *
     * @param iterable<array{payeur: string, client_id: ?int, ordre_reparation_id?: ?int, montant: ?float}> $parts
     * @return array{neemba: float, client: float, par_client: array<int, array{montant: float, par_or: array<int|string, float>}>}
     */
    public static function recapitulatif(iterable $parts): array
    {
        $recap = ['neemba' => 0.0, 'client' => 0.0, 'par_client' => []];
        foreach ($parts as $part) {
            $montant = (float) ($part['montant'] ?? 0);
            if ($part['payeur'] !== 'client') {
                $recap['neemba'] += $montant;
                continue;
            }
            $recap['client'] += $montant;
            $client = (int) ($part['client_id'] ?? 0);
            $recap['par_client'][$client]['montant'] = ($recap['par_client'][$client]['montant'] ?? 0) + $montant;
            $or = $part['ordre_reparation_id'] ?? null ?: 'sans_or';
            $recap['par_client'][$client]['par_or'][$or] = ($recap['par_client'][$client]['par_or'][$or] ?? 0) + $montant;
        }

        return $recap;
    }

    /**
     * RG-M12-36 : frais Orange Money d'un bon répartis entre les parts qu'il paie, au prorata de leur montant (défaut)
     * ou à 100 % sur les parts Neemba (PO-24). L'arrondi va à la part la plus forte du payeur retenu.
     *
     * @param array<int, array{payeur: string, montant: ?float}> $parts
     * @return array<int, float> frais par part, dans l'ordre des parts
     */
    public static function repartirFrais(float $frais, array $parts, string $mode = 'prorata'): array
    {
        $eligibles = array_map(fn ($p) => $mode === 'neemba' && $p['payeur'] !== 'neemba' ? 0.0 : (float) ($p['montant'] ?? 0), $parts);
        $base = array_sum($eligibles);
        if ($base <= 0) {
            /* Rien côté Neemba (tout client) : les frais suivent le prorata */
            $eligibles = array_map(fn ($p) => (float) ($p['montant'] ?? 0), $parts);
            $base = array_sum($eligibles);
        }
        if ($base <= 0) {
            return array_map(fn () => 0.0, $parts);
        }
        $repartis = array_map(fn ($m) => round($frais * $m / $base), $eligibles);
        $plusForte = array_keys($eligibles, max($eligibles))[0];
        $repartis[$plusForte] += round($frais) - array_sum($repartis);

        return array_map('floatval', $repartis);
    }

    /**
     * Total de l'ODM : somme des totaux des participants (null si l'un d'eux n'est pas calculable, ex. sans taux).
     *
     * @param array<int, array{total: float|int|null}> $calculs
     */
    public static function total(array $calculs): ?float
    {
        $total = 0.0;
        foreach ($calculs as $calcul) {
            if ($calcul['total'] === null) {
                return null;
            }
            $total += (float) $calcul['total'];
        }

        return $total;
    }

    /**
     * RG-M12-19 : contrôle d'une mission pour un participant non logé sur base vie.
     * Sur toute la chaîne de segments, nuits payées (nuits + nuitées de rattrapage) = jours − 1.
     *
     * @param array<int, array{jours: int, nuits: int, nuit_rattrapage?: int}> $segments
     * @return array{jours: int, nuits: int, nuits_attendues: int, ecart: int, coherent: bool}
     */
    public static function controleMission(array $segments): array
    {
        $jours = array_sum(array_map(fn ($s) => (int) $s['jours'], $segments));
        $nuits = array_sum(array_map(fn ($s) => (int) $s['nuits'] + (int) ($s['nuit_rattrapage'] ?? 0), $segments));
        $attendues = max(0, $jours - 1);

        return [
            'jours' => $jours,
            'nuits' => $nuits,
            'nuits_attendues' => $attendues,
            'ecart' => $nuits - $attendues,
            'coherent' => $nuits === $attendues,
        ];
    }
}
