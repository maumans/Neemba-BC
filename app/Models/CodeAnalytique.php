<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle CodeAnalytique - Table de paramétrage
 * 
 * Représente un code d'imputation analytique comptable.
 * Enrichi avec business_unit et catégorie de dépense par défaut.
 * Administrable par le DAF/Directeur Pays.
 */
class CodeAnalytique extends Model
{
    use HasFactory;

    protected $table = 'codes_analytiques';

    protected $fillable = [
        'code',
        'libelle',
        'description',
        'categorie_depense_defaut',
        'service_id',
        'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    public function scopeParService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /**
     * Label complet : code + libellé
     */
    public function getLabelCompletAttribute(): string
    {
        $label = "{$this->code} — {$this->libelle}";
        if ($this->service && $this->service->nom) {
            $label .= " ({$this->service->nom})";
        }
        return $label;
    }
}
