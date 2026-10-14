<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Visa (ou rejet, ou étape sautée) d'une justification de BP, par version (RG-M07-03) */
class JustificationVisa extends Model
{
    protected $table = 'justification_visas';

    protected $fillable = ['justification_id', 'version', 'etape', 'decision', 'valideur_id', 'au_titre_de_id', 'commentaire'];

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valideur_id');
    }

    public function auTitreDe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'au_titre_de_id');
    }
}
