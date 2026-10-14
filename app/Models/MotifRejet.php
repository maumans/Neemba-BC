<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Motif de rejet d'un bon (spec v2.3, RG-M04-04) : liste paramétrable, complétée d'un commentaire libre.
 * Un motif se désactive, il ne se supprime pas (les rejets passés restent lisibles).
 */
class MotifRejet extends Model
{
    protected $table = 'motifs_rejet';

    protected $fillable = ['code', 'libelle', 'actif', 'ordre'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /** Motifs actifs proposés au valideur : [code => libellé] */
    public static function options(): array
    {
        return static::where('actif', true)->orderBy('ordre')->orderBy('libelle')->pluck('libelle', 'code')->all();
    }
}
