<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tiers non salarié bénéficiaire d'un bon (spec v2.3, §6.5) : proposé par un demandeur, utilisable seulement une fois
 * validé par la Comptabilité ou le CDG (RG-M02-06) ; un refus est motivé ; un tiers se désactive, ne se supprime pas.
 */
class Tiers extends Model
{
    use HasFactory;
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    /** Référentiel propre à une entité, ou commun à toutes (entité vide) */
    protected bool $entiteCommune = true;

    protected $table = 'tiers';

    public const TYPES = [
        'fournisseur' => 'Fournisseur',
        'prestataire' => 'Prestataire',
        'particulier' => 'Particulier',
    ];

    public const STATUTS = [
        'propose' => 'Proposé, à valider',
        'valide' => 'Validé',
        'refuse' => 'Refusé',
        'desactive' => 'Désactivé',
    ];

    /** RG-M02-06 : la Comptabilité, le CDG et la direction financière valident les tiers */
    public const ROLES_VALIDATION = ['comptable', 'chef_comptable', 'controle_gestion', 'daf', 'daf_adjoint'];

    protected $fillable = [
        'entite_id',
        'nom', 'type', 'telephone', 'numero_om', 'nif', 'statut', 'motif_refus', 'origine',
        'propose_par_id', 'propose_le', 'valide_par_id', 'valide_le',
    ];

    protected function casts(): array
    {
        return ['propose_le' => 'datetime', 'valide_le' => 'datetime'];
    }

    public function proposePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propose_par_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_id');
    }

    public function bons(): HasMany
    {
        return $this->hasMany(BonCaisse::class, 'tiers_id');
    }

    public function scopeValides(Builder $query): Builder
    {
        return $query->where('statut', 'valide');
    }

    public function estValide(): bool
    {
        return $this->statut === 'valide';
    }

    /** Libellé de recherche : nom, type, téléphone */
    public function libelle(): string
    {
        return $this->nom . ' — ' . (self::TYPES[$this->type] ?? $this->type)
            . ($this->telephone ? ' — ' . $this->telephone : '');
    }
}
