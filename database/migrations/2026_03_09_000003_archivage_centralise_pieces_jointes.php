<?php

/**
 * Migration : Archivage Centralisé & Classification IA
 * 
 * Ajoute les colonnes nécessaires au module d'archivage :
 * - classification_ia : type de document détecté par l'IA
 * - confiance_classification : score de confiance de la classification (0-100)
 * - texte_indexable : contenu full-text pour la recherche
 * - dpi_detecte : résolution détectée du scan
 * - qualite_ok : contrôle qualité du scan (>= 300 dpi)
 * - version : numéro de version du document
 * - identifiant_unique : UUID pour traçabilité unique
 * - date_archivage : date d'archivage du document
 * - date_expiration_retention : date de fin de rétention (5 ans)
 * - archived_by : utilisateur ayant archivé le document
 * - checksum : hash SHA-256 pour intégrité
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pieces_jointes', function (Blueprint $table) {
            /* Classification IA */
            $table->string('classification_ia')->nullable()
                ->after('type_document')
                ->comment('Type détecté par IA : bon_caisse, facture, proforma, ordre_mission, recu_carburant, rapport_journalier');
            $table->unsignedTinyInteger('confiance_classification')->nullable()
                ->after('classification_ia')
                ->comment('Score de confiance 0-100');

            /* Indexation full-text */
            $table->longText('texte_indexable')->nullable()
                ->after('ocr_texte_brut')
                ->comment('Contenu normalisé pour recherche full-text');

            /* Contrôle qualité scan */
            $table->unsignedSmallInteger('dpi_detecte')->nullable()
                ->after('mime_type')
                ->comment('Résolution DPI détectée');
            $table->boolean('qualite_ok')->default(true)
                ->after('dpi_detecte')
                ->comment('True si DPI >= 300 ou non-image');

            /* Versionnement */
            $table->unsignedSmallInteger('version')->default(1)
                ->after('qualite_ok')
                ->comment('Numéro de version du document');
            $table->uuid('identifiant_unique')->nullable()
                ->after('version')
                ->comment('UUID unique pour traçabilité');

            /* Archivage & rétention */
            $table->timestamp('date_archivage')->nullable()
                ->after('identifiant_unique')
                ->comment('Date d\'archivage effectif');
            $table->date('date_expiration_retention')->nullable()
                ->after('date_archivage')
                ->comment('Fin de rétention (5 ans après archivage)');
            $table->foreignId('archived_by')->nullable()
                ->after('date_expiration_retention')
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Utilisateur ayant archivé');

            /* Intégrité */
            $table->string('checksum', 64)->nullable()
                ->after('archived_by')
                ->comment('SHA-256 du fichier pour contrôle d\'intégrité');

            /* Index pour recherche full-text (MySQL) */
            $table->index('classification_ia');
            $table->index('identifiant_unique');
            $table->index('date_expiration_retention');
        });

        /* Index full-text sur texte_indexable (MySQL InnoDB >= 5.6) */
        if (config('database.default') === 'mysql') {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE pieces_jointes ADD FULLTEXT INDEX idx_texte_indexable (texte_indexable)'
            );
        }
    }

    public function down(): void
    {
        Schema::table('pieces_jointes', function (Blueprint $table) {
            $table->dropIndex(['classification_ia']);
            $table->dropIndex(['identifiant_unique']);
            $table->dropIndex(['date_expiration_retention']);

            $table->dropColumn([
                'classification_ia',
                'confiance_classification',
                'texte_indexable',
                'dpi_detecte',
                'qualite_ok',
                'version',
                'identifiant_unique',
                'date_archivage',
                'date_expiration_retention',
                'archived_by',
                'checksum',
            ]);
        });
    }
};
