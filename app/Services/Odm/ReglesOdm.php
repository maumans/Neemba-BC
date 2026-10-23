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
        } elseif ($service = \App\Models\Service::where('nom', $odm->service)->first()) {
            /* Même règle que pour les bons, qui reprendront ce code (RG-M03-22) */
            $codes = CodeAnalytique::where('actif', true)->where('service_id', $service->id)->pluck('code');
            if ($codes->isNotEmpty() && !$codes->contains($odm->code_analytique)) {
                $ajouter('CODE_ANALYTIQUE_HORS_SERVICE', 'MSG-APP-026', ['service' => $service->nom], 'RG-M12-05', 'code_analytique');
            }
        }
        /* Spec v2.4, Q76 : un ODM urgent porte un motif de la liste des motifs d'urgence (MSG-M03-08) */
        if (($odm->niveau_urgence ?? 'normale') !== 'normale'
            && !\App\Models\MotifUrgence::where('actif', true)->where('libelle', $odm->motif_urgence)->exists()) {
            $ajouter('MOTIF_URGENCE', 'MSG-M03-08', [], 'RG-M03-14', 'motif_urgence');
        }
        if (empty(array_filter($odm->destinations ?? [], 'filled'))) {
            $ajouter('DESTINATION_OBLIGATOIRE', 'MSG-APP-012', [], 'RG-M12-05', 'destinations');
        }
        if (mb_strlen(trim((string) $odm->but)) < self::LONGUEUR_MIN_BUT) {
            $ajouter('BUT_TROP_COURT', 'MSG-APP-011', [], 'RG-M12-05', 'but');
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
        /* Complément du 22/10, RG-M12-44, 45 : mission longue, fréquence de la liste et fin prévisionnelle à respecter */
        if ($odm->duree_mission === 'longue') {
            if (!in_array((int) $odm->frequence_renouvellement, MissionLongue::frequences(), true)) {
                $ajouter('FREQUENCE_RENOUVELLEMENT', 'MSG-APP-115', [], 'RG-M12-44', 'frequence_renouvellement');
            }
            if (!$odm->date_fin_previsionnelle || ($odm->date_depart && $odm->date_fin_previsionnelle->lessThan($odm->date_depart))) {
                $ajouter('FIN_PREVISIONNELLE', 'MSG-APP-116', [], 'RG-M12-44', 'date_fin_previsionnelle');
            } elseif ($odm->date_retour_prevue && $odm->date_retour_prevue->greaterThan($odm->date_fin_previsionnelle)) {
                $ajouter('AU_DELA_FIN_PREVISIONNELLE', 'MSG-APP-117', ['date' => $odm->date_fin_previsionnelle->toDateString()], 'RG-M12-45', 'date_retour_prevue');
            }
        }
        /* RG-M12-50 : une prolongation porte son motif */
        if ($odm->type_segment === 'prolongation' && mb_strlen(trim((string) $odm->motif_prolongation)) < 10) {
            $ajouter('MOTIF_PROLONGATION', 'MSG-APP-118', [], 'RG-M12-50', 'motif_prolongation');
        }
        if ($odm->date_depart && $odm->date_depart->lessThan(today()) && mb_strlen(trim((string) $odm->motif_depart_passe)) < 1) {
            $ajouter('MOTIF_DEPART_PASSE', 'MSG-APP-015', [], 'RG-M12-06', 'motif_depart_passe');
        }

        /* OR d'une mission technique (RG-M12-02) ; spec v2.4, RG-M12-39 : ou « OR à régulariser » */
        $ors = $odm->ordresReparation()->get();
        if ($odm->technique && $ors->isEmpty() && !$odm->or_a_regulariser) {
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

        /* Spec v2.3 : parts de prise en charge (RG-M12-31, RG-M12-33) */
        $codesActifs = CodeAnalytique::where('actif', true)->pluck('code')->all();
        foreach ($odm->lignesFrais()->with(['parts.ordreReparation', 'participant'])->get() as $ligne) {
            if ($ligne->participant->retire) {
                continue;
            }
            $valeurs = ['element' => mb_strtolower(\App\Models\LigneFraisOdm::ELEMENTS[$ligne->element] ?? $ligne->element), 'participant' => $ligne->participant->nom];
            $total = round($ligne->parts->sum(fn ($p) => (float) $p->pourcentage), 4);
            if (abs($total - 100) >= 0.001) {
                $ajouter('REPARTITION_INCOMPLETE', 'MSG-APP-043', $valeurs + ['total' => str_replace('.', ',', (string) round($total, 2))], 'RG-M12-31', 'repartition');
            }
            foreach ($ligne->parts as $part) {
                if ($part->payeur === 'client' && !$part->client_id) {
                    $ajouter('CLIENT_DE_LA_PART', 'MSG-APP-042', $valeurs, 'RG-M12-31', 'repartition');
                }
                if (($avertissement = $part->avertissement()) && blank($part->commentaire)) {
                    $ajouter('COMMENTAIRE_PART', 'MSG-APP-044', $valeurs + ['avertissement' => $avertissement], 'RG-M12-33', 'repartition');
                }
                if ($part->code_analytique && !in_array($part->code_analytique, $codesActifs, true)) {
                    $ajouter('CODE_ANALYTIQUE_PART', 'MSG-APP-045', $valeurs + ['code' => $part->code_analytique], 'RG-M12-31', 'repartition');
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
