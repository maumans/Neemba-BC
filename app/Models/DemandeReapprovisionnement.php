<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de réapprovisionnement d'une caisse (spec v2.4, M08, RG-M08-04 à RG-M08-09) : demandée par le gérant avec le
 * rapprochement signé et le PV d'arrêté du jour, validée par la trésorerie, visée par la Finance, approuvée par le DP
 * (ou deux membres du CODIR en son absence), exécutée par la trésorerie, puis reçue par la caisse.
 */
class DemandeReapprovisionnement extends Model
{
    use \App\Models\Concerns\AppartientAUneEntite;

    protected $table = 'demandes_reapprovisionnement';

    public const STATUTS = [
        'soumise' => 'Soumise — à valider par la trésorerie',
        'validee_tresorerie' => 'Validée trésorerie — à viser par la Finance',
        'visee_finance' => 'Visée Finance — à approuver par le DP',
        'approuvee' => 'Approuvée — à exécuter par la trésorerie',
        'en_cours' => 'En cours — réception à confirmer',
        'recue' => 'Reçue',
        'refusee' => 'Refusée',
    ];

    /** Origine des fonds (RG-M08-08) */
    public const ORIGINES = [
        'cheque' => 'Chèque déposé en banque',
        'mise_a_disposition' => 'Mise à disposition de fonds par la banque',
        'orange_money' => 'Orange Money',
        'virement' => 'Virement bancaire (caisse Orange Money)',
        'transfert' => "Transfert depuis une autre caisse",
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'solde_a_la_demande' => 'decimal:2', 'montant_propose' => 'decimal:2', 'montant_demande' => 'decimal:2',
            'montant_valide' => 'decimal:2', 'montant_emis' => 'decimal:2', 'montant_recu' => 'decimal:2',
            'tresorerie_le' => 'datetime', 'finance_le' => 'datetime', 'dp_le' => 'datetime', 'codir1_le' => 'datetime',
            'codir2_le' => 'datetime', 'refuse_le' => 'datetime', 'execute_le' => 'datetime', 'recu_le' => 'datetime',
        ];
    }

    public function entiteParDefaut(): ?int
    {
        return $this->caisse_id ? Caisse::withoutGlobalScope('entite')->whereKey($this->caisse_id)->value('entite_id') : Entite::idParDefaut();
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function caisseSource(): BelongsTo
    {
        return $this->belongsTo(Caisse::class, 'caisse_source_id');
    }

    public function pvArrete(): BelongsTo
    {
        return $this->belongsTo(PvArrete::class);
    }

    public function utilisateur(string $colonne): ?User
    {
        return $this->{$colonne} ? User::find($this->{$colonne}) : null;
    }

    /** Montant retenu à ce stade : validé par la trésorerie, à défaut demandé */
    public function montantRetenu(): float
    {
        return (float) ($this->montant_valide ?? $this->montant_demande);
    }
}
