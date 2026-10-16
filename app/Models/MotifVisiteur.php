<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Motif d'un bon exceptionnel au profit d'un visiteur du groupe (spec v2.4, RG-M03-28) : liste fermée, paramétrable,
 * vide au départ (PO-30, attendue du DAF), complétée d'un commentaire obligatoire. Un motif se désactive, il ne se
 * supprime pas.
 */
class MotifVisiteur extends Model
{
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    /** Référentiel propre à une entité, ou commun à toutes (entité vide) */
    protected bool $entiteCommune = true;

    protected $table = 'motifs_visiteur';

    protected $fillable = ['entite_id', 'libelle', 'actif', 'ordre'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /** Motifs actifs proposés au demandeur */
    public static function libellesActifs(): array
    {
        return static::where('actif', true)->orderBy('ordre')->orderBy('libelle')->pluck('libelle')->all();
    }
}
