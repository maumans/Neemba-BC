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
 * Liste des mouvements par caisse et validation des mouvements en attente (DAF / DP ; ajustement d'inventaire : DAF).
 * Spec v2.4, RG-M08-02 : aucun mouvement saisi à la main ; l'approvisionnement vient de la demande de réapprovisionnement.
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
        } elseif (!\App\Services\Caisse\AccesCaisse::estTransverse($utilisateur) && $utilisateur->aLeRole('caissier')) {
            /* Spec v2.4, RG-M08-12 : le caissier voit les mouvements de sa caisse */
            $query->whereIn('caisse_id', \App\Services\Caisse\AccesCaisse::caissesVisibles($utilisateur)->pluck('id'));
        } elseif ($utilisateur->peutPayer() && $utilisateur->site) {
            // Délégué d'un caissier, avec un site affecté
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
        /* Spec v2.4, RG-M08-05 : le gérant d'une caisse demande son réapprovisionnement (lot F1b) */
        $peutCreer = $this->caissesAccessibles($utilisateur)->contains(fn (Caisse $c) => \App\Services\Caisse\AccesCaisse::estGerant($utilisateur, $c));
        $peutValider = $utilisateur->aLeRole(['daf', 'daf_adjoint', 'directeur_pays']);
        /* Spec v2.4, RG-M10-03 : sens de l'ajustement d'inventaire ; seul le DAF le valide */
        $mouvements->through(fn (MouvementCaisse $m) => $m->setAttribute('sortie', $m->type === 'retrait'
            || ($m->type === 'ajustement_inventaire' && ($m->metadata['sens'] ?? null) === 'sortie'))
            ->setAttribute('validable', $m->type !== 'ajustement_inventaire' || $utilisateur->aLeRole(['daf', 'daf_adjoint'])));

        /* Soldes par caisse (ANO-09) : le caissier voit les caisses de son site, DAF / DP / admin toutes */
        $soldesCaisses = $this->caissesAccessibles($utilisateur)->map(fn (Caisse $caisse) => [
            'id'              => $caisse->id,
            'code'            => $caisse->code,
            'libelle'         => $caisse->libelle,
            'site'            => $caisse->sites_libelle,
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
     * Valider un mouvement de caisse (DAF/DP uniquement)
     */
    public function valider(Request $request, MouvementCaisse $mouvement)
    {
        $utilisateur = Auth::user();

        if (!$utilisateur->aLeRole(['daf', 'daf_adjoint', 'directeur_pays'])) {
            abort(403, 'Seuls le DAF et le Directeur Pays peuvent valider les mouvements de caisse.');
        }

        if ($mouvement->statut !== 'en_attente') {
            return back()->with('error', 'Ce mouvement a déjà été traité.');
        }

        /* Spec v2.4, RG-M10-03 : un ajustement d'inventaire n'est passé qu'après validation du DAF, avec motif */
        $inventaire = $mouvement->type === 'ajustement_inventaire';
        if ($inventaire && !$utilisateur->aLeRole(['daf', 'daf_adjoint'])) {
            abort(403, "Seul le DAF valide un ajustement d'inventaire.");
        }
        $request->validate([
            'commentaire' => $inventaire ? ['required', 'string', 'min:10', 'max:1000'] : ['nullable', 'string', 'max:1000'],
        ], ['commentaire.required' => "Indiquez le motif de l'ajustement d'inventaire (10 caractères au moins)."]);

        $caisse = $mouvement->caisse;
        if (!$caisse) {
            return back()->with('error', "Le mouvement {$mouvement->reference} n'est rattaché à aucune caisse.");
        }

        /* Vérifications métier pour les retraits */
        $sortie = $mouvement->type === 'retrait' || ($inventaire && ($mouvement->metadata['sens'] ?? null) === 'sortie');
        if ($sortie && !$caisse->peutPayer((float) $mouvement->montant)) {
            return back()->with('error', "Solde insuffisant sur la {$caisse->libelle} pour ce retrait.");
        }

        DB::transaction(fn () => $mouvement->valider($utilisateur, $request->commentaire));

        \App\Services\NotificationService::notifierMouvementCaisseValidee($mouvement, $utilisateur);

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
        /* Spec v2.4, RG-M08-12 : un caissier n'opère que sa caisse ; les rôles transverses toutes */
        if (!\App\Services\Caisse\AccesCaisse::estTransverse($utilisateur) && $utilisateur->aLeRole('caissier')) {
            return \App\Services\Caisse\AccesCaisse::caissesVisibles($utilisateur);
        }

        $requete = Caisse::actives()->with('sites')->orderBy('id');

        if (!\App\Services\Caisse\AccesCaisse::estTransverse($utilisateur)) {
            $site = $utilisateur->site;
            if (!$site) {
                $delegation = \App\Models\Delegation::actives()
                    ->where('delegue_id', $utilisateur->id)
                    ->whereHas('delegant', fn ($q) => $q->avecRoleDans(['caissier']))
                    ->with('delegant')
                    ->first();
                $site = $delegation?->delegant?->site;
            }
            $site ? $requete->duSite($site) : $requete->whereRaw('1 = 0');
        }

        return $requete->get();
    }
}
