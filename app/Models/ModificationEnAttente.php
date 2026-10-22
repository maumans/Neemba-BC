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

    /**
     * Demande du 22/10 (Q107) : une modification qui attend la double validation prévient tout de suite les autres
     * administrateurs (avant, rien ne la signalait, et un rôle demandé restait inactif sans que personne le sache).
     */
    protected static function booted(): void
    {
        static::created(function (self $m) {
            if ($m->statut === 'en_attente') {
                $m->prevenirValideurs();
            }
        });
    }

    /** Administrateurs actifs qui peuvent valider (tous sauf l'auteur de la demande) */
    public static function valideursPossibles(?int $demandeurId): \Illuminate\Support\Collection
    {
        return User::actifs()->avecRoleDans(['administrateur'])->where('id', '!=', (int) $demandeurId)->orderBy('name')->get();
    }

    /** Description lisible : « Rôles de Thierno DIALLO : Demandeur, Gestionnaire des référentiels » */
    public function description(): string
    {
        $libelle = self::TYPES_CRITIQUES[$this->type_entite] ?? $this->type_entite;
        if (in_array($this->type_entite, ['utilisateur_role', 'utilisateur_roles'], true)) {
            $compte = User::find($this->entite_id);
            $roles = collect(explode(',', (string) $this->nouvelle_valeur))->map(fn ($r) => trim($r))->filter()
                ->map(fn ($r) => User::ROLES[$r] ?? $r)->implode(', ');

            return ($this->type_entite === 'utilisateur_role' ? 'Rôle principal de ' : 'Rôles de ')
                . ($compte?->nom_complet ?? "compte n° {$this->entite_id}") . ' : ' . ($roles !== '' ? $roles : 'aucun rôle complémentaire');
        }

        return "{$libelle} : {$this->champ} → " . ($this->nouvelle_valeur === null || $this->nouvelle_valeur === '' ? 'vide' : $this->nouvelle_valeur);
    }

    public function prevenirValideurs(): void
    {
        $auteur = $this->demandeur_id ? User::find($this->demandeur_id) : null;
        foreach (self::valideursPossibles($this->demandeur_id) as $admin) {
            \App\Services\NotificationService::creerEtDiffuserSimple($admin, $auteur, 'modification_a_valider', 'Modification à valider',
                ($auteur?->nom_complet ?? 'Un administrateur') . ' demande : ' . $this->description() . '. Elle attend votre validation.',
                ['lien' => route('admin.modifications-en-attente.index'), 'modification_id' => $this->id]);
        }
    }

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
        'utilisateur_roles' => 'Rôles complémentaires d\'un utilisateur',
        'site_caisse' => 'Modification caisse site',
        'caisse' => 'Caisse : plafond, seuil, avance ou solde',
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
            } elseif ($this->type_entite === 'caisse') {
                $caisse = Caisse::find($this->entite_id);
                if ($caisse && $this->champ === 'solde') {
                    /* Le solde n'est jamais écrasé : l'écart avec le solde actuel est inscrit au registre */
                    $ecart = (float) $this->nouvelle_valeur - (float) $caisse->solde;
                    $contexte = [
                        'utilisateur_id' => $valideur->id,
                        'libelle' => "Correction du solde approuvée par {$valideur->nom_complet} (demande #{$this->id})",
                    ];
                    if ($ecart > 0) {
                        $caisse->crediter($ecart, 'correction_solde', $contexte);
                    } elseif ($ecart < 0) {
                        $caisse->debiter(-$ecart, 'correction_solde', $contexte);
                    }
                } elseif ($caisse && in_array($this->champ, Caisse::CHAMPS_SENSIBLES, true)) {
                    $caisse->update([$this->champ => $this->nouvelle_valeur === '' ? null : $this->nouvelle_valeur]);
                }
            } elseif ($this->type_entite === 'utilisateur_roles') {
                /* Le rôle principal est conservé ; les rôles complémentaires deviennent ceux de la demande */
                $user = User::find($this->entite_id);
                if ($user) {
                    $complementaires = array_filter(array_map('trim', explode(',', (string) $this->nouvelle_valeur)));
                    $user->definirRoles([$user->role, ...$complementaires]);
                }
            } elseif ($this->type_entite === 'utilisateur_role') {
                $user = User::find($this->entite_id);
                if ($user) {
                    $champ = $this->champ;
                    $user->$champ = $this->nouvelle_valeur;
                    $user->save();
                    /* Lot T : rôles complémentaires demandés dans le même formulaire et déjà validés : ils s'appliquent au
                     * nouveau rôle principal (l'ancien rôle principal ne reste que s'il y figure). Encore en attente : ils
                     * s'appliqueront à leur validation. */
                    $memeDemande = static::where('type_entite', 'utilisateur_roles')->where('entite_id', $user->id)->where('statut', 'approuvee')
                        ->where('demandeur_id', $this->demandeur_id)
                        ->whereBetween('created_at', [$this->created_at->copy()->subSeconds(5), $this->created_at->copy()->addSeconds(5)])
                        ->latest('id')->first();
                    if ($memeDemande) {
                        $complementaires = array_filter(array_map('trim', explode(',', (string) $memeDemande->nouvelle_valeur)));
                        $user->definirRoles([$user->role, ...$complementaires]);
                    }
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
