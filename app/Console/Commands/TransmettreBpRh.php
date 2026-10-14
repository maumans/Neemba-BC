<?php

namespace App\Console\Commands;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\User;
use App\Services\BonCaisse\JustifierBp;
use App\Services\NotificationService;
use App\Support\Format;
use Illuminate\Console\Command;

/**
 * RG-M07-05 (spec v2.3) : un BP dont l'échéance est dépassée passe « En retard » et est transmis aux RH. La retenue sur
 * salaire n'intervient qu'après l'email d'information de la DRH à l'intéressé, tracé sur la fiche (suivi RH).
 * Une justification en cours de visa n'est pas transmise : le retard ne tient plus à l'intéressé.
 */
class TransmettreBpRh extends Command
{
    protected $signature = 'bons:transmettre-bp-rh';

    protected $description = 'Transmettre aux RH les BP dont l\'échéance de régularisation est dépassée';

    public function handle(): int
    {
        $bons = BonCaisse::where('type_bon', 'BP')
            ->enAttenteRegularisation()
            ->whereNull('suivi_rh')
            ->whereNotNull('date_limite_regularisation')
            ->where('date_limite_regularisation', '<', today())
            ->whereDoesntHave('justification', fn ($q) => $q->whereIn('statut', ['en_validation', 'validee']))
            ->with('demandeur', 'visiteur.directeur')
            ->get();
        $rh = User::actifs()->where(fn ($q) => $q->where('role', 'rh')->orWhereHas('roles', fn ($r) => $r->where('role', 'rh')))->get();

        foreach ($bons as $bon) {
            $personne = JustifierBp::initiateurDe($bon) ?? $bon->demandeur;
            $bon->update(['suivi_rh' => ['transmis_le' => now()->toIso8601String()]]);
            HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_SUIVI_RH, $bon->statut, $bon->statut, null,
                'BP en retard de régularisation (échéance du ' . Format::date($bon->date_limite_regularisation) . ') : transmis aux RH.');
            NotificationService::notifierSuiviBp($bon, null, $rh, 'suivi_rh', 'BP en retard transmis aux RH',
                "Le bon provisoire {$bon->numero} de {$personne?->nom_complet} (" . Format::montant($bon->montant)
                . ') n\'est pas régularisé : informez l\'intéressé avant toute retenue sur salaire.');
            $this->line("  → {$bon->numero} transmis aux RH");
        }
        $this->info($bons->count() . ' BP transmis aux RH.');

        return self::SUCCESS;
    }
}
