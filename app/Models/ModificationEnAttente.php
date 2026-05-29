<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle ModificationEnAttente - Double validation des modifications admin
 * 
 * Lorsqu'un administrateur modifie un paramètre critique (seuil, code analytique,
 * utilisateur), la modification est mise en attente de confirmation par un second admin/DAF.
 */
class ModificationEnAttente extends Model
{
    use HasFactory;

    protected $table = 'modifications_en_attente';

    protected $fillable = [
        'type_entite',
        'entite_id',
        'champ',
        'ancienne_valeur',
        'nouvelle_valeur',
        'demandeur_id',
        'valideur_id',
        'statut',
        'commentaire',
        'date_validation',
    ];

    protected function casts(): array
    {
        return [
            'date_validation' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------
     * CONSTANTES
     * ---------------------------------------------------------------- */

    const STATUTS = [
        'en_attente' => 'En attente de validation',
        'approuvee' => 'Approuvée',
        'refusee' => 'Refusée',
    ];

    /** Types d'entités nécessitant une double validation */
    const TYPES_CRITIQUES = [
        'parametre' => 'Paramètre système',
        'utilisateur_role' => 'Changement de rôle utilisateur',
        'site_caisse' => 'Modification caisse site',
        'code_analytique' => 'Code analytique',
    ];

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valideur_id');
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    public function scopeEnAttente($query)
    {
        return $query->where('statut', 'en_attente');
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    public function approuver(User $valideur, ?string $commentaire = null): void
    {
        if ($this->statut === 'en_attente') {
            // Appliquer physiquement la modification en BDD
            if ($this->type_entite === 'parametre') {
                $parametre = Parametre::find($this->entite_id);
                if ($parametre) {
                    Parametre::majValeur($parametre->cle, $this->nouvelle_valeur);
                }
            } elseif ($this->type_entite === 'site_caisse') {
                $site = Site::find($this->entite_id);
                if ($site) {
                    $champ = $this->champ;
                    $site->$champ = $this->nouvelle_valeur;
                    $site->save();
                }
            } elseif ($this->type_entite === 'utilisateur_role') {
                $user = User::find($this->entite_id);
                if ($user) {
                    $champ = $this->champ;
                    $user->$champ = $this->nouvelle_valeur;
                    $user->save();
                }
            }

            $this->update([
                'statut' => 'approuvee',
                'valideur_id' => $valideur->id,
                'commentaire' => $commentaire,
                'date_validation' => now(),
            ]);
        }
    }

    public function refuser(User $valideur, ?string $commentaire = null): void
    {
        $this->update([
            'statut' => 'refusee',
            'valideur_id' => $valideur->id,
            'commentaire' => $commentaire,
            'date_validation' => now(),
        ]);
    }

    public function getStatutLabelAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getTypeEntiteLabelAttribute(): string
    {
        return self::TYPES_CRITIQUES[$this->type_entite] ?? $this->type_entite;
    }
}
