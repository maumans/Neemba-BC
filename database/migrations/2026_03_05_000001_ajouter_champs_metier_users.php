<?php

/**
 * Migration : Ajout des champs métier à la table users
 * 
 * Cette migration ajoute les colonnes nécessaires au fonctionnement
 * de l'application de gestion de caisse NEEMBA.
 * 
 * Champs ajoutés :
 * - prenom : Prénom de l'utilisateur
 * - matricule : Identifiant unique dans l'entreprise
 * - telephone : Numéro de téléphone
 * - role : Rôle dans le workflow de validation
 * - service : Service/département de rattachement
 * - site : Site géographique (ex: Conakry, Kamsar)
 * - poste : Intitulé du poste
 * - actif : Indique si le compte est actif ou désactivé
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajouter les champs métier à la table users existante
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('prenom')->nullable()->after('name');
            $table->string('matricule')->unique()->nullable()->after('prenom');
            $table->string('telephone')->nullable()->after('matricule');

            /* 
             * Rôles possibles dans le système :
             * - demandeur : Peut créer des bons de caisse
             * - responsable_service : Valide en premier niveau
             * - controle_gestion : Vérifie la cohérence budgétaire
             * - daf : Directeur Administratif et Financier, valide
             * - directeur_pays : Valide les montants >= 5 000 000 GNF
             * - caissier : Effectue le paiement
             */
            $table->enum('role', [
                'demandeur',
                'responsable_service',
                'controle_gestion',
                'daf',
                'directeur_pays',
                'caissier',
            ])->default('demandeur')->after('telephone');

            $table->string('service')->nullable()->after('role');
            $table->string('site')->nullable()->after('service');
            $table->string('poste')->nullable()->after('site');
            $table->boolean('actif')->default(true)->after('poste');
        });
    }

    /**
     * Supprimer les champs métier ajoutés
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'prenom',
                'matricule',
                'telephone',
                'role',
                'service',
                'site',
                'poste',
                'actif',
            ]);
        });
    }
};
