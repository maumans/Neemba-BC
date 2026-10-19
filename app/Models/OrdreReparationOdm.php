<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OR lié à un ODM (spec v2.2, §7.3) : 8 chiffres commençant par 110 ; type choisi dans le référentiel des types d'OR
 * (spec v2.4, §6.5, `TypeOr`).
 * Obligatoire pour une mission technique (RG-M12-02, MSG-M12-01).
 * Spec v2.3 : client facultatif, proposé par défaut sur les parts de prise en charge (RG-M12-33).
 */
class OrdreReparationOdm extends Model
{
    protected $table = 'odm_ordres_reparation';

    /** Spec v2.2 (MSG-M03-07) : 8 chiffres commençant par 110 */
    public const FORMAT = '/^110\d{5}$/';

    protected $fillable = ['ordre_mission_id', 'numero', 'type', 'client_id'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ordreMission(): BelongsTo
    {
        return $this->belongsTo(OrdreMission::class, 'ordre_mission_id');
    }
}
