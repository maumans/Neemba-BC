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

    /** Rôles attribués en plus du rôle principal, triés */
    private static function rolesComplementaires(User $utilisateur): array
    {
        $roles = array_values(array_diff($utilisateur->listeRoles(), [$utilisateur->role]));
        sort($roles);

        return $roles;
    }

    /**
     * Liste des utilisateurs
     */
    public function index(Request $request)
    {
        $query = User::query()->latest();

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

        $utilisateurs = $query->paginate(15)->withQueryString();

        return Inertia::render('Utilisateurs/Index', [
            'utilisateurs' => $utilisateurs,
            'filtres' => $request->only(['recherche', 'role', 'site']),
            'roles' => [
                'demandeur' => 'Demandeur',
                'responsable_service' => 'Responsable Service',
                'controle_gestion' => 'Contrôle de Gestion',
                'daf' => 'DAF',
                'directeur_pays' => 'Directeur Pays',
                'caissier' => 'Caissier',
                'administrateur' => 'Administrateur',
            ],
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
            'poste' => ['nullable', 'string', 'max:255'],
        ], self::MESSAGES);

        $validated['password'] = Hash::make($validated['password']);
        $validated['actif'] = true;

        User::create($validated);

        return redirect()
            ->route('utilisateurs.index')
            ->with('success', 'Utilisateur créé avec succès.');
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
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::actifs()->orderBy('nom')->pluck('nom'),
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
            'poste' => ['nullable', 'string', 'max:255'],
            'actif' => ['boolean'],
            'roles_complementaires' => ['sometimes', 'array'],
            'roles_complementaires.*' => [Rule::in(array_keys(User::ROLES))],
        ], self::MESSAGES);

        /* Rôles complémentaires (chef d'atelier, DP adjoint, Trésorerie…) : double validation, comme le rôle principal */
        $rolesDemandes = null;
        if ($request->has('roles_complementaires')) {
            $actuels = self::rolesComplementaires($utilisateur);
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
