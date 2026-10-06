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

    /** Coupures en GNF retenues pour le billetage (de la plus grosse à la plus petite) */
    const COUPURES = [20000, 10000, 5000, 2000, 1000, 500, 100, 50];

    protected $fillable = [
        'date_rapport',
        'site',
        'solde_ouverture',
        'solde_ouverture_especes',
        'solde_ouverture_om',
        'total_entrees',
        'total_entrees_especes',
        'total_entrees_om',
        'total_sorties',
        'total_sorties_especes',
        'total_sorties_om',
        'nombre_bons',
        'detail_par_categorie',
        'detail_par_mode_paiement',
        'solde_cloture',
        'solde_cloture_especes',
        'solde_cloture_om',
        'billetage',
        'solde_physique_especes',
        'solde_physique_om',
        'ecart_especes',
        'ecart_om',
        'motif_ecart',
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
            'solde_ouverture_especes' => 'decimal:2',
            'solde_ouverture_om' => 'decimal:2',
            'total_entrees' => 'decimal:2',
            'total_entrees_especes' => 'decimal:2',
            'total_entrees_om' => 'decimal:2',
            'total_sorties' => 'decimal:2',
            'total_sorties_especes' => 'decimal:2',
            'total_sorties_om' => 'decimal:2',
            'solde_cloture' => 'decimal:2',
            'solde_cloture_especes' => 'decimal:2',
            'solde_cloture_om' => 'decimal:2',
            'nombre_bons' => 'integer',
            'detail_par_categorie' => 'array',
            'detail_par_mode_paiement' => 'array',
            'billetage' => 'array',
            'solde_physique_especes' => 'decimal:2',
            'solde_physique_om' => 'decimal:2',
            'ecart_especes' => 'decimal:2',
            'ecart_om' => 'decimal:2',
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
        $this->solde_cloture_especes = $this->solde_ouverture_especes + $this->total_entrees_especes - $this->total_sorties_especes;
        $this->solde_cloture_om = $this->solde_ouverture_om + $this->total_entrees_om - $this->total_sorties_om;
        $this->solde_cloture = $this->solde_cloture_especes + $this->solde_cloture_om;
        $this->save();
    }

    /**
     * Récupérer le solde de clôture du dernier rapport d'un site
     * (sera utilisé comme solde d'ouverture du jour suivant).
     *
     * $avant : ne retenir que les rapports antérieurs à cette date. Sans cette borne,
     * le rapport du jour lui-même servait d'ouverture et la journée était comptée deux fois.
     */
    public static function soldePrecedent(string $site, $avant = null): array
    {
        $dernierRapport = static::where('site', $site)
            ->when($avant, fn ($q) => $q->whereDate('date_rapport', '<', $avant))
            ->orderBy('date_rapport', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $dernierRapport ? [
            'total' => (float) $dernierRapport->solde_cloture,
            'especes' => (float) $dernierRapport->solde_cloture_especes,
            'om' => (float) $dernierRapport->solde_cloture_om,
        ] : [
            'total' => 0,
            'especes' => 0,
            'om' => 0,
        ];
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
