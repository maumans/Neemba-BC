<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CodeAnalytique>
 */
class CodeAnalytiqueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???ZZZ')),
            'libelle' => ucfirst(fake()->words(2, true)),
            'actif' => true,
        ];
    }
}
