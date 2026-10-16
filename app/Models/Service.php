<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Service - Table de paramétrage
 * 
 * Représente un service de l'entreprise (ex: Direction Générale, Finance...).
 * Administrable par le DAF/Directeur Pays.
 */
class Service extends Model
{
    use HasFactory;
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    /** Référentiel propre à une entité, ou commun à toutes (entité vide) */
    protected bool $entiteCommune = true;

    protected $fillable = [
        'entite_id','nom', 'code', 'equivalent_odm', 'prefixe_odm', 'diffusion_odm', 'actif'];

    protected $casts = [
        'actif' => 'boolean',
        /* M12 (RG-M12-24) : identifiants des utilisateurs notifiés des événements des ODM du service */
        'diffusion_odm' => 'array',
    ];

    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    public function codesAnalytiques()
    {
        return $this->hasMany(CodeAnalytique::class);
    }
}
