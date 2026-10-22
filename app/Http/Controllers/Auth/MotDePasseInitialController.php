<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Q108 : à la première connexion d'un compte au mot de passe initial, son titulaire choisit le sien
 * (middleware ExigerChangementMotDePasse).
 */
class MotDePasseInitialController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (!$request->user()->mot_de_passe_a_changer) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/MotDePasseInitial', ['nom' => $request->user()->nom_complet]);
    }

    public function store(Request $request): RedirectResponse
    {
        $utilisateur = $request->user();
        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults(),
                function (string $attribut, mixed $valeur, Closure $echec) use ($utilisateur) {
                    if ($valeur === User::MOT_DE_PASSE_INITIAL || Hash::check((string) $valeur, $utilisateur->password)) {
                        $echec('Choisissez un mot de passe différent du mot de passe initial.');
                    }
                }],
        ], [
            'password.required' => 'Saisissez votre nouveau mot de passe.',
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
        ]);

        $utilisateur->update(['password' => Hash::make($request->password), 'mot_de_passe_a_changer' => false]);

        return redirect()->route('dashboard')->with('success', 'Votre mot de passe est enregistré.');
    }
}
