<?php

namespace Tests\Unit;

use App\Services\Odm\CalculOdm;
use App\Services\Paiement\FraisOrangeMoney;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Calcul des indemnités d'un ODM (spec v2.2, §7.3, RG-M12-07 à RG-M12-19) sur les exemples de l'annexe B.
 * Calcul pur : les barèmes sont fournis, rien n'est lu en base.
 */
class CalculOdmTest extends TestCase
{
    private const BAREMES = [
        'indemnite_journaliere' => 250000,
        'libelle_indemnite_1' => 'Indemnité de repas',
        'libelle_indemnite_2' => 'Indemnité de déplacement',
        'hebergement_nuit' => 500000,
        'bareme_fcfa_non_cadre' => 22000,
        'bareme_fcfa_cadre' => 34000,
        'frais_om_paliers' => FraisOrangeMoney::PALIERS_DEFAUT,
    ];

    private function interieur(string $depart, string $retour, array $participant = []): array
    {
        $jours = CalculOdm::jours(Carbon::parse($depart), Carbon::parse($retour));

        return CalculOdm::participant($participant, ['type' => 'interieur', 'jours' => $jours], self::BAREMES);
    }

    public function test_les_jours_comptent_le_depart_et_le_retour(): void
    {
        $this->assertSame(5, CalculOdm::jours(Carbon::parse('2026-09-22'), Carbon::parse('2026-09-26')));
        $this->assertSame(1, CalculOdm::jours(Carbon::parse('2026-09-22'), Carbon::parse('2026-09-22')));
        $this->assertSame(0, CalculOdm::jours(Carbon::parse('2026-09-26'), Carbon::parse('2026-09-22')));
        $this->assertSame(0, CalculOdm::jours(null, Carbon::parse('2026-09-22')));
    }

    /** Annexe B.1 (SC-20) : Conakry → Kouroussa, du 22/09 au 26/09, une personne */
    public function test_annexe_b1_odm_interieur_une_personne(): void
    {
        $calcul = $this->interieur('2026-09-22', '2026-09-26');

        $this->assertSame(5, $calcul['jours']);
        $this->assertSame(4, $calcul['nuits']);
        $this->assertSame(0, $calcul['nuit_rattrapage']);
        $this->assertSame(1250000, $calcul['indemnite']);
        $this->assertSame(625000, $calcul['indemnite_ligne_1']);   // 5 × 125 000
        $this->assertSame(625000, $calcul['indemnite_ligne_2']);
        $this->assertSame(2000000, $calcul['hebergement']);
        $this->assertSame(3250000, $calcul['total']);
        $this->assertSame(32500, $calcul['frais_om']);
        $this->assertEquals(3282500, $calcul['montant_verse_om']);
    }

    /** Annexe B.2 (SC-21, SC-23) : deux participants, un bon par participant ou un bon groupé */
    public function test_annexe_b2_deux_participants(): void
    {
        $calculs = [$this->interieur('2026-09-22', '2026-09-26'), $this->interieur('2026-09-22', '2026-09-26')];

        $this->assertSame(6500000.0, CalculOdm::total($calculs));
        $this->assertEquals(6565000, array_sum(array_column($calculs, 'montant_verse_om')));   // un bon par participant
        $groupe = FraisOrangeMoney::calculer(6500000, self::BAREMES['frais_om_paliers']);
        $this->assertSame(52000, $groupe['frais']);                                              // 0,8 %
        $this->assertEquals(6552000, $groupe['montant_verse']);
    }

    /** Annexe B.3 (SC-24) : prolongation du 27/09 au 03/10, avec la nuitée de rattrapage du segment 1 */
    public function test_annexe_b3_prolongation_et_nuitee_de_rattrapage(): void
    {
        $segment1 = $this->interieur('2026-09-22', '2026-09-26');
        $prolongation = $this->interieur('2026-09-27', '2026-10-03', ['rattrapage' => true]);

        $this->assertSame(7, $prolongation['jours']);
        $this->assertSame(6, $prolongation['nuits']);
        $this->assertSame(1, $prolongation['nuit_rattrapage']);
        $this->assertSame(1750000, $prolongation['indemnite']);
        $this->assertSame(3000000, $prolongation['hebergement']);
        $this->assertSame(500000, $prolongation['rattrapage']);
        $this->assertSame(5250000, $prolongation['total']);
        $this->assertSame(42000, $prolongation['frais_om']);
        $this->assertEquals(5292000, $prolongation['montant_verse_om']);

        /* Contrôle de la mission : 5 + 7 = 12 jours ; 4 + 7 = 11 nuits = 12 − 1 */
        $controle = CalculOdm::controleMission([$segment1, $prolongation]);
        $this->assertSame(['jours' => 12, 'nuits' => 11, 'nuits_attendues' => 11, 'ecart' => 0, 'coherent' => true], $controle);
    }

    /** Annexe B.4 : mission KOUROUMA, 59 jours ; 57 nuits payées par e-mail, 58 selon la règle */
    public function test_annexe_b4_mission_kourouma(): void
    {
        /* Nuits réellement payées (fil d'e-mails) : le dernier segment a oublié la nuitée de rattrapage */
        $payees = [
            ['jours' => 9, 'nuits' => 8], ['jours' => 14, 'nuits' => 14], ['jours' => 6, 'nuits' => 6],
            ['jours' => 25, 'nuits' => 25], ['jours' => 5, 'nuits' => 4],
        ];
        $this->assertSame(['jours' => 59, 'nuits' => 57, 'nuits_attendues' => 58, 'ecart' => -1, 'coherent' => false], CalculOdm::controleMission($payees));

        /* La règle, segment par segment : 8, puis jours − 1 + 1 nuitée de rattrapage à chaque prolongation */
        $segments = [
            $this->interieur('2026-07-29', '2026-08-06'),
            $this->interieur('2026-08-07', '2026-08-20', ['rattrapage' => true]),
            $this->interieur('2026-08-21', '2026-08-26', ['rattrapage' => true]),
            $this->interieur('2026-08-27', '2026-09-20', ['rattrapage' => true]),
            $this->interieur('2026-09-21', '2026-09-25', ['rattrapage' => true]),
        ];
        $this->assertSame([9, 14, 6, 25, 5], array_column($segments, 'jours'));
        $this->assertSame([8, 14, 6, 25, 5], array_map(fn ($s) => $s['nuits'] + $s['nuit_rattrapage'], $segments));
        $this->assertTrue(CalculOdm::controleMission($segments)['coherent']);
        $this->assertSame(58, CalculOdm::controleMission($segments)['nuits']);
    }

    /** RG-M12-09 (SC-27) : logé sur base vie, pas d'hébergement ni de nuitée de rattrapage */
    public function test_base_vie_sans_hebergement(): void
    {
        $calcul = $this->interieur('2026-09-22', '2026-09-26', ['base_vie' => true]);
        $this->assertSame(0, $calcul['nuits']);
        $this->assertSame(0, $calcul['hebergement']);
        $this->assertSame(1250000, $calcul['total']);

        $prolongation = $this->interieur('2026-09-27', '2026-09-28', ['base_vie' => true, 'rattrapage' => true]);
        $this->assertSame(0, $prolongation['nuit_rattrapage']);
        $this->assertSame(500000, $prolongation['total']);
    }

    /** RG-M12-10, RG-M12-26 (SC-29) : ODM extérieur, barème FCFA converti au taux */
    public function test_odm_exterieur(): void
    {
        $segment = ['type' => 'exterieur', 'jours' => 5, 'hebergement_exterieur' => 'avant_depart', 'taux' => 14.52];

        $nonCadre = CalculOdm::participant(['statut_cadre' => 'non_cadre', 'hebergement_facture' => 1200000], $segment, self::BAREMES);
        $this->assertSame(110000, $nonCadre['indemnite_fcfa']);       // 5 × 22 000
        $this->assertSame(1597200, $nonCadre['indemnite']);           // × 14,52
        $this->assertSame(4, $nonCadre['nuits']);
        $this->assertEquals(1200000, $nonCadre['hebergement']);      // facture payée avant le départ (Q24)
        $this->assertEquals(2797200, $nonCadre['total']);
        $this->assertNull($nonCadre['indemnite_ligne_1']);

        $cadre = CalculOdm::participant(['statut_cadre' => 'cadre'], ['hebergement_exterieur' => 'filiale'] + $segment, self::BAREMES);
        $this->assertSame(170000, $cadre['indemnite_fcfa']);          // 5 × 34 000
        $this->assertSame(2468400, $cadre['indemnite']);
        $this->assertSame(0, $cadre['nuits']);                        // hébergé par la filiale : sans objet
        $this->assertEquals(0, $cadre['hebergement']);

        $auRetour = CalculOdm::participant(['statut_cadre' => 'cadre', 'hebergement_facture' => 900000], ['hebergement_exterieur' => 'au_retour'] + $segment, self::BAREMES);
        $this->assertEquals(0, $auRetour['hebergement']);            // BD complémentaire à la clôture
    }

    public function test_odm_exterieur_sans_taux_ou_sans_statut_cadre(): void
    {
        $sansTaux = CalculOdm::participant(['statut_cadre' => 'cadre'], ['type' => 'exterieur', 'jours' => 3, 'hebergement_exterieur' => 'filiale'], self::BAREMES);
        $this->assertSame(102000, $sansTaux['indemnite_fcfa']);
        $this->assertNull($sansTaux['indemnite']);
        $this->assertNull($sansTaux['total']);
        $this->assertNull(CalculOdm::total([$sansTaux]));

        $sansStatut = CalculOdm::participant([], ['type' => 'exterieur', 'jours' => 3, 'taux' => 14.5], self::BAREMES);
        $this->assertNull($sansStatut['indemnite_fcfa']);
        $this->assertNull($sansStatut['total']);
    }

    /** RG-M12-08 : les deux lignes restent égales si l'indemnité journalière change */
    public function test_les_baremes_sont_des_parametres(): void
    {
        $baremes = ['indemnite_journaliere' => 300000, 'hebergement_nuit' => 400000] + self::BAREMES;
        $calcul = CalculOdm::participant([], ['type' => 'interieur', 'jours' => 2], $baremes);

        $this->assertSame(600000, $calcul['indemnite']);
        $this->assertSame(300000, $calcul['indemnite_ligne_1']);
        $this->assertSame(400000, $calcul['hebergement']);
        $this->assertSame(1000000, $calcul['total']);
    }

    /* ------------------------------------------------------------------
     * Spec v2.3 : lignes de frais (RG-M12-30) et parts (RG-M12-31, 34, 36)
     * ------------------------------------------------------------------ */

    public function test_lignes_de_frais_avec_quantite_et_taux(): void
    {
        $calcul = $this->interieur('2026-09-22', '2026-09-26');

        $this->assertSame(['indemnite', 'hebergement'], array_keys($calcul['lignes']));   // pas de rattrapage
        $this->assertEquals(['quantite' => 5, 'taux' => 250000, 'devise' => 'GNF', 'montant' => 1250000], $calcul['lignes']['indemnite']);
        $this->assertEquals(['quantite' => 4, 'taux' => 500000, 'devise' => 'GNF', 'montant' => 2000000], $calcul['lignes']['hebergement']);
        $this->assertEquals($calcul['total'], array_sum(array_column($calcul['lignes'], 'montant')));   // total = somme des lignes

        /* Base vie : pas de ligne d'hébergement ; prolongation : nuitée de rattrapage */
        $this->assertSame(['indemnite'], array_keys($this->interieur('2026-09-22', '2026-09-26', ['base_vie' => true])['lignes']));
        $rattrapage = $this->interieur('2026-09-27', '2026-09-30', ['rattrapage' => true])['lignes']['rattrapage'];
        $this->assertEquals(['quantite' => 1, 'taux' => 500000, 'devise' => 'GNF', 'montant' => 500000], $rattrapage);
    }

    public function test_lignes_d_un_odm_exterieur(): void
    {
        $segment = ['type' => 'exterieur', 'jours' => 5, 'hebergement_exterieur' => 'avant_depart', 'taux' => 15];
        $lignes = CalculOdm::participant(['statut_cadre' => 'non_cadre', 'hebergement_facture' => 900000], $segment, self::BAREMES)['lignes'];

        $this->assertEquals(['quantite' => 5, 'taux' => 22000, 'devise' => 'FCFA', 'montant' => 1650000], $lignes['indemnite']);
        $this->assertEquals(['quantite' => 1, 'taux' => 900000, 'devise' => 'GNF', 'montant' => 900000], $lignes['hebergement_exterieur']);

        /* Sans taux : montant de l'indemnité inconnu ; facture payée au retour : montant connu à la clôture */
        $auRetour = CalculOdm::participant(['statut_cadre' => 'cadre'], ['type' => 'exterieur', 'jours' => 2, 'hebergement_exterieur' => 'au_retour'], self::BAREMES)['lignes'];
        $this->assertNull($auRetour['indemnite']['montant']);
        $this->assertNull($auRetour['hebergement_exterieur']['montant']);
    }

    public function test_les_parts_suivent_leur_pourcentage_et_la_plus_forte_recoit_l_arrondi(): void
    {
        $this->assertSame([1250000.0], CalculOdm::repartirLigne(1250000, [100]));
        $this->assertSame([750000.0, 500000.0], CalculOdm::repartirLigne(1250000, [60, 40]));
        /* 1 000 000 en trois tiers : 333 333 + 333 333 + 333 334 = 1 000 000 */
        $tiers = CalculOdm::repartirLigne(1000000, [33.3333, 33.3333, 33.3334]);
        $this->assertSame(1000000.0, array_sum($tiers));
        /* Répartition incomplète : pas d'arrondi forcé ; montant inconnu : montants inconnus */
        $this->assertSame([500000.0], CalculOdm::repartirLigne(1000000, [50]));
        $this->assertSame([null, null], CalculOdm::repartirLigne(null, [60, 40]));
        $this->assertSame(32.0, CalculOdm::pourcentageDe(400000, 1250000));
    }

    public function test_recapitulatif_par_client_et_par_or(): void
    {
        $recap = CalculOdm::recapitulatif([
            ['payeur' => 'neemba', 'client_id' => null, 'ordre_reparation_id' => null, 'montant' => 1000000],
            ['payeur' => 'client', 'client_id' => 7, 'ordre_reparation_id' => 3, 'montant' => 500000],
            ['payeur' => 'client', 'client_id' => 7, 'ordre_reparation_id' => null, 'montant' => 250000],
            ['payeur' => 'client', 'client_id' => 9, 'ordre_reparation_id' => 4, 'montant' => 100000],
        ]);

        $this->assertEquals(1000000, $recap['neemba']);
        $this->assertEquals(850000, $recap['client']);
        $this->assertEquals(['montant' => 750000, 'par_or' => [3 => 500000, 'sans_or' => 250000]], $recap['par_client'][7]);
        $this->assertEquals(100000, $recap['par_client'][9]['montant']);
    }

    public function test_frais_om_au_prorata_ou_sur_neemba(): void
    {
        $parts = [['payeur' => 'neemba', 'montant' => 2000000], ['payeur' => 'client', 'montant' => 1250000]];

        /* Prorata (défaut, PO-24) : 32 500 de frais pour 3 250 000 */
        $this->assertSame([20000.0, 12500.0], CalculOdm::repartirFrais(32500, $parts));
        /* 100 % Neemba */
        $this->assertSame([32500.0, 0.0], CalculOdm::repartirFrais(32500, $parts, 'neemba'));
        /* Tout client et mode Neemba : rien côté Neemba, les frais suivent le prorata */
        $this->assertSame([32500.0], CalculOdm::repartirFrais(32500, [['payeur' => 'client', 'montant' => 3250000]], 'neemba'));
    }
}
