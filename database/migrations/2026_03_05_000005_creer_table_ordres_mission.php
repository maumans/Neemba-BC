<?php

/**
 * Migration : Création de la table ordres_mission
 * 
 * Gère les ordres de mission professionnelle liés aux bons de caisse.
 * Un ordre de mission peut être associé à un bon de caisse pour justifier
 * les frais de déplacement, hébergement, indemnités, etc.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordres_mission', function (Blueprint $table) {
            $table->id();

            /* Référence unique de l'ordre de mission (ex: OM-2026-001) */
            $table->string('reference')->unique();

            /* Nom du collaborateur en mission */
            $table->string('collaborateur');

            /* Lieu de destination de la mission */
            $table->string('destination');

            /* Objet/motif de la mission */
            $table->text('objet')->nullable();

            /* Dates de la mission */
            $table->date('date_depart');
            $table->date('date_retour');

            /* Montant des indemnités de mission en GNF */
            $table->decimal('montant_indemnites', 15, 2)->default(0);

            /* Référence vers le bon de caisse associé */
            $table->foreignId('bon_caisse_id')
                ->nullable()
                ->constrained('bons_caisse')
                ->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordres_mission');
    }
};
