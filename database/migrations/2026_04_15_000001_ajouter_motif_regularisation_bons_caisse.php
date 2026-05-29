<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajouter le motif de régularisation sur la table bons_caisse.
     * Permet au demandeur de fournir un motif lors de la régularisation d'un BP.
     */
    public function up(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->text('motif_regularisation')->nullable()->after('date_regularisation');
        });
    }

    public function down(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropColumn('motif_regularisation');
        });
    }
};
