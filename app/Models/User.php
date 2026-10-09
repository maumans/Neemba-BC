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
        'numero_om',
        'role',
        'service',
        'site',
        'poste',
        'actif',
        'entite',
        'statut_cadre',
        'responsable_id',
    ];

    /**
     * Rôles de la plateforme. Les rôles « déclarés » (décision Q5) s'attribuent dès maintenant, à partir du
     * référentiel de Neemba ; leurs droits arrivent avec leur module (Finance élargie M04, Trésorerie M07-M08, RH M09).
     */
    public const ROLES = [
        'demandeur' => 'Demandeur',
        'responsable_service' => 'Chef de service',
        'controle_gestion' => 'Contrôle de gestion',
        'daf' => 'DAF',
        'directeur_pays' => 'Directeur Pays',
        'caissier' => 'Caissier',
        'administrateur' => 'Administrateur',
        'daf_adjoint' => 'DAF adjoint',
        'chef_comptable' => 'Chef comptable',
        'tresorerie' => 'Trésorerie',
        'rh' => 'Ressources humaines',
        /* Module M12 « Ordres de mission » (spec v2.2) : visa de l'ODM par le chef d'atelier ou chef d'équipe, puis le DAF,
         * puis le DP ou son adjoint ; la logistique fixera les avances carburant de mission (M13) */
        'chef_atelier' => "Chef d'atelier / chef d'équipe",
        'dp_adjoint' => 'DP adjoint',
        'logistique' => 'Logistique',
    ];

    public const ROLES_DECLARES = ['daf_adjoint', 'chef_comptable', 'tresorerie', 'rh', 'logistique'];

    /** Rôle principal proposé à l'écran Utilisateurs ; les autres rôles s'ajoutent en rôles complémentaires */
    public const ROLES_PRINCIPAUX = ['demandeur', 'responsable_service', 'controle_gestion', 'daf', 'directeur_pays', 'caissier', 'administrateur'];

    /** RG-M02-05 : n° Orange Money au format guinéen, 9 chiffres commençant par 6 */
    public const FORMAT_NUMERO_OM = '/^6\d{8}$/';

    /** N° Orange Money saisi avec espaces, indicatif +224 ou 00224 : ramené à 9 chiffres */
    public static function normaliserNumeroOm(?string $numero): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $numero);
        if ($chiffres === '') {
            return null;
        }
        if (strlen($chiffres) === 14 && str_starts_with($chiffres, '00224')) {
            $chiffres = substr($chiffres, 5);
        } elseif (strlen($chiffres) === 12 && str_starts_with($chiffres, '224')) {
            $chiffres = substr($chiffres, 3);
        }

        return $chiffres;
    }

    public function responsable(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'responsable_id');
    }

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
    /**
     * Rôles attribués (un utilisateur peut en avoir plusieurs ; le rôle principal reste dans users.role)
     */
    public function roles(): HasMany
    {
        return $this->hasMany(RoleUtilisateur::class, 'user_id');
    }

    protected static function booted(): void
    {
        /* Le rôle principal figure toujours parmi les rôles. Un nouveau compte reçoit aussi « demandeur » :
         * tout le monde peut créer un bon tant que l'administration des rôles (M02) n'existe pas. */
        static::created(function (User $utilisateur) {
            $utilisateur->ajouterRoles([$utilisateur->role, 'demandeur']);
        });

        /* « updated » (et non « saved ») : wasRecentlyCreated reste vrai sur l'instance créée */
        static::updated(function (User $utilisateur) {
            if (!$utilisateur->wasChanged('role')) {
                return;
            }
            $ancien = $utilisateur->getOriginal('role');
            if ($ancien && $ancien !== 'demandeur') {
                $utilisateur->roles()->where('role', $ancien)->delete();
            }
            $utilisateur->ajouterRoles([$utilisateur->role]);
        });
    }

    /**
     * Attribuer des rôles (ceux déjà présents sont ignorés)
     */
    public function ajouterRoles(array $roles): void
    {
        foreach (array_unique(array_filter($roles)) as $role) {
            $this->roles()->firstOrCreate(['role' => $role]);
        }
        $this->rolesCharges = null;
    }

    /**
     * Remplacer tous les rôles par la liste donnée
     */
    public function definirRoles(array $roles): void
    {
        $roles = array_values(array_unique(array_filter($roles)));
        $this->roles()->whereNotIn('role', $roles)->delete();
        $this->ajouterRoles($roles);
    }

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
    public function aLeRole(string|array $roles): bool
    {
        return !empty(array_intersect($this->listeRoles(), (array) $roles));
    }

    /** Rôles chargés (cache de l'instance) */
    protected ?array $rolesCharges = null;

    /**
     * Rôles de l'utilisateur : rôle principal + rôles attribués
     */
    public function listeRoles(): array
    {
        if ($this->rolesCharges === null) {
            $attribues = $this->exists ? $this->roles()->pluck('role')->all() : [];
            $this->rolesCharges = array_values(array_unique(array_filter([$this->role, ...$attribues])));
        }

        return $this->rolesCharges;
    }

    /**
     * Peut créer un bon de caisse (rôle DEMANDEUR — US-BC-01, TC-BC-032)
     */
    public function peutInitierBon(): bool
    {
        /* Rôle demandeur, ou délégation d'initiation active d'un collègue (US-BC-13) */
        return $this->aLeRole('demandeur') || Delegation::initiationsActivesPour($this->id)->isNotEmpty();
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

        if ($this->aLeRole($rolesValidateurs)) {
            return true;
        }

        /* Vérifier les délégations actives avec la fonctionnalité 'validation' */
        return Delegation::actives()
            ->where('delegue_id', $this->id)
            ->with('delegant')
            ->get()
            ->contains(fn ($d) => $d->autorise('validation') && $d->delegant?->aLeRole($rolesValidateurs));
    }

    /**
     * Récupère les rôles effectifs (propre rôle + tous les rôles délégués active)
     * Couvre aussi les rôles non-validateurs comme 'caissier'
     */
    public function rolesValidationEffectifs(): array
    {
        // Inclure d'abord les rôles propres de l'utilisateur
        $roles = $this->listeRoles();

        // Ajouter les rôles de tous les délégants actifs ayant autorisé la 'validation'
        $delegations = \App\Models\Delegation::actives()
            ->where('delegue_id', $this->id)
            ->with('delegant')
            ->get();

        foreach ($delegations as $delegation) {
            /* Seuls les rôles de validation du titulaire passent au suppléant (principal ou complémentaires) */
            if ($delegation->autorise('validation') && $delegation->delegant) {
                $rolesTitulaire = array_intersect($delegation->delegant->listeRoles(), ['responsable_service', 'controle_gestion', 'daf', 'directeur_pays']);
                $roles = array_values(array_unique(array_merge($roles, $rolesTitulaire)));
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
        if ($this->aLeRole('caissier')) {
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
        foreach ($this->listeRoles() as $role) {
            if (in_array($fonctionnalite, Delegation::FONCTIONNALITES_PAR_ROLE[$role] ?? [])) {
                return true;
            }
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
        return $this->aLeRole('administrateur');
    }

    /**
     * Vérifie si l'utilisateur est un caissier du site donné
     */
    public function estCaissierDuSite(string $site): bool
    {
        return $this->aLeRole('caissier') && $this->site === $site;
    }
}
