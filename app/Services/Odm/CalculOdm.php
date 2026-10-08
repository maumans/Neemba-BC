<?php

namespace App\Services\Odm;

use App\Models\Parametre;
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
 */
final class CalculOdm
{
    /**
     * Barèmes et paramètres en vigueur, enregistrés avec l'ODM à sa validation (RG-M12-25).
     */
    public static function baremesEnVigueur(): array
    {
        return [
            'indemnite_journaliere' => (int) Parametre::valeur('odm_indemnite_journaliere', 250000),
            'libelle_indemnite_1' => (string) Parametre::valeur('odm_libelle_indemnite_1', 'Indemnité de repas'),
            'libelle_indemnite_2' => (string) Parametre::valeur('odm_libelle_indemnite_2', 'Indemnité de déplacement'),
            'hebergement_nuit' => (int) Parametre::valeur('odm_hebergement_nuit', 500000),
            'bareme_fcfa_non_cadre' => (int) Parametre::valeur('odm_bareme_fcfa_non_cadre', 22000),
            'bareme_fcfa_cadre' => (int) Parametre::valeur('odm_bareme_fcfa_cadre', 34000),
            'frais_om_paliers' => FraisOrangeMoney::paliers(),
        ];
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

        if (($segment['type'] ?? 'interieur') === 'exterieur') {
            return self::exterieur($participant, $segment, $baremes, $jours, $rattrapage);
        }

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
