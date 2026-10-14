<?php

namespace App\Console\Commands;

use App\Models\BonCaisse;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Format;
use Illuminate\Console\Command;

/**
 * RG-M07-07 (spec v2.3) : rapport mensuel des BP non régularisés au Directeur Pays (et à son adjoint), avec le lien vers le
 * tableau des BP en retard.
 */
class RapportBpMensuel extends Command
{
    protected $signature = 'bons:rapport-bp-mensuel';

    protected $description = 'Envoyer au Directeur Pays le rapport mensuel des BP non régularisés';

    public function handle(): int
    {
        $bons = BonCaisse::where('type_bon', 'BP')->enAttenteRegularisation()->get(['id', 'montant', 'date_limite_regularisation', 'suivi_rh']);
        $enRetard = $bons->filter(fn ($b) => $b->date_limite_regularisation && $b->date_limite_regularisation->lt(today()));
        $message = $bons->isEmpty()
            ? 'Aucun bon provisoire n\'est en attente de régularisation au ' . Format::date(today()) . '.'
            : 'Au ' . Format::date(today()) . ' : ' . $bons->count() . ' BP non régularisé(s) pour ' . Format::montant($bons->sum('montant'))
                . ', dont ' . $enRetard->count() . ' en retard (' . Format::montant($enRetard->sum('montant')) . ') et '
                . $bons->whereNotNull('suivi_rh')->count() . ' transmis aux RH.';
        $destinataires = User::actifs()->where(fn ($q) => $q->whereIn('role', ['directeur_pays', 'dp_adjoint'])
            ->orWhereHas('roles', fn ($r) => $r->whereIn('role', ['directeur_pays', 'dp_adjoint'])))->get();
        NotificationService::notifierRapportBp($destinataires, 'Rapport mensuel des BP non régularisés', $message);
        $this->info($message);

        return self::SUCCESS;
    }
}
