<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rapports_caisse', function (Blueprint $table) {
            /* Soldes d'ouverture séparés (Espèces / OM) */
            $table->decimal('solde_ouverture_especes', 15, 2)->default(0)->after('solde_ouverture');
            $table->decimal('solde_ouverture_om', 15, 2)->default(0)->after('solde_ouverture_especes');
            
            /* Total des entrées séparées (Espèces / OM) */
            $table->decimal('total_entrees_especes', 15, 2)->default(0)->after('total_entrees');
            $table->decimal('total_entrees_om', 15, 2)->default(0)->after('total_entrees_especes');
            
            /* Total des sorties séparées (Espèces / OM) */
            $table->decimal('total_sorties_especes', 15, 2)->default(0)->after('total_sorties');
            $table->decimal('total_sorties_om', 15, 2)->default(0)->after('total_sorties_especes');

            /* Solde de clôture calculé séparément */
            $table->decimal('solde_cloture_especes', 15, 2)->default(0)->after('solde_cloture');
            $table->decimal('solde_cloture_om', 15, 2)->default(0)->after('solde_cloture_especes');

            /* Billetage et réconciliation */
            $table->json('billetage')->nullable()->after('solde_cloture_om');
            $table->decimal('solde_physique_especes', 15, 2)->nullable()->after('billetage');
            $table->decimal('solde_physique_om', 15, 2)->nullable()->after('solde_physique_especes');
            $table->decimal('ecart_especes', 15, 2)->nullable()->after('solde_physique_om');
            $table->decimal('ecart_om', 15, 2)->nullable()->after('ecart_especes');
            
            /* Remarques / Observations sur la réconciliation */
            $table->text('motif_ecart')->nullable()->after('ecart_om');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rapports_caisse', function (Blueprint $table) {
            $table->dropColumn([
                'solde_ouverture_especes',
                'solde_ouverture_om',
                'total_entrees_especes',
                'total_entrees_om',
                'total_sorties_especes',
                'total_sorties_om',
                'solde_cloture_especes',
                'solde_cloture_om',
                'billetage',
                'solde_physique_especes',
                'solde_physique_om',
                'ecart_especes',
                'ecart_om',
                'motif_ecart'
            ]);
        });
    }
};
