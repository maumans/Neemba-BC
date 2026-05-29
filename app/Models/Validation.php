<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle Validation - Étape de validation d'un bon de caisse
 * 
 * Chaque bon de caisse passe par plusieurs niveaux de validation.
 * Ce modèle enregistre chaque étape avec son résultat et son horodatage.
 * 
 * Niveaux :
 * 1 - Responsable Service
 * 2 - Contrôle de Gestion
 * 3 - DAF
 * 4 - Directeur Pays (si montant >= 5 000 000 GNF)
 */
class Validation extends Model
{
    use HasFactory;

    protected $table = 'validations';

    protected $fillable = [
        'bon_caisse_id',
        'niveau',
        'role',
        'statut',
        'commentaire',
        'validateur_id',
        'date_validation',
        'date_attribution',
        'date_relance',
        'nb_relances',
        'escalade',
        'date_escalade',
    ];

    protected function casts(): array
    {
        return [
            'date_validation' => 'datetime',
            'date_attribution' => 'datetime',
            'date_relance' => 'datetime',
            'date_escalade' => 'datetime',
            'escalade' => 'boolean',
        ];
    }

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Bon de caisse concerné par cette validation
     */
    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    /**
     * Utilisateur qui a effectué la validation
     */
    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validateur_id');
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    /**
     * Validations en attente
     */
    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }

    /**
     * Validations approuvées
     */
    public function scopeApprouvees($query)
    {
        return $query->where('statut', 'approuve');
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /* ----------------------------------------------------------------
     * MÉTHODES SLA
     * ---------------------------------------------------------------- */

    /**
     * Récupérer le SLA en heures pour ce niveau de validation
     */
    public function slaHeures(): float
    {
        return (float) Parametre::valeur('sla_' . $this->role, 4);
    }

    /**
     * Vérifie si le SLA est dépassé
     */
    public function slaDepasse(): bool
    {
        if ($this->statut !== 'en_attente' || !$this->date_attribution) {
            return false;
        }
        return $this->date_attribution->addHours($this->slaHeures())->isPast();
    }

    /**
     * Vérifie si l'escalade doit être déclenchée (2× SLA par défaut)
     */
    public function doitEscalader(): bool
    {
        if ($this->statut !== 'en_attente' || !$this->date_attribution || $this->escalade) {
            return false;
        }
        $multiplicateur = (float) Parametre::valeur('sla_multiplicateur_escalade', 2);
        return $this->date_attribution->addHours($this->slaHeures() * $multiplicateur)->isPast();
    }

    /**
     * Enregistrer une relance
     */
    public function enregistrerRelance(): void
    {
        $this->update([
            'date_relance' => now(),
            'nb_relances' => $this->nb_relances + 1,
        ]);
    }

    /**
     * Marquer comme escaladé
     */
    public function marquerEscalade(): void
    {
        $this->update([
            'escalade' => true,
            'date_escalade' => now(),
        ]);
    }

    /**
     * Approuver cette étape de validation
     * 
     * @param User $validateur L'utilisateur qui approuve
     * @param string|null $commentaire Commentaire optionnel
     */
    public function approuver(User $validateur, ?string $commentaire = null): void
    {
        $this->update([
            'statut' => 'approuve',
            'validateur_id' => $validateur->id,
            'commentaire' => $commentaire,
            'date_validation' => now(),
        ]);

        /* Passer le bon au niveau de validation suivant */
        $this->bonCaisse->passerAuNiveauSuivant($validateur, $commentaire);
    }

    /**
     * Rejeter cette étape de validation
     * 
     * @param User $validateur L'utilisateur qui rejette
     * @param string $commentaire Motif du rejet (obligatoire)
     */
    public function rejeter(User $validateur, string $commentaire): void
    {
        $this->update([
            'statut' => 'rejete',
            'validateur_id' => $validateur->id,
            'commentaire' => $commentaire,
            'date_validation' => now(),
        ]);

        /* Rejeter le bon de caisse avec le commentaire */
        $this->bonCaisse->rejeter($commentaire, $validateur);
    }
}
