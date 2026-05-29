<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Ajouter les limites de caisse par site
 * 
 * Chaque site a sa propre caisse avec :
 * - solde_actuel : solde courant calculé
 * - plafond_caisse : montant maximum autorisé en caisse
 * - seuil_minimum : seuil d'alerte quand le solde est bas
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->decimal('solde_caisse', 15, 2)->default(0)->after('adresse');
            $table->decimal('plafond_caisse', 15, 2)->nullable()->after('solde_caisse');
            $table->decimal('seuil_minimum_caisse', 15, 2)->default(500000)->after('plafond_caisse');
        });

        // Paramètre global seuil minimum par défaut
        DB::table('parametres')->insert([
            'cle' => 'seuil_minimum_caisse',
            'valeur' => '500000',
            'libelle' => 'Seuil minimum caisse (défaut)',
            'description' => 'Seuil d\'alerte par défaut quand le solde de caisse est bas (en GNF). Chaque site peut avoir son propre seuil.',
            'type' => 'number',
            'groupe' => 'caisse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['solde_caisse', 'plafond_caisse', 'seuil_minimum_caisse']);
        });

        DB::table('parametres')->where('cle', 'seuil_minimum_caisse')->delete();
    }
};
