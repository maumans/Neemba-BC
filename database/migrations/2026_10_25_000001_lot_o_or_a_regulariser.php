<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot O (spec v2.4, RG-M12-02, RG-M12-39 à RG-M12-42, SC-38) :
 * - « OR à régulariser » : mission technique soumise sans OR ; échéance, relances, alerte de retard ; OR saisi après coup ;
 * - besoin carburant porté par l'ODM : immatriculation et itinéraire (le véhicule garde le modèle) ;
 * - paramètres : délai de régularisation (5 jours ouvrés) et blocage du prochain ODM (désactivé).
 */
return new class extends Migration
{
    private const PARAMETRES = [
        ['odm_delai_or', '5', "Délai de régularisation d'un OR (jours ouvrés après la validation)",
            "RG-M12-40 : relance au demandeur à J+3 et à l'échéance ; au-delà, alerte au chef d'atelier et au DAF", 'number'],
        ['odm_blocage_or_retard', 'false', 'Bloquer le prochain ODM du demandeur qui a un OR en retard',
            'RG-M12-40 : désactivé par défaut (MSG-M12-11)', 'boolean'],
    ];

    public function up(): void
    {
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->boolean('or_a_regulariser')->default(false)->after('technique');
            $table->date('or_echeance')->nullable()->after('or_a_regulariser');
            $table->timestamp('or_relance_le')->nullable()->after('or_echeance');
            $table->timestamp('or_relance_echeance_le')->nullable()->after('or_relance_le');
            $table->timestamp('or_retard_signale_le')->nullable()->after('or_relance_echeance_le');
            $table->string('immatriculation', 20)->nullable()->after('vehicule');
            $table->string('itineraire', 255)->nullable()->after('immatriculation');
        });

        Schema::table('odm_ordres_reparation', function (Blueprint $table) {
            $table->timestamp('saisi_apres_coup_le')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
        });

        $maintenant = now();
        foreach (self::PARAMETRES as [$cle, $valeur, $libelle, $description, $type]) {
            DB::table('parametres')->insertOrIgnore(compact('cle', 'valeur', 'libelle', 'description', 'type')
                + ['groupe' => 'odm', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        }
    }

    public function down(): void
    {
        DB::table('parametres')->whereIn('cle', array_column(self::PARAMETRES, 0))->delete();
        Schema::table('odm_ordres_reparation', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saisi_par');
            $table->dropColumn('saisi_apres_coup_le');
        });
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->dropColumn(['or_a_regulariser', 'or_echeance', 'or_relance_le', 'or_relance_echeance_le', 'or_retard_signale_le', 'immatriculation', 'itineraire']);
        });
    }
};
