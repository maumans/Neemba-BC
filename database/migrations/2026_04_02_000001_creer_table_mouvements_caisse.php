<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des mouvements de caisse (approvisionnement, ajustement)
 * 
 * Permet de tracer les entrées/sorties de fonds dans la caisse
 * indépendamment des bons de caisse (réapprovisionnement par la trésorerie, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_caisse', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->enum('type', ['approvisionnement', 'retrait', 'ajustement']);
            $table->decimal('montant', 15, 2);
            $table->text('motif');
            $table->string('site');
            $table->enum('statut', ['en_attente', 'valide', 'rejete'])->default('en_attente');
            $table->foreignId('effectue_par')->constrained('users');
            $table->foreignId('valide_par')->nullable()->constrained('users');
            $table->timestamp('date_mouvement')->useCurrent();
            $table->timestamp('date_validation')->nullable();
            $table->text('commentaire_validation')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['site', 'statut']);
            $table->index('date_mouvement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_caisse');
    }
};
