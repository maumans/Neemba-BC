<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Système de délégation de pouvoirs (absences/remplacements)
 * 
 * Permet à un validateur de déléguer ses droits de validation
 * à un autre utilisateur pendant une période définie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegant_id')->constrained('users')->comment('Utilisateur qui délègue');
            $table->foreignId('delegue_id')->constrained('users')->comment('Utilisateur qui reçoit la délégation');
            $table->date('date_debut');
            $table->date('date_fin');
            $table->text('motif')->nullable();
            $table->enum('statut', ['en_attente', 'acceptee', 'refusee', 'terminee'])->default('en_attente');
            $table->timestamp('acceptee_le')->nullable();
            $table->timestamps();

            $table->index(['delegant_id', 'statut']);
            $table->index(['delegue_id', 'statut']);
            $table->index(['date_debut', 'date_fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
