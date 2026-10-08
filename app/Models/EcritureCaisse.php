<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écriture du registre de caisse : un mouvement d'argent sur une caisse, avec le solde avant / après.
 * Créée uniquement par Caisse::crediter() / Caisse::debiter() (jamais modifiée ensuite).
 */
class EcritureCaisse extends Model
{
    protected $table = 'ecritures_caisse';

    const NATURES = [
        'solde_initial' => 'Solde initial',
        'paiement_bon' => 'Paiement d\'un bon',
        'approvisionnement' => 'Approvisionnement',
        'retrait' => 'Retrait',
        'ajustement' => 'Ajustement',
        'correction_solde' => 'Correction du solde (double validation)',
        'reversement_odm' => "Reversement d'un trop-perçu de mission",
    ];

    protected $fillable = [
        'caisse_id', 'date_ecriture', 'sens', 'nature', 'montant', 'solde_avant', 'solde_apres',
        'libelle', 'bon_caisse_id', 'mouvement_caisse_id', 'utilisateur_id',
    ];

    protected $casts = [
        'date_ecriture' => 'datetime',
        'montant' => 'decimal:2',
        'solde_avant' => 'decimal:2',
        'solde_apres' => 'decimal:2',
    ];

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class);
    }

    public function mouvementCaisse(): BelongsTo
    {
        return $this->belongsTo(MouvementCaisse::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }
}
