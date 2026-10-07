<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? array_merge($request->user()->toArray(), [
                    /* Rôles propres (principal + attribués) */
                    'roles' => $request->user()->listeRoles(),
                    /* Rôles propres + rôles reçus par délégation (menu, droits d'écran) */
                    'roles_effectifs' => array_values(array_unique(array_merge(
                        $request->user()->listeRoles(),
                        $request->user()->rolesValidationEffectifs(),
                    ))),
                    'peut_initier_bon' => $request->user()->peutInitierBon(),
                ]) : null,
            ],
            /* Messages flash pour les notifications (succès, erreur) */
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
            /* Compteur de notifications non lues (chargé côté serveur pour affichage immédiat) */
            'notificationsNonLues' => fn () => $request->user()
                ? Notification::pourUtilisateur($request->user()->id)->nonLues()->count()
                : 0,
        ];
    }
}
