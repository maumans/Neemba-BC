<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

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
