<?php

namespace App\Http\Controllers;

use App\Models\MouvementCaisse;
use App\Models\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Contrôleur des Mouvements de Caisse
 * 
 * Gère les approvisionnements, retraits et ajustements de la caisse par site.
 * Accessible par les caissiers (créer), DAF/DP (valider).
 */
class MouvementCaisseController extends Controller
{
    /**
     * Liste des mouvements de caisse
     */
    public function index(Request $request)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        $query = MouvementCaisse::with(['effectuePar', 'validePar'])
            ->latest('date_mouvement');

        /* Filtrage par site */
        if ($request->filled('site')) {
            $query->where('site', $request->site);
        } elseif ($utilisateur->peutPayer() && $utilisateur->site) {
            // Si caissier réel ou délégué caissier avec un site affecté
            $query->where('site', $utilisateur->site);
        }

        /* Filtrage par statut */
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        /* Filtrage par type */
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $mouvements = $query->paginate(15)->withQueryString();

        /* Soldes par site — get() sans restriction de colonnes pour préserver le type Eloquent (Site) */
        /* Le caissier ne voit que son propre site ; le DAF/DP/Admin voient tous les sites */
        $soldesSitesQuery = Site::actifs()->orderBy('nom');
        if ($utilisateur->peutPayer() && !in_array($utilisateur->role, ['daf', 'directeur_pays', 'administrateur'])) {
            // Caissier réel : filtrer par son site
            // Caissier délégué : filtrer par le site du délégant (ou du délégué s'il en a un)
            $siteUtilisateur = $utilisateur->site;
            if (!$siteUtilisateur) {
                // Le délégué n'a pas forcément de site propre → récupérer celui du délégant caissier
                $delegant = \App\Models\Delegation::actives()
                    ->where('delegue_id', $utilisateur->id)
                    ->with('delegant')
                    ->whereHas('delegant', fn($q) => $q->where('role', 'caissier'))
                    ->first();
                $siteUtilisateur = $delegant?->delegant?->site;
            }
            if ($siteUtilisateur) {
                $soldesSitesQuery->where('nom', $siteUtilisateur);
            }
        }
        $soldesSites = $soldesSitesQuery->get();

        /* Droits d'action basés sur le rôle effectif */
        $peutCreer = $utilisateur->peutPayer() || in_array($utilisateur->role, ['daf', 'directeur_pays', 'administrateur']);
        $peutValider = in_array($utilisateur->role, ['daf', 'directeur_pays']);

        /* Enrichir les soldes sites avec les champs formatés */
        $soldesSitesEnrichis = $soldesSites->map(fn ($site) => [
            'nom' => $site->nom,
            'solde_caisse' => (float) $site->solde_caisse,
            'plafond_caisse' => $site->plafond_caisse,
            'seuil_minimum_caisse' => $site->seuil_minimum_caisse,
            'sous_seuil' => $site->soldeSousSeuil(),
        ]);

        return Inertia::render('MouvementsCaisse/Index', [
            'mouvements' => $mouvements,
            'soldesSites' => $soldesSitesEnrichis,
            'filtres' => $request->only(['site', 'statut', 'type']),
            'types' => MouvementCaisse::TYPES,
            'statuts' => MouvementCaisse::STATUTS,
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'peutCreer' => $peutCreer,
            'peutValider' => $peutValider,
        ]);
    }

    /**
     * Formulaire de création d'un mouvement
     */
    public function create()
    {
        return Inertia::render('MouvementsCaisse/Create', [
            'sites' => Site::actifs()->orderBy('nom')->get(['id', 'nom', 'solde_caisse', 'plafond_caisse']),
            'types' => MouvementCaisse::TYPES,
        ]);
    }

    /**
     * Enregistrer un nouveau mouvement de caisse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:approvisionnement,retrait,ajustement'],
            'montant' => ['required', 'numeric', 'min:1'],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
            'site' => ['required', 'string', 'exists:sites,nom'],
        ]);

        $mouvement = MouvementCaisse::create([
            'reference' => MouvementCaisse::genererReference(),
            'type' => $validated['type'],
            'montant' => $validated['montant'],
            'motif' => $validated['motif'],
            'site' => $validated['site'],
            'statut' => 'en_attente',
            'effectue_par' => Auth::id(),
            'date_mouvement' => now(),
        ]);

        \App\Services\NotificationService::notifierMouvementCaisseCreee($mouvement, Auth::user());

        return redirect()
            ->route('mouvements-caisse.index')
            ->with('success', "Mouvement {$mouvement->reference} créé et en attente de validation.");
    }

    /**
     * Valider un mouvement de caisse (DAF/DP uniquement)
     */
    public function valider(Request $request, MouvementCaisse $mouvement)
    {
        $utilisateur = Auth::user();

        if (!in_array($utilisateur->role, ['daf', 'directeur_pays'])) {
            abort(403, 'Seuls le DAF et le Directeur Pays peuvent valider les mouvements de caisse.');
        }

        if ($mouvement->statut !== 'en_attente') {
            return back()->with('error', 'Ce mouvement a déjà été traité.');
        }

        $request->validate([
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ]);

        /* Vérifications métier pour les retraits */
        if ($mouvement->type === 'retrait') {
            $site = Site::where('nom', $mouvement->site)->first();
            if ($site && !$site->peutPayer((float) $mouvement->montant)) {
                return back()->with('error', "Solde insuffisant sur le site {$mouvement->site} pour ce retrait.");
            }
        }

        $mouvement->valider($utilisateur, $request->commentaire);

        \App\Services\NotificationService::notifierMouvementCaisseValidee($mouvement, $utilisateur);

        /* Alerte si solde sous le seuil minimum après un retrait */
        if ($mouvement->type === 'retrait') {
            $siteModel = Site::where('nom', $mouvement->site)->first();
            if ($siteModel && $siteModel->soldeSousSeuil()) {
                \App\Services\NotificationService::notifierAlerteSolde($siteModel, $utilisateur);
            }
        }

        return back()->with('success', "Mouvement {$mouvement->reference} validé. Solde du site mis à jour.");
    }

    /**
     * Rejeter un mouvement de caisse (DAF/DP uniquement)
     */
    public function rejeter(Request $request, MouvementCaisse $mouvement)
    {
        $utilisateur = Auth::user();

        if (!in_array($utilisateur->role, ['daf', 'directeur_pays'])) {
            abort(403);
        }

        if ($mouvement->statut !== 'en_attente') {
            return back()->with('error', 'Ce mouvement a déjà été traité.');
        }

        $request->validate([
            'commentaire' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $mouvement->rejeter($utilisateur, $request->commentaire);

        \App\Services\NotificationService::notifierMouvementCaisseRejetee($mouvement, $utilisateur, $request->commentaire);

        return back()->with('success', "Mouvement {$mouvement->reference} rejeté.");
    }
}
