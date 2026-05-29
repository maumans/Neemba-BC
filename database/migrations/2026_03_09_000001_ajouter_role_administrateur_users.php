<?php

/**
 * Migration : Ajout du rôle administrateur à la table users
 * 
 * Ajoute le rôle 'administrateur' à l'enum des rôles possibles.
 * L'administrateur gère les utilisateurs et le paramétrage de l'application.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /* Modifier l'enum pour ajouter le rôle administrateur */
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'demandeur',
            'responsable_service',
            'controle_gestion',
            'daf',
            'directeur_pays',
            'caissier',
            'administrateur'
        ) DEFAULT 'demandeur'");
    }

    public function down(): void
    {
        /* Remettre l'enum sans administrateur */
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'demandeur',
            'responsable_service',
            'controle_gestion',
            'daf',
            'directeur_pays',
            'caissier'
        ) DEFAULT 'demandeur'");
    }
};
