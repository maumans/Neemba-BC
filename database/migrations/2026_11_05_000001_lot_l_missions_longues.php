<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot L — missions ponctuelles et missions longue durée (complément « Missions longue durée et renouvellements » du
 * 22/10, RG-M12-43 à 51) :
 * - `duree_mission` (ponctuelle | longue), fréquence de renouvellement et date de fin prévisionnelle, portées par chaque
 *   segment (modifiables d'une période à l'autre, RG-M12-45) ;
 * - `type_segment` : prolongation (imprévue, motif obligatoire) ou renouvellement (période suivante d'une mission longue) ;
 * - `echue_le` : terme dépassé sans renouvellement validé (RG-M12-49) ; relances J-5, J-2, J-1 (RG-M12-48) ; alerte de
 *   requalification d'une mission ponctuelle (RG-M12-50).
 * Le paramètre `odm_duree_max_segment` (Q110, blocage à 30 jours) est retiré : le complément le remplace.
 */
return new class extends Migration
{
    private const PARAMETRES = [
        ['odm_frequences_renouvellement', '15,30,45', 'text', 'Fréquences de renouvellement des missions longues (jours, séparées par des virgules)',
            'RG-M12-44 : liste proposée au créateur d\'une mission longue durée'],
        ['odm_delai_relance_longue', '5', 'number', 'Relance avant le terme d\'une période de mission longue (jours ouvrés)',
            'RG-M12-48 : au demandeur et au chef d\'atelier, avec le lien « Renouveler » ; puis J-2 (chef d\'atelier) et J-1 (DAF)'],
        ['odm_requalification_prolongations', '2', 'number', 'Mission ponctuelle : prolongations avant l\'alerte de requalification',
            'RG-M12-50 : au-delà, proposition de requalifier la mission en longue durée'],
        ['odm_requalification_jours', '30', 'number', 'Mission ponctuelle : jours cumulés avant l\'alerte de requalification',
            'RG-M12-50 : au-delà, proposition de requalifier la mission en longue durée'],
    ];

    public function up(): void
    {
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->string('duree_mission', 12)->default('ponctuelle')->after('technique');
            $table->unsignedSmallInteger('frequence_renouvellement')->nullable()->after('duree_mission');
            $table->date('date_fin_previsionnelle')->nullable()->after('frequence_renouvellement');
            $table->string('type_segment', 16)->nullable()->after('rang');
            $table->string('motif_prolongation', 500)->nullable()->after('type_segment');
            $table->date('echue_le')->nullable()->after('non_effectue');
            $table->timestamp('relance_longue_j5_le')->nullable()->after('rappel_envoye_le');
            $table->timestamp('relance_longue_j2_le')->nullable()->after('relance_longue_j5_le');
            $table->timestamp('alerte_longue_j1_le')->nullable()->after('relance_longue_j2_le');
            $table->timestamp('alerte_requalification_le')->nullable()->after('alerte_longue_j1_le');
        });
        /* Segments existants : des prolongations */
        DB::table('ordres_mission')->whereNotNull('segment_precedent_id')->update(['type_segment' => 'prolongation']);

        $maintenant = now();
        foreach (self::PARAMETRES as [$cle, $valeur, $type, $libelle, $description]) {
            DB::table('parametres')->insertOrIgnore(compact('cle', 'valeur', 'type', 'libelle', 'description')
                + ['groupe' => 'odm', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        }
        DB::table('parametres')->where('cle', 'odm_duree_max_segment')->delete();
    }

    public function down(): void
    {
        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'odm_duree_max_segment', 'valeur' => '30', 'type' => 'number', 'groupe' => 'odm',
            'libelle' => 'Durée maximale d\'un segment de mission (jours)',
            'description' => 'Au-delà, la mission est reconduite par une prolongation, avec de nouveaux visas (décision du 23/10, à confirmer par Neemba)',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
        DB::table('parametres')->whereIn('cle', array_column(self::PARAMETRES, 0))->delete();
        Schema::table('ordres_mission', fn (Blueprint $table) => $table->dropColumn([
            'duree_mission', 'frequence_renouvellement', 'date_fin_previsionnelle', 'type_segment', 'motif_prolongation', 'echue_le',
            'relance_longue_j5_le', 'relance_longue_j2_le', 'alerte_longue_j1_le', 'alerte_requalification_le',
        ]));
    }
};
