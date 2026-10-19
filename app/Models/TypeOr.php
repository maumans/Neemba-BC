<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Type d'OR (spec v2.4, §6.5, RG-M03-13) : référentiel tenu par le gestionnaire des référentiels. VENTE et GARANTIE au
 * départ ; les autres types sont attendus de Neemba (PO-28). Un type se désactive, il ne se supprime pas : les OR déjà
 * saisis gardent leur libellé.
 */
class TypeOr extends Model
{
    /* Spec v2.4, RG-M01-08 : référentiel par entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    protected bool $entiteCommune = true;

    protected $table = 'types_or';

    protected $fillable = ['entite_id', 'code', 'libelle', 'actif', 'ordre'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /** Types proposés à la saisie : code => libellé */
    public static function options(): array
    {
        return static::where('actif', true)->orderBy('ordre')->orderBy('libelle')->pluck('libelle', 'code')->all();
    }

    /** Codes acceptés à la saisie */
    public static function codesActifs(): array
    {
        return array_keys(static::options());
    }

    /** Libellés de tous les types, actifs ou non (affichage des OR déjà saisis) */
    public static function libelles(): array
    {
        return static::withoutGlobalScope('entite')->orderBy('ordre')->pluck('libelle', 'code')->all();
    }

    public static function libelle(?string $code): string
    {
        return $code === null ? '' : (static::libelles()[$code] ?? ucfirst($code));
    }

    /** « 11012345 (Vente), 11054321 (Garantie) » */
    public static function decrire(array $numeros, array $types): string
    {
        $libelles = static::libelles();

        return collect($numeros)->map(fn ($numero) => isset($types[$numero])
            ? "{$numero} (" . ($libelles[$types[$numero]] ?? ucfirst($types[$numero])) . ')'
            : (string) $numero)->implode(', ');
    }
}
