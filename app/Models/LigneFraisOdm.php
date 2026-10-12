<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne de frais d'un ODM (spec v2.3, RG-M12-30) : une par participant, par segment et par élément,
 * avec quantité, taux et montant. Elle se répartit en parts de prise en charge (RG-M12-31).
 * Montant nul (null) : ODM extérieur sans taux, ou facture d'hébergement payée au retour (connue à la clôture).
 */
class LigneFraisOdm extends Model
{
    protected $table = 'odm_lignes_frais';

    public const ELEMENTS = [
        'indemnite' => 'Indemnité',
        'hebergement' => 'Hébergement',
        'rattrapage' => 'Nuitée de rattrapage',
        'hebergement_exterieur' => "Hébergement à l'étranger (payé en GNF)",
    ];

    protected $fillable = ['ordre_mission_id', 'participant_id', 'element', 'quantite', 'taux', 'devise', 'montant'];

    protected function casts(): array
    {
        return ['quantite' => 'decimal:2', 'taux' => 'decimal:2', 'montant' => 'decimal:2'];
    }

    public function ordreMission(): BelongsTo
    {
        return $this->belongsTo(OrdreMission::class, 'ordre_mission_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ParticipantOdm::class, 'participant_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(PartOdm::class, 'ligne_frais_id')->orderBy('rang');
    }
}
