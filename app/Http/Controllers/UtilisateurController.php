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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', Rules\Password::defaults()],
            'matricule' => ['required', 'string', 'unique:users'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in([
                'demandeur', 'responsable_service', 'controle_gestion',
                'daf', 'directeur_pays', 'caissier', 'administrateur',
            ])],
            'service' => ['required', 'string', 'max:255'],
            'site' => ['required', 'string', 'max:255'],
            'poste' => ['nullable', 'string', 'max:255'],
        ]);

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
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::actifs()->orderBy('nom')->pluck('nom'),
        ]);
    }

    /**
     * Mettre à jour un utilisateur
     */
    public function update(Request $request, User $utilisateur)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($utilisateur->id)],
            'matricule' => ['required', 'string', Rule::unique('users')->ignore($utilisateur->id)],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in([
                'demandeur', 'responsable_service', 'controle_gestion',
                'daf', 'directeur_pays', 'caissier', 'administrateur',
            ])],
            'service' => ['required', 'string', 'max:255'],
            'site' => ['required', 'string', 'max:255'],
            'poste' => ['nullable', 'string', 'max:255'],
            'actif' => ['boolean'],
        ]);

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

        if ($pendingCreated) {
            return redirect()
                ->route('utilisateurs.index')
                ->with('success', 'Utilisateur mis à jour. La modification du rôle a été mise en attente de double validation.');
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
