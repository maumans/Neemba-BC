<?php

namespace App\Console\Commands;

use App\Models\PieceJointe;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Commande Artisan : Alertes d'expiration d'archives
 *
 * Vérifie quotidiennement les documents archivés dont la date d'expiration
 * approche (J-30, J-7 et J-1) et envoie des notifications aux DAF, administrateurs
 * et gestionnaires concernés.
 *
 * Usage : php artisan archives:alerter-expiration
 * Planification recommandée : quotidienne via le scheduler Laravel
 */
class AlerterExpirationArchives extends Command
{
    protected $signature   = 'archives:alerter-expiration';
    protected $description = 'Alerter les responsables lorsque des archives approchent de leur date d\'expiration légale';

    /** Seuils de préavis (en jours) avant expiration */
    private const SEUILS_JOURS = [30, 7, 1];

    public function handle(): int
    {
        $this->info('Vérification des archives proches de leur expiration...');

        $nbAlertes = 0;

        foreach (self::SEUILS_JOURS as $jours) {
            $dateExpiration = now()->addDays($jours)->toDateString();

            $archivesExpirant = PieceJointe::query()
                ->whereNotNull('date_expiration_archive')
                ->whereDate('date_expiration_archive', $dateExpiration)
                ->with('bonCaisse.demandeur')
                ->get();

            foreach ($archivesExpirant as $archive) {
                /* Éviter les doublons d'alerte pour le même seuil */
                $cle = "archive_expiration_{$archive->id}_{$jours}j";
                if (\Illuminate\Support\Facades\Cache::has($cle)) {
                    continue;
                }

                $this->notifierExpiration($archive, $jours);
                \Illuminate\Support\Facades\Cache::put($cle, true, now()->addHours(23));
                $nbAlertes++;

                $this->line("  → {$archive->nom_fichier} (bon #{$archive->bon_caisse_id}) expire dans {$jours} jour(s) — {$archive->date_expiration_archive}");
            }
        }

        if ($nbAlertes === 0) {
            $this->info('Aucune archive en voie d\'expiration aujourd\'hui.');
        } else {
            $this->info("{$nbAlertes} alerte(s) d'expiration envoyée(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Envoyer une notification d'expiration imminente aux responsables
     */
    private function notifierExpiration(PieceJointe $archive, int $joursRestants): void
    {
        /* Destinataires : DAF + administrateurs */
        $destinataires = User::actifs()
            ->whereIn('role', ['daf', 'administrateur'])
            ->get();

        $urgenceLabel = match (true) {
            $joursRestants <= 1  => '⚠ DEMAIN',
            $joursRestants <= 7  => '⚠ Dans 7 jours',
            default              => 'Dans 30 jours',
        };

        $nomBon  = $archive->bonCaisse?->numero ?? "#{$archive->bon_caisse_id}";
        $message = "Le document \"{$archive->nom_fichier}\" (bon {$nomBon}) atteint sa durée légale de conservation dans {$joursRestants} jour(s) (expiration : {$archive->date_expiration_archive}). Veuillez décider de sa destruction ou de sa prolongation de conservation.";

        foreach ($destinataires as $destinataire) {
            try {
                $notification = Notification::create([
                    'destinataire_id' => $destinataire->id,
                    'bon_caisse_id'   => $archive->bon_caisse_id,
                    'expediteur_id'   => null,
                    'type'            => 'alerte_expiration_archive',
                    'titre'           => "{$urgenceLabel} — Expiration archive : {$archive->nom_fichier}",
                    'message'         => $message,
                    'metadata'        => [
                        'piece_jointe_id'      => $archive->id,
                        'nom_fichier'          => $archive->nom_fichier,
                        'date_expiration'      => $archive->date_expiration_archive,
                        'jours_restants'       => $joursRestants,
                        'bon_caisse_id'        => $archive->bon_caisse_id,
                    ],
                ]);

                /* Diffusion temps réel si Reverb disponible */
                try {
                    broadcast(new \App\Events\NouvelleNotification($notification));
                } catch (\Throwable $e) {
                    Log::warning('Broadcast alerte expiration archive échoué : ' . $e->getMessage());
                }
            } catch (\Throwable $e) {
                Log::error("Erreur notification expiration archive #{$archive->id} pour {$destinataire->email} : " . $e->getMessage());
            }
        }
    }
}
