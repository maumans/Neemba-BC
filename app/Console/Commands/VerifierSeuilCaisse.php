<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Commande Artisan : Vérification proactive des seuils de caisse
 * 
 * Parcourt tous les sites actifs et envoie des alertes (push + SMS)
 * aux caissiers et responsables lorsque le solde de caisse
 * est passé sous le seuil minimum configuré pour chaque site.
 * 
 * Dédoublonnage : max 1 alerte par jour par site (via cache Laravel).
 * 
 * Planification recommandée : 2× par jour (08:00 et 14:00)
 */
class VerifierSeuilCaisse extends Command
{
    protected $signature = 'caisse:verifier-seuils';

    protected $description = 'Vérifie les soldes de caisse de tous les sites et envoie des alertes SMS + push aux caissiers si le seuil minimum est atteint';

    public function handle(): int
    {
        $this->info('🔍 Vérification des seuils de caisse en cours...');
        $this->newLine();

        $resultats = NotificationService::verifierSeuilsCaisse();

        if (empty($resultats)) {
            $this->info('✅ Aucun site sous le seuil minimum — rien à signaler.');
            return self::SUCCESS;
        }

        $this->table(
            ['Site', 'Résultat'],
            collect($resultats)->map(fn ($message, $site) => [$site, $message])->values()->toArray()
        );

        $alertesEnvoyees = collect($resultats)->filter(fn ($msg) => str_contains($msg, 'Alertes envoyées'))->count();
        $ignorees = collect($resultats)->filter(fn ($msg) => str_contains($msg, 'ignorée'))->count();

        $this->newLine();
        $this->info("📊 Résumé : {$alertesEnvoyees} alerte(s) envoyée(s), {$ignorees} ignorée(s) (déjà alerté aujourd'hui).");

        return self::SUCCESS;
    }
}
