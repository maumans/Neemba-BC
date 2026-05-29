<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrichir les codes analytiques avec business_unit et catégorie de dépense par défaut
 * + créer la table de ventilation analytique (multi-codes sur un même bon)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('codes_analytiques', function (Blueprint $table) {
            $table->text('description')->nullable()->after('libelle');
            $table->string('categorie_depense_defaut')->nullable()->after('description');
        });

        // Table de ventilation analytique (multi-codes sur un même bon)
        Schema::create('ventilations_analytiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bon_caisse_id')->constrained('bons_caisse')->cascadeOnDelete();
            $table->string('code_analytique');
            $table->string('libelle')->nullable();
            $table->decimal('montant', 15, 2);
            $table->decimal('pourcentage', 5, 2)->nullable();
            $table->timestamps();

            $table->index('bon_caisse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventilations_analytiques');

        Schema::table('codes_analytiques', function (Blueprint $table) {
            $table->dropColumn(['description', 'categorie_depense_defaut']);
        });
    }
};
