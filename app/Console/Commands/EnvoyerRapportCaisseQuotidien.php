<?php

namespace App\Console\Commands;

use App\Mail\RapportCaisseQuotidien;
use App\Models\BonCaisse;
use App\Models\Notification;
use App\Models\RapportCaisse;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Commande Artisan : Envoi automatique du Rapport Journalier de Caisse
 * 
 * Pour chaque site actif :
 * 1. Calcule les données en temps réel (bons payés, soldes) sans créer de rapport en base
 * 2. Consolide les flux entrants/sortants
 * 3. Envoie le rapport par e-mail (Excel + PDF) aux destinataires définis :
 *    DAF, Contrôle de gestion, Caissier du site, Administrateurs
 * 4. Alerte si aucun mouvement détecté pour un site
 * 
 * Usage : php artisan rapports:envoyer-quotidien
 * Planification : quotidienne à 07:30 via le scheduler Laravel
 */
class EnvoyerRapportCaisseQuotidien extends Command
{
    protected $signature = 'rapports:envoyer-quotidien
                            {--date= : Date du rapport (YYYY-MM-DD), défaut = veille}
                            {--site= : Limiter à un site spécifique}';

    protected $description = 'Générer et envoyer automatiquement les rapports journaliers de caisse par e-mail';

    public function handle(): int
    {
        
        $dateRapport = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))
            : now()->subDay();

        $this->info("Envoi des rapports journaliers pour le {$dateRapport->format('d/m/Y')}...");

        /* Récupérer les sites à traiter */
        $sites = $this->option('site')
            ? collect([$this->option('site')])
            : Site::actifs()->pluck('nom');

        if ($sites->isEmpty()) {
            $this->warn('Aucun site actif trouvé.');
            return self::SUCCESS;
        }

        /* Destinataires : DAF + contrôle de gestion + administrateurs */
        $destinataires = User::where('actif', true)
            ->whereIn('role', ['daf', 'controle_gestion', 'administrateur'])
            ->whereNotNull('email')
            ->get();

        $nbEnvoyes = 0;
        $nbErreurs = 0;

        foreach ($sites as $site) {
            $this->line("  → Site : {$site}");

            try {
                /* Calculer les données en temps réel (sans créer de rapport en base) */
                $donnees = $this->calculerDonneesJournalieres($dateRapport, $site);

                if (!$donnees) {
                    $this->alerterDonneesIncompletes($site, $dateRapport);
                    $nbErreurs++;
                    continue;
                }

                $rapport = $donnees['rapport'];
                $bonsPaye = $donnees['bonsPaye'];

                /* Ajouter le caissier du site aux destinataires */
                $destinatairesSite = $destinataires->merge(
                    User::where('actif', true)
                        ->where('role', 'caissier')
                        ->where('site', $site)
                        ->whereNotNull('email')
                        ->get()
                );

                if ($destinatairesSite->isEmpty()) {
                    $this->warn("    Aucun destinataire trouvé pour le site {$site}.");
                    continue;
                }

                /* Envoyer l'e-mail */
                Mail::to($destinatairesSite->pluck('email')->unique()->toArray())
                    ->send(new RapportCaisseQuotidien($rapport, $bonsPaye));

                $nbEnvoyes++;
                $this->info("    ✓ Rapport envoyé à {$destinatairesSite->count()} destinataire(s).");

            } catch (\Exception $e) {
                $nbErreurs++;
                $this->error("    ✗ Erreur pour {$site} : {$e->getMessage()}");
                Log::error("Erreur envoi rapport caisse {$site}", [
                    'date' => $dateRapport->toDateString(),
                    'site' => $site,
                    'error' => $e->getMessage(),
                ]);

                $this->alerterDonneesIncompletes($site, $dateRapport, $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Terminé : {$nbEnvoyes} rapport(s) envoyé(s), {$nbErreurs} erreur(s).");

        return $nbErreurs > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Calculer les données journalières en temps réel pour un site
     * (sans créer ni modifier de rapport en base de données)
     */
    protected function calculerDonneesJournalieres(\Carbon\Carbon $date, string $site): ?array
    {
        /* Récupérer les bons payés ce jour pour ce site */
        $bonsPaye = BonCaisse::with('demandeur')
            ->parStatut('PAYE')
            ->parSite($site)
            ->whereDate('date_paiement', $date)
            ->get();

        $totalSorties = (float) $bonsPaye->sum('montant');

        /* Si aucun mouvement ce jour, ne pas envoyer */
        if ($bonsPaye->isEmpty() && $totalSorties == 0) {
            return null;
        }

        /* Solde d'ouverture : dernier rapport existant ou solde du site */
        $soldeOuverture = RapportCaisse::soldePrecedent($site);

        /* Trouver le caissier du site */
        $caissier = User::where('actif', true)
            ->where('role', 'caissier')
            ->where('site', $site)
            ->first();

        /* Construire un objet RapportCaisse en mémoire (sans persister) */
        $rapport = new RapportCaisse([
            'date_rapport' => $date,
            'site' => $site,
            'solde_ouverture' => $soldeOuverture,
            'total_entrees' => 0,
            'total_sorties' => $totalSorties,
            'solde_cloture' => $soldeOuverture - $totalSorties,
            'caissier_id' => $caissier?->id,
            'observations' => 'Rapport journalier calculé en temps réel.',
        ]);

        /* Charger manuellement les relations pour l'email */
        $rapport->setRelation('caissier', $caissier);

        /* Calculer les statistiques détaillées (en mémoire) */
        $rapport->calculerStatistiques($bonsPaye);

        $this->line("    Données calculées : {$bonsPaye->count()} bon(s), sorties = {$totalSorties} GNF");

        return [
            'rapport' => $rapport,
            'bonsPaye' => $bonsPaye,
        ];
    }

    /**
     * Envoyer une alerte si le rapport ne peut être généré
     */
    protected function alerterDonneesIncompletes(string $site, \Carbon\Carbon $date, ?string $raison = null): void
    {
        $message = $raison
            ? "Rapport caisse {$site} du {$date->format('d/m/Y')} : {$raison}"
            : "Aucun mouvement de caisse pour {$site} le {$date->format('d/m/Y')} — rapport non généré.";

        $this->warn("    ⚠ {$message}");

        Log::warning('Rapport caisse non généré', [
            'site' => $site,
            'date' => $date->toDateString(),
            'raison' => $raison ?? 'Aucun mouvement',
        ]);

        /* Notifier les caissiers du site */
        $caissiers = User::where('actif', true)
            ->where('role', 'caissier')
            ->where('site', $site)
            ->get();

        foreach ($caissiers as $caissier) {
            Notification::create([
                'destinataire_id' => $caissier->id,
                'type' => 'alerte_rapport',
                'titre' => 'Rapport de caisse non généré',
                'message' => $message,
                'metadata' => [
                    'site' => $site,
                    'date' => $date->toDateString(),
                ],
            ]);
        }
    }
}
