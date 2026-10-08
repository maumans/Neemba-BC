<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape du circuit de validation d'un ODM (RG-M12-11) : chef d'atelier → DAF → (RH) → DP.
 * Une série d'étapes par version : un ODM rejeté puis resoumis repart sur une nouvelle version (RG-M12-12).
 */
class EtapeOdm extends Model
{
    protected $table = 'odm_etapes';

    public const STATUTS = [
        'a_venir' => 'À venir',
        'en_attente' => 'En attente',
        'validee' => 'Visé',
        'rejetee' => 'Rejeté',
        'annulee' => 'Non atteint',
    ];

    protected $fillable = [
        'ordre_mission_id', 'version', 'niveau', 'role', 'statut',
        'valideur_id', 'au_titre_de_id', 'date_attribution', 'date_decision', 'commentaire', 'derniere_relance', 'escalade',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'niveau' => 'integer',
            'date_attribution' => 'datetime',
            'date_decision' => 'datetime',
            'derniere_relance' => 'datetime',
            'escalade' => 'boolean',
        ];
    }

    public function ordreMission(): BelongsTo
    {
        return $this->belongsTo(OrdreMission::class, 'ordre_mission_id');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valideur_id');
    }

    public function auTitreDe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'au_titre_de_id');
    }

    public function getLibelleAttribute(): string
    {
        return OrdreMission::NIVEAUX[$this->role]['libelle'] ?? $this->role;
    }
}
