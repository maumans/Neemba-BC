<?php

/**
 * Migration : Enrichissement de la table rapports_caisse
 * 
 * Ajoute des colonnes pour un rapport journalier plus détaillé :
 * - Nombre de bons payés
 * - Ventilation par catégorie de dépense (JSON)
 * - Ventilation par mode de paiement (JSON)
 * - Visa DAF (validation hiérarchique du rapport)
 * - Heure de clôture
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rapports_caisse', function (Blueprint $table) {
            /* Nombre de bons payés dans la journée */
            $table->unsignedInteger('nombre_bons')->default(0)->after('total_sorties');

            /* Ventilation des sorties par catégorie de dépense (JSON) */
            $table->json('detail_par_categorie')->nullable()->after('nombre_bons');

            /* Ventilation des sorties par mode de paiement (JSON) */
            $table->json('detail_par_mode_paiement')->nullable()->after('detail_par_categorie');

            /* Visa du DAF */
            $table->foreignId('visa_daf_id')->nullable()->after('cloture')
                ->constrained('users')->onDelete('set null');
            $table->timestamp('date_visa_daf')->nullable()->after('visa_daf_id');
        });
    }

    public function down(): void
    {
        Schema::table('rapports_caisse', function (Blueprint $table) {
            $table->dropForeign(['visa_daf_id']);
            $table->dropColumn([
                'nombre_bons',
                'detail_par_categorie',
                'detail_par_mode_paiement',
                'visa_daf_id',
                'date_visa_daf',
            ]);
        });
    }
};
