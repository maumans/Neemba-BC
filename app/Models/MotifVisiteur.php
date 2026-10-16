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
    protected $table = 'motifs_visiteur';

    protected $fillable = ['libelle', 'actif', 'ordre'];

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
