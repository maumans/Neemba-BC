<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle RapportCaisse - Rapport Journalier de Caisse
 * 
 * Un rapport est généré chaque jour pour tracer les mouvements de caisse.
 * 
 * Formule : solde_cloture = solde_ouverture + total_entrees - total_sorties
 * Contrainte : un seul rapport par jour et par site
 */
class RapportCaisse extends Model
{
    use HasFactory;

    protected $table = 'rapports_caisse';

    protected $fillable = [
        'date_rapport',
        'site',
        'solde_ouverture',
        'total_entrees',
        'total_sorties',
        'nombre_bons',
        'detail_par_categorie',
        'detail_par_mode_paiement',
        'solde_cloture',
        'observations',
        'caissier_id',
        'cloture',
        'visa_daf_id',
        'date_visa_daf',
    ];

    protected function casts(): array
    {
        return [
            'date_rapport' => 'date',
            'solde_ouverture' => 'decimal:2',
            'total_entrees' => 'decimal:2',
            'total_sorties' => 'decimal:2',
            'solde_cloture' => 'decimal:2',
            'nombre_bons' => 'integer',
            'detail_par_categorie' => 'array',
            'detail_par_mode_paiement' => 'array',
            'cloture' => 'boolean',
            'date_visa_daf' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Caissier responsable de ce rapport
     */
    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }

    /**
     * DAF ayant visé le rapport
     */
    public function visaDaf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visa_daf_id');
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    /**
     * Rapports d'un site donné
     */
    public function scopeParSite($query, string $site)
    {
        return $query->where('site', 'like', '%' . $site . '%');
    }

    /**
     * Rapports visés par le DAF
     */
    public function scopeVises($query)
    {
        return $query->whereNotNull('visa_daf_id');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /**
     * Solde de clôture formaté
     */
    public function getSoldeClotureFormatAttribute(): string
    {
        return number_format($this->solde_cloture, 0, ',', ' ') . ' GNF';
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Calculer le solde de clôture automatiquement
     */
    public function calculerSoldeCloture(): void
    {
        $this->solde_cloture = $this->solde_ouverture + $this->total_entrees - $this->total_sorties;
        $this->save();
    }

    /**
     * Récupérer le solde de clôture du jour précédent pour un site
     * (sera utilisé comme solde d'ouverture du jour suivant)
     */
    public static function soldePrecedent(string $site): float
    {
        $dernierRapport = static::where('site', $site)
            ->orderBy('date_rapport', 'desc')
            ->first();

        return $dernierRapport ? (float) $dernierRapport->solde_cloture : 0;
    }

    /**
     * Calculer les statistiques détaillées à partir des bons payés
     *
     * @param \Illuminate\Support\Collection $bonsPaye Collection de BonCaisse payés
     */
    public function calculerStatistiques($bonsPaye): void
    {
        $this->nombre_bons = $bonsPaye->count();

        /* Ventilation par catégorie de dépense */
        $this->detail_par_categorie = $bonsPaye
            ->groupBy('categorie_depense')
            ->map(function ($group, $categorie) {
                return [
                    'categorie' => $categorie,
                    'label' => BonCaisse::CATEGORIES_DEPENSE[$categorie] ?? $categorie,
                    'nombre' => $group->count(),
                    'montant' => (float) $group->sum('montant'),
                ];
            })
            ->values()
            ->toArray();

        /* Ventilation par mode de paiement effectif */
        $this->detail_par_mode_paiement = $bonsPaye
            ->groupBy('mode_paiement_effectif')
            ->map(function ($group, $mode) {
                return [
                    'mode' => $mode,
                    'label' => BonCaisse::MODES_PAIEMENT[$mode] ?? $mode,
                    'nombre' => $group->count(),
                    'montant' => (float) $group->sum('montant'),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Apposer le visa du DAF sur le rapport
     */
    public function viserParDaf(User $daf): bool
    {
        if ($this->visa_daf_id) {
            return false;
        }

        $this->visa_daf_id = $daf->id;
        $this->date_visa_daf = now();
        $this->save();

        return true;
    }
}
