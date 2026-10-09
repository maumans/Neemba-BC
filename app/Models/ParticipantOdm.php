<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participant d'un ODM (RG-M12-04) : un salarié, ses informations reprises du référentiel au moment de l'ajout
 * (service, statut cadre, n° Orange Money) et son calcul (RG-M12-07).
 */
class ParticipantOdm extends Model
{
    protected $table = 'odm_participants';

    protected $fillable = [
        'ordre_mission_id', 'user_id',
        'nom', 'matricule', 'service', 'statut_cadre', 'numero_om', 'base_vie',
        'jours', 'nuits', 'nuit_rattrapage', 'indemnite_fcfa', 'indemnite', 'hebergement', 'rattrapage', 'hebergement_facture', 'total',
        'prises_en_charge', 'montant_bon', 'montant_refacturable', 'montant_client_direct',
        'retire', 'trop_percu', 'regularisation', 'regularisation_statut', 'regularise_le', 'regularise_par_id',
        'bon_caisse_id', 'bon_complement_id',
    ];

    public const REGULARISATIONS = [
        'reversement' => 'Reversement en caisse',
        'retenue' => 'Retenue sur salaire',
    ];

    protected function casts(): array
    {
        return [
            'base_vie' => 'boolean',
            'retire' => 'boolean',
            'jours' => 'integer',
            'nuits' => 'integer',
            'nuit_rattrapage' => 'integer',
            'indemnite_fcfa' => 'decimal:2',
            'indemnite' => 'decimal:2',
            'hebergement' => 'decimal:2',
            'rattrapage' => 'decimal:2',
            'hebergement_facture' => 'decimal:2',
            'total' => 'decimal:2',
            'prises_en_charge' => 'array',
            'montant_bon' => 'decimal:2',
            'montant_refacturable' => 'decimal:2',
            'montant_client_direct' => 'decimal:2',
            'trop_percu' => 'decimal:2',
            'regularise_le' => 'datetime',
        ];
    }

    /** Qui prend en charge une ligne de frais (Q49) */
    public function priseEnCharge(string $ligne): string
    {
        return $this->prises_en_charge[$ligne] ?? $this->ordreMission?->priseParDefaut() ?? 'neemba';
    }

    /** ODM extérieur : indemnité en FCFA versée par Neemba (nulle si le client la paie directement) */
    public function indemniteFcfaDansLeBon(): float
    {
        return $this->priseEnCharge('indemnite') === 'client_direct' ? 0.0 : (float) $this->indemnite_fcfa;
    }

    /** Hébergement et rattrapage versés par Neemba (part fixe en GNF du bon d'un ODM extérieur) */
    public function fraisFixesDansLeBon(): float
    {
        return ($this->priseEnCharge('hebergement') === 'client_direct' ? 0.0 : (float) $this->hebergement)
            + ($this->priseEnCharge('rattrapage') === 'client_direct' ? 0.0 : (float) $this->rattrapage);
    }

    public function ordreMission(): BelongsTo
    {
        return $this->belongsTo(OrdreMission::class, 'ordre_mission_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function regularisePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'regularise_par_id');
    }

    public function bonComplement(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_complement_id');
    }

    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    /** Informations du référentiel reprises sur le participant (RG-M12-04) */
    public static function depuisUtilisateur(User $utilisateur): array
    {
        return [
            'user_id' => $utilisateur->id,
            'nom' => trim(mb_strtoupper($utilisateur->name) . ' ' . $utilisateur->prenom),
            'matricule' => $utilisateur->matricule,
            'service' => $utilisateur->service,
            'statut_cadre' => $utilisateur->statut_cadre,
            'numero_om' => $utilisateur->numero_om,
        ];
    }

    public function getTotalFormatAttribute(): string
    {
        return $this->total === null ? '—' : Format::montant($this->total);
    }
}
