<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Site - Table de paramétrage
 * 
 * Représente un site géographique NEEMBA (ex: Conakry, Kamsar, Fria...).
 * Chaque site possède sa propre caisse avec un solde, un plafond et un seuil d'alerte.
 * Administrable par le DAF/Directeur Pays.
 */
class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'nom', 'ville', 'adresse', 'actif',
        'solde_caisse', 'plafond_caisse', 'seuil_minimum_caisse',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'solde_caisse' => 'decimal:2',
        'plafond_caisse' => 'decimal:2',
        'seuil_minimum_caisse' => 'decimal:2',
    ];

    protected $appends = ['solde_caisse_format', 'plafond_caisse_format'];

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Mouvements de caisse du site
     */
    public function mouvementsCaisse(): HasMany
    {
        return $this->hasMany(MouvementCaisse::class, 'site', 'nom');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    public function getSoldeCaisseFormatAttribute(): string
    {
        return number_format($this->solde_caisse ?? 0, 0, ',', ' ') . ' GNF';
    }

    public function getPlafondCaisseFormatAttribute(): string
    {
        if (!$this->plafond_caisse) return 'Non défini';
        return number_format($this->plafond_caisse, 0, ',', ' ') . ' GNF';
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Vérifie si le solde de la caisse permet un paiement du montant donné
     */
    public function peutPayer(float $montant): bool
    {
        return $this->solde_caisse >= $montant;
    }

    /**
     * Vérifie si le solde est sous le seuil minimum
     */
    public function soldeSousSeuil(): bool
    {
        $seuil = $this->seuil_minimum_caisse ?? (float) Parametre::valeur('seuil_minimum_caisse', 500000);
        return $this->solde_caisse <= $seuil;
    }

    /**
     * Débiter la caisse du site après un paiement
     */
    public function debiter(float $montant): void
    {
        $this->decrement('solde_caisse', $montant);
    }

    /**
     * Créditer la caisse du site (approvisionnement)
     */
    public function crediter(float $montant): void
    {
        $this->increment('solde_caisse', $montant);
    }
}
