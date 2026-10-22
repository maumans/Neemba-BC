<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mot de passe initial (signalement du 22/10, Q108) : un compte créé par l'import ou par l'administrateur, ou dont
 * l'administrateur a saisi le mot de passe, reçoit un mot de passe connu de plusieurs personnes ; il doit le changer à
 * sa première connexion.
 * - `users.mot_de_passe_a_changer` : les comptes existants ne sont pas marqués (comptes pilotes inchangés).
 * - Réglage `changement_mot_de_passe_initial` (onglet « Sécurité ») : actif par défaut ; coupé, le mot de passe initial
 *   reste utilisable (recette).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mot_de_passe_a_changer')->default(false)->after('password');
        });

        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'changement_mot_de_passe_initial', 'valeur' => 'true', 'type' => 'boolean', 'groupe' => 'securite',
            'libelle' => 'Changement du mot de passe initial à la première connexion',
            'description' => 'Compte importé, créé ou réinitialisé par l\'administrateur : son titulaire choisit son mot de passe à la première connexion',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'changement_mot_de_passe_initial')->delete();
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('mot_de_passe_a_changer'));
    }
};
