<?php

namespace App\Http\Middleware;

use App\Services\Securite\ReglagesSecurite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Q108 : un compte au mot de passe initial (import, création ou réinitialisation par l'administrateur) ne voit aucun
 * autre écran tant que son titulaire n'a pas choisi son mot de passe. Coupé dans Paramétrage > Sécurité, le contrôle
 * ne s'applique plus (recette).
 */
class ExigerChangementMotDePasse
{
    /** Routes ouvertes pendant le changement */
    private const ROUTES_LIBRES = ['mot-de-passe.initial', 'mot-de-passe.initial.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();
        if (!$utilisateur?->mot_de_passe_a_changer || $request->routeIs(...self::ROUTES_LIBRES)
            || !ReglagesSecurite::valeurs()['changement_mot_de_passe_initial']) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Choisissez votre mot de passe pour continuer.'], 409);
        }

        return redirect()->route('mot-de-passe.initial');
    }
}
