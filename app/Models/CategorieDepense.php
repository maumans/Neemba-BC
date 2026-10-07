<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Catégorie de dépense (SFD §5.4.3, paramétrable — ANO-14).
 * Elle décide des champs affichés dans l'assistant : véhicule / matériel (obligatoire ou non), n° d'OR.
 * « Mission » n'est pas proposée dans l'assistant : elle passe par un ordre de mission (M12).
 */
class CategorieDepense extends Model
{
    protected $table = 'categories_depense';

    protected $fillable = [
        'code', 'libelle', 'vehicule_obligatoire', 'vehicule_affiche', 'or_affiche',
        'proposee_assistant', 'ordre', 'actif',
    ];

    protected $casts = [
        'vehicule_obligatoire' => 'boolean',
        'vehicule_affiche' => 'boolean',
        'or_affiche' => 'boolean',
        'proposee_assistant' => 'boolean',
        'actif' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('categories_depense.libelles'));
    }

    public function scopeActives($query)
    {
        return $query->where('actif', true)->orderBy('ordre');
    }

    /**
     * Libellés de toutes les catégories (code => libellé), pour les rapports et exports.
     */
    public static function libelles(): array
    {
        return Cache::rememberForever(
            'categories_depense.libelles',
            fn () => static::orderBy('ordre')->pluck('libelle', 'code')->all(),
        );
    }
}
