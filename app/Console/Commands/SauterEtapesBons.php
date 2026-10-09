<?php

namespace App\Console\Commands;

use App\Models\BonCaisse;
use App\Services\BonCaisse\CircuitBon;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * RG-M04-09 : réapplique le passage au niveau supérieur aux bons déjà en attente, quand personne ne peut viser
 * l'étape en cours (seul valideur demandeur ou bénéficiaire du bon sans suppléant, ou aucun titulaire désigné).
 *
 * Utile pour les bons soumis avant la règle, ou quand un valideur quitte son poste. Planifiée toutes les heures.
 * Usage : php artisan bons:sauter-etapes [--simulation]
 */
class SauterEtapesBons extends Command
{
    protected $signature = 'bons:sauter-etapes {--simulation : liste les bons concernés sans les modifier}';

    protected $description = 'Faire passer au niveau supérieur les bons dont l\'étape en cours n\'a aucun valideur possible';

    public function handle(): int
    {
        $traites = 0;

        $bons = BonCaisse::whereIn('statut', array_keys(CircuitBon::ROLES_PAR_STATUT))->with('demandeur')->get();
        foreach ($bons as $bon) {
            $role = CircuitBon::roleEnCours($bon);
            if (CircuitBon::valideurs($bon, $role)->isNotEmpty()) {
                continue;
            }

            if ($this->option('simulation')) {
                $this->line("  {$bon->numero} : aucun valideur pour l'étape " . CircuitBon::LIBELLES[$role]);
                $traites++;
                continue;
            }

            $sautees = CircuitBon::sauterEtapesSansValideur($bon);
            if ($sautees) {
                $traites++;
                $bon->refresh()->load('demandeur');
                $this->line("  {$bon->numero} : étape(s) " . implode(', ', $sautees) . " sautée(s), maintenant {$bon->statut}");
                /* Les valideurs de la nouvelle étape sont prévenus, comme à la soumission */
                NotificationService::notifierSoumission($bon, $bon->demandeur);
            }
        }

        $this->info(($this->option('simulation') ? 'Bons concernés : ' : 'Bons passés au niveau supérieur : ') . $traites);

        return self::SUCCESS;
    }
}
