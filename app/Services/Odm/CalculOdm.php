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
 *
 * Prise en charge par ligne (Q49) : chaque ligne est à la charge de Neemba, du client avec avance de Neemba
 * (dans le bon, à refacturer) ou du client qui la paie directement (hors bon). Le total reste le coût complet
 * de la mission ; le bon de caisse ne porte que les lignes que Neemba décaisse.
 */
final class CalculOdm
{
    /** Qui prend en charge une ligne de frais (Q49) */
    public const PRISES_EN_CHARGE = [
        'neemba' => 'Neemba',
        'client_avance' => 'Client, avancé par Neemba (refacturé)',
        'client_direct' => 'Client, payé directement',
    ];

    /** Lignes de frais d'un participant, intérieur et extérieur confondus */
    public const LIGNES = ['indemnite_1', 'indemnite_2', 'hebergement', 'rattrapage', 'indemnite', 'hebergement_retour'];

    /** Ligne dont le montant n'est connu qu'à la clôture (facture d'hébergement payée au retour, Q24) */
    public const LIGNE_A_LA_CLOTURE = 'hebergement_retour';

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
     * @param array{base_vie?: bool, statut_cadre?: ?string, hebergement_facture?: float|int|string|null, rattrapage?: bool,
     *              prises_en_charge?: ?array, prise_defaut?: string} $participant
     *        « rattrapage » : prolongation, et le participant n'était pas logé sur base vie au segment précédent (RG-M12-18)
     *        « prises_en_charge » : ligne => neemba | client_avance | client_direct ; une ligne absente prend « prise_defaut »
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

        /* Q49 : ventilation selon qui prend en charge chaque ligne ; les frais OM portent sur ce que Neemba verse */
        $prises = self::prisesNormalisees($participant['prises_en_charge'] ?? null, $participant['prise_defaut'] ?? 'neemba');
        $lignes = self::lignes($segment['type'] ?? 'interieur', $resultat, $segment['hebergement_exterieur'] ?? null);
        $repartition = self::repartition($lignes, $prises);
        $frais = $repartition['montant_bon'] === null ? null : FraisOrangeMoney::calculer($repartition['montant_bon'], $baremes['frais_om_paliers'] ?? null);

        return array_merge($resultat, $repartition, [
            'prises_en_charge' => $prises,
            'lignes' => $lignes,
            'frais_om' => $frais['frais'] ?? null,
            'montant_verse_om' => $frais['montant_verse'] ?? null,
        ]);
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
        ];
    }

    /**
     * Choix de prise en charge complet : une valeur valide pour chaque ligne, le défaut sinon.
     *
     * @return array<string, string>
     */
    public static function prisesNormalisees(?array $prises, string $defaut = 'neemba'): array
    {
        $defaut = array_key_exists($defaut, self::PRISES_EN_CHARGE) ? $defaut : 'neemba';
        $normalisees = [];
        foreach (self::LIGNES as $ligne) {
            $valeur = $prises[$ligne] ?? null;
            $normalisees[$ligne] = is_string($valeur) && array_key_exists($valeur, self::PRISES_EN_CHARGE) ? $valeur : $defaut;
        }

        return $normalisees;
    }

    /**
     * Lignes de frais présentes pour le type d'ODM, avec leur montant :
     * - intérieur : les deux lignes d'indemnité, l'hébergement et la nuitée de rattrapage ;
     * - extérieur : l'indemnité (null sans taux), la facture payée avant le départ, la facture payée au retour
     *   (montant connu à la clôture : null).
     *
     * @param array{indemnite_ligne_1?: ?float, indemnite_ligne_2?: ?float, indemnite?: ?float, hebergement?: ?float, rattrapage?: ?float} $montants
     * @return array<string, float|int|null>
     */
    public static function lignes(string $type, array $montants, ?string $hebergementExterieur = null): array
    {
        if ($type === 'exterieur') {
            $lignes = ['indemnite' => $montants['indemnite'] ?? null];
            if ($hebergementExterieur === 'avant_depart') {
                $lignes['hebergement'] = (float) ($montants['hebergement'] ?? 0);
            }
            if ($hebergementExterieur === 'au_retour') {
                $lignes[self::LIGNE_A_LA_CLOTURE] = null;
            }

            return $lignes;
        }

        return [
            'indemnite_1' => $montants['indemnite_ligne_1'] ?? null,
            'indemnite_2' => $montants['indemnite_ligne_2'] ?? null,
            'hebergement' => (float) ($montants['hebergement'] ?? 0),
            'rattrapage' => (float) ($montants['rattrapage'] ?? 0),
        ];
    }

    /**
     * Ventilation d'un participant (Q49) :
     * - montant du bon : lignes de Neemba et lignes du client avancées par Neemba ;
     * - à refacturer : lignes du client avancées par Neemba ;
     * - payé directement par le client : hors bon.
     * Null si un montant est inconnu (ODM extérieur sans taux). La facture payée au retour n'entre pas ici :
     * son bon complémentaire est préparé à la clôture.
     *
     * @param array<string, float|int|null> $lignes
     * @param array<string, string> $prises
     * @return array{montant_bon: ?float, montant_refacturable: ?float, montant_client_direct: ?float}
     */
    public static function repartition(array $lignes, array $prises): array
    {
        $parts = ['neemba' => 0.0, 'client_avance' => 0.0, 'client_direct' => 0.0];
        foreach ($lignes as $ligne => $montant) {
            if ($ligne === self::LIGNE_A_LA_CLOTURE) {
                continue;
            }
            if ($montant === null) {
                return ['montant_bon' => null, 'montant_refacturable' => null, 'montant_client_direct' => null];
            }
            $parts[$prises[$ligne] ?? 'neemba'] += (float) $montant;
        }

        return [
            'montant_bon' => $parts['neemba'] + $parts['client_avance'],
            'montant_refacturable' => $parts['client_avance'],
            'montant_client_direct' => $parts['client_direct'],
        ];
    }

    /**
     * Prise en charge de l'en-tête déduite des lignes qui ont un montant : neemba, client ou mixte,
     * et le mode des lignes client quand elles ont toutes le même. Null s'il n'y a aucune ligne.
     *
     * @param array<int, array{lignes: array<string, float|int|null>, prises_en_charge: array<string, string>}> $calculs
     * @return array{prise_en_charge: string, mode_client: ?string}|null
     */
    public static function priseEnChargeGlobale(array $calculs): ?array
    {
        $valeurs = [];
        foreach ($calculs as $calcul) {
            foreach ($calcul['lignes'] as $ligne => $montant) {
                /* Une ligne sans objet (montant nul) ne compte pas ; un montant encore inconnu, si */
                if ($montant === null || (float) $montant > 0) {
                    $valeurs[] = $calcul['prises_en_charge'][$ligne] ?? 'neemba';
                }
            }
        }
        $valeurs = array_values(array_unique($valeurs));
        if ($valeurs === []) {
            return null;
        }
        if ($valeurs === ['neemba']) {
            return ['prise_en_charge' => 'neemba', 'mode_client' => null];
        }
        if (count($valeurs) === 1) {
            return ['prise_en_charge' => 'client', 'mode_client' => $valeurs[0] === 'client_direct' ? 'direct' : 'avance'];
        }

        return ['prise_en_charge' => 'mixte', 'mode_client' => null];
    }

    /**
     * Total de l'ODM : somme des totaux des participants (null si l'un d'eux n'est pas calculable, ex. sans taux).
     * Sert aussi aux autres montants de la ventilation (« montant_bon », « montant_refacturable »…).
     *
     * @param array<int, array<string, float|int|null>> $calculs
     */
    public static function total(array $calculs, string $cle = 'total'): ?float
    {
        $total = 0.0;
        foreach ($calculs as $calcul) {
            if (($calcul[$cle] ?? null) === null) {
                return null;
            }
            $total += (float) $calcul[$cle];
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
