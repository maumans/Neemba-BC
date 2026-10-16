<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Motif de rejet d'un bon (spec v2.3, RG-M04-04) : liste paramétrable, complétée d'un commentaire libre.
 * Un motif se désactive, il ne se supprime pas (les rejets passés restent lisibles).
 */
class MotifRejet extends Model
{
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    /** Référentiel propre à une entité, ou commun à toutes (entité vide) */
    protected bool $entiteCommune = true;

    protected $table = 'motifs_rejet';

    protected $fillable = ['entite_id', 'code', 'libelle', 'actif', 'ordre'];

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
