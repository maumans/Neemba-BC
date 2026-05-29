<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pieces_jointes', function (Blueprint $table) {
            $table->string('ocr_statut')->default('en_attente')->after('mime_type');
            $table->json('ocr_data')->nullable()->after('ocr_statut');
            $table->longText('ocr_texte_brut')->nullable()->after('ocr_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pieces_jointes', function (Blueprint $table) {
            $table->dropColumn(['ocr_statut', 'ocr_data', 'ocr_texte_brut']);
        });
    }
};
