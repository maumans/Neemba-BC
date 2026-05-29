<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware : Vérification du rôle utilisateur
 * 
 * Restreint l'accès à certaines routes selon le rôle de l'utilisateur.
 * Accepte un ou plusieurs rôles séparés par des virgules.
 * 
 * Usage dans les routes :
 *   ->middleware('role:caissier')
 *   ->middleware('role:daf,directeur_pays')
 *   ->middleware('role:responsable_service,controle_gestion,daf,directeur_pays')
 */
class VerifierRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $utilisateur = $request->user();

        if (!$utilisateur) {
            abort(403, 'Non authentifié.');
        }

        // Récupérer le rôle propre + tous les rôles délégués actifs
        $rolesPossedes = [$utilisateur->role];

        $delegants = \App\Models\Delegation::actives()
            ->where('delegue_id', $utilisateur->id)
            ->with('delegant')
            ->get();

        foreach ($delegants as $delegation) {
            $roleDelegant = $delegation->delegant->role ?? null;
            if ($roleDelegant && !in_array($roleDelegant, $rolesPossedes)) {
                $rolesPossedes[] = $roleDelegant;
            }
        }

        if (empty(array_intersect($rolesPossedes, $roles))) {
            abort(403, 'Vous n\'avez pas les droits nécessaires pour accéder à cette ressource.');
        }

        return $next($request);
    }
}
