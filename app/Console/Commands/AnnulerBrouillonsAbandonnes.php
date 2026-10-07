<?php

namespace App\Console\Commands;

use App\Models\BonCaisse;
use App\Models\Parametre;
use App\Services\BonCaisse\AnnulerBon;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * RG-BC-26 : un brouillon non modifié depuis 30 jours (paramètre delai_abandon_brouillon) est annulé,
 * et son demandeur reçoit une notification in-app (US-BC-11, scénario « brouillon abandonné »).
 *
 * Une pièce ajoutée récemment compte comme une modification du brouillon.
 *
 * Usage : php artisan bons:annuler-brouillons-abandonnes — planifiée chaque nuit.
 */
class AnnulerBrouillonsAbandonnes extends Command
{
    protected $signature = 'bons:annuler-brouillons-abandonnes';

    protected $description = 'Annuler les brouillons de bon de caisse non modifiés depuis le délai paramétré (RG-BC-26)';

    public function handle(): int
    {
        $jours = Parametre::delaiAbandonBrouillon();
        $limite = now()->subDays($jours);

        $brouillons = BonCaisse::where('statut', 'BROUILLON')
            ->where('updated_at', '<', $limite)
            ->whereDoesntHave('piecesJointes', fn ($pieces) => $pieces->where('created_at', '>=', $limite))
            ->with('demandeur')
            ->get();

        foreach ($brouillons as $brouillon) {
            $bon = AnnulerBon::executer($brouillon, null, "Annulation automatique : brouillon non modifié depuis {$jours} jours.");
            NotificationService::notifierAnnulationBrouillon($bon->setRelation('demandeur', $brouillon->demandeur), $jours);
            $this->line("  → brouillon #{$bon->id} ({$brouillon->demandeur?->nom_complet}) annulé");
        }

        $this->info($brouillons->isEmpty() ? 'Aucun brouillon abandonné.' : "{$brouillons->count()} brouillon(s) annulé(s).");

        return self::SUCCESS;
    }
}
