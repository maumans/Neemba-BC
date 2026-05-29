<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : Création de la table notifications
 * 
 * Stocke les notifications push en temps réel pour chaque utilisateur.
 * Chaque notification est liée à un bon de caisse et un destinataire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destinataire_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bon_caisse_id')->nullable()->constrained('bons_caisse')->nullOnDelete();
            $table->foreignId('expediteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');          /* soumission, validation, rejet, paiement, etc. */
            $table->string('titre');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('lue_le')->nullable();
            $table->timestamps();

            $table->index(['destinataire_id', 'lue_le']);
            $table->index(['destinataire_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
