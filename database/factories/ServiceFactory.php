<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nom' => ucfirst(fake()->unique()->word()),
            'code' => fake()->numerify('###'),
            'actif' => true,
        ];
    }
}
