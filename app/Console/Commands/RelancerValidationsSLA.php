<?php

namespace App\Console\Commands;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\Parametre;
use App\Models\User;
use App\Models\Validation;
use App\Services\NimbaSmsService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Commande Artisan : Relance automatique des validations SLA dépassées
 * 
 * Vérifie toutes les heures les validations en attente dont le SLA est dépassé.
 * - SLA dépassé (1×) → Relance email/push + SMS (si activé)
 * - SLA dépassé (2×) → Escalade automatique au niveau N+1
 * 
 * SLA par défaut :
 * - Resp. Service : 4h
 * - CDG : 8h
 * - DAF : 4h
 * - DP : 8h
 * 
 * Usage : php artisan validations:relancer-sla
 * Planification recommandée : toutes les heures via le scheduler Laravel
 */
class RelancerValidationsSLA extends Command
{
    protected $signature = 'validations:relancer-sla';

    protected $description = 'Relancer les validateurs dont le SLA est dépassé et escalader si nécessaire';

    /** Mapping rôle → rôle supérieur pour l'escalade */
    private const ESCALADE_MAP = [
        'responsable_service' => 'controle_gestion',
        'controle_gestion' => 'daf',
        'daf' => 'directeur_pays',
        'directeur_pays' => null, // pas d'escalade au-delà du DP
    ];

    /** Labels lisibles pour les rôles */
    private const ROLE_LABELS = [
        'responsable_service' => 'le Chef de Service',
        'controle_gestion' => 'le Contrôle de Gestion',
        'daf' => 'le DAF',
        'directeur_pays' => 'le Directeur Pays',
    ];

    public function handle(): int
    {
        $this->info('Vérification des SLA de validation...');

        /* Récupérer toutes les validations en attente avec une date d'attribution */
        $validationsEnAttente = Validation::where('statut', 'en_attente')
            ->whereNotNull('date_attribution')
            ->with(['bonCaisse.demandeur'])
            ->get();

        $nbRelances = 0;
        $nbEscalades = 0;
        $smsActif = Parametre::valeur('sla_relance_sms', false);

        foreach ($validationsEnAttente as $validation) {
            $bon = $validation->bonCaisse;

            /* Vérifier que le bon est toujours en attente de validation */
            if (!$bon || !str_starts_with($bon->statut, 'EN_ATTENTE_')) {
                continue;
            }

            /* Priorité : vérifier l'escalade d'abord */
            if ($validation->doitEscalader()) {
                $this->traiterEscalade($validation, $bon, $smsActif);
                $nbEscalades++;
                continue;
            }

            /* Sinon vérifier si relance nécessaire */
            if ($validation->slaDepasse()) {
                $this->traiterRelance($validation, $bon, $smsActif);
                $nbRelances++;
            }
        }

        if ($nbRelances === 0 && $nbEscalades === 0) {
            $this->info('Aucun SLA dépassé.');
        } else {
            $this->info("{$nbRelances} relance(s), {$nbEscalades} escalade(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Traiter une relance SLA (SLA dépassé, pas encore escaladé)
     */
    private function traiterRelance(Validation $validation, BonCaisse $bon, bool $smsActif): void
    {
        $heuresRetard = $validation->date_attribution->diffInHours(now());

        /* Ne pas relancer plus d'une fois par heure */
        if ($validation->date_relance && $validation->date_relance->diffInMinutes(now()) < 55) {
            return;
        }

        /* Notification push */
        NotificationService::notifierRelanceSla($bon, $validation->role, $heuresRetard);

        /* SMS si activé */
        if ($smsActif) {
            $this->envoyerSmsRelance($validation->role, $bon, $heuresRetard);
        }

        /* Enregistrer la relance */
        $validation->enregistrerRelance();

        /* Historique */
        HistoriqueAction::enregistrer(
            $bon,
            'relance_sla',
            $bon->statut,
            $bon->statut,
            null,
            "Relance SLA automatique : {$validation->role} en retard de {$heuresRetard}h (relance #{$validation->nb_relances}).",
            [
                'role' => $validation->role,
                'heures_retard' => $heuresRetard,
                'nb_relances' => $validation->nb_relances,
                'sla_heures' => $validation->slaHeures(),
            ],
        );

        $roleLabel = self::ROLE_LABELS[$validation->role] ?? $validation->role;
        $this->line("  → {$bon->numero} : relance {$roleLabel} ({$heuresRetard}h de retard)");
    }

    /**
     * Traiter une escalade SLA (2× SLA dépassé)
     */
    private function traiterEscalade(Validation $validation, BonCaisse $bon, bool $smsActif): void
    {
        $roleEscalade = self::ESCALADE_MAP[$validation->role] ?? null;

        /* Si pas de niveau supérieur, juste relancer */
        if (!$roleEscalade) {
            $this->traiterRelance($validation, $bon, $smsActif);
            return;
        }

        $heuresRetard = $validation->date_attribution->diffInHours(now());

        /* Notification push escalade */
        NotificationService::notifierEscaladeSla($bon, $validation->role, $roleEscalade);

        /* SMS si activé */
        if ($smsActif) {
            $this->envoyerSmsEscalade($roleEscalade, $bon, $validation->role);
        }

        /* Marquer l'escalade */
        $validation->marquerEscalade();

        /* Historique */
        HistoriqueAction::enregistrer(
            $bon,
            'escalade_sla',
            $bon->statut,
            $bon->statut,
            null,
            "Escalade SLA automatique : {$validation->role} → {$roleEscalade} après {$heuresRetard}h sans réponse.",
            [
                'role_origine' => $validation->role,
                'role_escalade' => $roleEscalade,
                'heures_retard' => $heuresRetard,
            ],
        );

        $roleLabelOrigine = self::ROLE_LABELS[$validation->role] ?? $validation->role;
        $roleLabelEscalade = self::ROLE_LABELS[$roleEscalade] ?? $roleEscalade;
        $this->line("  ⚡ {$bon->numero} : escalade {$roleLabelOrigine} → {$roleLabelEscalade} ({$heuresRetard}h)");
    }

    /**
     * Envoyer les SMS de relance aux validateurs du rôle
     */
    private function envoyerSmsRelance(string $role, BonCaisse $bon, int $heuresRetard): void
    {
        try {
            $smsService = app(NimbaSmsService::class);
            $query = User::actifs()->parRole($role)->whereNotNull('telephone');
            if ($role === 'responsable_service' && $bon->service) {
                $query->where('service', $bon->service);
            }
            $validateurs = $query->get();

            foreach ($validateurs as $validateur) {
                $smsService->envoyerRelanceSla(
                    $validateur->telephone,
                    $bon->numero,
                    $bon->montant_format,
                    $bon->demandeur->nom_complet ?? 'N/A',
                    $heuresRetard,
                );
            }
        } catch (\Throwable $e) {
            Log::warning("SMS relance SLA échoué : {$e->getMessage()}");
        }
    }

    /**
     * Envoyer les SMS d'escalade aux validateurs du rôle N+1
     */
    private function envoyerSmsEscalade(string $roleEscalade, BonCaisse $bon, string $roleOrigine): void
    {
        try {
            $smsService = app(NimbaSmsService::class);
            $query = User::actifs()->parRole($roleEscalade)->whereNotNull('telephone');
            if ($roleEscalade === 'responsable_service' && $bon->service) {
                $query->where('service', $bon->service);
            }
            $validateurs = $query->get();
            $roleLabelOrigine = self::ROLE_LABELS[$roleOrigine] ?? $roleOrigine;

            foreach ($validateurs as $validateur) {
                $smsService->envoyerEscaladeSla(
                    $validateur->telephone,
                    $bon->numero,
                    $bon->montant_format,
                    $roleLabelOrigine,
                );
            }
        } catch (\Throwable $e) {
            Log::warning("SMS escalade SLA échoué : {$e->getMessage()}");
        }
    }
}
