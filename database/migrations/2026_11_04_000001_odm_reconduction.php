<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prolongation des missions (décisions du 23/10, Q109 et Q110) :
 * - `odm_duree_max_segment` : un segment (ODM initial ou prolongation) ne dépasse pas cette durée ; au-delà, la mission
 *   est reconduite par une prolongation (règle ajoutée à la spec, à confirmer par Neemba) ;
 * - `ordres_mission.non_effectue` : segment payé ou validé mais non effectué, la mission s'étant terminée avant son départ
 *   (clôture avec trop-perçu, Q109) ; exclu des cumuls de la mission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->boolean('non_effectue')->default(false)->after('date_cloture');
        });

        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'odm_duree_max_segment', 'valeur' => '30', 'type' => 'number', 'groupe' => 'odm',
            'libelle' => 'Durée maximale d\'un segment de mission (jours)',
            'description' => 'Au-delà, la mission est reconduite par une prolongation, avec de nouveaux visas (décision du 23/10, à confirmer par Neemba)',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'odm_duree_max_segment')->delete();
        Schema::table('ordres_mission', fn (Blueprint $table) => $table->dropColumn('non_effectue'));
    }
};
