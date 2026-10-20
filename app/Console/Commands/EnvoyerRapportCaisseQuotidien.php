<?php

namespace App\Console\Commands;

use App\Services\Caisse\ClotureJournaliere;
use App\Services\Caisse\RapportsCaisse;
use Illuminate\Console\Command;

/**
 * Spec v2.4, RG-M09-04 : envoi, avant 8 h, du rapport « REPORTING CAISSE » de la veille, par caisse, en PDF et en Excel,
 * aux destinataires de chaque caisse. Une journée restée ouverte (planificateur arrêté à 23 h 45) est d'abord clôturée.
 *
 * Remplace l'envoi par site du socle (spec v2.4 : rapport par caisse).
 */
class EnvoyerRapportCaisseQuotidien extends Command
{
    protected $signature = 'rapports:envoyer-quotidien
                            {--date= : Journée du rapport (AAAA-MM-JJ), défaut : la veille}
                            {--caisse= : Limiter à une caisse (identifiant)}';

    protected $description = 'Envoie le rapport REPORTING CAISSE de la veille, par caisse';

    public function handle(): int
    {
        $jour = $this->option('date') ? \Carbon\Carbon::parse($this->option('date')) : now()->subDay();
        ClotureJournaliere::cloturerEnAttente($jour);
        $compte = RapportsCaisse::envoyer($jour, $this->option('caisse') ? (int) $this->option('caisse') : null);
        $this->info("Journée du {$jour->format('d/m/Y')} : {$compte['envoyes']} rapport(s) envoyé(s), {$compte['sans_operation']} caisse(s) sans opération, "
            . "{$compte['sans_destinataire']} sans destinataire.");

        return self::SUCCESS;
    }
}
