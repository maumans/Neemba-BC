<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Utilisateur ayant exactement ces rôles ; le premier est le rôle principal (users.role).
     * Ex. utilisateurAvecRoles(['caissier']) : caissier sans le rôle demandeur (TC-BC-032).
     */
    protected function utilisateurAvecRoles(array $roles, array $attributs = []): User
    {
        $utilisateur = User::factory()->create($attributs + ['role' => $roles[0]]);
        $utilisateur->definirRoles($roles);

        return $utilisateur->fresh();
    }
}
