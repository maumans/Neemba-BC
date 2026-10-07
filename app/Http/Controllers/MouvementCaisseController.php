<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\MouvementCaisse;
use App\Models\Site;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $query = MouvementCaisse::with(['effectuePar', 'validePar', 'caisse'])
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

        /* Droits d'action basés sur le rôle effectif */
        $peutCreer = $utilisateur->peutPayer() || $utilisateur->aLeRole(['daf', 'directeur_pays', 'administrateur']);
        $peutValider = $utilisateur->aLeRole(['daf', 'directeur_pays']);

        /* Soldes par caisse (ANO-09) : le caissier voit les caisses de son site, DAF / DP / admin toutes */
        $soldesCaisses = $this->caissesAccessibles($utilisateur)->map(fn (Caisse $caisse) => [
            'id'              => $caisse->id,
            'code'            => $caisse->code,
            'libelle'         => $caisse->libelle,
            'site'            => $caisse->site->nom,
            'type'            => $caisse->type,
            'solde'           => (float) $caisse->solde,
            'plafond_retrait' => $caisse->plafond_retrait !== null ? (float) $caisse->plafond_retrait : null,
            'seuil_alerte'    => $caisse->seuilAlerteEffectif(),
            'sous_seuil'      => $caisse->sousSeuil(),
        ])->values();

        return Inertia::render('MouvementsCaisse/Index', [
            'mouvements' => $mouvements,
            'soldesCaisses' => $soldesCaisses,
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
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        /* Un caissier ne peut créer un mouvement que sur les caisses de son propre site */
        return Inertia::render('MouvementsCaisse/Create', [
            'caisses' => $this->caissesAccessibles($utilisateur)->map(fn (Caisse $caisse) => [
                'id'      => $caisse->id,
                'libelle' => $caisse->libelle,
                'site'    => $caisse->site->nom,
                'type'    => $caisse->type,
                'solde'   => (float) $caisse->solde,
            ])->values(),
            'types' => MouvementCaisse::TYPES,
        ]);
    }

    /**
     * Enregistrer un nouveau mouvement de caisse
     */
    public function store(Request $request)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        $validated = $request->validate([
            'type'               => ['required', 'in:approvisionnement,retrait,ajustement'],
            'caisse_id'          => ['required', 'integer', 'exists:caisses,id'],
            'montant'            => ['required', 'numeric', 'min:1'],
            'motif'              => ['required', 'string', 'min:5', 'max:1000'],
            'piece_justificative'=> ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        /* Restriction : un caissier ne peut créer un mouvement que sur les caisses de son propre site */
        $caisse = $this->caissesAccessibles($utilisateur)->firstWhere('id', (int) $validated['caisse_id']);
        if (!$caisse) {
            abort(403, 'Vous ne pouvez créer des mouvements que sur les caisses de votre site.');
        }

        $cheminPiece = null;
        if ($request->hasFile('piece_justificative')) {
            $cheminPiece = $request->file('piece_justificative')->store('mouvements_caisse', 'public');
        }

        $mouvement = MouvementCaisse::create([
            'reference'           => MouvementCaisse::genererReference(),
            'type'                => $validated['type'],
            'caisse_id'           => $caisse->id,
            'type_caisse'         => $caisse->type === 'orange_money' ? 'om' : 'especes',
            'montant'             => $validated['montant'],
            'motif'               => $validated['motif'],
            'site'                => $caisse->site->nom,
            'statut'              => 'en_attente',
            'effectue_par'        => $utilisateur->id,
            'date_mouvement'      => now(),
            'piece_justificative' => $cheminPiece,
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

        if (!$utilisateur->aLeRole(['daf', 'directeur_pays'])) {
            abort(403, 'Seuls le DAF et le Directeur Pays peuvent valider les mouvements de caisse.');
        }

        if ($mouvement->statut !== 'en_attente') {
            return back()->with('error', 'Ce mouvement a déjà été traité.');
        }

        $request->validate([
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ]);

        $caisse = $mouvement->caisse;
        if (!$caisse) {
            return back()->with('error', "Le mouvement {$mouvement->reference} n'est rattaché à aucune caisse.");
        }

        /* Vérifications métier pour les retraits */
        if ($mouvement->type === 'retrait' && !$caisse->peutPayer((float) $mouvement->montant)) {
            return back()->with('error', "Solde insuffisant sur la {$caisse->libelle} pour ce retrait.");
        }

        DB::transaction(fn () => $mouvement->valider($utilisateur, $request->commentaire));

        \App\Services\NotificationService::notifierMouvementCaisseValidee($mouvement, $utilisateur);

        /* Alerte si la caisse passe sous son seuil après un retrait */
        if ($mouvement->type === 'retrait' && $caisse->fresh()->sousSeuil()) {
            \App\Services\NotificationService::notifierAlerteSolde($caisse->fresh(), $utilisateur);
        }

        return back()->with('success', "Mouvement {$mouvement->reference} validé. Solde de la {$caisse->libelle} mis à jour.");
    }

    /**
     * Rejeter un mouvement de caisse (DAF/DP uniquement)
     */
    public function rejeter(Request $request, MouvementCaisse $mouvement)
    {
        $utilisateur = Auth::user();

        if (!$utilisateur->aLeRole(['daf', 'directeur_pays'])) {
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

    /**
     * Caisses actives sur lesquelles l'utilisateur peut agir : DAF, DP et administrateur toutes ;
     * caissier (ou délégué d'un caissier) celles de son site, ou du site du caissier délégant.
     */
    private function caissesAccessibles(\App\Models\User $utilisateur)
    {
        $requete = Caisse::actives()->with('site')->orderBy('site_id')->orderBy('id');

        if (!$utilisateur->aLeRole(['daf', 'directeur_pays', 'administrateur'])) {
            $site = $utilisateur->site;
            if (!$site) {
                $delegation = \App\Models\Delegation::actives()
                    ->where('delegue_id', $utilisateur->id)
                    ->whereHas('delegant', fn ($q) => $q->where('role', 'caissier'))
                    ->with('delegant')
                    ->first();
                $site = $delegation?->delegant?->site;
            }
            $site ? $requete->duSite($site) : $requete->whereRaw('1 = 0');
        }

        return $requete->get();
    }
}
