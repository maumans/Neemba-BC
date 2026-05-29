<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajouter le niveau d'urgence aux bons de caisse
 * 
 * Permet de définir une priorité sur chaque bon :
 * - normale : workflow standard (notifications push uniquement)
 * - urgente : notifications push + email au validateur suivant
 * - tres_urgente : notifications push + email + SMS au validateur suivant
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->string('niveau_urgence', 20)->default('normale')->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropColumn('niveau_urgence');
        });
    }
};
