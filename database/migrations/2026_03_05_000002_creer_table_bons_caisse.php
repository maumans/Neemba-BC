<?php

/**
 * Migration : Création de la table bons_caisse
 * 
 * Table principale du système de gestion de caisse.
 * Chaque enregistrement représente une demande de fonds (bon de caisse).
 * 
 * Types de bons :
 * - BD (Bon Définitif) : La facture/justificatif existe déjà
 * - BP (Bon Provisoire) : L'argent est donné à l'avance, justificatif à fournir plus tard
 * 
 * Règles métier :
 * - Montant maximum : 20 000 000 GNF
 * - Validation directeur pays obligatoire si montant >= 5 000 000 GNF
 * - Numéro unique au format BC-AAAA-NNNN
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bons_caisse', function (Blueprint $table) {
            $table->id();

            /* Numéro unique du bon au format BC-AAAA-NNNN (ex: BC-2026-0001) */
            $table->string('numero')->unique();

            /* Type de bon : BD (Définitif) ou BP (Provisoire) */
            $table->enum('type_bon', ['BD', 'BP']);

            /* Informations de localisation et organisation */
            $table->string('site');
            $table->string('service');
            $table->string('code_analytique')->nullable();

            /* Bénéficiaire de la dépense (peut être différent du demandeur) */
            $table->string('beneficiaire');

            /* Motif détaillé de la demande de fonds */
            $table->text('motif');

            /* Montant en GNF (Franc Guinéen) - max 20 000 000 */
            $table->decimal('montant', 15, 2);

            /* Montant écrit en lettres pour validation */
            $table->string('montant_lettres')->nullable();

            /**
             * Statuts possibles du bon de caisse :
             * - BROUILLON : En cours de rédaction
             * - EN_ATTENTE_CHEF_SERVICE : Soumis, en attente de validation niveau 1
             * - EN_ATTENTE_CDG : Validé par chef service, en attente contrôle de gestion
             * - EN_ATTENTE_DAF : Validé par CDG, en attente du DAF
             * - EN_ATTENTE_DP : Montant >= 5M, en attente du Directeur Pays
             * - APPROUVE : Toutes validations effectuées
             * - PAYE : Paiement effectué par la caisse
             * - REJETE : Rejeté à un niveau de validation
             * - EN_ATTENTE_REGULARISATION : BP payé, en attente de justificatifs
             * - REGULARISE : BP avec justificatifs fournis
             * - ARCHIVE : Bon archivé
             */
            $table->enum('statut', [
                'BROUILLON',
                'EN_ATTENTE_CHEF_SERVICE',
                'EN_ATTENTE_CDG',
                'EN_ATTENTE_DAF',
                'EN_ATTENTE_DP',
                'APPROUVE',
                'PAYE',
                'REJETE',
                'EN_ATTENTE_REGULARISATION',
                'REGULARISE',
                'ARCHIVE',
            ])->default('BROUILLON');

            /* Référence vers l'utilisateur qui a créé le bon */
            $table->foreignId('demandeur_id')
                ->constrained('users')
                ->onDelete('restrict');

            /* Date à laquelle la demande a été créée */
            $table->date('date_demande');

            /* Commentaire de rejet si applicable */
            $table->text('commentaire_rejet')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bons_caisse');
    }
};
