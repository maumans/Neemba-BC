<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Export quotidien des écritures (M16, RG-M16-03) : les imputations qu'il a emportées ne se modifient plus */
class ExportComptable extends Model
{
    protected $table = 'exports_comptables';

    protected $fillable = ['date_debut', 'date_fin', 'nombre_ecritures', 'montant_total', 'exporte_par_id'];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date', 'montant_total' => 'decimal:2'];
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(Imputation::class);
    }

    public function exportePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exporte_par_id');
    }
}
