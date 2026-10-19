<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PV d'arrêté de caisse (spec v2.4, RG-M10-02, Annexe 2) : comptage d'une caisse à une date et une heure — billetage par
 * coupure (20 000 à 50 GNF), solde physique, solde comptable, écart — signé par le gérant de la caisse, la trésorerie et
 * le Chef comptable. Le PV ne se modifie pas : un nouveau comptage donne un nouveau PV. Un écart non nul crée, à la
 * dernière signature, un ajustement « En attente » validé par le DAF avec motif (RG-M10-03).
 */
class PvArrete extends Model
{
    use \App\Models\Concerns\AppartientAUneEntite;

    protected $table = 'pv_arretes';

    public const MOTIFS = [
        'inventaire_mensuel' => 'Inventaire de fin de mois',
        'reapprovisionnement' => 'Avant une demande de réapprovisionnement',
        'controle_inopine' => 'Contrôle inopiné',
        'cloture' => 'Arrêté de caisse',
    ];

    /** Signatures de l'Annexe 2 : colonne => libellé */
    public const SIGNATURES = [
        'gerant' => 'Gérant de la caisse',
        'tresorerie' => 'Trésorerie',
        'chef_comptable' => 'Chef comptable',
    ];

    protected $fillable = [
        'entite_id', 'caisse_id', 'numero', 'motif', 'arrete_le', 'billetage', 'solde_physique', 'solde_comptable', 'ecart',
        'observations', 'etabli_par', 'gerant_id', 'gerant_le', 'tresorerie_id', 'tresorerie_le', 'chef_comptable_id',
        'chef_comptable_le', 'ajustement_id',
    ];

    protected function casts(): array
    {
        return [
            'arrete_le' => 'datetime',
            'billetage' => 'array',
            'solde_physique' => 'decimal:2',
            'solde_comptable' => 'decimal:2',
            'ecart' => 'decimal:2',
            'gerant_le' => 'datetime',
            'tresorerie_le' => 'datetime',
            'chef_comptable_le' => 'datetime',
        ];
    }

    /** Entité : celle de la caisse */
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

    public function gerant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerant_id');
    }

    public function tresorerie(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tresorerie_id');
    }

    public function chefComptable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chef_comptable_id');
    }

    public function ajustement(): BelongsTo
    {
        return $this->belongsTo(MouvementCaisse::class, 'ajustement_id');
    }

    public function estSigne(): bool
    {
        return $this->gerant_le && $this->tresorerie_le && $this->chef_comptable_le;
    }

    /** « 2 / 3 signatures » */
    public function nombreSignatures(): int
    {
        return collect(array_keys(self::SIGNATURES))->filter(fn (string $s) => $this->{"{$s}_le"} !== null)->count();
    }
}
