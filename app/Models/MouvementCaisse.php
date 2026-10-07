<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Format;

/**
 * Modèle MouvementCaisse - Mouvements de caisse (approvisionnement, retrait, ajustement)
 * 
 * Permet de tracer les entrées/sorties de fonds dans la caisse
 * indépendamment des bons de caisse (réapprovisionnement par la trésorerie, etc.)
 */
class MouvementCaisse extends Model
{
    use HasFactory;

    protected $table = 'mouvements_caisse';

    protected $fillable = [
        'reference',
        'type',
        'montant',
        'motif',
        'piece_justificative',
        'type_caisse',
        'site',
        'caisse_id',
        'statut',
        'effectue_par',
        'valide_par',
        'date_mouvement',
        'date_validation',
        'commentaire_validation',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_mouvement' => 'datetime',
            'date_validation' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /* ----------------------------------------------------------------
     * CONSTANTES
     * ---------------------------------------------------------------- */

    const TYPES = [
        'approvisionnement' => 'Approvisionnement',
        'retrait' => 'Retrait',
        'ajustement' => 'Ajustement',
    ];

    const STATUTS = [
        'en_attente' => 'En attente de validation',
        'valide' => 'Validé',
        'rejete' => 'Rejeté',
    ];

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    public function effectuePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'effectue_par');
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    public function scopeParSite($query, string $site)
    {
        return $query->where('site', $site);
    }

    public function scopeValides($query)
    {
        return $query->where('statut', 'valide');
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    public function getMontantFormatAttribute(): string
    {
        return Format::montant($this->montant);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getStatutLabelAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Génère une référence unique au format MVC-AAAA-NNNN
     */
    public static function genererReference(): string
    {
        $annee = now()->year;
        $prefixe = "MVC-{$annee}-";

        $dernier = static::where('reference', 'like', $prefixe . '%')
            ->orderBy('reference', 'desc')
            ->first();

        $numero = $dernier ? ((int) substr($dernier->reference, -4)) + 1 : 1;

        return $prefixe . str_pad($numero, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Valider ce mouvement et mettre à jour le solde du site
     */
    public function valider(User $valideur, ?string $commentaire = null): bool
    {
        if ($this->statut !== 'en_attente') {
            return false;
        }

        $this->update([
            'statut' => 'valide',
            'valide_par' => $valideur->id,
            'date_validation' => now(),
            'commentaire_validation' => $commentaire,
        ]);

        /* Inscription au registre de la caisse : retrait = sortie, approvisionnement et ajustement = entrée */
        if ($this->caisse) {
            $contexte = [
                'mouvement_caisse_id' => $this->id,
                'utilisateur_id' => $valideur->id,
                'libelle' => "{$this->type_label} {$this->reference} — {$this->motif}",
            ];
            $this->type === 'retrait'
                ? $this->caisse->debiter((float) $this->montant, 'retrait', $contexte)
                : $this->caisse->crediter((float) $this->montant, $this->type, $contexte);
        }

        return true;
    }

    /**
     * Rejeter ce mouvement
     */
    public function rejeter(User $valideur, ?string $commentaire = null): bool
    {
        if ($this->statut !== 'en_attente') {
            return false;
        }

        $this->update([
            'statut' => 'rejete',
            'valide_par' => $valideur->id,
            'date_validation' => now(),
            'commentaire_validation' => $commentaire,
        ]);

        return true;
    }
}
