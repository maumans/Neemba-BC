<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Delegation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Modèle User - Utilisateur de l'application NEEMBA
 * 
 * Étend le modèle Breeze avec les champs métier nécessaires
 * à la gestion de caisse (rôle, service, site, etc.)
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Champs remplissables en masse
     * Inclut les champs Breeze + les champs métier NEEMBA
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'prenom',
        'email',
        'password',
        'matricule',
        'telephone',
        'role',
        'service',
        'site',
        'poste',
        'actif',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
        ];
    }

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Bons de caisse créés par cet utilisateur
     */
    public function bonsCaisse(): HasMany
    {
        return $this->hasMany(BonCaisse::class, 'demandeur_id');
    }

    /**
     * Validations effectuées par cet utilisateur
     */
    public function validations(): HasMany
    {
        return $this->hasMany(Validation::class, 'validateur_id');
    }

    /**
     * Rapports de caisse créés par cet utilisateur (caissier)
     */
    public function rapportsCaisse(): HasMany
    {
        return $this->hasMany(RapportCaisse::class, 'caissier_id');
    }

    /**
     * Délégations données par cet utilisateur (il est absent)
     */
    public function delegationsDonnees(): HasMany
    {
        return $this->hasMany(Delegation::class, 'delegant_id');
    }

    /**
     * Délégations reçues par cet utilisateur (il remplace quelqu'un)
     */
    public function delegationsRecues(): HasMany
    {
        return $this->hasMany(Delegation::class, 'delegue_id');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /**
     * Nom complet de l'utilisateur (Prénom + Nom)
     */
    public function getNomCompletAttribute(): string
    {
        return trim(($this->prenom ?? '') . ' ' . $this->name);
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    /**
     * Filtrer les utilisateurs actifs
     */
    public function scopeActifs($query)
    {
        return $query->where('actif', true);
    }

    /**
     * Filtrer par rôle
     */
    public function scopeParRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Filtrer par service
     */
    public function scopeParService($query, string $service)
    {
        return $query->where('service', $service);
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Vérifie si l'utilisateur a un rôle spécifique
     */
    public function aLeRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Vérifie si l'utilisateur peut valider des bons
     * (par son rôle propre OU via une délégation active)
     */
    public function peutValider(): bool
    {
        $rolesValidateurs = [
            'responsable_service',
            'controle_gestion',
            'daf',
            'directeur_pays',
        ];

        if (in_array($this->role, $rolesValidateurs)) {
            return true;
        }

        /* Vérifier les délégations actives avec la fonctionnalité 'validation' */
        return Delegation::actives()
            ->where('delegue_id', $this->id)
            ->whereHas('delegant', function ($q) use ($rolesValidateurs) {
                $q->whereIn('role', $rolesValidateurs);
            })
            ->get()
            ->contains(fn ($d) => $d->autorise('validation'));
    }

    /**
     * Récupère les rôles effectifs (propre rôle + tous les rôles délégués active)
     * Couvre aussi les rôles non-validateurs comme 'caissier'
     */
    public function rolesValidationEffectifs(): array
    {
        // Inclure d'abord le rôle propre de l'utilisateur
        $roles = [$this->role];

        // Ajouter les rôles de tous les délégants actifs ayant autorisé la 'validation'
        $delegations = \App\Models\Delegation::actives()
            ->where('delegue_id', $this->id)
            ->with('delegant')
            ->get();

        foreach ($delegations as $delegation) {
            if ($delegation->autorise('validation')) {
                $role = $delegation->delegant->role;
                if (!in_array($role, $roles)) {
                    $roles[] = $role;
                }
            }
        }

        return $roles;
    }

    /**
     * Vérifie si l'utilisateur a une délégation active pour un rôle donné
     */
    public function aDelegationPour(string $role): bool
    {
        return Delegation::actives()
            ->where('delegue_id', $this->id)
            ->whereHas('delegant', function ($q) use ($role) {
                $q->where('role', $role);
            })
            ->exists();
    }

    /**
     * Vérifie si l'utilisateur peut effectuer des paiements
     * (par son rôle propre OU via une délégation active d'un caissier)
     */
    public function peutPayer(): bool
    {
        if ($this->role === 'caissier') {
            return true;
        }

        /* Vérifier les délégations actives avec la fonctionnalité 'paiement' */
        return Delegation::actives()
            ->where('delegue_id', $this->id)
            ->whereHas('delegant', function ($q) {
                $q->where('role', 'caissier');
            })
            ->get()
            ->contains(fn ($d) => $d->autorise('paiement'));
    }

    /**
     * Vérifie si l'utilisateur peut effectuer une fonctionnalité spécifique
     * (par son rôle propre OU via une délégation active)
     */
    public function peutEffectuer(string $fonctionnalite): bool
    {
        /* Vérifier si le rôle propre inclut cette fonctionnalité */
        $fonctionnalitesRole = Delegation::FONCTIONNALITES_PAR_ROLE[$this->role] ?? [];
        if (in_array($fonctionnalite, $fonctionnalitesRole)) {
            return true;
        }

        /* Vérifier les délégations actives */
        return Delegation::actives()
            ->where('delegue_id', $this->id)
            ->get()
            ->contains(fn ($d) => $d->autorise($fonctionnalite));
    }

    /**
     * Vérifie si l'utilisateur est administrateur
     */
    public function estAdministrateur(): bool
    {
        return $this->role === 'administrateur';
    }

    /**
     * Vérifie si l'utilisateur est un caissier du site donné
     */
    public function estCaissierDuSite(string $site): bool
    {
        return $this->role === 'caissier' && $this->site === $site;
    }
}
