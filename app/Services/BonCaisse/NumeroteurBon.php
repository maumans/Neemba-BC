<?php

namespace App\Services\BonCaisse;

use App\Models\Caisse;
use App\Models\Parametre;
use Illuminate\Support\Facades\DB;

/**
 * Numéro attribué à la soumission (RG-BC-27, ANO-03), sur une séquence propre à chaque caisse émettrice et à chaque
 * année (spec v2.3, RG-M03-18) : BC-[code caisse]-[AAAA]-[00001].
 *
 * - Le compteur (caisse, année) est verrouillé (lockForUpdate) : deux soumissions simultanées obtiennent deux numéros
 *   différents, et un numéro n'est consommé que si la transaction de soumission aboutit (aucun trou). À appeler dans
 *   une transaction.
 * - RG-M08-11 : à la première séquence d'une caisse, le « numéro de départ » paramétré reprend la suite du carnet papier.
 * - Le nombre de chiffres est celui de la caisse, sinon le paramètre « numero_bon_chiffres » (5 par défaut).
 * Les numéros déjà attribués au format BC-AAAA-NNNN restent tels quels (Q62).
 */
class NumeroteurBon
{
    public static function prochain(?Caisse $caisse, ?int $annee = null): string
    {
        $annee ??= (int) now()->year;
        if (!$caisse) {
            return self::sansCaisse($annee);
        }
        $code = $caisse->codeNumerotation();

        if (!DB::table('compteurs_bons')->where('caisse_id', $caisse->id)->exists()) {
            /* Première séquence de la caisse : reprise du carnet papier */
            $depart = max(0, (int) $caisse->numero_depart - 1);
            DB::table('compteurs_bons')->insertOrIgnore([
                'caisse_id' => $caisse->id, 'annee' => $annee, 'dernier_numero' => $depart, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            DB::table('compteurs_bons')->insertOrIgnore([
                'caisse_id' => $caisse->id, 'annee' => $annee, 'dernier_numero' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $compteur = DB::table('compteurs_bons')->where('caisse_id', $caisse->id)->where('annee', $annee)->lockForUpdate()->first();

        /* Sécurité : jamais en dessous d'un numéro déjà présent pour ce préfixe (bons créés hors compteur) */
        $prefixe = "BC-{$code}-{$annee}-";
        $maxExistant = (int) DB::table('bons_caisse')
            /* RG-M01-08 : le code d'une caisse est unique dans son entité */
            ->where('entite_id', $caisse->entite_id)
            ->where('numero', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefixe) . '%')
            ->max(DB::raw('CAST(SUBSTRING(numero, ' . (mb_strlen($prefixe) + 1) . ') AS UNSIGNED)'));

        $suivant = max((int) $compteur->dernier_numero, $maxExistant) + 1;

        DB::table('compteurs_bons')->where('caisse_id', $caisse->id)->where('annee', $annee)->update([
            'dernier_numero' => $suivant, 'updated_at' => now(),
        ]);

        $chiffres = (int) ($caisse->chiffres_numero ?: Parametre::valeur('numero_bon_chiffres', 5));

        return $prefixe . str_pad((string) $suivant, max(1, $chiffres), '0', STR_PAD_LEFT);
    }

    /** Repli sans aucune caisse paramétrée : ancienne séquence annuelle BC-AAAA-NNNN */
    private static function sansCaisse(int $annee): string
    {
        DB::table('compteurs_numerotation')->insertOrIgnore([
            'annee' => $annee, 'dernier_numero' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $compteur = DB::table('compteurs_numerotation')->where('annee', $annee)->lockForUpdate()->first();
        $maxExistant = (int) DB::table('bons_caisse')->where('numero', 'like', "BC-{$annee}-%")
            ->max(DB::raw('CAST(SUBSTRING(numero, 9) AS UNSIGNED)'));
        $suivant = max((int) $compteur->dernier_numero, $maxExistant) + 1;
        DB::table('compteurs_numerotation')->where('annee', $annee)->update(['dernier_numero' => $suivant, 'updated_at' => now()]);

        return sprintf('BC-%d-%04d', $annee, $suivant);
    }
}
