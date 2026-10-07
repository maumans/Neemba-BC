<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Referentiels\ClasseurReferentiels;
use App\Services\Referentiels\ImportReferentiels;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Import du classeur « Référentiels de paramétrage — tous sites » (utilisateurs, codes analytiques, services,
 * caisses, valideurs). Sans --appliquer, c'est une simulation : le compte rendu montre ce qui serait fait.
 *
 *   php artisan referentiels:importer docs/Referentiels_Parametrage_Neemba_tous_sites_v01.xlsx
 *   php artisan referentiels:importer docs/… --appliquer --auteur=admin@neemba.com
 */
class ImporterReferentiels extends Command
{
    protected $signature = 'referentiels:importer
        {fichier : Classeur .xlsx des référentiels}
        {--appliquer : Enregistrer les changements (sans cette option : simulation)}
        {--auteur= : Adresse e-mail de l\'administrateur qui demande les modifications sensibles (double validation)}
        {--sans-double-validation : Appliquer directement plafonds et seuils de caisse (installation initiale ; interdit en production)}
        {--rapport= : Fichier du compte rendu Markdown (par défaut : storage/app/referentiels/)}';

    protected $description = 'Importer les référentiels de paramétrage de Neemba depuis le classeur Excel (simulation par défaut)';

    public function handle(): int
    {
        $fichier = $this->argument('fichier');
        if (!is_file($fichier)) {
            $this->error("Fichier introuvable : {$fichier}");

            return self::FAILURE;
        }

        $sansDoubleValidation = (bool) $this->option('sans-double-validation');
        if ($sansDoubleValidation && app()->isProduction()) {
            $this->error('En production, plafonds et seuils de caisse passent toujours par la double validation.');

            return self::FAILURE;
        }

        $auteur = $this->auteur();
        if ($this->option('auteur') && !$auteur) {
            $this->error("Aucun administrateur actif avec l'adresse {$this->option('auteur')}.");

            return self::FAILURE;
        }

        $classeur = new ClasseurReferentiels($fichier);
        $appliquer = (bool) $this->option('appliquer');
        $rapport = (new ImportReferentiels($classeur, $auteur, !$sansDoubleValidation))->executer($appliquer);

        $chemin = $this->option('rapport')
            ?: storage_path('app/referentiels/import_' . now()->format('Ymd_His') . ($appliquer ? '' : '_simulation') . '.md');
        File::ensureDirectoryExists(dirname($chemin));
        File::put($chemin, $rapport->markdown($classeur->nomFichier, $appliquer, $classeur->responsables()));

        $this->info($appliquer ? 'Import appliqué.' : 'Simulation : rien n\'a été enregistré (ajoutez --appliquer pour enregistrer).');
        $this->table(['Résultat', 'Nombre'], collect($rapport->resume())->map(fn ($n, $libelle) => [$libelle, $n])->values()->all());
        $this->line("Compte rendu : {$chemin}");

        return self::SUCCESS;
    }

    /** Demandeur des modifications sensibles : l'administrateur indiqué, sinon le premier administrateur actif */
    private function auteur(): ?User
    {
        $administrateurs = User::where('actif', true)->get()->filter(fn (User $u) => $u->aLeRole('administrateur'));
        $email = $this->option('auteur');

        return $email
            ? $administrateurs->first(fn (User $u) => strtolower($u->email) === strtolower($email))
            : $administrateurs->sortBy('id')->first();
    }
}
