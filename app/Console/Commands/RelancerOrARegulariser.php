<?php

namespace App\Console\Commands;

use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Services\Odm\NotificationsOdm;
use App\Support\Format;
use App\Support\JoursOuvres;
use Illuminate\Console\Command;

/**
 * RG-M12-40 (spec v2.4, SC-38) : ODM « OR à régulariser ». Relance au demandeur à J+3 (jours ouvrés après la validation)
 * et le jour de l'échéance (MSG-M12-10) ; échéance dépassée : alerte au chef d'atelier et au DAF. Chaque envoi n'a lieu
 * qu'une fois ; il est tracé dans l'historique de l'ODM.
 *
 * Planifiée chaque jour à 7 h 15. Usage : php artisan odm:relancer-or
 */
class RelancerOrARegulariser extends Command
{
    protected $signature = 'odm:relancer-or';

    protected $description = 'Relancer les ODM « OR à régulariser » (J+3, échéance, retard)';

    public function handle(): int
    {
        $envois = 0;
        $odms = OrdreMission::withoutGlobalScope('entite')
            ->where('or_a_regulariser', true)
            ->whereNotNull('or_echeance')
            ->whereNotIn('statut', ['ANNULE', 'REJETE', 'BROUILLON'])
            ->with(['demandeur', 'initiateur'])
            ->get();

        foreach ($odms as $odm) {
            $echeance = $odm->or_echeance->copy()->startOfDay();
            $jPlus3 = $odm->date_validation ? JoursOuvres::ajouter($odm->date_validation, 3)->startOfDay() : null;

            if (today()->greaterThan($echeance)) {
                if (!$odm->or_retard_signale_le) {
                    NotificationsOdm::orEnRetard($odm);
                    $odm->update(['or_retard_signale_le' => now()]);
                    HistoriqueOdm::enregistrer($odm, 'rappel', $odm->statut, $odm->statut, null,
                        "OR à régulariser en retard (échéance du {$this->date($echeance)}) : alerte au chef d'atelier et au DAF.");
                    $envois++;
                }
            } elseif (today()->equalTo($echeance)) {
                if (!$odm->or_relance_echeance_le) {
                    NotificationsOdm::orARegulariser($odm);
                    $odm->update(['or_relance_echeance_le' => now()]);
                    HistoriqueOdm::enregistrer($odm, 'rappel', $odm->statut, $odm->statut, null, "Relance au demandeur : OR à régulariser aujourd'hui.");
                    $envois++;
                }
            } elseif ($jPlus3 && !today()->lessThan($jPlus3) && !$odm->or_relance_le) {
                NotificationsOdm::orARegulariser($odm);
                $odm->update(['or_relance_le' => now()]);
                HistoriqueOdm::enregistrer($odm, 'rappel', $odm->statut, $odm->statut, null,
                    "Relance au demandeur : OR à régulariser avant le {$this->date($echeance)}.");
                $envois++;
            }
        }

        $this->info("{$envois} relance(s) ou alerte(s) envoyée(s).");

        return self::SUCCESS;
    }

    private function date($date): string
    {
        return Format::date($date);
    }
}
