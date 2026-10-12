<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Imputation comptable d'un bon payé (M16, RG-M16-01) : brouillon, validée, puis exportée.
 * Une imputation exportée ne se modifie plus (RG-M16-06).
 */
class Imputation extends Model
{
    public const STATUTS = [
        'brouillon' => 'Brouillon',
        'validee' => 'Imputée, à exporter',
        'exportee' => 'Exportée',
    ];

    /** Qui impute les bons : la Comptabilité, le Chef comptable, le DAF et son adjoint */
    public const ROLES = ['comptable', 'chef_comptable', 'daf', 'daf_adjoint'];

    protected $fillable = ['bon_caisse_id', 'statut', 'code_journal', 'motif_correction', 'saisi_par_id', 'valide_par_id', 'valide_le', 'export_comptable_id'];

    protected function casts(): array
    {
        return ['valide_le' => 'datetime'];
    }

    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneImputation::class)->orderBy('rang');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par_id');
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par_id');
    }

    public function exportComptable(): BelongsTo
    {
        return $this->belongsTo(ExportComptable::class);
    }

    public function estModifiable(): bool
    {
        return $this->statut !== 'exportee';
    }
}
