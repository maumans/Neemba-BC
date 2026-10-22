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
        'entite_id',
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
        'caisse_id',
        'poste',
        'actif',
        'entite',
        'statut_cadre',
        'responsable_id',
        'mot_de_passe_a_changer',
    ];

    /** Mot de passe initial des comptes importés ou créés (Q108) : à changer à la première connexion */
    public const MOT_DE_PASSE_INITIAL = 'Neemba@2026';

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
        /* M16 (spec v2.3, chapitre 3) : la Comptabilité impute les bons payés */
        'comptable' => 'Comptabilité',
        /* Spec v2.4, RG-M01-09 : tient les référentiels métier (barèmes ODM, clients, types d'OR, codes analytiques,
         * services, motifs), sans accès aux comptes ni aux droits */
        'gestionnaire_referentiels' => 'Gestionnaire des référentiels',
        /* Spec v2.4, RG-M08-07 (Q89) : deux membres du CODIR approuvent ensemble un réapprovisionnement en l'absence
         * du DP et de son adjoint */
        'membre_codir' => 'Membre du CODIR',
        /* Spec v2.4, chapitre 3 : « Caissier / assistant trésorier », mêmes droits que le caissier (Q75) */
        'assistant_tresorier' => 'Assistant trésorier',
    ];

    public const ROLES_DECLARES = ['daf_adjoint', 'chef_comptable', 'tresorerie', 'rh', 'logistique', 'comptable'];

    /** Rôle principal proposé à l'écran Utilisateurs ; les autres rôles s'ajoutent en rôles complémentaires */
    /**
     * Spec v2.3 §8.2 (Annexe 1) et RG-M05-04 : rôles qui tiennent chaque étape du circuit des bons.
     * Finance : DAF, DAF adjoint ou Chef comptable ; Directeur Pays : DP ou DP adjoint.
     */
    public const ROLES_ETAPES_BON = [
        'responsable_service' => ['responsable_service'],
        'controle_gestion' => ['controle_gestion'],
        'daf' => ['daf', 'daf_adjoint', 'chef_comptable'],
        'directeur_pays' => ['directeur_pays', 'dp_adjoint'],
    ];

    /** Étapes du circuit des bons tenues par ces rôles */
    public static function etapesBon(array $roles): array
    {
        return array_keys(array_filter(self::ROLES_ETAPES_BON, fn (array $tenants) => array_intersect($tenants, $roles)));
    }

    /**
     * Rôles qu'on peut choisir comme rôle principal (celui qu'affichent les écrans). Seuls le gestionnaire des référentiels
     * et le membre du CODIR, qui s'ajoutent à un métier, restent des rôles complémentaires (Q103).
     */
    public const ROLES_PRINCIPAUX = [
        'demandeur', 'responsable_service', 'controle_gestion', 'daf', 'daf_adjoint', 'chef_comptable', 'comptable', 'tresorerie',
        'directeur_pays', 'dp_adjoint', 'caissier', 'assistant_tresorier', 'chef_atelier', 'rh', 'logistique', 'administrateur',
    ];

    /** Rôle principal retenu quand un compte en a plusieurs (import des référentiels, reprise du lot T) */
    public const PRIORITE_PRINCIPAL = [
        'administrateur', 'directeur_pays', 'dp_adjoint', 'daf', 'daf_adjoint', 'chef_comptable', 'controle_gestion', 'comptable',
        'responsable_service', 'assistant_tresorier', 'caissier', 'tresorerie', 'chef_atelier', 'rh', 'logistique', 'demandeur',
    ];

    /** Rôles qui donnent les droits d'un autre rôle (l'assistant trésorier a ceux du caissier) */
    public const ROLES_EQUIVALENTS = ['assistant_tresorier' => ['caissier']];

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
            'mot_de_passe_a_changer' => 'boolean',
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
    /** Spec v2.3, RG-M02-03 : caisse de rattachement */
    public function caisse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Caisse::class, 'caisse_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(RoleUtilisateur::class, 'user_id');
    }

    protected static function booted(): void
    {
        /* Spec v2.4, RG-M01-02 : entité du compte. Le champ texte « entite » (import des référentiels) désigne l'entité par
         * son nom ou son code ; à défaut, Neemba Guinée */
        static::saving(function (User $utilisateur) {
            if ($utilisateur->isDirty('entite') && filled($utilisateur->entite) && !$utilisateur->isDirty('entite_id')) {
                $libelle = trim((string) $utilisateur->entite);
                $trouvee = Entite::where('code', $libelle)->orWhere('nom', $libelle)->value('id');
                $utilisateur->entite_id = $trouvee ?? $utilisateur->entite_id;
            }
            $utilisateur->entite_id ??= Entite::idParDefaut();
        });

        /* Le rôle principal figure toujours parmi les rôles. Un nouveau compte reçoit aussi « demandeur » :
         * tout le monde peut créer un bon tant que l'administration des rôles (M02) n'existe pas. */
        static::created(function (User $utilisateur) {
            $utilisateur->ajouterRoles([$utilisateur->role, 'demandeur']);
        });

        /* « updated » (et non « saved ») : wasRecentlyCreated reste vrai sur l'instance créée. Lot T : l'ancien rôle principal
         * n'est plus retiré d'office (il reste un rôle de la personne) ; on le retire en décochant le rôle complémentaire. */
        static::updated(function (User $utilisateur) {
            if ($utilisateur->wasChanged('role')) {
                $utilisateur->ajouterRoles([$utilisateur->role]);
            }
        });
    }

    /**
     * Attribuer des rôles (ceux déjà présents sont ignorés). RG-M01-08 : un rôle vaut dans une entité, par défaut celle du
     * compte.
     */
    public function ajouterRoles(array $roles, ?int $entite = null): void
    {
        $entite ??= $this->entite_id ?? Entite::idParDefaut();
        foreach (array_unique(array_filter($roles)) as $role) {
            $this->roles()->firstOrCreate(['role' => $role, 'entite_id' => $entite]);
        }
        $this->rolesCharges = null;
    }

    /**
     * Spec v2.4, RG-M01-02 : entité du compte. Attention : `$user->entite` lit le champ texte « entite » (import des
     * référentiels) ; l'entité se lit par `$user->entite()->first()` ou `entite_id`.
     */
    public function entite(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    /**
     * RG-M01-08 : comptes qui ont l'un des rôles dans l'entité donnée (rôle principal dans l'entité du compte ; rôle
     * attribué dans cette entité ou sans entité). Sans entité : tous les comptes qui ont le rôle.
     */
    public function scopeAvecRoleDans($query, array $roles, ?int $entite = null)
    {
        /* L'assistant trésorier compte comme caissier */
        foreach (self::ROLES_EQUIVALENTS as $role => $equivalents) {
            if (array_intersect($equivalents, $roles)) {
                $roles[] = $role;
            }
        }

        return $query->where(fn ($q) => $q
            ->where(fn ($p) => $p->whereIn('role', $roles)->when($entite, fn ($e) => $e->where('entite_id', $entite)))
            ->orWhereHas('roles', fn ($r) => $r->whereIn('role', $roles)
                ->when($entite, fn ($e) => $e->where(fn ($w) => $w->whereNull('entite_id')->orWhere('entite_id', $entite)))));
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
        /* Lot T : rôle principal ou complémentaire */
        return $query->avecRoleDans([$role]);
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

    /** Rôles chargés (cache de l'instance), pour l'entité de la clé */
    protected ?array $rolesCharges = null;
    protected ?string $rolesChargesCle = null;

    /**
     * Rôles de l'utilisateur : rôle principal + rôles attribués. RG-M01-08 : pour l'utilisateur connecté, seuls comptent
     * les rôles de son entité courante (le rôle principal vaut dans l'entité du compte) ; en vue consolidée, hors session
     * ou pour un autre compte, tous ses rôles.
     */
    public function listeRoles(): array
    {
        $entite = $this->exists && \Illuminate\Support\Facades\Auth::id() === $this->id ? \App\Support\EntiteCourante::filtre() : null;
        if ($this->rolesCharges === null || $this->rolesChargesCle !== (string) $entite) {
            $this->rolesChargesCle = (string) $entite;
            $attribues = $this->exists
                ? $this->roles()->when($entite, fn ($q) => $q->where(fn ($w) => $w->whereNull('entite_id')->orWhere('entite_id', $entite)))->pluck('role')->all()
                : [];
            $principal = $entite === null || $entite === $this->entite_id ? $this->role : null;
            $roles = array_values(array_unique(array_filter([$principal, ...$attribues])));
            foreach (self::ROLES_EQUIVALENTS as $role => $equivalents) {
                if (in_array($role, $roles, true)) {
                    $roles = array_values(array_unique([...$roles, ...$equivalents]));
                }
            }
            $this->rolesCharges = $roles;
        }

        return $this->rolesCharges;
    }

    /**
     * Lot T : libellés des rôles du compte, rôle principal d'abord ; « Demandeur » n'est montré que s'il est le seul rôle
     * (tout le monde l'a). Rôles enregistrés, toutes entités.
     *
     * @return string[]
     */
    public static function libellesRoles(self $u): array
    {
        $roles = array_values(array_unique(array_filter([$u->role, ...$u->roles->pluck('role')->all()])));
        if (count($roles) > 1) {
            $roles = array_values(array_diff($roles, ['demandeur'])) ?: ['demandeur'];
        }

        return array_map(fn (string $r) => self::ROLES[$r] ?? $r, $roles);
    }

    /** Tous les rôles du compte, toutes entités confondues (vue consolidée, sélecteur d'entité) */
    public function listeRolesToutesEntites(): array
    {
        $attribues = $this->exists ? $this->roles()->pluck('role')->all() : [];

        return array_values(array_unique(array_filter([$this->role, ...$attribues])));
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
        $rolesValidateurs = array_merge(...array_values(self::ROLES_ETAPES_BON));

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
        // Inclure d'abord les rôles propres de l'utilisateur, et l'étape du circuit que tient un adjoint
        $roles = array_values(array_unique(array_merge($this->listeRoles(), self::etapesBon($this->listeRoles()))));

        // Ajouter les rôles de tous les délégants actifs ayant autorisé la 'validation'
        $delegations = \App\Models\Delegation::actives()
            ->where('delegue_id', $this->id)
            ->with('delegant')
            ->get();

        foreach ($delegations as $delegation) {
            /* Seuls les rôles de validation du titulaire passent au suppléant (principal ou complémentaires) */
            if ($delegation->autorise('validation') && $delegation->delegant) {
                $rolesTitulaire = self::etapesBon($delegation->delegant->listeRoles());
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
            ->whereHas('delegant', fn ($q) => $q->avecRoleDans([$role]))
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
            ->whereHas('delegant', fn ($q) => $q->avecRoleDans(['caissier']))
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
