<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Client de Neemba (spec v2.3, §6.5) : référentiel alimenté par import (PO-26), puis par la Comptabilité ou le CDG.
 * Sert aux ODM (clients de la mission, client d'un OR, parts de prise en charge des frais, RG-M12-31).
 */
class Client extends Model
{
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    /** Référentiel propre à une entité, ou commun à toutes (entité vide) */
    protected bool $entiteCommune = true;

    /** Qui gère le référentiel : la Comptabilité (chef comptable), le CDG, l'administrateur */
    public const ROLES_GESTION = ['chef_comptable', 'comptable', 'controle_gestion', 'administrateur'];

    protected $fillable = ['entite_id', 'code', 'raison_sociale', 'actif', 'cree_par_id'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    public function ordresMission(): BelongsToMany
    {
        return $this->belongsToMany(OrdreMission::class, 'odm_clients')->withTimestamps();
    }

    /** « SMD — Société Minière de Dinguiraye » */
    public function getLibelleAttribute(): string
    {
        return "{$this->code} — {$this->raison_sociale}";
    }

    /** Format renvoyé aux écrans (sélecteurs, fiches) */
    public function resume(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'raison_sociale' => $this->raison_sociale, 'actif' => (bool) $this->actif];
    }
}
