<?php

use App\Support\MontantEnLettres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ANO-01 : les montants en lettres enregistrés jusqu'ici venaient de l'écran, avec un algorithme faux
 * (« deux cents mille », « quatre-vingts mille », majuscule initiale, « de » manquant devant la devise).
 * Ils sont imprimés sur le PDF officiel : on les recalcule tous avec l'algorithme de référence.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bons_caisse')
            ->select('id', 'montant')
            ->orderBy('id')
            ->chunkById(500, function ($bons) {
                foreach ($bons as $bon) {
                    DB::table('bons_caisse')
                        ->where('id', $bon->id)
                        ->update(['montant_lettres' => MontantEnLettres::convertir($bon->montant)]);
                }
            });
    }

    public function down(): void
    {
        /* Correction de données : les anciennes valeurs (fausses) ne sont pas restaurées. */
    }
};
