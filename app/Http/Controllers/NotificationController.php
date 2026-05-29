<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Contrôleur des Notifications
 * 
 * API JSON pour lister, compter et marquer comme lues
 * les notifications de l'utilisateur connecté.
 */
class NotificationController extends Controller
{
    /**
     * Lister les notifications de l'utilisateur (paginées)
     */
    public function index(Request $request)
    {
        $notifications = Notification::pourUtilisateur(Auth::id())
            ->with([
                'expediteur:id,name,prenom',
                'bonCaisse:id,numero,beneficiaire,montant,statut'
            ])
            ->select(['id', 'destinataire_id', 'bon_caisse_id', 'expediteur_id', 'type', 'titre', 'message', 'metadata', 'lue_le', 'created_at'])
            ->latest()
            ->limit($request->input('par_page', 20))
            ->get();

        return response()->json(['data' => $notifications]);
    }

    /**
     * Compter les notifications non lues
     */
    public function compterNonLues()
    {
        $count = Notification::pourUtilisateur(Auth::id())
            ->nonLues()
            ->count();

        return response()->json(['non_lues' => $count]);
    }

    /**
     * Marquer une notification comme lue
     */
    public function marquerLue(Notification $notification)
    {
        if ($notification->destinataire_id !== Auth::id()) {
            abort(403);
        }

        $notification->marquerCommeLue();

        return response()->json(['success' => true]);
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    public function marquerToutesLues()
    {
        Notification::pourUtilisateur(Auth::id())
            ->nonLues()
            ->update(['lue_le' => now()]);

        return response()->json(['success' => true]);
    }
}
