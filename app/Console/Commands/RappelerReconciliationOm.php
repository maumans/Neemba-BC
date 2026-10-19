<?php

namespace App\Console\Commands;

use App\Services\Caisse\ReconciliationOrangeMoney;
use Illuminate\Console\Command;

/**
 * Spec v2.4, RG-M08-13 : chaque lundi, rappel de la réconciliation Orange Money de la semaine écoulée, si elle n'est pas
 * établie (trésorerie) ou pas encore validée (signataires manquants).
 */
class RappelerReconciliationOm extends Command
{
    protected $signature = 'caisse:rappeler-reconciliation-om';

    protected $description = 'Rappelle la réconciliation hebdomadaire de la caisse Orange Money';

    public function handle(): int
    {
        $this->info(ReconciliationOrangeMoney::rappels() . ' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
