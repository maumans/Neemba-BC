<?php

namespace App\Console\Commands;

use App\Services\Securite\ReglagesSecurite;
use Illuminate\Console\Command;

/**
 * Secours de la double authentification (spec v2.4, RG-M01-07) : quand plus personne ne peut se connecter (serveur
 * d'email en panne), l'interrupteur se bascule sur le serveur. En temps normal, l'administrateur le fait dans
 * Paramétrage > Sécurité.
 *
 * Usage : php artisan securite:double-authentification desactiver
 */
class BasculerDoubleAuthentification extends Command
{
    protected $signature = 'securite:double-authentification
        {etat : activer ou desactiver}';

    protected $description = 'Activer ou désactiver la double authentification (secours, sur le serveur)';

    public function handle(): int
    {
        $etat = strtolower((string) $this->argument('etat'));
        if (!in_array($etat, ['activer', 'desactiver'], true)) {
            $this->error('État attendu : activer ou desactiver.');

            return self::INVALID;
        }

        $cle = 'double_authentification_active';
        $modifies = ReglagesSecurite::appliquer([$cle => $etat === 'activer'], null);
        $libelle = ReglagesSecurite::REGLAGES[$cle]['libelle'];

        $this->info($modifies
            ? "{$libelle} : " . ($etat === 'activer' ? 'activé' : 'désactivé') . '.'
            : "{$libelle} : déjà " . ($etat === 'activer' ? 'activé' : 'désactivé') . '.');

        return self::SUCCESS;
    }
}
