<?php

/**
 * Migration : Création de la table pieces_jointes
 * 
 * Stocke les références vers les documents justificatifs associés aux bons de caisse.
 * Les fichiers physiques sont stockés via Laravel Storage (disk local ou S3).
 * 
 * Types de documents courants :
 * - facture : Facture fournisseur
 * - recu : Reçu de paiement
 * - devis : Devis ou proforma
 * - autre : Tout autre justificatif
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();

            /* Référence vers le bon de caisse associé */
            $table->foreignId('bon_caisse_id')
                ->constrained('bons_caisse')
                ->onDelete('cascade');

            /* Type/catégorie du document */
            $table->enum('type_document', [
                'facture',
                'recu',
                'devis',
                'ordre_mission',
                'autre',
            ]);

            /* Nom original du fichier uploadé */
            $table->string('nom_fichier');

            /* Chemin de stockage dans Laravel Storage */
            $table->string('chemin_fichier');

            /* Taille du fichier en octets */
            $table->unsignedBigInteger('taille')->nullable();

            /* Type MIME du fichier (ex: application/pdf, image/jpeg) */
            $table->string('mime_type')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_jointes');
    }
};
