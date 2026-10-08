<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OR lié à un ODM (spec v2.2, §7.3) : 8 chiffres commençant par 110, de type VENTE ou GARANTIE.
 * Obligatoire pour une mission technique (RG-M12-02, MSG-M12-01).
 */
class OrdreReparationOdm extends Model
{
    protected $table = 'odm_ordres_reparation';

    public const TYPES = [
        'vente' => 'Vente',
        'garantie' => 'Garantie',
    ];

    /** Spec v2.2 (MSG-M03-07) : 8 chiffres commençant par 110 */
    public const FORMAT = '/^110\d{5}$/';

    protected $fillable = ['ordre_mission_id', 'numero', 'type'];

    public function ordreMission(): BelongsTo
    {
        return $this->belongsTo(OrdreMission::class, 'ordre_mission_id');
    }
}
