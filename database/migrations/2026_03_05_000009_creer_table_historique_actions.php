<?php

/**
 * Migration : Création de la table historique_actions
 * 
 * Enregistre chaque événement du cycle de vie d'un bon de caisse
 * pour assurer une traçabilité complète et un audit trail.
 * 
 * Événements tracés :
 * - creation, soumission, validation, rejet, demande_complement
 * - paiement, regularisation, archivage
 * - modification, ajout_piece_jointe, relance_regularisation
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historique_actions', function (Blueprint $table) {
            $table->id();

            /* Bon de caisse concerné */
            $table->foreignId('bon_caisse_id')
                ->constrained('bons_caisse')
                ->onDelete('cascade');

            /* Type d'action effectuée */
            $table->string('action');

            /* Statut du bon avant et après l'action */
            $table->string('statut_avant')->nullable();
            $table->string('statut_apres')->nullable();

            /* Utilisateur ayant effectué l'action (nullable pour actions système) */
            $table->foreignId('utilisateur_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            /* Commentaire ou détail de l'action */
            $table->text('commentaire')->nullable();

            /* Métadonnées supplémentaires en JSON (ex: fichiers ajoutés, ancien montant...) */
            $table->json('metadata')->nullable();

            /* Adresse IP de l'utilisateur */
            $table->string('adresse_ip')->nullable();

            $table->timestamps();

            /* Index pour la recherche par bon et par date */
            $table->index(['bon_caisse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_actions');
    }
};
