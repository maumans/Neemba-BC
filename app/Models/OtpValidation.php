<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle OtpValidation
 * 
 * Gère les codes OTP envoyés par SMS pour valider les paiements.
 */
class OtpValidation extends Model
{
    protected $fillable = [
        'bon_caisse_id',
        'code',
        'telephone',
        'expires_at',
        'verified_at',
        'is_used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    /* ================================================================ */
    /*  Relations                                                       */
    /* ================================================================ */

    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class);
    }

    /* ================================================================ */
    /*  Scopes                                                          */
    /* ================================================================ */

    /**
     * Scope pour récupérer les OTP valides (non expirés et non utilisés)
     */
    public function scopeValide($query)
    {
        return $query->where('expires_at', '>', now())
            ->where('is_used', false);
    }

    /**
     * Scope pour récupérer les OTP non vérifiés
     */
    public function scopeNonVerifie($query)
    {
        return $query->whereNull('verified_at');
    }

    /* ================================================================ */
    /*  Méthodes                                                        */
    /* ================================================================ */

    /**
     * Vérifier si le code OTP est encore valide
     */
    public function estValide(): bool
    {
        return $this->expires_at > now() && !$this->is_used;
    }

    /**
     * Vérifier si le code OTP a expiré
     */
    public function estExpire(): bool
    {
        return $this->expires_at <= now();
    }

    /**
     * Marquer le code comme vérifié
     */
    public function marquerCommeVerifie(): bool
    {
        if (!$this->estValide()) {
            return false;
        }

        $this->verified_at = now();
        return $this->save();
    }

    /**
     * Marquer le code comme utilisé (après paiement)
     */
    public function marquerCommeUtilise(): bool
    {
        $this->is_used = true;
        return $this->save();
    }

    /**
     * Générer un code OTP aléatoire à 6 chiffres
     */
    public static function genererCode(): string
    {
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }
}
