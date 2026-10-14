<?php

namespace App\Console\Commands;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Commande Artisan : Relance automatique des BP non régularisés
 *
 * RG-M07-04 (spec v2.3) : chaque vendredi, relance des BP dont l'échéance est dépassée, au nom du caissier qui les a payés,
 * adressée à la personne concernée avec en copie le directeur de son département, le Chef comptable, la Trésorerie et le
 * DAF. Une justification en cours de visa n'est pas relancée : la personne a fait sa part.
 *
 * Usage : php artisan bons:relancer-regularisation
 * Planification : chaque vendredi à 8 h (routes/console.php)
 */
class RelancerRegularisationBP extends Command
{
    protected $signature = 'bons:relancer-regularisation';

    protected $description = 'Relancer les demandeurs dont les bons provisoires ne sont pas régularisés dans les délais';

    public function handle(): int
    {
        $this->info('Vérification des BP en attente de régularisation...');

        /* Récupérer les BP en attente dont la date limite est dépassée */
        $bonsEnRetard = BonCaisse::enAttenteRegularisation()
            ->whereNotNull('date_limite_regularisation')
            ->where('date_limite_regularisation', '<', now())
            ->whereDoesntHave('justification', fn ($q) => $q->whereIn('statut', ['en_validation', 'validee']))
            ->with('demandeur', 'caissier')
            ->get();

        $nbRelances = 0;

        foreach ($bonsEnRetard as $bon) {
            /* Vérifier qu'on n'a pas déjà relancé aujourd'hui */
            $dejaRelanceAujourdhui = $bon->historiqueActions()
                ->where('action', HistoriqueAction::ACTION_RELANCE_REGULARISATION)
                ->whereDate('created_at', today())
                ->exists();

            if ($dejaRelanceAujourdhui) {
                continue;
            }

            $joursRetard = now()->diffInDays($bon->date_limite_regularisation);

            /* Enregistrer la relance dans l'historique */
            HistoriqueAction::enregistrer(
                $bon,
                HistoriqueAction::ACTION_RELANCE_REGULARISATION,
                $bon->statut,
                $bon->statut,
                null,
                "Relance automatique : régularisation en retard de {$joursRetard} jour(s). Demandeur : {$bon->demandeur?->name}",
                [
                    'jours_retard' => $joursRetard,
                    'date_limite' => $bon->date_limite_regularisation->toDateString(),
                    'demandeur_id' => $bon->demandeur_id,
                ],
            );

            /* Notification push au demandeur */
            NotificationService::notifierRelanceRegularisation($bon);

            $nbRelances++;

            $this->line("  → {$bon->numero} : retard de {$joursRetard} jour(s) — {$bon->demandeur?->name}");
        }

        if ($nbRelances === 0) {
            $this->info('Aucun BP en retard de régularisation.');
        } else {
            $this->info("{$nbRelances} relance(s) enregistrée(s).");
        }

        return self::SUCCESS;
    }
}
