<?php

namespace App\Console\Commands;

use App\Services\Caisse\InventairesCaisse;
use Illuminate\Console\Command;

/**
 * Spec v2.4, RG-M10-01 : chaque jour, rappels et alertes des inventaires de fin de mois et des contrôles inopinés.
 */
class VerifierInventairesCaisse extends Command
{
    protected $signature = 'caisse:verifier-inventaires';

    protected $description = 'Rappelle les inventaires de fin de mois et les contrôles inopinés des caisses';

    public function handle(): int
    {
        $this->info(InventairesCaisse::verifier() . ' notification(s) envoyée(s).');

        return self::SUCCESS;
    }
}
