<?php

namespace App\Console\Commands;

use App\Services\Caisse\ClotureJournaliere;
use Illuminate\Console\Command;

/**
 * Spec v2.4, RG-M09-03, RG-M09-04 : à 23 h 45, clôture des journées que le gérant n'a pas clôturées (chaque caisse active,
 * journées oubliées rattrapées sur 31 jours).
 */
class CloturerJourneesCaisse extends Command
{
    protected $signature = 'caisse:cloturer-journees {--date= : Clôturer jusqu\'à cette date (AAAA-MM-JJ), défaut : aujourd\'hui}';

    protected $description = 'Clôture automatiquement les journées de caisse non clôturées';

    public function handle(): int
    {
        $jusqua = $this->option('date') ? \Carbon\Carbon::parse($this->option('date')) : today();
        $faites = ClotureJournaliere::cloturerEnAttente($jusqua);
        $this->info($faites->count() . ' journée(s) clôturée(s).');

        return self::SUCCESS;
    }
}
