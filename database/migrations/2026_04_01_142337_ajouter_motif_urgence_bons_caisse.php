<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Ajouter justification obligatoire pour les bons urgents
 * + motif d'urgence prédéfini (liste déroulante)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->string('motif_urgence')->nullable()->after('niveau_urgence');
            $table->text('justification_urgence')->nullable()->after('motif_urgence');
        });

        // Ajouter le paramètre des motifs d'urgence prédéfinis
        DB::table('parametres')->insert([
            'cle' => 'motifs_urgence_predefinis',
            'valeur' => json_encode([
                'Panne machine/équipement',
                'Accident/incident sécurité',
                'Urgence médicale',
                'Rupture de stock critique',
                'Obligation légale/réglementaire',
                'Autre (préciser)',
            ]),
            'libelle' => 'Motifs d\'urgence prédéfinis',
            'description' => 'Liste JSON des motifs d\'urgence disponibles dans le formulaire de bon de caisse',
            'type' => 'json',
            'groupe' => 'bons_caisse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropColumn(['motif_urgence', 'justification_urgence']);
        });

        DB::table('parametres')->where('cle', 'motifs_urgence_predefinis')->delete();
    }
};
