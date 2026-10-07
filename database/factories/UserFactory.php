<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => strtoupper(fake()->lastName()),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'matricule' => 'T-' . fake()->unique()->numerify('#####'),
            'telephone' => '62' . fake()->numerify('#######'),
            'role' => 'demandeur',
            'site' => 'Conakry',
            'service' => 'Aftermarket',
            'actif' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Utilisateur ayant le rôle (principal) donné.
     */
    public function role(string $role): static
    {
        return $this->state(fn (array $attributes) => ['role' => $role]);
    }

    /**
     * Compte désactivé par l'administrateur.
     */
    public function inactif(): static
    {
        return $this->state(fn (array $attributes) => ['actif' => false]);
    }
}
