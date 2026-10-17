<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visiteur du groupe (spec v2.3, §6.5) : salarié d'une autre filiale en mission en Guinée. Il peut recevoir un BP ;
 * la régularisation relève du directeur du département d'accueil (RG-M03-04, RG-M07-01).
 */
class Visiteur extends Model
{
    use HasFactory;

    /** Gestion du référentiel : RH, direction financière, administrateur */
    public const ROLES_GESTION = ['rh', 'daf', 'daf_adjoint', 'chef_comptable', 'administrateur'];

    protected $fillable = [
        'nom', 'prenom', 'filiale', 'matricule_groupe', 'numero_om', 'departement_accueil', 'directeur_id',
        'date_debut', 'date_fin', 'actif', 'cree_par_id',
    ];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date', 'actif' => 'boolean'];
    }

    public function directeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'directeur_id');
    }

    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    public function getNomCompletAttribute(): string
    {
        return trim($this->prenom . ' ' . mb_strtoupper($this->nom));
    }

    /** Le séjour couvre-t-il la date du jour ? */
    public function enSejour(): bool
    {
        return today()->between($this->date_debut, $this->date_fin);
    }

    public function libelle(): string
    {
        return mb_strtoupper($this->nom) . ' ' . $this->prenom . ' — ' . $this->filiale
            . ($this->matricule_groupe ? ' — ' . $this->matricule_groupe : '');
    }
}
