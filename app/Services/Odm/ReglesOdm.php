<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\CodeAnalytique;
use App\Models\OrdreMission;
use App\Models\OrdreReparationOdm;
use App\Models\Parametre;

/**
 * Contrôles bloquants de la soumission d'un ODM (spec v2.2, §7.3), au format §5.7 : une entrée par erreur,
 * avec le champ concerné pour placer le curseur à l'écran.
 *
 * RG-M12-01 type ; RG-M12-02 OR d'une mission technique ; RG-M12-04 participants ; RG-M12-05 champs obligatoires ;
 * RG-M12-06 dates ; RG-M02-04 statut cadre d'un ODM extérieur ; RG-M12-26 hébergement extérieur ;
 * RG-M12-16 chevauchement (sauf dérogation accordée) ; décision Q22 code analytique.
 */
final class ReglesOdm
{
    public const LONGUEUR_MIN_BUT = 10;

    /**
     * @return array<int, array{code: string, regle: ?string, message_cle: string, valeurs: array, message: string, champ: ?string}>
     */
    public static function erreursSoumission(OrdreMission $odm): array
    {
        $erreurs = [];
        $ajouter = function (string $code, string $cle, array $valeurs, ?string $regle, ?string $champ) use (&$erreurs) {
            $erreurs[] = [
                'code' => $code,
                'regle' => $regle,
                'message_cle' => $cle,
                'valeurs' => $valeurs,
                'message' => ErreurMetier::texte($cle, $valeurs),
                'champ' => $champ,
            ];
        };

        /* En-tête */
        if (!array_key_exists($odm->type, OrdreMission::TYPES)) {
            $ajouter('TYPE_OBLIGATOIRE', 'MSG-BC-001', [], 'RG-M12-01', 'type');
        }
        if (blank($odm->service)) {
            $ajouter('SERVICE_OBLIGATOIRE', 'MSG-BC-001', [], 'RG-M12-05', 'service');
        }
        if (blank($odm->code_analytique)) {
            $ajouter('CODE_ANALYTIQUE_OBLIGATOIRE', 'MSG-BC-001', [], 'RG-M12-05', 'code_analytique');
        } elseif (!CodeAnalytique::where('code', $odm->code_analytique)->where('actif', true)->exists()) {
            $ajouter('CODE_ANALYTIQUE_INCONNU', 'MSG-APP-021', [], 'RG-M12-05', 'code_analytique');
        }
        if (empty(array_filter($odm->destinations ?? [], 'filled'))) {
            $ajouter('DESTINATION_OBLIGATOIRE', 'MSG-APP-012', [], 'RG-M12-05', 'destinations');
        }
        if (mb_strlen(trim((string) $odm->but)) < self::LONGUEUR_MIN_BUT) {
            $ajouter('BUT_TROP_COURT', 'MSG-APP-011', [], 'RG-M12-05', 'but');
        }
        if (!array_key_exists($odm->prise_en_charge, OrdreMission::PRISES_EN_CHARGE)) {
            $ajouter('PRISE_EN_CHARGE_OBLIGATOIRE', 'MSG-BC-001', [], 'RG-M12-05', 'prise_en_charge');
        }

        /* Dates (RG-M12-06) */
        if (!$odm->date_depart) {
            $ajouter('DEPART_OBLIGATOIRE', 'MSG-BC-001', [], 'RG-M12-05', 'date_depart');
        }
        if (!$odm->date_retour_prevue) {
            $ajouter('RETOUR_OBLIGATOIRE', 'MSG-BC-001', [], 'RG-M12-05', 'date_retour_prevue');
        }
        if ($odm->date_depart && $odm->date_retour_prevue && $odm->date_retour_prevue->lessThan($odm->date_depart)) {
            $ajouter('RETOUR_AVANT_DEPART', 'MSG-M12-02', [], 'RG-M12-06', 'date_retour_prevue');
        }
        if ($odm->date_depart && $odm->date_depart->lessThan(today()) && mb_strlen(trim((string) $odm->motif_depart_passe)) < 1) {
            $ajouter('MOTIF_DEPART_PASSE', 'MSG-APP-015', [], 'RG-M12-06', 'motif_depart_passe');
        }

        /* OR d'une mission technique (RG-M12-02) */
        $ors = $odm->ordresReparation()->get();
        if ($odm->technique && $ors->isEmpty()) {
            $ajouter('OR_OBLIGATOIRE', 'MSG-M12-01', [], 'RG-M12-02', 'ordres_reparation');
        }
        if ($ors->contains(fn (OrdreReparationOdm $or) => !preg_match(OrdreReparationOdm::FORMAT, $or->numero))) {
            $ajouter('OR_INVALIDE', 'MSG-M03-07', [], 'RG-M03-13', 'ordres_reparation');
        }

        /* Participants (RG-M12-04) */
        $participants = $odm->participantsActifs()->with('utilisateur')->get();
        $maximum = (int) Parametre::valeur('odm_participants_max', 10);
        if ($participants->isEmpty()) {
            $ajouter('PARTICIPANT_OBLIGATOIRE', 'MSG-APP-013', [], 'RG-M12-04', 'participants');
        } elseif ($participants->count() > $maximum) {
            $ajouter('TROP_DE_PARTICIPANTS', 'MSG-APP-014', ['max' => $maximum], 'RG-M12-04', 'participants');
        }
        foreach ($participants as $participant) {
            if (!$participant->utilisateur?->actif) {
                $ajouter('PARTICIPANT_INACTIF', 'MSG-APP-017', ['participant' => $participant->nom], 'RG-M12-04', 'participants');
            }
        }

        /* ODM extérieur : statut cadre (RG-M02-04) et hébergement (RG-M12-26) */
        if ($odm->type === 'exterieur') {
            if (!array_key_exists((string) $odm->hebergement_exterieur, OrdreMission::HEBERGEMENTS_EXTERIEURS)) {
                $ajouter('HEBERGEMENT_EXTERIEUR_OBLIGATOIRE', 'MSG-APP-016', [], 'RG-M12-26', 'hebergement_exterieur');
            }
            foreach ($participants as $participant) {
                if (!in_array($participant->statut_cadre, ['cadre', 'non_cadre'], true)) {
                    $ajouter('STATUT_CADRE_MANQUANT', 'MSG-M12-04', ['participant' => $participant->nom], 'RG-M02-04', 'participants');
                }
                if ($odm->hebergement_exterieur === 'avant_depart' && (float) $participant->hebergement_facture <= 0) {
                    $ajouter('FACTURE_HEBERGEMENT_MANQUANTE', 'MSG-APP-018', ['participant' => $participant->nom], 'RG-M12-26', 'participants');
                }
            }
        }

        /* Chevauchement (RG-M12-16), sauf dérogation accordée par le DAF */
        if ($odm->derogation_statut !== 'accordee') {
            foreach (ChevauchementOdm::conflits($odm) as $conflit) {
                $ajouter('CHEVAUCHEMENT', 'MSG-M12-03', [
                    'participant' => $conflit['participant'],
                    'debut' => $conflit['debut'],
                    'fin' => $conflit['fin'],
                    'numero' => $conflit['numero'],
                ], 'RG-M12-16', 'participants');
            }
        }

        return $erreurs;
    }

    /** L'ODM a-t-il un chevauchement bloquant ? (pour proposer la demande de dérogation) */
    public static function aUnChevauchement(array $erreurs): bool
    {
        return collect($erreurs)->contains('code', 'CHEVAUCHEMENT');
    }
}
