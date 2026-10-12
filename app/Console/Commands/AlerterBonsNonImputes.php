<?php

namespace App\Console\Commands;

use App\Models\BonCaisse;
use App\Models\User;
use App\Services\Comptabilite\ImputationBon;
use App\Services\NotificationService;
use App\Support\Format;
use App\Support\JoursOuvres;
use Illuminate\Console\Command;

/**
 * RG-M16-04 : alerte au Chef comptable pour tout bon payé non imputé à J+1 ouvré (délai paramétrable,
 * « imputation_delai_alerte »). Une notification par Chef comptable, rappelée chaque jour ouvré tant qu'il en reste.
 * Planifiée chaque jour à 8 h. Usage : php artisan comptabilite:alerter-non-imputes
 */
class AlerterBonsNonImputes extends Command
{
    protected $signature = 'comptabilite:alerter-non-imputes';

    protected $description = 'Alerter le Chef comptable des bons payés non imputés dans le délai';

    public function handle(): int
    {
        if (!JoursOuvres::estOuvre(today())) {
            $this->info('Jour non ouvré : pas d\'alerte.');

            return self::SUCCESS;
        }

        $enRetard = ImputationBon::requeteAImputer()->with('imputation')->orderBy('date_paiement')->get()
            ->filter(fn (BonCaisse $bon) => ImputationBon::enRetard($bon))->values();
        if ($enRetard->isEmpty()) {
            $this->info('Aucun bon payé en retard d\'imputation.');

            return self::SUCCESS;
        }

        $liste = $enRetard->take(10)->map(fn (BonCaisse $b) => "{$b->numero} (payé le " . Format::date($b->date_paiement) . ')')->implode(', ')
            . ($enRetard->count() > 10 ? ' et ' . ($enRetard->count() - 10) . ' autre(s)' : '');
        $destinataires = User::actifs()->get()->filter(fn (User $u) => $u->aLeRole('chef_comptable'));
        foreach ($destinataires as $chef) {
            NotificationService::creerEtDiffuserSimple($chef, null, 'imputation_en_retard',
                'Bons payés non imputés',
                "{$enRetard->count()} bon(s) payé(s) attendent leur imputation comptable : {$liste}.",
                ['bons' => $enRetard->pluck('id')->all(), 'url' => route('comptabilite.index')]);
        }
        $this->info("{$enRetard->count()} bon(s) en retard ; {$destinataires->count()} Chef(s) comptable(s) alerté(s).");

        return self::SUCCESS;
    }
}
