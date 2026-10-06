<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des rapports de caisse antérieurs au double solde Espèces / OM.
 *
 * La migration 2026_06_02_000003 a ajouté les colonnes *_especes / *_om avec la valeur 0.
 * Pour les rapports existants, la ventilation restait donc à 0 alors que les totaux ne l'étaient pas :
 * le rapport suivant ouvrait avec un solde espèces et OM à 0 (soldePrecedent()).
 * Avant le double solde, toute la caisse était en espèces : on reporte les totaux sur les colonnes espèces.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rapports_caisse')
            ->where('solde_ouverture_especes', 0)->where('solde_ouverture_om', 0)
            ->where('total_entrees_especes', 0)->where('total_entrees_om', 0)
            ->where('total_sorties_especes', 0)->where('total_sorties_om', 0)
            ->where('solde_cloture_especes', 0)->where('solde_cloture_om', 0)
            ->where(function ($q) {
                $q->where('solde_ouverture', '!=', 0)
                    ->orWhere('total_entrees', '!=', 0)
                    ->orWhere('total_sorties', '!=', 0)
                    ->orWhere('solde_cloture', '!=', 0);
            })
            ->update([
                'solde_ouverture_especes' => DB::raw('solde_ouverture'),
                'total_entrees_especes' => DB::raw('total_entrees'),
                'total_sorties_especes' => DB::raw('total_sorties'),
                'solde_cloture_especes' => DB::raw('solde_cloture'),
            ]);
    }

    public function down(): void
    {
        /* Reprise de données : pas de retour arrière (les valeurs d'origine étaient toutes à 0). */
    }
};
