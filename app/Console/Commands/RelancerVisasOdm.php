<?php

namespace App\Console\Commands;

use App\Models\EtapeOdm;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Services\Odm\CircuitOdm;
use App\Services\Odm\NotificationsOdm;
use App\Support\Format;
use Illuminate\Console\Command;

/**
 * Délais des visas d'ODM (spec v2.2, §6.7 : mêmes délais que les étapes équivalentes du circuit des bons).
 *
 * - À l'échéance : une relance aux valideurs de l'étape (titulaires et suppléants).
 * - Au double du délai (paramètre « sla_multiplicateur_escalade ») : escalade au niveau supérieur, pour le DP au DAF.
 *
 * Planifiée toutes les heures. Usage : php artisan odm:relancer-visas
 */
class RelancerVisasOdm extends Command
{
    protected $signature = 'odm:relancer-visas';

    protected $description = 'Relancer les visas d\'ordres de mission en retard et escalader au double du délai';

    public function handle(): int
    {
        $multiplicateur = (float) Parametre::valeur('sla_multiplicateur_escalade', 2);
        $relances = 0;
        $escalades = 0;

        $etapes = EtapeOdm::where('statut', 'en_attente')
            ->whereNotNull('date_attribution')
            ->whereHas('ordreMission', fn ($q) => $q->whereIn('statut', OrdreMission::STATUTS_EN_CIRCUIT))
            ->with('ordreMission')
            ->get();

        foreach ($etapes as $etape) {
            $odm = $etape->ordreMission;
            if ($etape->version !== $odm->version) {
                continue;
            }
            $delaiMinutes = (int) round(CircuitOdm::delaiHeures($etape) * 60);
            $attente = Format::dureeEntre($etape->date_attribution);

            if (!$etape->escalade && $etape->date_attribution->copy()->addMinutes((int) round($delaiMinutes * $multiplicateur))->isPast()) {
                $niveau = CircuitOdm::ESCALADE[$etape->role] ?? null;
                if ($niveau) {
                    NotificationsOdm::envoyer(CircuitOdm::valideursPossibles($odm, $niveau), null, $odm, 'odm_escalade',
                        "ODM {$odm->numero} : visa en retard",
                        "L'ordre de mission {$odm->numero} attend le visa « {$etape->libelle} » depuis {$attente}, le double du délai prévu.");
                }
                $etape->update(['escalade' => true, 'derniere_relance' => now()]);
                HistoriqueOdm::enregistrer($odm, 'rappel', $odm->statut, $odm->statut, null,
                    "Visa « {$etape->libelle} » en retard ({$attente}) : escalade" . ($niveau ? ' au niveau ' . OrdreMission::NIVEAUX[$niveau]['libelle'] : '') . '.');
                $escalades++;
                continue;
            }

            if (!$etape->derniere_relance && $etape->date_attribution->copy()->addMinutes($delaiMinutes)->isPast()) {
                NotificationsOdm::envoyer(CircuitOdm::valideursPossibles($odm, $etape->role), null, $odm, 'odm_relance',
                    "Relance : ODM {$odm->numero} à viser",
                    "L'ordre de mission {$odm->numero} attend votre visa (« {$etape->libelle} ») depuis {$attente}.");
                $etape->update(['derniere_relance' => now()]);
                HistoriqueOdm::enregistrer($odm, 'rappel', $odm->statut, $odm->statut, null, "Relance du visa « {$etape->libelle} » ({$attente} d'attente).");
                $relances++;
            }
        }

        $this->info("{$relances} relance(s), {$escalades} escalade(s).");

        return self::SUCCESS;
    }
}
