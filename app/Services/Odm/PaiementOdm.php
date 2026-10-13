<?php

namespace App\Services\Odm;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\TauxChange;
use App\Models\User;
use App\Support\Format;

/**
 * Paiement des bons générés depuis un ODM.
 *
 * - ODM extérieur (RG-M12-10) : le montant estimé au dernier taux est recalculé au paiement avec le taux saisi par
 *   la Trésorerie le jour même ; sans taux du jour, le paiement est bloqué (MSG-M12-05). L'écart est tracé sur le bon.
 * - RG-M12-21 : l'ODM passe « Payé » quand tous ses bons encore actifs sont payés.
 * - Spec v2.3 : l'indemnité payée au taux du jour devient le montant de la ligne de frais ; ses parts suivent
 *   leur pourcentage (RG-M12-31) et le montant à refacturer est mis à jour (RG-M12-37).
 */
final class PaiementOdm
{
    /** Statuts d'un bon payé (un BP payé attend ensuite sa régularisation) */
    public const STATUTS_PAYES = ['PAYE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE', 'ARCHIVE'];

    /**
     * Montant du bon au taux du jour.
     *
     * @return array{montant: float, taux: float}|false|null null : bon sans part en FCFA ; false : aucun taux du jour
     */
    public static function montantAuTauxDuJour(BonCaisse $bon): array|false|null
    {
        if (!$bon->genere_par_odm || $bon->montant_fcfa === null) {
            return null;
        }
        $taux = TauxChange::duJour();
        if (!$taux) {
            return false;
        }

        return [
            'montant' => round((float) $bon->montant_fcfa * (float) $taux->taux) + (float) $bon->montant_gnf_fixe,
            'taux' => (float) $taux->taux,
        ];
    }

    /** Montant recalculé enregistré sur le bon, avec le taux appliqué ; l'écart avec l'estimation est journalisé */
    public static function appliquerTaux(BonCaisse $bon, array $recalcul): void
    {
        $estime = (float) ($bon->montant_estime ?? $bon->montant);
        $ecart = $recalcul['montant'] - $estime;
        $bon->modifierAvecJournal(
            ['montant' => $recalcul['montant'], 'taux_change_applique' => $recalcul['taux']],
            HistoriqueAction::ACTION_MODIFICATION,
            'Montant recalculé au taux du jour (1 FCFA = ' . str_replace('.', ',', (string) $recalcul['taux']) . ' GNF) : estimé '
                . Format::montant($estime) . ' au taux ' . str_replace('.', ',', (string) (float) $bon->taux_change_estime)
                . ', payé ' . Format::montant($recalcul['montant']) . ' (écart ' . ($ecart >= 0 ? '+' : '−') . Format::montant(abs($ecart)) . ').',
        );
        self::indemniteAuTauxPaye($bon, $recalcul['taux']);
    }

    /** Lignes « indemnité » des participants payés par ce bon : montant au taux appliqué, parts recalculées */
    private static function indemniteAuTauxPaye(BonCaisse $bon, float $taux): void
    {
        $odm = OrdreMission::find($bon->odm_id);
        if (!$odm) {
            return;
        }
        $participants = $odm->participants()->where('bon_caisse_id', $bon->id)->get();
        foreach ($participants as $participant) {
            if ($participant->indemnite_fcfa !== null) {
                $participant->lignes()->where('element', 'indemnite')->update(['montant' => round((float) $participant->indemnite_fcfa * $taux)]);
            }
        }
        if ($participants->isNotEmpty()) {
            RepartitionOdm::repartir($odm);
        }
    }

    /** RG-M12-21 : statut « Payé » de l'ODM quand tous ses bons actifs sont payés et que chaque participant a le sien */
    public static function apresPaiement(BonCaisse $bon, ?User $caissier = null): void
    {
        if (!$bon->odm_id || !$bon->genere_par_odm) {
            return;
        }
        $odm = OrdreMission::find($bon->odm_id);
        if (!$odm || $odm->statut !== 'BONS_GENERES') {
            return;
        }
        $actifs = GenererBonsOdm::bonsActifs($odm);
        if ($actifs->isNotEmpty() && $actifs->every(fn (BonCaisse $b) => in_array($b->statut, self::STATUTS_PAYES, true))
            && GenererBonsOdm::participantsSansBon($odm)->isEmpty()) {
            $odm->update(['statut' => 'PAYE']);
            HistoriqueOdm::enregistrer($odm, 'paiement', 'BONS_GENERES', 'PAYE', $caissier?->id,
                'Tous les bons de l\'ordre de mission sont payés : ' . $actifs->pluck('numero')->implode(', ') . '.');
        }
    }
}
