<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visiteur du groupe (spec v2.4, §6.5) : salarié d'une autre filiale en mission en Guinée. Il ne reçoit qu'un BD, à titre
 * exceptionnel, refacturé à sa filiale (RG-M03-04, RG-M03-28) ; jamais de BP. Le directeur du département d'accueil reste
 * l'initiateur de la régularisation des BP de visiteurs émis avant la v2.4 (RG-M07-01, décision du 16/10).
 */
class Visiteur extends Model
{
    use HasFactory;
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    /** Référentiel propre à une entité, ou commun à toutes (entité vide) */
    protected bool $entiteCommune = true;

    /** Gestion du référentiel : RH, direction financière, administrateur */
    public const ROLES_GESTION = ['rh', 'daf', 'daf_adjoint', 'chef_comptable', 'administrateur'];

    protected $fillable = [
        'entite_id',
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
