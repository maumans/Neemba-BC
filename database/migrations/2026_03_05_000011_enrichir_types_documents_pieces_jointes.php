<?php

/**
 * Migration : Enrichir les types de documents pour pieces_jointes
 * 
 * Ajoute les types manquants : proforma, email, recu_carburant, bon_commande
 * pour correspondre aux options du formulaire frontend.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Modifier l'enum pour ajouter les nouveaux types */
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

    public function down(): void
    {
        /* Revenir à l'enum d'origine */
        DB::statement("ALTER TABLE pieces_jointes MODIFY COLUMN type_document ENUM(
            'facture',
            'recu',
            'devis',
            'ordre_mission',
            'autre'
        ) NOT NULL");
    }
};
