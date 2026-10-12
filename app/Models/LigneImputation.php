<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'imputation (M16) : compte, libellé, montant, code analytique ; pour un bon d'ODM, la part de prise en charge
 * qu'elle reprend (payeur, client, OR, ligne de frais, RG-M16-05).
 */
class LigneImputation extends Model
{
    protected $table = 'lignes_imputation';

    protected $fillable = ['imputation_id', 'rang', 'nature', 'compte', 'libelle', 'montant', 'code_analytique', 'payeur', 'client_id', 'ordre_reparation', 'ligne_frais_id'];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2', 'rang' => 'integer'];
    }

    public function imputation(): BelongsTo
    {
        return $this->belongsTo(Imputation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ligneFrais(): BelongsTo
    {
        return $this->belongsTo(LigneFraisOdm::class, 'ligne_frais_id');
    }
}
