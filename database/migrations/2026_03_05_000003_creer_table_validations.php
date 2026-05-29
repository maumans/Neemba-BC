<?php

/**
 * Migration : Création de la table validations
 * 
 * Enregistre chaque étape de validation d'un bon de caisse.
 * Le workflow de validation suit un ordre hiérarchique :
 * 
 * 1. responsable_service (Chef de Service)
 * 2. controle_gestion (Contrôle de Gestion)
 * 3. daf (Directeur Administratif et Financier)
 * 4. directeur_pays (Directeur Pays) - uniquement si montant >= 5 000 000 GNF
 * 
 * Chaque validation est horodatée et traçable.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validations', function (Blueprint $table) {
            $table->id();

            /* Référence vers le bon de caisse concerné */
            $table->foreignId('bon_caisse_id')
                ->constrained('bons_caisse')
                ->onDelete('cascade');

            /* Niveau de validation dans le workflow (1, 2, 3, 4) */
            $table->integer('niveau');

            /* Rôle attendu pour cette validation */
            $table->enum('role', [
                'responsable_service',
                'controle_gestion',
                'daf',
                'directeur_pays',
            ]);

            /* Résultat de la validation */
            $table->enum('statut', [
                'en_attente',
                'approuve',
                'rejete',
            ])->default('en_attente');

            /* Commentaire optionnel du validateur */
            $table->text('commentaire')->nullable();

            /* Référence vers l'utilisateur qui a effectué la validation */
            $table->foreignId('validateur_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('restrict');

            /* Date et heure de la validation */
            $table->timestamp('date_validation')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validations');
    }
};
