<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle VentilationAnalytique - Ventilation multi-codes analytiques
 * 
 * Permet de répartir le montant d'un bon de caisse sur plusieurs
 * codes analytiques (ex: 60% carburant, 40% transport).
 */
class VentilationAnalytique extends Model
{
    use HasFactory;

    protected $table = 'ventilations_analytiques';

    protected $fillable = [
        'bon_caisse_id',
        'code_analytique',
        'libelle',
        'montant',
        'pourcentage',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'pourcentage' => 'decimal:2',
        ];
    }

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    public function getMontantFormatAttribute(): string
    {
        return number_format($this->montant, 0, ',', ' ') . ' GNF';
    }
}
