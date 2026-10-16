<?php

namespace App\Models;

use App\Support\HeuresOuvrees;
use Carbon\CarbonInterface;
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
 * 4 - Directeur Pays (au-delà du seuil « seuil_validation_dp », 1 500 000 GNF par défaut)
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
        'version',
        'commentaire',
        'validateur_id',
        'au_titre_de_id',
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
        $heures = (float) Parametre::valeur('sla_' . $this->role, 4);

        /* RG-BC-05 : délais divisés par 2 pour un bon très urgent */
        return $this->bonCaisse?->niveau_urgence === 'tres_urgente' ? $heures / 2 : $heures;
    }

    /**
     * Échéance de l'étape (§6.7) : le délai est compté en heures ouvrées, du lundi au vendredi de 8 h à 17 h,
     * hors jours fériés. Un bon soumis le vendredi en fin de journée n'est donc pas « en retard » le samedi.
     */
    public function echeanceSla(float $multiplicateur = 1.0): ?CarbonInterface
    {
        return $this->date_attribution
            ? HeuresOuvrees::ajouter($this->date_attribution, $this->slaHeures() * $multiplicateur)
            : null;
    }

    /**
     * Vérifie si le SLA est dépassé
     */
    public function slaDepasse(): bool
    {
        if ($this->statut !== 'en_attente' || !$this->date_attribution) {
            return false;
        }

        return $this->echeanceSla()->isPast();
    }

    /**
     * Vérifie si l'escalade doit être déclenchée (2× SLA par défaut)
     */
    public function doitEscalader(): bool
    {
        if ($this->statut !== 'en_attente' || !$this->date_attribution || $this->escalade) {
            return false;
        }

        return $this->echeanceSla((float) Parametre::valeur('sla_multiplicateur_escalade', 2))->isPast();
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
            'au_titre_de_id' => $this->titulaireRemplacePar($validateur),
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
    /**
     * Titulaire au nom duquel valide un suppléant (délégation de validation) ; null si le valideur a lui-même le rôle.
     * Affiché sur la fiche : « X au titre de Y » (E-03.8).
     */
    private function titulaireRemplacePar(User $validateur): ?int
    {
        /* Circuit en cours : le suppléant vise au titre du titulaire de l'étape (chef de service du bon compris) */
        if ($this->bonCaisse && ($droit = \App\Services\BonCaisse\CircuitBon::peutViser($this->bonCaisse, $validateur))) {
            return $droit['au_titre_de']?->id;
        }
        if ($validateur->aLeRole($this->role)) {
            return null;
        }

        return Delegation::actives()->where('delegue_id', $validateur->id)->with('delegant')->get()
            ->first(fn (Delegation $d) => $d->autorise('validation') && $d->delegant?->aLeRole($this->role))
            ?->delegant_id;
    }

    public function auTitreDe(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'au_titre_de_id');
    }

    public function rejeter(User $validateur, string $commentaire): void
    {
        $this->update([
            'statut' => 'rejete',
            'validateur_id' => $validateur->id,
            'au_titre_de_id' => $this->titulaireRemplacePar($validateur),
            'commentaire' => $commentaire,
            'date_validation' => now(),
        ]);

        /* Rejeter le bon de caisse avec le commentaire */
        $this->bonCaisse->rejeter($commentaire, $validateur);
    }
}
