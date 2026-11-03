<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /* Pas de WithoutModelEvents : à la création d'un compte, User remplit son entité (Neemba Guinée par défaut,
     * RG-M01-02) et enregistre ses rôles (rôle principal et « demandeur ») */

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /* $this->call([
            NeembaSeeder::class,
        ]); */

        User::create([
            'name'       => 'Neemba',
            'prenom'     => 'Admin',
            'email'      => 'admin@neemba.com',
            'password'   => Hash::make('Neemba@2026'),
            'matricule'  => 'ADV-001',
            'telephone'  => '',
            'role'       => 'administrateur',
            'service'    => 'Direction',
            'site'       => 'Conakry',
            'poste'      => 'Lead Consultant Addvalis',
            'actif'      => true,
        ]);
    }
}
