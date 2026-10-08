<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M12-4 — Bons générés depuis un ODM extérieur (RG-M12-10) : le montant est estimé au dernier taux pour le circuit,
 * puis recalculé au paiement avec le taux du jour. On garde sur le bon :
 * - la part en FCFA (indemnités) et la part en GNF qui ne dépend pas du taux (hébergement, nuitée de rattrapage) ;
 * - le taux estimé, le taux appliqué et le montant estimé (l'écart est tracé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->decimal('montant_fcfa', 15, 2)->nullable()->after('genere_par_odm');
            $table->decimal('montant_gnf_fixe', 15, 2)->nullable()->after('montant_fcfa');
            $table->decimal('taux_change_estime', 12, 4)->nullable()->after('montant_gnf_fixe');
            $table->decimal('taux_change_applique', 12, 4)->nullable()->after('taux_change_estime');
            $table->decimal('montant_estime', 15, 2)->nullable()->after('taux_change_applique');
        });
    }

    public function down(): void
    {
        Schema::table('bons_caisse', fn (Blueprint $table) => $table->dropColumn([
            'montant_fcfa', 'montant_gnf_fixe', 'taux_change_estime', 'taux_change_applique', 'montant_estime',
        ]));
    }
};
