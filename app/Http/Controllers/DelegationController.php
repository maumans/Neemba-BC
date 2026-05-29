<?php

namespace App\Http\Controllers;

use App\Models\Delegation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Contrôleur des Délégations de Pouvoirs
 * 
 * Gère la création, acceptation, refus et terminaison des délégations.
 * Un validateur peut déléguer ses droits pendant une période d'absence.
 */
class DelegationController extends Controller
{
    /**
     * Liste des délégations (données et reçues)
     */
    public function index()
    {
        $utilisateur = Auth::user();

        $delegationsDonnees = Delegation::with('delegue')
            ->where('delegant_id', $utilisateur->id)
            ->latest()
            ->get();

        $delegationsRecues = Delegation::with('delegant')
            ->where('delegue_id', $utilisateur->id)
            ->latest()
            ->get();

        $enAttenteAcceptation = Delegation::with('delegant')
            ->where('delegue_id', $utilisateur->id)
            ->where('statut', 'en_attente')
            ->get();

        $peutCreer = in_array($utilisateur->role, [
            'employe', 'caissier', 'responsable_service', 'controle_gestion', 'daf', 'directeur_pays', 'administrateur',
        ]);

        return Inertia::render('Delegations/Index', [
            'delegationsDonnees' => $delegationsDonnees,
            'delegationsRecues' => $delegationsRecues,
            'enAttenteAcceptation' => $enAttenteAcceptation,
            'statuts' => Delegation::STATUTS,
            'peutCreer' => $peutCreer,
            'fonctionnalitesLabels' => Delegation::FONCTIONNALITES_LABELS,
        ]);
    }

    /**
     * Formulaire de création d'une délégation
     */
    public function create()
    {
        $utilisateur = Auth::user();

        /* Tous les utilisateurs peuvent déléguer */
        if (!in_array($utilisateur->role, ['employe', 'caissier', 'responsable_service', 'controle_gestion', 'daf', 'directeur_pays', 'administrateur'])) {
            abort(403, 'Vous n\'êtes pas autorisé à créer des délégations.');
        }

        /* Utilisateurs actifs pouvant recevoir une délégation */
        $deleguesPotentiels = User::actifs()
            ->where('id', '!=', $utilisateur->id)
            ->orderBy('name')
            ->get(['id', 'name', 'prenom', 'role', 'service', 'site']);

        /* Fonctionnalités délégables en fonction du rôle de l'utilisateur */
        $fonctionnalitesDisponibles = Delegation::FONCTIONNALITES_PAR_ROLE[$utilisateur->role] ?? [];

        return Inertia::render('Delegations/Create', [
            'deleguesPotentiels' => $deleguesPotentiels,
            'fonctionnalitesDisponibles' => $fonctionnalitesDisponibles,
            'fonctionnalitesLabels' => Delegation::FONCTIONNALITES_LABELS,
        ]);
    }

    /**
     * Enregistrer une nouvelle délégation
     */
    public function store(Request $request)
    {
        $utilisateur = Auth::user();

        if (!in_array($utilisateur->role, ['employe', 'caissier', 'responsable_service', 'controle_gestion', 'daf', 'directeur_pays', 'administrateur'])) {
            abort(403);
        }

        $validated = $request->validate([
            'delegue_id' => ['required', 'exists:users,id'],
            'date_debut' => ['required', 'date', 'after_or_equal:today'],
            'date_fin' => ['required', 'date', 'after:date_debut'],
            'motif' => ['nullable', 'string', 'max:500'],
            'fonctionnalites' => ['nullable', 'array', 'min:1'],
            'fonctionnalites.*' => ['string', 'in:validation,paiement,rapport_caisse,mouvement_caisse,archivage'],
        ]);

        /* Vérifier qu'il n'y a pas de chevauchement */
        $chevauchement = Delegation::where('delegant_id', $utilisateur->id)
            ->whereIn('statut', ['en_attente', 'acceptee'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('date_debut', [$validated['date_debut'], $validated['date_fin']])
                    ->orWhereBetween('date_fin', [$validated['date_debut'], $validated['date_fin']])
                    ->orWhere(function ($q2) use ($validated) {
                        $q2->where('date_debut', '<=', $validated['date_debut'])
                            ->where('date_fin', '>=', $validated['date_fin']);
                    });
            })
            ->exists();

        if ($chevauchement) {
            return back()->with('error', 'Une délégation existe déjà pour cette période.');
        }

        $delegation = Delegation::create([
            'delegant_id' => $utilisateur->id,
            'delegue_id' => $validated['delegue_id'],
            'date_debut' => $validated['date_debut'],
            'date_fin' => $validated['date_fin'],
            'motif' => $validated['motif'],
            'fonctionnalites' => $validated['fonctionnalites'] ?? null,
            'statut' => 'en_attente',
        ]);

        \App\Services\NotificationService::notifierDelegationCreee($delegation, $utilisateur);

        return redirect()
            ->route('delegations.index')
            ->with('success', 'Délégation créée et envoyée pour acceptation.');
    }

    /**
     * Accepter une délégation reçue
     */
    public function accepter(Delegation $delegation)
    {
        $utilisateur = Auth::user();

        if ($delegation->delegue_id !== $utilisateur->id || $delegation->statut !== 'en_attente') {
            abort(403);
        }

        $delegation->accepter();

        \App\Services\NotificationService::notifierDelegationAcceptee($delegation, $utilisateur);

        return back()->with('success', 'Délégation acceptée. Vous avez maintenant les droits de validation du délégant.');
    }

    /**
     * Refuser une délégation reçue
     */
    public function refuser(Delegation $delegation)
    {
        $utilisateur = Auth::user();

        if ($delegation->delegue_id !== $utilisateur->id || $delegation->statut !== 'en_attente') {
            abort(403);
        }

        $delegation->refuser();

        \App\Services\NotificationService::notifierDelegationRefusee($delegation, $utilisateur);

        return back()->with('success', 'Délégation refusée.');
    }

    /**
     * Terminer une délégation active prématurément
     */
    public function terminer(Delegation $delegation)
    {
        $utilisateur = Auth::user();

        if ($delegation->delegant_id !== $utilisateur->id || $delegation->statut !== 'acceptee') {
            abort(403);
        }

        $delegation->terminer();

        \App\Services\NotificationService::notifierDelegationTerminee($delegation, $utilisateur);

        return back()->with('success', 'Délégation terminée.');
    }
}
