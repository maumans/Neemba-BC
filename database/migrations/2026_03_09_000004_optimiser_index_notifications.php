<?php

/**
 * Migration : Optimisation des index sur la table notifications
 * 
 * Ajoute des index composites pour accélérer les requêtes fréquentes :
 * - destinataire_id + lue_le (pour compter les non lues)
 * - destinataire_id + created_at (pour lister par ordre chronologique)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            /* Index composite pour les requêtes de comptage des non lues */
            $table->index(['destinataire_id', 'lue_le'], 'idx_destinataire_lue');
            
            /* Index composite pour les requêtes de listing chronologique */
            $table->index(['destinataire_id', 'created_at'], 'idx_destinataire_created');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_destinataire_lue');
            $table->dropIndex('idx_destinataire_created');
        });
    }
};
