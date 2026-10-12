<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part de prise en charge d'une ligne de frais (spec v2.3, RG-M12-31) : payeur (Neemba ou Client), client du
 * référentiel, OR facultatif, code analytique (null : celui de l'ODM), pourcentage et montant.
 * Le pourcentage fait foi (décision Q52) : le montant suit la ligne quand elle change.
 */
class PartOdm extends Model
{
    protected $table = 'odm_parts';

    public const PAYEURS = [
        'neemba' => 'Neemba',
        'client' => 'Client',
    ];

    protected $fillable = ['ligne_frais_id', 'rang', 'payeur', 'client_id', 'ordre_reparation_id', 'code_analytique', 'pourcentage', 'montant', 'commentaire'];

    protected function casts(): array
    {
        return ['pourcentage' => 'decimal:4', 'montant' => 'decimal:2', 'rang' => 'integer'];
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(LigneFraisOdm::class, 'ligne_frais_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function ordreReparation(): BelongsTo
    {
        return $this->belongsTo(OrdreReparationOdm::class, 'ordre_reparation_id');
    }

    /**
     * RG-M12-33 : avertissement non bloquant (commentaire obligatoire) pour une part client sans OR,
     * ou dont le client diffère de celui de son OR. Null s'il n'y a rien à signaler.
     */
    public function avertissement(): ?string
    {
        if ($this->payeur !== 'client') {
            return null;
        }
        if (!$this->ordre_reparation_id) {
            return 'Part client sans OR.';
        }
        $or = $this->ordreReparation;
        if ($or?->client_id && $this->client_id && $or->client_id !== $this->client_id) {
            return "Client différent de celui de l'OR {$or->numero}.";
        }

        return null;
    }
}
