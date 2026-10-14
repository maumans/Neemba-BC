<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot D1 (spec v2.3, saisie) : type de chaque OR (RG-M03-13) ; n° Orange Money du bon, pré-rempli depuis le référentiel
 * du bénéficiaire, et motif s'il est modifié (RG-M03-10). Les anciens bons gardent leurs OR sans type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->json('types_or')->nullable()->after('references_or');
            $table->string('numero_om', 20)->nullable()->after('telephone_beneficiaire');
            $table->string('motif_numero_om', 255)->nullable()->after('numero_om');
        });
    }

    public function down(): void
    {
        Schema::table('bons_caisse', fn (Blueprint $table) => $table->dropColumn(['types_or', 'numero_om', 'motif_numero_om']));
    }
};
