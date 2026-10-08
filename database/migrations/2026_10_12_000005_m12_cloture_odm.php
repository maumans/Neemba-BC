<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12-6 — Clôture d'un ODM (RG-M12-20, RG-M12-26) :
 * - régularisation du trop-perçu d'un retour anticipé : par qui et quand (reversement en caisse ou retenue sur salaire) ;
 * - bon complémentaire d'hébergement à l'étranger, facture payée au retour (décision Q24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odm_participants', function (Blueprint $table) {
            $table->timestamp('regularise_le')->nullable()->after('regularisation_statut');
            $table->foreignId('regularise_par_id')->nullable()->after('regularise_le')->constrained('users')->nullOnDelete();
            $table->foreignId('bon_complement_id')->nullable()->after('bon_caisse_id')->constrained('bons_caisse')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('odm_participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bon_complement_id');
            $table->dropConstrainedForeignId('regularise_par_id');
            $table->dropColumn('regularise_le');
        });
    }
};
