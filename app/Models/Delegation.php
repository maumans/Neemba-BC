<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle Delegation - Délégation de pouvoirs
 * 
 * Permet à un validateur de déléguer ses droits de validation
 * à un autre utilisateur pendant une période définie (absence, congé, etc.)
 */
class Delegation extends Model
{
    use HasFactory;

    protected $table = 'delegations';

    protected $fillable = [
        'delegant_id',
        'delegue_id',
        'date_debut',
        'date_fin',
        'motif',
        'fonctionnalites',
        'statut',
        'acceptee_le',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'acceptee_le' => 'datetime',
            'fonctionnalites' => 'array',
        ];
    }

    /* ----------------------------------------------------------------
     * CONSTANTES
     * ---------------------------------------------------------------- */

    const STATUTS = [
        'en_attente' => 'En attente d\'acceptation',
        'acceptee' => 'Acceptée',
        'refusee' => 'Refusée',
        'terminee' => 'Terminée',
    ];

    /**
     * Fonctionnalités délégables par rôle
     */
    const FONCTIONNALITES_PAR_ROLE = [
        'responsable_service' => ['validation', 'archivage'],
        'controle_gestion'    => ['validation'],
        'daf'                 => ['validation', 'mouvement_caisse', 'archivage'],
        'directeur_pays'      => ['validation', 'archivage'],
        'caissier'            => ['paiement', 'rapport_caisse', 'mouvement_caisse'],
        'demandeur'           => [],
        'administrateur'      => ['validation', 'archivage', 'mouvement_caisse'],
    ];

    /**
     * Labels lisibles pour les fonctionnalités
     */
    const FONCTIONNALITES_LABELS = [
        'validation'       => 'Validation des bons',
        'paiement'         => 'Paiement des bons',
        'rapport_caisse'   => 'Rapports de caisse',
        'mouvement_caisse' => 'Mouvements de caisse',
        'archivage'        => 'Archivage des bons',
    ];

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Utilisateur qui délègue (le titulaire)
     */
    public function delegant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegant_id');
    }

    /**
     * Utilisateur qui reçoit la délégation (le remplaçant)
     */
    public function delegue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_id');
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    /**
     * Délégations actives (acceptées et dans la période)
     */
    public function scopeActives($query)
    {
        return $query->where('statut', 'acceptee')
            ->where('date_debut', '<=', today())
            ->where('date_fin', '>=', today());
    }

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }

    public function scopePourDelegue($query, int $delegueId)
    {
        return $query->where('delegue_id', $delegueId);
    }

    public function scopePourDelegant($query, int $delegantId)
    {
        return $query->where('delegant_id', $delegantId);
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    public function getStatutLabelAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /**
     * Vérifie si la délégation est actuellement active
     */
    public function getEstActiveAttribute(): bool
    {
        return $this->statut === 'acceptee'
            && $this->date_debut->lte(today())
            && $this->date_fin->gte(today());
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Accepter la délégation
     */
    public function accepter(): void
    {
        $this->update([
            'statut' => 'acceptee',
            'acceptee_le' => now(),
        ]);
    }

    /**
     * Refuser la délégation
     */
    public function refuser(): void
    {
        $this->update(['statut' => 'refusee']);
    }

    /**
     * Terminer la délégation manuellement
     */
    public function terminer(): void
    {
        $this->update(['statut' => 'terminee']);
    }

    /**
     * Vérifie si un utilisateur a une délégation active pour un rôle donné
     * Supporte le filtrage par fonctionnalité spécifique
     */
    public static function delegationActivePour(int $delegueId, ?string $roleDelegant = null, ?string $fonctionnalite = null): ?self
    {
        $query = static::actives()->where('delegue_id', $delegueId);

        if ($roleDelegant) {
            $query->whereHas('delegant', function ($q) use ($roleDelegant) {
                $q->where('role', $roleDelegant);
            });
        }

        $delegation = $query->first();

        /* Si on filtre par fonctionnalité, vérifier que la délégation l'inclut */
        if ($delegation && $fonctionnalite && !$delegation->autorise($fonctionnalite)) {
            return null;
        }

        return $delegation;
    }

    /**
     * Vérifie si cette délégation autorise une fonctionnalité spécifique
     * Si fonctionnalites est null (ancien comportement), tout est autorisé
     */
    public function autorise(string $fonctionnalite): bool
    {
        /* null => toutes les fonctionnalités (rétrocompatibilité) */
        if ($this->fonctionnalites === null) {
            return true;
        }

        return in_array($fonctionnalite, $this->fonctionnalites);
    }

    /**
     * Récupérer les utilisateurs pour qui ce délégué a des délégations actives
     */
    public static function delegantsActifsPour(int $delegueId): \Illuminate\Support\Collection
    {
        return static::actives()
            ->where('delegue_id', $delegueId)
            ->with('delegant')
            ->get()
            ->pluck('delegant');
    }
}
