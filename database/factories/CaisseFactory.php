<?php

namespace Database\Factories;

use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Caisse>
 *
 * Le solde est posé directement (sans écriture au registre) : utiliser Caisse::crediter()
 * dans un test qui vérifie le registre.
 */
class CaisseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')) . '-ESP',
            'libelle' => 'Caisse ' . fake()->city(),
            'site_id' => Site::factory(),
            'type' => 'especes',
            'mode' => 'standard',
            'solde' => 0,
            'plafond_retrait' => null,
            'seuil_alerte' => 0,
            'actif' => true,
        ];
    }

    public function orangeMoney(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => strtoupper(fake()->unique()->lexify('???')) . '-OM',
            'type' => 'orange_money',
        ]);
    }
}
