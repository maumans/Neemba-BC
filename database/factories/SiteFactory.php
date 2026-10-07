<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        $ville = fake()->unique()->city();

        return [
            'code' => fake()->unique()->numerify('##'),
            'nom' => $ville,
            'ville' => $ville,
            'actif' => true,
            'solde_especes' => 0,
            'solde_om' => 0,
            'seuil_minimum_caisse' => 0,
        ];
    }

    /**
     * Site pilote de Conakry (nom utilisé par les utilisateurs et les bons de test).
     */
    public function conakry(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => '01',
            'nom' => 'Conakry',
            'ville' => 'Conakry',
        ]);
    }
}
