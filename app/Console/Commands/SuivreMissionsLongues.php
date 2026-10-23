<?php

namespace App\Console\Commands;

use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Services\Odm\MissionLongue;
use App\Services\Odm\NotificationsOdm;
use App\Services\Odm\ProlongerOdm;
use App\Support\Format;
use App\Support\JoursOuvres;
use Illuminate\Console\Command;

/**
 * Complément du 22/10 à la spec v2.4 : suivi des missions longue durée, sur la période en cours (dernier segment validé).
 *
 * - RG-M12-48 : J-5 ouvrés avant le terme (paramètre `odm_delai_relance_longue`), au demandeur et au chef d'atelier,
 *   avec le lien « Renouveler » (dernière période : clôturer ou reporter la fin) ; J-2, aucun renouvellement soumis :
 *   relance au chef d'atelier ; J-1, renouvellement non validé : alerte au DAF.
 * - RG-M12-49 : terme dépassé sans renouvellement validé : mission « Échue », alerte au chef d'atelier et au DAF.
 * Chaque envoi n'est fait qu'une fois et tracé dans l'historique de l'ODM.
 *
 * Planifiée chaque jour à 7 h 05. Usage : php artisan odm:suivre-missions-longues
 */
class SuivreMissionsLongues extends Command
{
    protected $signature = 'odm:suivre-missions-longues';

    protected $description = 'Relancer le renouvellement des missions longues et signaler les missions échues';

    public function handle(): int
    {
        $delai = (int) Parametre::valeur('odm_delai_relance_longue', 5);
        $envois = 0;

        $segments = OrdreMission::whereIn('statut', ProlongerOdm::STATUTS_PROLONGEABLES)
            ->where('duree_mission', 'longue')
            ->whereNull('date_retour_reelle')
            ->with(['demandeur', 'initiateur'])
            ->get();

        foreach ($segments as $segment) {
            $suite = ProlongerOdm::prolongationEnCours($segment);
            /* La période suivante est validée : ce segment n'est plus la période en cours */
            if ($suite && in_array($suite->statut, OrdreMission::STATUTS_VALIDES, true)) {
                continue;
            }
            $terme = $segment->date_retour_prevue->copy()->startOfDay();
            $derniere = MissionLongue::dernierePeriode($segment);
            $soumis = $suite && in_array($suite->statut, ['SOUMIS', 'EN_VALIDATION'], true);

            if (today()->greaterThan($terme)) {
                if (!$segment->echue_le) {
                    $segment->update(['echue_le' => today()]);
                    NotificationsOdm::missionEchue($segment);
                    $this->tracer($segment, 'Mission échue : période terminée le ' . Format::date($terme) . ' sans renouvellement validé (chef d\'atelier et DAF alertés).');
                    $envois++;
                }

                continue;
            }

            if (!$segment->relance_longue_j5_le && !today()->lessThan(JoursOuvres::retrancher($terme, $delai))) {
                NotificationsOdm::relanceRenouvellement($segment, $derniere);
                $segment->update(['relance_longue_j5_le' => now()]);
                $this->tracer($segment, ($derniere ? 'Dernière période : clôture ou report de la fin prévisionnelle proposés' : 'Relance de renouvellement')
                    . ' au demandeur et au chef d\'atelier (terme le ' . Format::date($terme) . ').');
                $envois++;
            }
            if ($derniere) {
                continue;
            }
            if (!$segment->relance_longue_j2_le && !$soumis && !today()->lessThan(JoursOuvres::retrancher($terme, 2))) {
                NotificationsOdm::relanceChefAtelier($segment);
                $segment->update(['relance_longue_j2_le' => now()]);
                $this->tracer($segment, 'Aucun renouvellement soumis à J-2 : relance au chef d\'atelier.');
                $envois++;
            }
            if (!$segment->alerte_longue_j1_le && !today()->lessThan(JoursOuvres::retrancher($terme, 1))) {
                NotificationsOdm::alerteDafRenouvellement($segment, $suite);
                $segment->update(['alerte_longue_j1_le' => now()]);
                $this->tracer($segment, 'Renouvellement non validé à J-1 : alerte au DAF.');
                $envois++;
            }
        }

        $this->info("{$envois} relance(s) ou alerte(s) envoyée(s).");

        return self::SUCCESS;
    }

    private function tracer(OrdreMission $segment, string $commentaire): void
    {
        HistoriqueOdm::enregistrer($segment, 'rappel', $segment->statut, $segment->statut, null, $commentaire);
    }
}
