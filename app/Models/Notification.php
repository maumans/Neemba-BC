<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle Notification - Notifications push en temps réel
 * 
 * Chaque notification est envoyée à un destinataire spécifique
 * et liée à un bon de caisse. Diffusée via Laravel Reverb.
 */
class Notification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'destinataire_id',
        'bon_caisse_id',
        'expediteur_id',
        'type',
        'titre',
        'message',
        'metadata',
        'lue_le',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'lue_le' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------
     * CONSTANTES : Types de notification
     * ---------------------------------------------------------------- */

    const TYPE_SOUMISSION = 'soumission';
    const TYPE_VALIDATION = 'validation';
    const TYPE_APPROBATION_FINALE = 'approbation_finale';
    const TYPE_REJET = 'rejet';
    const TYPE_DEMANDE_COMPLEMENT = 'demande_complement';
    const TYPE_PAIEMENT = 'paiement';
    const TYPE_REGULARISATION = 'regularisation';
    const TYPE_RELANCE_REGULARISATION = 'relance_regularisation';
    const TYPE_ARCHIVAGE = 'archivage';
    const TYPE_ALERTE_RAPPORT = 'alerte_rapport';
    const TYPE_RELANCE_SLA = 'relance_sla';
    const TYPE_ESCALADE_SLA = 'escalade_sla';
    const TYPE_ALERTE_SOLDE = 'alerte_solde';
    const TYPE_MOUVEMENT_CAISSE = 'mouvement_caisse';
    const TYPE_DELEGATION = 'delegation';

    /** Icônes par type (utilisées côté frontend) */
    const TYPES_CONFIG = [
        self::TYPE_SOUMISSION => [
            'icone' => 'FileText',
            'couleur' => 'text-blue-600',
            'bg' => 'bg-blue-50',
        ],
        self::TYPE_VALIDATION => [
            'icone' => 'CheckCircle2',
            'couleur' => 'text-green-600',
            'bg' => 'bg-green-50',
        ],
        self::TYPE_APPROBATION_FINALE => [
            'icone' => 'CheckCircle2',
            'couleur' => 'text-emerald-600',
            'bg' => 'bg-emerald-50',
        ],
        self::TYPE_REJET => [
            'icone' => 'XCircle',
            'couleur' => 'text-red-600',
            'bg' => 'bg-red-50',
        ],
        self::TYPE_DEMANDE_COMPLEMENT => [
            'icone' => 'MessageSquare',
            'couleur' => 'text-amber-600',
            'bg' => 'bg-amber-50',
        ],
        self::TYPE_PAIEMENT => [
            'icone' => 'Banknote',
            'couleur' => 'text-emerald-600',
            'bg' => 'bg-emerald-50',
        ],
        self::TYPE_REGULARISATION => [
            'icone' => 'ClipboardCheck',
            'couleur' => 'text-purple-600',
            'bg' => 'bg-purple-50',
        ],
        self::TYPE_RELANCE_REGULARISATION => [
            'icone' => 'AlertTriangle',
            'couleur' => 'text-orange-600',
            'bg' => 'bg-orange-50',
        ],
        self::TYPE_ARCHIVAGE => [
            'icone' => 'Archive',
            'couleur' => 'text-gray-600',
            'bg' => 'bg-gray-50',
        ],
        self::TYPE_ALERTE_RAPPORT => [
            'icone' => 'AlertTriangle',
            'couleur' => 'text-red-600',
            'bg' => 'bg-red-50',
        ],
        self::TYPE_RELANCE_SLA => [
            'icone' => 'Clock',
            'couleur' => 'text-orange-600',
            'bg' => 'bg-orange-50',
        ],
        self::TYPE_ESCALADE_SLA => [
            'icone' => 'ArrowUpCircle',
            'couleur' => 'text-red-600',
            'bg' => 'bg-red-50',
        ],
        self::TYPE_ALERTE_SOLDE => [
            'icone'  => 'AlertTriangle',
            'couleur' => 'text-amber-600',
            'bg'     => 'bg-amber-50',
        ],
        self::TYPE_MOUVEMENT_CAISSE => [
            'icone'  => 'Wallet',
            'couleur' => 'text-blue-600',
            'bg'     => 'bg-blue-50',
        ],
        self::TYPE_DELEGATION => [
            'icone'  => 'Users',
            'couleur' => 'text-indigo-600',
            'bg'     => 'bg-indigo-50',
        ],
    ];

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_id');
    }

    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expediteur_id');
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    public function scopeNonLues($query)
    {
        return $query->whereNull('lue_le');
    }

    public function scopePourUtilisateur($query, int $utilisateurId)
    {
        return $query->where('destinataire_id', $utilisateurId);
    }

    /* ----------------------------------------------------------------
     * MÉTHODES
     * ---------------------------------------------------------------- */

    /**
     * Marquer la notification comme lue
     */
    public function marquerCommeLue(): void
    {
        if (!$this->lue_le) {
            $this->update(['lue_le' => now()]);
        }
    }

    /**
     * Vérifie si la notification est non lue
     */
    public function estNonLue(): bool
    {
        return $this->lue_le === null;
    }
}
