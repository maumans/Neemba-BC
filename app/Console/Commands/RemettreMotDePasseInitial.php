<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Q108 : remet le mot de passe initial sur des comptes (ids donnés, ou --tous : comptes actifs dont le mot de passe n'est
 * pas le mot de passe initial) et les marque « à changer à la première connexion ».
 *
 * Usage : php artisan utilisateurs:mot-de-passe-initial 8 9 10
 *         php artisan utilisateurs:mot-de-passe-initial --tous
 */
class RemettreMotDePasseInitial extends Command
{
    protected $signature = 'utilisateurs:mot-de-passe-initial
        {ids?* : identifiants des comptes}
        {--tous : tous les comptes actifs dont le mot de passe n\'est pas le mot de passe initial}';

    protected $description = 'Remettre le mot de passe initial, à changer à la première connexion';

    public function handle(): int
    {
        $ids = array_map('intval', (array) $this->argument('ids'));
        if (!$ids && !$this->option('tous')) {
            $this->error('Donnez les identifiants des comptes, ou --tous.');

            return self::INVALID;
        }

        $comptes = $ids
            ? User::whereIn('id', $ids)->orderBy('id')->get()
            : User::actifs()->orderBy('id')->get()->reject(fn (User $u) => Hash::check(User::MOT_DE_PASSE_INITIAL, $u->password));

        foreach ($comptes as $compte) {
            $compte->update(['password' => User::MOT_DE_PASSE_INITIAL, 'mot_de_passe_a_changer' => true]);
            $this->line("{$compte->id}  {$compte->nom_complet}  ({$compte->email})");
        }
        $this->info($comptes->count() . ' compte(s) : mot de passe initial ' . User::MOT_DE_PASSE_INITIAL . ', à changer à la première connexion.');

        return self::SUCCESS;
    }
}
