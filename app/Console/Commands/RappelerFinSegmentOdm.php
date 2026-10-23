<?php

namespace App\Console\Commands;

use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Services\Odm\NotificationsOdm;
use App\Services\Odm\ProlongerOdm;
use App\Support\Format;
use App\Support\JoursOuvres;
use Illuminate\Console\Command;

/**
 * RG-M12-28 : rappel automatique au demandeur d'une mission ponctuelle avant l'échéance d'un segment
 * (paramètre « odm_delai_rappel », 2 jours ouvrés par défaut), pour prolonger ou clôturer.
 *
 * Un seul rappel par segment ; seul le dernier segment d'une mission en cours est concerné.
 * Planifiée chaque jour à 7 h. Usage : php artisan odm:rappeler-fin-segment
 */
class RappelerFinSegmentOdm extends Command
{
    protected $signature = 'odm:rappeler-fin-segment';

    protected $description = 'Rappeler aux demandeurs la fin prochaine de leurs missions (prolonger ou clôturer)';

    public function handle(): int
    {
        $delai = (int) Parametre::valeur('odm_delai_rappel', 2);
        $rappels = 0;

        $segments = OrdreMission::whereIn('statut', ProlongerOdm::STATUTS_PROLONGEABLES)
            ->whereNull('rappel_envoye_le')
            /* Complément du 22/10 : les missions longues ont leurs propres relances (odm:suivre-missions-longues) */
            ->where('duree_mission', '!=', 'longue')
            ->whereNull('date_retour_reelle')
            ->whereDate('date_retour_prevue', '>=', today())
            ->with(['demandeur', 'initiateur'])
            ->get();

        foreach ($segments as $segment) {
            if (ProlongerOdm::prolongationEnCours($segment)) {
                continue;
            }
            if (today()->lessThan(JoursOuvres::retrancher($segment->date_retour_prevue, $delai))) {
                continue;
            }

            NotificationsOdm::rappelFinDeSegment($segment);
            $segment->update(['rappel_envoye_le' => now()]);
            HistoriqueOdm::enregistrer($segment, 'rappel', $segment->statut, $segment->statut, null,
                'Rappel au demandeur : fin de la mission le ' . Format::date($segment->date_retour_prevue) . ' (prolonger ou clôturer).');
            $rappels++;
        }

        $this->info("{$rappels} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
