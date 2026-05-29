<?php

/**
 * Migration : Enrichissement de la table bons_caisse
 * 
 * Ajoute les champs métier détaillés pour le cycle de vie complet :
 * - Informations bénéficiaire (type, téléphone, mode paiement)
 * - Catégorie de dépense et devise
 * - Dates de soumission, paiement, régularisation
 * - Référence caissier et délai régularisation
 * - Action "demander complément" dans le workflow
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            /* --- BÉNÉFICIAIRE --- */
            $table->enum('type_beneficiaire', ['employe', 'fournisseur', 'prestataire', 'autre'])
                ->default('employe')
                ->after('beneficiaire');

            $table->string('telephone_beneficiaire')->nullable()->after('type_beneficiaire');

            $table->enum('mode_paiement', ['especes', 'orange_money', 'virement', 'autre'])
                ->default('especes')
                ->after('telephone_beneficiaire');

            /* --- DÉTAILS DÉPENSE --- */
            $table->enum('categorie_depense', [
                'carburant',
                'transport',
                'frais_mission',
                'achat_materiel',
                'fournitures_bureau',
                'prestations_externes',
                'entretien_reparation',
                'telecommunication',
                'formation',
                'restauration',
                'autre',
            ])->nullable()->after('motif');

            $table->string('devise', 10)->default('GNF')->after('montant_lettres');

            /* --- DATES DU CYCLE DE VIE --- */
            $table->timestamp('date_soumission')->nullable()->after('date_demande');
            $table->timestamp('date_paiement')->nullable()->after('date_soumission');
            $table->timestamp('date_regularisation')->nullable()->after('date_paiement');
            $table->date('date_limite_regularisation')->nullable()->after('date_regularisation');

            /* --- CAISSIER --- */
            $table->foreignId('caissier_id')
                ->nullable()
                ->after('demandeur_id')
                ->constrained('users')
                ->onDelete('restrict');

            /* --- MODE DE PAIEMENT EFFECTIF (au moment du décaissement) --- */
            $table->enum('mode_paiement_effectif', ['especes', 'orange_money', 'virement', 'autre'])
                ->nullable()
                ->after('caissier_id');
        });

        /* Ajouter le statut COMPLEMENT_REQUIS dans l'enum */
        /* Note: SQLite ne supporte pas ALTER COLUMN pour les enum, on gère ça via le modèle */
    }

    public function down(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropForeign(['caissier_id']);
            $table->dropColumn([
                'type_beneficiaire',
                'telephone_beneficiaire',
                'mode_paiement',
                'categorie_depense',
                'devise',
                'date_soumission',
                'date_paiement',
                'date_regularisation',
                'date_limite_regularisation',
                'caissier_id',
                'mode_paiement_effectif',
            ]);
        });
    }
};
