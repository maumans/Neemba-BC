<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Justification d'un bon provisoire, conforme à l'Annexe 3 (spec v2.3, RG-M07-03) : lignes de dépense (intitulé, code
 * analytique, compte, montant, pièce), calculs, et visas initiateur → N+1 → Contrôle de gestion → Comptabilité.
 */
class JustificationBp extends Model
{
    protected $table = 'justifications_bp';

    public const STATUTS = [
        'brouillon' => 'Brouillon',
        'en_validation' => 'En validation',
        'rejetee' => 'Rejetée, à corriger',
        'validee' => 'Visée',
    ];

    /** Étapes de visa, dans l'ordre (RG-M07-03) */
    public const ETAPES = [
        'n1' => 'N+1 de l\'initiateur',
        'cdg' => 'Contrôle de gestion',
        'comptabilite' => 'Comptabilité',
    ];

    /** Rôles qui visent chaque étape (le N+1 est le chef de service de l'initiateur) */
    public const ROLES_ETAPES = [
        'n1' => ['responsable_service'],
        'cdg' => ['controle_gestion'],
        'comptabilite' => ['comptable', 'chef_comptable'],
    ];

    public const LIGNES_MAX = 10;

    protected $fillable = [
        'bon_caisse_id', 'initiateur_id', 'statut', 'etape', 'version', 'montant_avance', 'montant_justifie',
        'montant_a_retourner', 'trop_justifie', 'soumise_le', 'validee_le', 'bon_complement_id',
        'reliquat_encaisse_le', 'reliquat_encaisse_par_id',
    ];

    protected function casts(): array
    {
        return [
            'montant_avance' => 'decimal:2',
            'montant_justifie' => 'decimal:2',
            'montant_a_retourner' => 'decimal:2',
            'trop_justifie' => 'decimal:2',
            'soumise_le' => 'datetime',
            'validee_le' => 'datetime',
            'reliquat_encaisse_le' => 'datetime',
        ];
    }

    public function bon(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    public function initiateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiateur_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(JustificationLigne::class, 'justification_id')->orderBy('ordre');
    }

    public function visas(): HasMany
    {
        return $this->hasMany(JustificationVisa::class, 'justification_id')->orderBy('id');
    }

    public function bonComplement(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_complement_id');
    }

    public function reliquatEncaissePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reliquat_encaisse_par_id');
    }

    public function estModifiable(): bool
    {
        return in_array($this->statut, ['brouillon', 'rejetee'], true);
    }

    /** Reliquat à rétrocéder en caisse, pas encore encaissé */
    public function reliquatAEncaisser(): bool
    {
        return $this->statut === 'validee' && (float) $this->montant_a_retourner > 0 && !$this->reliquat_encaisse_le;
    }
}
