<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plusieurs rôles par utilisateur (SFD v1.3 : « tout utilisateur ayant le rôle DEMANDEUR »,
 * « mon seul rôle est CAISSIER » → pas de création de bon).
 *
 * users.role reste le rôle principal (affichage, compatibilité du code existant).
 * Reprise : chaque compte reçoit son rôle actuel + « demandeur », car aujourd'hui tout le monde
 * peut créer un bon ; retirer « demandeur » à un compte se fera dans l'administration (M02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles_utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 40);
            $table->timestamps();

            $table->unique(['user_id', 'role']);
            $table->index('role');
        });

        $maintenant = now();
        DB::table('users')->select('id', 'role')->orderBy('id')->chunkById(500, function ($utilisateurs) use ($maintenant) {
            $lignes = [];
            foreach ($utilisateurs as $utilisateur) {
                foreach (array_unique(array_filter([$utilisateur->role, 'demandeur'])) as $role) {
                    $lignes[] = ['user_id' => $utilisateur->id, 'role' => $role, 'created_at' => $maintenant, 'updated_at' => $maintenant];
                }
            }
            DB::table('roles_utilisateurs')->insertOrIgnore($lignes);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_utilisateurs');
    }
};
