<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

/**
 * Contrôleur de Gestion des Utilisateurs
 * 
 * Permet aux administrateurs (DAF ou Directeur Pays) de gérer
 * les comptes utilisateurs de l'application.
 */
class UtilisateurController extends Controller
{
    private const MESSAGES = [
        /* RG-M02-05 (spec v2.2) */
        'numero_om.regex' => 'Le n° Orange Money est au format guinéen (9 chiffres commençant par 6).',
    ];

    /**
     * Rôles enregistrés en plus du rôle principal donné (par défaut le rôle principal actuel), triés. Lot T : on lit les
     * rôles enregistrés, toutes entités, et non les droits déduits (l'assistant trésorier n'a pas de rôle caissier à cocher).
     */
    private static function rolesComplementaires(User $utilisateur, ?string $principal = null): array
    {
        $roles = array_values(array_unique(array_diff($utilisateur->roles()->pluck('role')->all(), [$principal ?? $utilisateur->role])));
        sort($roles);

        return $roles;
    }

    /** Lot T : rôles qu'on peut choisir comme rôle principal, avec leur libellé */
    private static function rolesPrincipaux(): array
    {
        return array_intersect_key(User::ROLES, array_flip(User::ROLES_PRINCIPAUX));
    }

    /**
     * Liste des utilisateurs
     */
    public function index(Request $request)
    {
        $query = User::query()->with('roles')->latest();

        /* Recherche par nom, prénom ou matricule */
        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->where(function ($q) use ($recherche) {
                $q->where('name', 'like', "%{$recherche}%")
                    ->orWhere('prenom', 'like', "%{$recherche}%")
                    ->orWhere('matricule', 'like', "%{$recherche}%")
                    ->orWhere('email', 'like', "%{$recherche}%");
            });
        }

        /* Filtrage par rôle */
        if ($request->filled('role')) {
            $query->parRole($request->role);
        }

        /* Filtrage par site */
        if ($request->filled('site')) {
            $query->where('site', $request->site);
        }

        /* Lot T : chaque compte affiche tous ses rôles (rôle principal d'abord, « demandeur » seulement s'il est seul) */
        $utilisateurs = $query->paginate(15)->withQueryString()->through(function (User $u) {
            $u->setAttribute('roles_affiches', User::libellesRoles($u));
            $u->setRelation('roles', collect());

            return $u;
        });

        return Inertia::render('Utilisateurs/Index', [
            'utilisateurs' => $utilisateurs,
            'filtres' => $request->only(['recherche', 'role', 'site']),
            /* Le filtre cherche le rôle principal et les rôles complémentaires */
            'roles' => User::ROLES,
        ]);
    }

    /**
     * Formulaire de création d'un utilisateur
     */
    public function create()
    {
        return Inertia::render('Utilisateurs/Create', [
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::actifs()->orderBy('nom')->pluck('nom'),
            'caisses' => self::caisses(),
            'entites' => \App\Models\Entite::actives()->orderBy('id')->get(['id', 'code', 'nom']),
            'rolesPrincipaux' => self::rolesPrincipaux(),
        ]);
    }

    /**
     * Enregistrer un nouvel utilisateur
     */
    public function store(Request $request)
    {
        $request->merge(['numero_om' => User::normaliserNumeroOm($request->numero_om)]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', Rules\Password::defaults()],
            'matricule' => ['required', 'string', 'unique:users'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'numero_om' => ['nullable', 'regex:' . User::FORMAT_NUMERO_OM],
            'statut_cadre' => ['nullable', Rule::in(['cadre', 'non_cadre'])],
            'role' => ['required', Rule::in(User::ROLES_PRINCIPAUX)],
            'service' => ['required', 'string', 'max:255'],
            'site' => ['required', 'string', 'max:255'],
            /* Spec v2.3, RG-M02-03 : caisse de rattachement (vide : caisse principale du site) */
            'caisse_id' => ['nullable', 'integer', Rule::exists('caisses', 'id')],
            /* Spec v2.4, RG-M01-02 : entité du compte (entités actives) */
            'entite_id' => ['nullable', 'integer', Rule::exists('entites', 'id')->where('actif', true)],
            'poste' => ['nullable', 'string', 'max:255'],
        ], self::MESSAGES);

        $validated['password'] = Hash::make($validated['password']);
        $validated['actif'] = true;

        User::create($validated);

        return redirect()
            ->route('utilisateurs.index')
            ->with('success', 'Utilisateur créé avec succès.');
    }

    /** Caisses proposées comme caisse de rattachement (RG-M02-03) */
    private static function caisses()
    {
        return \App\Models\Caisse::actives()->with('sites')->orderBy('libelle')->get()
            ->map(fn (\App\Models\Caisse $c) => ['id' => $c->id, 'libelle' => $c->libelle, 'code' => $c->codeNumerotation(), 'site' => $c->sites_libelle]);
    }

    /**
     * Formulaire d'édition d'un utilisateur
     */
    public function edit(User $utilisateur)
    {
        return Inertia::render('Utilisateurs/Edit', [
            'utilisateur' => $utilisateur,
            'rolesComplementaires' => self::rolesComplementaires($utilisateur),
            'roles' => User::ROLES,
            'rolesPrincipaux' => self::rolesPrincipaux(),
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::actifs()->orderBy('nom')->pluck('nom'),
            'caisses' => self::caisses(),
            'entites' => \App\Models\Entite::actives()->orderBy('id')->get(['id', 'code', 'nom']),
        ]);
    }

    /**
     * Mettre à jour un utilisateur
     */
    public function update(Request $request, User $utilisateur)
    {
        $request->merge(['numero_om' => User::normaliserNumeroOm($request->numero_om)]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($utilisateur->id)],
            /* Les comptes créés par l'import des référentiels n'ont souvent pas de matricule : facultatif ici, unique s'il est saisi */
            'matricule' => ['nullable', 'string', Rule::unique('users')->ignore($utilisateur->id)],
            'telephone' => ['nullable', 'string', 'max:20'],
            'numero_om' => ['nullable', 'regex:' . User::FORMAT_NUMERO_OM],
            'statut_cadre' => ['nullable', Rule::in(['cadre', 'non_cadre'])],
            'role' => ['required', Rule::in(User::ROLES_PRINCIPAUX)],
            'service' => ['required', 'string', 'max:255'],
            'site' => ['required', 'string', 'max:255'],
            /* Spec v2.3, RG-M02-03 : caisse de rattachement (vide : caisse principale du site) */
            'caisse_id' => ['nullable', 'integer', Rule::exists('caisses', 'id')],
            /* Spec v2.4, RG-M01-02 : entité du compte (entités actives) */
            'entite_id' => ['nullable', 'integer', Rule::exists('entites', 'id')->where('actif', true)],
            'poste' => ['nullable', 'string', 'max:255'],
            'actif' => ['boolean'],
            'roles_complementaires' => ['sometimes', 'array'],
            'roles_complementaires.*' => [Rule::in(array_keys(User::ROLES))],
        ], self::MESSAGES);

        /* Rôles complémentaires (chef d'atelier, DP adjoint, Trésorerie…) : double validation, comme le rôle principal */
        $rolesDemandes = null;
        if ($request->has('roles_complementaires')) {
            /* Lot T : comparés au nouveau rôle principal (l'ancien, resté coché à l'écran, demeure un rôle de la personne) */
            $actuels = self::rolesComplementaires($utilisateur, $validated['role']);
            $demandes = array_values(array_unique(array_diff($validated['roles_complementaires'] ?? [], [$validated['role']])));
            sort($demandes);
            if ($demandes !== $actuels) {
                $rolesDemandes = $demandes;
                \App\Models\ModificationEnAttente::create([
                    'type_entite' => 'utilisateur_roles',
                    'entite_id' => $utilisateur->id,
                    'champ' => 'roles',
                    'ancienne_valeur' => implode(', ', $actuels),
                    'nouvelle_valeur' => implode(', ', $demandes),
                    'demandeur_id' => \Illuminate\Support\Facades\Auth::id(),
                    'statut' => 'en_attente',
                ]);
            }
        }
        unset($validated['roles_complementaires']);

        /* Mise à jour du mot de passe uniquement si fourni */
        if ($request->filled('password')) {
            $request->validate(['password' => Rules\Password::defaults()]);
            $validated['password'] = Hash::make($request->password);
        }

        $pendingCreated = false;

        if (array_key_exists('role', $validated) && $validated['role'] != $utilisateur->role) {
            \App\Models\ModificationEnAttente::create([
                'type_entite' => 'utilisateur_role',
                'entite_id' => $utilisateur->id,
                'champ' => 'role',
                'ancienne_valeur' => $utilisateur->role,
                'nouvelle_valeur' => $validated['role'],
                'demandeur_id' => \Illuminate\Support\Facades\Auth::id(),
                'statut' => 'en_attente',
            ]);
            $pendingCreated = true;
            unset($validated['role']);
        }

        $utilisateur->update($validated);

        if ($pendingCreated || $rolesDemandes !== null) {
            return redirect()
                ->route('utilisateurs.index')
                ->with('success', 'Utilisateur mis à jour. La modification des rôles a été mise en attente de double validation.');
        }

        return redirect()
            ->route('utilisateurs.index')
            ->with('success', 'Utilisateur mis à jour avec succès.');
    }

    /**
     * Activer/Désactiver un utilisateur
     */
    public function toggleActif(User $utilisateur)
    {
        $utilisateur->update(['actif' => !$utilisateur->actif]);

        $message = $utilisateur->actif ? 'Utilisateur activé.' : 'Utilisateur désactivé.';

        return back()->with('success', $message);
    }
}
