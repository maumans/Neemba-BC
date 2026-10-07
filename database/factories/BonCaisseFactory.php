<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BonCaisse>
 */
class BonCaisseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'numero' => 'BC-2026-' . fake()->unique()->numerify('9###'),
            'type_bon' => 'BD',
            'site' => 'Conakry',
            'service' => 'Aftermarket',
            'beneficiaire' => 'Souadou BARRY',
            'type_beneficiaire' => 'employe',
            'mode_paiement' => 'especes',
            'motif' => 'Achat carburant mission Kankan',
            'categorie_depense' => 'carburant',
            'montant' => 500000,
            'statut' => 'BROUILLON',
            'demandeur_id' => User::factory(),
            'date_demande' => today(),
        ];
    }

    public function statut(string $statut): static
    {
        return $this->state(fn (array $attributes) => ['statut' => $statut]);
    }

    public function approuve(): static
    {
        return $this->statut('APPROUVE');
    }

    public function provisoire(): static
    {
        return $this->state(fn (array $attributes) => ['type_bon' => 'BP']);
    }
}
