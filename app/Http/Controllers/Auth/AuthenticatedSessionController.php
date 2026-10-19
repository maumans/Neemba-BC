<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $utilisateur = $request->verifierIdentifiants();

        /* Paramètre de secours (panne du serveur d'email) : connexion sans code */
        if (!\App\Services\Securite\DoubleAuthentification::connexionActive()) {
            Auth::login($utilisateur, $request->boolean('remember'));
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        }

        /* Spec v2.4, RG-M01-07 : la session ne s'ouvre qu'après le code reçu par email */
        \App\Services\Securite\DoubleAuthentification::envoyerCodeConnexion($utilisateur);
        $request->session()->put(CodeConnexionController::CLE, [
            'utilisateur' => $utilisateur->id,
            'souvenir' => $request->boolean('remember'),
            'envoye_le' => now()->timestamp,
        ]);

        return redirect()->route('login.code');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
