<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle OrdreMission - Ordre de Mission
 * 
 * Représente un déplacement professionnel associé à un bon de caisse.
 * Permet de justifier les frais de mission (transport, hébergement, indemnités).
 */
class OrdreMission extends Model
{
    use HasFactory;

    protected $table = 'ordres_mission';

    protected $fillable = [
        'reference',
        'collaborateur',
        'destination',
        'objet',
        'date_depart',
        'date_retour',
        'montant_indemnites',
        'bon_caisse_id',
    ];

    protected function casts(): array
    {
        return [
            'date_depart' => 'date',
            'date_retour' => 'date',
            'montant_indemnites' => 'decimal:2',
        ];
    }

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Bon de caisse associé à cet ordre de mission
     */
    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /**
     * Durée de la mission en jours
     */
    public function getDureeJoursAttribute(): int
    {
        return $this->date_depart->diffInDays($this->date_retour);
    }

    /**
     * Montant des indemnités formaté
     */
    public function getMontantIndemnitesFormatAttribute(): string
    {
        return number_format($this->montant_indemnites, 0, ',', ' ') . ' GNF';
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Génère une référence unique pour l'ordre de mission (OM-AAAA-NNN)
     */
    public static function genererReference(): string
    {
        $annee = now()->year;
        $prefixe = "OM-{$annee}-";

        $dernierOM = static::where('reference', 'like', $prefixe . '%')
            ->orderBy('reference', 'desc')
            ->first();

        if ($dernierOM) {
            $dernierNumero = (int) substr($dernierOM->reference, -3);
            $nouveauNumero = $dernierNumero + 1;
        } else {
            $nouveauNumero = 1;
        }

        return $prefixe . str_pad($nouveauNumero, 3, '0', STR_PAD_LEFT);
    }
}
