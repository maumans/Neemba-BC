<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle Site - Table de paramétrage
 *
 * Chaque site possède deux caisses : Espèces et OM (Mobile Money).
 * Le solde total (solde_caisse) est la somme des deux via accesseur.
 */
class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'nom', 'ville', 'adresse', 'actif',
        'solde_especes', 'solde_om', 'plafond_caisse', 'seuil_minimum_caisse',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'solde_especes' => 'decimal:2',
        'solde_om' => 'decimal:2',
        'plafond_caisse' => 'decimal:2',
        'seuil_minimum_caisse' => 'decimal:2',
    ];

    protected $appends = [
        'solde_caisse',
        'solde_caisse_format',
        'solde_especes_format',
        'solde_om_format',
        'plafond_caisse_format',
    ];

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

    public function mouvementsCaisse(): HasMany
    {
        return $this->hasMany(MouvementCaisse::class, 'site', 'nom');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /** Solde total = Espèces + OM */
    public function getSoldeCaisseAttribute(): float
    {
        return (float) $this->solde_especes + (float) $this->solde_om;
    }

    public function getSoldeCaisseFormatAttribute(): string
    {
        return number_format($this->solde_caisse, 0, ',', ' ') . ' GNF';
    }

    public function getSoldeEspecesFormatAttribute(): string
    {
        return number_format((float) $this->solde_especes, 0, ',', ' ') . ' GNF';
    }

    public function getSoldeOmFormatAttribute(): string
    {
        return number_format((float) $this->solde_om, 0, ',', ' ') . ' GNF';
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
     * Vérifie si la caisse (du type donné) permet un paiement.
     * type : 'especes' | 'om' | 'total' (défaut : 'especes')
     */
    public function peutPayer(float $montant, string $type = 'especes'): bool
    {
        $solde = match ($type) {
            'om'    => (float) $this->solde_om,
            'total' => $this->solde_caisse,
            default => (float) $this->solde_especes,
        };
        return $solde >= $montant;
    }

    /**
     * Vérifie si le solde total est sous le seuil minimum d'alerte.
     */
    public function soldeSousSeuil(): bool
    {
        $seuil = (float) ($this->seuil_minimum_caisse ?? Parametre::valeur('seuil_minimum_caisse', 500000));
        return $this->solde_caisse <= $seuil;
    }

    /**
     * Débiter la caisse du type spécifié.
     */
    public function debiter(float $montant, string $type = 'especes'): void
    {
        $col = $type === 'om' ? 'solde_om' : 'solde_especes';
        $this->decrement($col, $montant);
    }

    /**
     * Créditer la caisse du type spécifié.
     */
    public function crediter(float $montant, string $type = 'especes'): void
    {
        $col = $type === 'om' ? 'solde_om' : 'solde_especes';
        $this->increment($col, $montant);
    }
}
