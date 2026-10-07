<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Format;

/**
 * Modèle Site - Table de paramétrage
 *
 * Les soldes ne sont plus portés par le site mais par ses caisses (lot 2) :
 * solde_especes, solde_om et solde_caisse sont des totaux calculés sur les caisses actives du site.
 * La caisse Orange Money est unique et rattachée à Conakry (RG-BC-12).
 */
class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'nom', 'ville', 'adresse', 'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    protected $appends = [
        'solde_especes',
        'solde_om',
        'solde_caisse',
        'solde_caisse_format',
        'solde_especes_format',
        'solde_om_format',
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

    public function caisses(): HasMany
    {
        return $this->hasMany(Caisse::class);
    }

    public function mouvementsCaisse(): HasMany
    {
        return $this->hasMany(MouvementCaisse::class, 'site', 'nom');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS — totaux des caisses actives du site
     * ---------------------------------------------------------------- */

    private function totalCaisses(?string $type = null): float
    {
        return (float) $this->caisses
            ->where('actif', true)
            ->when($type, fn ($caisses) => $caisses->where('type', $type))
            ->sum('solde');
    }

    public function getSoldeEspecesAttribute(): float
    {
        return $this->totalCaisses('especes');
    }

    public function getSoldeOmAttribute(): float
    {
        return $this->totalCaisses('orange_money');
    }

    /** Solde total = toutes les caisses actives du site */
    public function getSoldeCaisseAttribute(): float
    {
        return $this->totalCaisses();
    }

    public function getSoldeCaisseFormatAttribute(): string
    {
        return Format::montant($this->solde_caisse);
    }

    public function getSoldeEspecesFormatAttribute(): string
    {
        return Format::montant($this->solde_especes);
    }

    public function getSoldeOmFormatAttribute(): string
    {
        return Format::montant($this->solde_om);
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Une caisse active du site est-elle sous son seuil d'alerte ?
     */
    public function soldeSousSeuil(): bool
    {
        return $this->caisses->where('actif', true)->contains(fn (Caisse $caisse) => $caisse->sousSeuil());
    }
}
