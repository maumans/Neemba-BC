<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot A « contrôle interne » (spec v2.3) : doublon probable confirmé (RG-M03-20) et dérogation « BP en retard »
 * (RG-M07-06) conservés sur le bon ; paramètres du délai de doublon et du blocage des BP en retard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->json('doublon_probable')->nullable()->after('references_or');
            $table->json('derogation_bp')->nullable()->after('doublon_probable');
        });

        $maintenant = now();
        foreach ([
            ['doublon_delai_jours', '7', 'Doublon probable : période de recherche (jours)',
                'RG-M03-20 : même bénéficiaire et même montant sur cette période → alerte, le demandeur confirme', 'number', 'bons_caisse'],
            ['blocage_bp_en_retard', 'partiel', 'Blocage en cas de BP en retard du bénéficiaire',
                'RG-M07-06 : aucun (alerte seule), partiel (nouveau BP bloqué, BD avec bandeau) ou total ; dérogation du DAF par bon', 'choix', 'bons_caisse'],
        ] as [$cle, $valeur, $libelle, $description, $type, $groupe]) {
            DB::table('parametres')->insertOrIgnore(compact('cle', 'valeur', 'libelle', 'description', 'type', 'groupe') + [
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('parametres')->whereIn('cle', ['doublon_delai_jours', 'blocage_bp_en_retard'])->delete();
        Schema::table('bons_caisse', fn (Blueprint $table) => $table->dropColumn(['doublon_probable', 'derogation_bp']));
    }
};
