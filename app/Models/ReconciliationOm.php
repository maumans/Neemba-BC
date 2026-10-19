<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réconciliation hebdomadaire de la caisse Orange Money (spec v2.4, RG-M08-13, §5.4) : brouillard de caisse (registre)
 * rapproché du relevé de la plateforme, lignes non rapprochées, écart de solde ; signée par la trésorière, le Chef
 * comptable et le DAF, chacun une fois et par trois personnes différentes.
 */
class ReconciliationOm extends Model
{
    use \App\Models\Concerns\AppartientAUneEntite;

    protected $table = 'reconciliations_om';

    public const STATUTS = [
        'a_valider' => 'À valider',
        'validee' => 'Validée',
    ];

    /** Signatures : colonne => libellé */
    public const SIGNATURES = [
        'tresorerie' => 'Trésorière',
        'chef_comptable' => 'Chef comptable',
        'daf' => 'DAF',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'lignes_releve' => 'array',
            'ecritures_non_rapprochees' => 'array',
            'solde_brouillard_debut' => 'decimal:2',
            'solde_brouillard_fin' => 'decimal:2',
            'solde_releve_fin' => 'decimal:2',
            'ecart' => 'decimal:2',
            'tresorerie_le' => 'datetime',
            'chef_comptable_le' => 'datetime',
            'daf_le' => 'datetime',
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

    public function etabliPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'etabli_par');
    }

    public function signataire(string $signature): ?User
    {
        return $this->{"{$signature}_id"} ? User::find($this->{"{$signature}_id"}) : null;
    }

    public function nombreSignatures(): int
    {
        return count(array_filter(array_keys(self::SIGNATURES), fn (string $s) => $this->{"{$s}_le"} !== null));
    }

    public function estValidee(): bool
    {
        return $this->nombreSignatures() === count(self::SIGNATURES);
    }
}
