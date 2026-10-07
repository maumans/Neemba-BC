<?php

namespace App\Services\BonCaisse;

use Illuminate\Support\Facades\DB;

/**
 * Numéro BC-AAAA-NNNN attribué à la soumission (RG-BC-27, ANO-03).
 *
 * Le compteur de l'année est verrouillé (lockForUpdate) : deux soumissions simultanées
 * obtiennent deux numéros différents, et un numéro n'est consommé que si la transaction
 * de soumission aboutit (aucun trou de numérotation). À appeler dans une transaction.
 */
class NumeroteurBon
{
    public static function prochain(?int $annee = null): string
    {
        $annee ??= (int) now()->year;

        DB::table('compteurs_numerotation')->insertOrIgnore([
            'annee' => $annee, 'dernier_numero' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $compteur = DB::table('compteurs_numerotation')->where('annee', $annee)->lockForUpdate()->first();

        /* Sécurité : jamais en dessous d'un numéro déjà présent (bons créés hors compteur, ex. jeu de données) */
        $maxExistant = (int) DB::table('bons_caisse')
            ->where('numero', 'like', "BC-{$annee}-%")
            ->max(DB::raw('CAST(SUBSTRING(numero, 9) AS UNSIGNED)'));

        $suivant = max((int) $compteur->dernier_numero, $maxExistant) + 1;

        DB::table('compteurs_numerotation')->where('annee', $annee)->update([
            'dernier_numero' => $suivant, 'updated_at' => now(),
        ]);

        return sprintf('BC-%d-%04d', $annee, $suivant);
    }
}
