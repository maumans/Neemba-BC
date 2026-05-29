<?php

/**
 * Migration : Ajouter les types 'justificatif' et 'rapport_journalier'
 *
 * Ces types manquaient dans l'ENUM :
 * - 'justificatif' : utilisé lors de la régularisation d'un BP
 * - 'rapport_journalier' : présent dans PieceJointe::TYPES_DOCUMENTS mais absent de l'ENUM
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE pieces_jointes MODIFY COLUMN type_document ENUM(
            'facture',
            'recu',
            'devis',
            'ordre_mission',
            'proforma',
            'email',
            'recu_carburant',
            'bon_commande',
            'rapport_journalier',
            'justificatif',
            'autre'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pieces_jointes MODIFY COLUMN type_document ENUM(
            'facture',
            'recu',
            'devis',
            'ordre_mission',
            'proforma',
            'email',
            'recu_carburant',
            'bon_commande',
            'autre'
        ) NOT NULL");
    }
};
