<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* ── Sites : remplacer solde_caisse par solde_especes + solde_om ── */
        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('solde_especes', 15, 2)->default(0)->after('seuil_minimum_caisse');
            $table->decimal('solde_om', 15, 2)->default(0)->after('solde_especes');
        });

        // Copier les données existantes vers solde_especes
        DB::statement('UPDATE sites SET solde_especes = COALESCE(solde_caisse, 0)');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('solde_caisse');
        });

        /* ── Mouvements : type de caisse concerné ── */
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->enum('type_caisse', ['especes', 'om'])->default('especes')->after('piece_justificative');
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->dropColumn('type_caisse');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('solde_caisse', 15, 2)->default(0)->after('seuil_minimum_caisse');
        });

        DB::statement('UPDATE sites SET solde_caisse = solde_especes + solde_om');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['solde_especes', 'solde_om']);
        });
    }
};
