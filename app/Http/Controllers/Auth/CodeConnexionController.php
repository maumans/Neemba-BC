<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Models\User;
use App\Services\Securite\DoubleAuthentification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Second temps de la connexion (spec v2.4, RG-M01-07) : saisie du code reçu par email. La session n'est ouverte qu'ici ;
 * en attendant, seul l'identifiant du compte est gardé en session (clé « 2fa »).
 */
class CodeConnexionController extends Controller
{
    public const CLE = '2fa';

    /** Délai minimal entre deux envois de code (secondes) */
    private const DELAI_RENVOI = 60;

    public function create(Request $request): Response|RedirectResponse
    {
        $utilisateur = self::enAttente($request);
        if (!$utilisateur) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/CodeConnexion', [
            'email' => DoubleAuthentification::masquer($utilisateur->email),
            'minutes' => (int) Parametre::valeur('otp_connexion_duree', 10),
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $utilisateur = self::enAttente($request);
        if (!$utilisateur) {
            return redirect()->route('login');
        }
        $request->validate(['code' => ['required', 'string']], ['code.required' => __('MSG-BC-001')]);

        DoubleAuthentification::verifier($utilisateur, 'connexion', null, $request->input('code'), 'code');

        $souvenir = (bool) ($request->session()->get(self::CLE)['souvenir'] ?? false);
        $request->session()->forget(self::CLE);
        Auth::login($utilisateur, $souvenir);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function renvoyer(Request $request): RedirectResponse
    {
        $utilisateur = self::enAttente($request);
        if (!$utilisateur) {
            return redirect()->route('login');
        }
        $attente = $request->session()->get(self::CLE);
        if (now()->timestamp - (int) ($attente['envoye_le'] ?? 0) < self::DELAI_RENVOI) {
            return back()->withErrors(['code' => __('MSG-APP-085')]);
        }
        DoubleAuthentification::envoyerCodeConnexion($utilisateur);
        $request->session()->put(self::CLE, ['envoye_le' => now()->timestamp] + $attente);

        return back()->with('status', __('MSG-APP-077', ['email' => DoubleAuthentification::masquer($utilisateur->email),
            'minutes' => (int) Parametre::valeur('otp_connexion_duree', 10)]));
    }

    /** Compte en attente de son code, s'il est toujours actif */
    private static function enAttente(Request $request): ?User
    {
        $id = $request->session()->get(self::CLE)['utilisateur'] ?? null;
        $utilisateur = $id ? User::find($id) : null;

        return $utilisateur && $utilisateur->actif ? $utilisateur : null;
    }
}
