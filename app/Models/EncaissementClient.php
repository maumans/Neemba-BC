<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encaissement client (spec v2.4, RG-M08-10) : paiement au comptant d'un client, sur la caisse de Conakry principale
 * seulement — client, n° de pièce (OR ou facture), montant, mode — avec un reçu PDF numéroté. Un encaissement en espèces
 * entre au registre de la caisse ; un chèque est enregistré et remis en banque, hors du solde espèces.
 */
class EncaissementClient extends Model
{
    use \App\Models\Concerns\AppartientAUneEntite;

    protected $table = 'encaissements_clients';

    public const MODES = [
        'especes' => 'Espèces',
        'cheque' => 'Chèque (remis en banque, hors solde de la caisse)',
    ];

    protected $fillable = [
        'entite_id', 'caisse_id', 'numero', 'client_id', 'numero_piece', 'montant', 'mode', 'observations', 'piece',
        'encaisse_par', 'encaisse_le', 'ecriture_caisse_id',
    ];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2', 'encaisse_le' => 'datetime'];
    }

    public function entiteParDefaut(): ?int
    {
        return $this->caisse_id ? Caisse::withoutGlobalScope('entite')->whereKey($this->caisse_id)->value('entite_id') : Entite::idParDefaut();
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function encaissePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encaisse_par');
    }
}
