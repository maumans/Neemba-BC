<?php

namespace App\Services;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\EcritureCaisse;
use App\Models\MouvementCaisse;
use App\Models\RapportCaisse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcul du rapport journalier de caisse, en mémoire (rien n'est enregistré en base).
 *
 * Source unique pour l'envoi automatique (rapports:envoyer-quotidien) et l'envoi manuel
 * depuis l'écran Rapports. Les deux calculs divergeaient : l'envoi automatique ignorait
 * les entrées et ne retenait que les bons au statut PAYE, ce qui excluait les BP payés
 * (passés en attente de régularisation dès le paiement).
 *
 * Depuis le lot 2, les jours qui suivent l'ouverture du registre des caisses sont lus dans le registre
 * (ouverture exacte, décision Q13) ; les jours antérieurs restent calculés sur les documents :
 *
 * Soldes ventilés Espèces / Orange Money :
 * - sorties : bons payés en espèces ou en Orange Money (virement / autre = hors caisse, RG-BC-12)
 *   + retraits de caisse validés ;
 * - entrées : approvisionnements et ajustements validés (même sens que MouvementCaisse::valider()).
 */
class RapportJournalierService
{
    /**
     * @return array{rapport: RapportCaisse, bonsPaye: Collection}
     */
    public static function construire(Carbon $date, ?string $site = null): array
    {
        $caisses = Caisse::query()->when($site, fn ($q) => $q->duSite($site))->get();

        /* Registre des caisses (lot 2) pour les jours qui suivent son ouverture ; avant, calcul sur les documents */
        $debutRegistre = $caisses->isEmpty()
            ? null
            : EcritureCaisse::whereIn('caisse_id', $caisses->pluck('id'))->min('date_ecriture');

        if ($debutRegistre && $date->copy()->startOfDay()->gt(Carbon::parse($debutRegistre)->startOfDay())) {
            return self::depuisRegistre($date, $site, $caisses);
        }

        return self::depuisDocuments($date, $site);
    }

    /**
     * Rapport lu dans le registre : ouverture = solde de chaque caisse en début de journée,
     * entrées / sorties = écritures du jour, ventilées par type de caisse (espèces / Orange Money).
     */
    private static function depuisRegistre(Carbon $date, ?string $site, Collection $caisses): array
    {
        $debut = $date->copy()->startOfDay();
        $fin = $date->copy()->endOfDay();
        $totaux = [
            'especes' => ['ouverture' => 0.0, 'entrees' => 0.0, 'sorties' => 0.0],
            'orange_money' => ['ouverture' => 0.0, 'entrees' => 0.0, 'sorties' => 0.0],
        ];

        foreach ($caisses as $caisse) {
            $type = $caisse->type;
            $totaux[$type]['ouverture'] += $caisse->soldeAu($debut) ?? 0.0;

            foreach ($caisse->ecritures()->whereBetween('date_ecriture', [$debut, $fin])->get() as $ecriture) {
                if ($ecriture->nature === 'solde_initial') {
                    /* Reprise d'un solde existant : fait partie de l'ouverture, pas des flux du jour */
                    $totaux[$type]['ouverture'] += $ecriture->sens === 'entree' ? (float) $ecriture->montant : -(float) $ecriture->montant;
                    continue;
                }
                $totaux[$type][$ecriture->sens === 'entree' ? 'entrees' : 'sorties'] += (float) $ecriture->montant;
            }
        }

        $bonsPaye = BonCaisse::with('demandeur')
            ->payesLe($date)
            ->whereIn('caisse_id', $caisses->pluck('id'))
            ->orderBy('date_paiement')
            ->get();

        $especes = $totaux['especes'];
        $om = $totaux['orange_money'];

        return self::assembler($date, $site, $bonsPaye, [
            'ouverture_especes' => $especes['ouverture'], 'ouverture_om' => $om['ouverture'],
            'entrees_especes' => $especes['entrees'], 'entrees_om' => $om['entrees'],
            'sorties_especes' => $especes['sorties'], 'sorties_om' => $om['sorties'],
        ]);
    }

    /**
     * Rapport calculé sur les documents (bons payés, mouvements validés), pour les jours antérieurs au registre.
     */
    private static function depuisDocuments(Carbon $date, ?string $site): array
    {
        /* Bons payés ce jour (date de paiement), quel que soit leur statut actuel */
        $bonsPaye = BonCaisse::with('demandeur')
            ->payesLe($date)
            ->whereIn('mode_paiement_effectif', BonCaisse::MODES_PAIEMENT_CAISSE)
            ->when($site, fn ($q) => $q->parSite($site))
            ->orderBy('date_paiement')
            ->get();

        /* Mouvements de caisse validés ce jour */
        $mouvements = MouvementCaisse::valides()
            ->whereNotNull('date_validation')
            ->whereDate('date_validation', $date)
            ->when($site, fn ($q) => $q->parSite($site))
            ->get();

        $mouvementsDe = fn (string $typeCaisse, bool $retrait) => (float) $mouvements
            ->filter(fn ($m) => ($m->type_caisse ?? 'especes') === $typeCaisse && ($m->type === 'retrait') === $retrait)
            ->sum('montant');

        $entreesEspeces = $mouvementsDe('especes', false);
        $entreesOm = $mouvementsDe('om', false);
        $sortiesEspeces = (float) $bonsPaye->where('mode_paiement_effectif', 'especes')->sum('montant') + $mouvementsDe('especes', true);
        $sortiesOm = (float) $bonsPaye->where('mode_paiement_effectif', 'orange_money')->sum('montant') + $mouvementsDe('om', true);

        /* Solde d'ouverture : clôture du dernier rapport enregistré pour le site */
        $ouverture = $site ? RapportCaisse::soldePrecedent($site, $date) : ['total' => 0, 'especes' => 0, 'om' => 0];

        return self::assembler($date, $site, $bonsPaye, [
            'ouverture_especes' => (float) $ouverture['especes'], 'ouverture_om' => (float) $ouverture['om'],
            'entrees_especes' => $entreesEspeces, 'entrees_om' => $entreesOm,
            'sorties_especes' => $sortiesEspeces, 'sorties_om' => $sortiesOm,
        ]);
    }

    /**
     * RapportCaisse en mémoire à partir des montants ventilés espèces / Orange Money.
     */
    private static function assembler(Carbon $date, ?string $site, Collection $bonsPaye, array $m): array
    {
        $ouvertureEspeces = $m['ouverture_especes'];
        $ouvertureOm = $m['ouverture_om'];
        $entreesEspeces = $m['entrees_especes'];
        $entreesOm = $m['entrees_om'];
        $sortiesEspeces = $m['sorties_especes'];
        $sortiesOm = $m['sorties_om'];

        $caissier = $site
            ? User::where('actif', true)->where('role', 'caissier')->where('site', $site)->first()
            : null;

        $rapport = new RapportCaisse([
            'date_rapport' => $date,
            'site' => $site ?? 'Tous les sites',
            'solde_ouverture' => $ouvertureEspeces + $ouvertureOm,
            'solde_ouverture_especes' => $ouvertureEspeces,
            'solde_ouverture_om' => $ouvertureOm,
            'total_entrees' => $entreesEspeces + $entreesOm,
            'total_entrees_especes' => $entreesEspeces,
            'total_entrees_om' => $entreesOm,
            'total_sorties' => $sortiesEspeces + $sortiesOm,
            'total_sorties_especes' => $sortiesEspeces,
            'total_sorties_om' => $sortiesOm,
            'solde_cloture_especes' => $ouvertureEspeces + $entreesEspeces - $sortiesEspeces,
            'solde_cloture_om' => $ouvertureOm + $entreesOm - $sortiesOm,
            'solde_cloture' => ($ouvertureEspeces + $entreesEspeces - $sortiesEspeces) + ($ouvertureOm + $entreesOm - $sortiesOm),
            'caissier_id' => $caissier?->id,
        ]);
        $rapport->setRelation('caissier', $caissier);
        $rapport->calculerStatistiques($bonsPaye);

        return ['rapport' => $rapport, 'bonsPaye' => $bonsPaye];
    }

    /**
     * Le jour a-t-il eu au moins une opération de caisse (paiement, entrée ou retrait) ?
     */
    public static function aDesMouvements(RapportCaisse $rapport, Collection $bonsPaye): bool
    {
        return $bonsPaye->isNotEmpty()
            || (float) $rapport->total_entrees != 0
            || (float) $rapport->total_sorties != 0;
    }
}
