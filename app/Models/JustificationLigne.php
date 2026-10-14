<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne de la justification d'un BP (Annexe 3) : intitulé, code analytique, n° de compte, montant, pièce jointe */
class JustificationLigne extends Model
{
    protected $table = 'justification_lignes';

    protected $fillable = ['justification_id', 'ordre', 'intitule', 'code_analytique', 'compte', 'montant', 'piece_jointe_id'];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2'];
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(PieceJointe::class, 'piece_jointe_id');
    }
}
