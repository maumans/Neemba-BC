<?php

namespace App\Services\Odm;

use App\Models\Service;
use App\Services\Referentiels\Normalisation;
use Illuminate\Support\Facades\DB;

/**
 * Numéro d'un ODM attribué à la soumission (RG-M12-03) : N°[séquence]/[préfixe du service]/[AA], ex. N°285/AT/26.
 *
 * - Une séquence par préfixe et par année, dans « compteurs_odm », verrouillée (lockForUpdate) : deux soumissions
 *   simultanées obtiennent deux numéros différents. À appeler dans la transaction de soumission.
 * - Préfixe : celui du service (services.prefixe_odm, décision Q28) ; à défaut, les trois premières lettres du nom du service.
 * - Reprise des carnets papier : definirDepart() fixe le dernier numéro utilisé (ex. 284 pour reprendre à 285).
 */
class NumeroteurOdm
{
    /**
     * @return array{numero: string, prefixe: string, sequence: int, annee: int}
     */
    public static function prochain(string $prefixe, ?int $annee = null): array
    {
        $annee ??= (int) now()->year;
        $prefixe = mb_strtoupper(trim($prefixe));

        DB::table('compteurs_odm')->insertOrIgnore([
            'prefixe' => $prefixe, 'annee' => $annee, 'dernier_numero' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $compteur = DB::table('compteurs_odm')->where('prefixe', $prefixe)->where('annee', $annee)->lockForUpdate()->first();

        /* Jamais en dessous d'un numéro déjà attribué (ODM saisis hors compteur) */
        $maxExistant = (int) DB::table('ordres_mission')->where('prefixe', $prefixe)->where('annee', $annee)->max('sequence');
        $suivant = max((int) $compteur->dernier_numero, $maxExistant) + 1;

        DB::table('compteurs_odm')->where('id', $compteur->id)->update(['dernier_numero' => $suivant, 'updated_at' => now()]);

        return [
            'numero' => self::formater($suivant, $prefixe, $annee),
            'prefixe' => $prefixe,
            'sequence' => $suivant,
            'annee' => $annee,
        ];
    }

    public static function formater(int $sequence, string $prefixe, int $annee): string
    {
        return sprintf('N°%d/%s/%02d', $sequence, $prefixe, $annee % 100);
    }

    /** Préfixe des ODM d'un service (Q28) */
    public static function prefixePour(?string $service): string
    {
        $prefixe = $service ? Service::where('nom', $service)->value('prefixe_odm') : null;
        if (filled($prefixe)) {
            return mb_strtoupper(trim($prefixe));
        }

        $lettres = preg_replace('/[^A-Z]/', '', Normalisation::cle($service ?? ''));

        return $lettres === '' ? 'ODM' : substr($lettres, 0, 3);
    }

    /** Reprise des carnets papier : le prochain ODM du préfixe et de l'année portera le numéro $dernier + 1 */
    public static function definirDepart(string $prefixe, int $annee, int $dernier): void
    {
        DB::table('compteurs_odm')->updateOrInsert(
            ['prefixe' => mb_strtoupper(trim($prefixe)), 'annee' => $annee],
            ['dernier_numero' => max(0, $dernier), 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /** Dernier numéro utilisé pour ce préfixe et cette année (0 si aucun) */
    public static function dernier(string $prefixe, int $annee): int
    {
        return (int) DB::table('compteurs_odm')->where('prefixe', mb_strtoupper(trim($prefixe)))->where('annee', $annee)->value('dernier_numero');
    }
}
