<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M12-3 — Circuit de validation des ODM : une étape peut être « sautée » quand personne ne peut la viser
 * (RG-M01-04 : le seul titulaire est le demandeur ou un participant, et il n'a pas de suppléant) ;
 * l'ODM passe alors au niveau supérieur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odm_etapes', function (Blueprint $table) {
            $table->enum('statut', ['a_venir', 'en_attente', 'validee', 'rejetee', 'annulee', 'sautee'])->default('a_venir')->change();
        });
    }

    public function down(): void
    {
        DB::table('odm_etapes')->where('statut', 'sautee')->update(['statut' => 'annulee']);
        Schema::table('odm_etapes', function (Blueprint $table) {
            $table->enum('statut', ['a_venir', 'en_attente', 'validee', 'rejetee', 'annulee'])->default('a_venir')->change();
        });
    }
};
