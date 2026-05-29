<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajouter le champ `code` aux sites (ex: 01, 31, 11...)
 * et le champ `code_service` aux codes analytiques pour lier
 * chaque compte analytique à son service rattaché.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* Ajouter code au site (ex: 01 pour Ckry, 31 pour Boke) */
        Schema::table('sites', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique()->after('id');
        });

        /* Ajouter service_id aux codes analytiques pour les lier aux services */
        Schema::table('codes_analytiques', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('categorie_depense_defaut')->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('code');
        });

        Schema::table('codes_analytiques', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
    }
};
