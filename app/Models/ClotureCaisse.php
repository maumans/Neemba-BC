<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journée de caisse close (spec v2.4, RG-M09-03) : soldes et totaux figés à la clôture, contrôle du solde initial avec le
 * solde final de la veille (RG-M09-02), envoi du rapport « REPORTING CAISSE » (RG-M09-04). Aucune écriture n'est plus
 * possible sur la journée.
 */
class ClotureCaisse extends Model
{
    use \App\Models\Concerns\AppartientAUneEntite;

    protected $table = 'clotures_caisse';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_journee' => 'date',
            'solde_initial' => 'decimal:2',
            'total_encaissements' => 'decimal:2',
            'total_decaissements' => 'decimal:2',
            'solde_final' => 'decimal:2',
            'solde_veille' => 'decimal:2',
            'ecart_controle' => 'decimal:2',
            'automatique' => 'boolean',
            'cloture_le' => 'datetime',
            'envoye_le' => 'datetime',
            'destinataires' => 'array',
        ];
    }

    public function entiteParDefaut(): ?int
    {
        return $this->caisse_id ? Caisse::withoutGlobalScope('entite')->whereKey($this->caisse_id)->value('entite_id') : Entite::idParDefaut();
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloture_par');
    }

    /** La journée de cette caisse est-elle close ? (toutes entités : contrôle d'écriture) */
    public static function estClose(int $caisseId, CarbonInterface $jour): bool
    {
        return static::withoutGlobalScope('entite')->where('caisse_id', $caisseId)->whereDate('date_journee', $jour->toDateString())->exists();
    }
}
