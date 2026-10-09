<?php

namespace App\Http\Controllers;

use App\Models\BonCaisse;
use App\Models\Delegation;
use App\Models\HistoriqueAction;
use App\Models\MouvementCaisse;
use App\Models\RapportCaisse;
use App\Models\Site;
use App\Models\Validation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Contrôleur du tableau de bord
 * 
 * Affiche les statistiques globales de la gestion de caisse :
 * - KPIs principaux (total, en attente, approuvés, payés, rejetés, montants)
 * - Statistiques spécifiques par rôle
 * - Bons en attente de validation pour l'utilisateur connecté
 * - Derniers bons / rapports
 * - Activité récente (historique)
 * - Répartition par catégorie de dépense
 * - BP en retard de régularisation
 */
class DashboardController extends Controller
{
    /**
     * Afficher le tableau de bord principal
     */
    public function index(Request $request)
    {
        $utilisateur = Auth::user();

        /* Base query : filtrage par rôle */
        /* Base query : filtrage par rôle effectif (croisement des droits propres et délégués) */
        $baseQuery = BonCaisse::query();
        
        $roles = array_unique(array_merge([$utilisateur->role], $utilisateur->rolesValidationEffectifs()));

        if (array_intersect(['controle_gestion', 'daf', 'directeur_pays', 'administrateur'], $roles)) {
            // Accès global à tous les bons
        } elseif (in_array('responsable_service', $roles)) {
            $servicesAccessibles = \App\Services\BonCaisse\CircuitBon::servicesChef($utilisateur);
            $baseQuery->where(function ($q) use ($utilisateur, $servicesAccessibles) {
                $q->parDemandeur($utilisateur->id)
                  ->orWhereIn('service', array_unique($servicesAccessibles));
            });
        } elseif (in_array('caissier', $roles)) {
            $baseQuery->where(function ($q) use ($utilisateur) {
                $q->parDemandeur($utilisateur->id);
                if ($utilisateur->site) {
                    $q->orWhere('site', $utilisateur->site);
                }
            });
        } else {
            // Strictement Demandeur sans procuration élargie
            $baseQuery->where('statut', '!=', 'BROUILLON')->parDemandeur($utilisateur->id);
        }

        /* Statistiques des bons de caisse (filtrées par rôle, hors brouillons) */
        $statistiques = [
            'total_bons' => (clone $baseQuery)->where('statut', '!=', 'BROUILLON')->count(),
            'bons_en_attente' => (clone $baseQuery)->whereIn('statut', [
                'EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP',
            ])->count(),
            'bons_approuves' => (clone $baseQuery)->where('statut', 'APPROUVE')->count(),
            'bons_termines' => (clone $baseQuery)->whereIn('statut', [
                'PAYE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE', 'ARCHIVE',
            ])->count(),
            'bons_rejetes' => (clone $baseQuery)->where('statut', 'REJETE')->count(),
            'montant_total_approuve' => (clone $baseQuery)->where('statut', 'APPROUVE')->sum('montant'),
            'montant_total_paye' => (clone $baseQuery)->whereIn('statut', ['PAYE', 'REGULARISE', 'ARCHIVE', 'EN_ATTENTE_REGULARISATION'])->sum('montant'),
        ];

        /* Statistiques du mois en cours */
        $debutMois = now()->startOfMonth();
        $statistiquesMois = [
            'bons_crees' => (clone $baseQuery)->where('statut', '!=', 'BROUILLON')->where('date_demande', '>=', $debutMois)->count(),
            'montant_paye' => (clone $baseQuery)
                ->where('statut', 'PAYE')
                ->where('date_paiement', '>=', $debutMois)
                ->sum('montant'),
            'bons_payes' => (clone $baseQuery)
                ->where('statut', 'PAYE')
                ->where('date_paiement', '>=', $debutMois)
                ->count(),
        ];

        /* Bons en attente de validation par l'utilisateur connecté */
        $bonsEnAttenteValidation = [];
        if ($utilisateur->peutValider()) {
            $bonsEnAttenteValidation = \App\Services\BonCaisse\CircuitBon::requeteAViser($utilisateur)->with('demandeur')
                ->latest('date_demande')
                ->take(5)
                ->get();
        }

        /* Derniers bons de l'utilisateur connecté */
        $mesDerniersBons = BonCaisse::with('demandeur')
            ->parDemandeur($utilisateur->id)
            ->latest('date_demande')
            ->take(5)
            ->get();

        /* Derniers rapports de caisse (pour les caissiers et DAF, filtrés par site pour le caissier) */
        $derniersRapports = [];
        if (in_array($utilisateur->role, ['caissier', 'daf', 'directeur_pays'])) {
            $derniersRapports = RapportCaisse::with('caissier')
                ->when($utilisateur->role === 'caissier' && $utilisateur->site, fn($q) => $q->parSite($utilisateur->site))
                ->latest('date_rapport')
                ->take(5)
                ->get();
        }

        /* Bons à payer (pour le caissier — filtrés par son site) */
        $bonsAPayer = [];
        if ($utilisateur->role === 'caissier') {
            $bonsAPayer = BonCaisse::with('demandeur')
                ->where('statut', 'APPROUVE')
                ->when($utilisateur->site, fn($q) => $q->parSite($utilisateur->site))
                ->latest('updated_at')
                ->take(5)
                ->get();
        }

        /* BP en retard de régularisation (filtrés par site pour le caissier) */
        $bpEnRetard = BonCaisse::with('demandeur')
            ->where('statut', 'EN_ATTENTE_REGULARISATION')
            ->whereNotNull('date_limite_regularisation')
            ->where('date_limite_regularisation', '<', now())
            ->when($utilisateur->role === 'caissier' && $utilisateur->site, fn($q) => $q->parSite($utilisateur->site))
            ->latest('date_limite_regularisation')
            ->take(5)
            ->get();

        /* Répartition par catégorie de dépense (tous les bons non-brouillon) */
        $repartitionCategories = (clone $baseQuery)
            ->whereNotNull('categorie_depense')
            ->where('statut', '!=', 'BROUILLON')
            ->select('categorie_depense', DB::raw('COUNT(*) as nombre'), DB::raw('SUM(montant) as montant_total'))
            ->groupBy('categorie_depense')
            ->orderByDesc('montant_total')
            ->get();

        /* Activité récente (filtrée par site pour le caissier) */
        $activiteRecente = HistoriqueAction::with(['bonCaisse', 'utilisateur'])
            ->when(
                $utilisateur->role === 'caissier' && $utilisateur->site,
                fn($q) => $q->whereHas('bonCaisse', fn($bq) => $bq->parSite($utilisateur->site))
            )
            ->latest()
            ->take(8)
            ->get();

        /* ====== Données pour les graphiques ====== */

        /* Évolution mensuelle sur 6 mois (bons créés, payés, montant payé) */
        $evolutionMensuelle = collect();
        for ($i = 5; $i >= 0; $i--) {
            $moisDebut = now()->subMonths($i)->startOfMonth();
            $moisFin = now()->subMonths($i)->endOfMonth();
            $moisLabel = $moisDebut->translatedFormat('M Y');

            $bonsCreesM = (clone $baseQuery)
                ->whereBetween('date_demande', [$moisDebut, $moisFin])
                ->count();
            $bonsPayesM = (clone $baseQuery)
                ->where('statut', 'PAYE')
                ->whereBetween('date_paiement', [$moisDebut, $moisFin])
                ->count();
            $montantPayeM = (clone $baseQuery)
                ->where('statut', 'PAYE')
                ->whereBetween('date_paiement', [$moisDebut, $moisFin])
                ->sum('montant');
            $bonsRejetesM = (clone $baseQuery)
                ->where('statut', 'REJETE')
                ->whereBetween('updated_at', [$moisDebut, $moisFin])
                ->count();

            $evolutionMensuelle->push([
                'mois' => $moisLabel,
                'crees' => $bonsCreesM,
                'payes' => $bonsPayesM,
                'rejetes' => $bonsRejetesM,
                'montant_paye' => (int) $montantPayeM,
            ]);
        }

        /* Répartition par statut (pour PieChart) */
        $repartitionStatuts = (clone $baseQuery)
            ->select('statut', DB::raw('COUNT(*) as nombre'))
            ->groupBy('statut')
            ->get()
            ->map(fn ($item) => [
                'statut' => $item->statut,
                'label' => BonCaisse::STATUTS_LABELS[$item->statut] ?? $item->statut,
                'nombre' => $item->nombre,
            ]);

        /* Top 5 bénéficiaires par montant total */
        $topBeneficiaires = (clone $baseQuery)
            ->where('statut', '!=', 'BROUILLON')
            ->select('beneficiaire', DB::raw('COUNT(*) as nombre'), DB::raw('SUM(montant) as montant_total'))
            ->groupBy('beneficiaire')
            ->orderByDesc('montant_total')
            ->take(5)
            ->get();

        /* Taux d'approbation global */
        $totalTraites = (clone $baseQuery)->whereNotIn('statut', ['BROUILLON'])->count();
        $totalRejetes = (clone $baseQuery)->where('statut', 'REJETE')->count();
        $totalApprouves = (clone $baseQuery)->whereIn('statut', ['APPROUVE', 'PAYE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE', 'ARCHIVE'])->count();

        $tauxApprobation = $totalTraites > 0 ? round(($totalApprouves / $totalTraites) * 100) : 0;
        $tauxRejet = $totalTraites > 0 ? round(($totalRejetes / $totalTraites) * 100) : 0;

        /* Délai moyen de traitement (soumission → paiement, en jours — filtré par site pour le caissier) */
        $delaiMoyen = BonCaisse::where('statut', 'PAYE')
            ->whereNotNull('date_soumission')
            ->whereNotNull('date_paiement')
            ->when($utilisateur->role === 'caissier' && $utilisateur->site, fn($q) => $q->parSite($utilisateur->site))
            ->selectRaw('AVG(DATEDIFF(date_paiement, date_soumission)) as delai')
            ->value('delai');

        /* ====== Soldes par caisse (ANO-09) : caissier, DAF, DP, admin ====== */
        $soldesCaisses = [];
        if ($utilisateur->aLeRole(['caissier', 'daf', 'directeur_pays', 'administrateur'])) {
            $caissesQuery = \App\Models\Caisse::actives()->with('site')->orderBy('site_id')->orderBy('id');

            /* Un caissier ne voit que les caisses de son propre site */
            if (!$utilisateur->aLeRole(['daf', 'directeur_pays', 'administrateur'])) {
                $caissesQuery->duSite((string) $utilisateur->site);
            }

            $soldesCaisses = $caissesQuery->get()->map(fn (\App\Models\Caisse $caisse) => [
                'id'              => $caisse->id,
                'code'            => $caisse->code,
                'libelle'         => $caisse->libelle,
                'site'            => $caisse->site->nom,
                'type'            => $caisse->type,
                'solde'           => (float) $caisse->solde,
                'seuil_alerte'    => $caisse->seuilAlerteEffectif(),
                'plafond_retrait' => $caisse->plafond_retrait !== null ? (float) $caisse->plafond_retrait : null,
                'sous_seuil'      => $caisse->sousSeuil(),
            ])->values();
        }

        /* Mouvements de caisse en attente (pour DAF/DP) */
        $mouvementsEnAttente = [];
        if (in_array($utilisateur->role, ['daf', 'directeur_pays'])) {
            $mouvementsEnAttente = MouvementCaisse::with('effectuePar')
                ->enAttente()
                ->latest('date_mouvement')
                ->take(5)
                ->get();
        }

        /* Délégations actives pour l'utilisateur */
        $delegationsActives = [];
        if ($utilisateur->peutValider()) {
            $delegationsActives = Delegation::with(['delegant', 'delegue'])
                ->actives()
                ->where(function ($q) use ($utilisateur) {
                    $q->where('delegue_id', $utilisateur->id)
                      ->orWhere('delegant_id', $utilisateur->id);
                })
                ->get();
        }

        /* Graphique de Performance SLA (Chefs de service) pour la Direction */
        $performancesN1 = [];
        if (in_array($utilisateur->role, ['daf', 'directeur_pays', 'administrateur'])) {
            $performancesN1 = Validation::with('validateur')
                ->where('role', 'responsable_service')
                ->where('statut', 'approuve')
                ->whereNotNull('date_attribution')
                ->whereNotNull('date_validation')
                ->get()
                ->groupBy('validateur_id')
                ->map(function ($validations) {
                    $validateur = current($validations)[0]->validateur->nom_complet ?? 'Pompier';
                    // The validateur relation might be null or name could vary
                    $validateur = $validations->first()->validateur->name ?? $validations->first()->validateur->nom_complet ?? 'Inconnu';
                    $avgMinutes = $validations->avg(function ($val) {
                        return $val->date_attribution->diffInMinutes($val->date_validation);
                    });
                    return [
                        'nom' => $validateur,
                        'heures' => round($avgMinutes / 60, 1),
                        'total' => $validations->count(),
                    ];
                })
                ->values()
                ->sortByDesc('heures')
                ->take(10)
                ->values()
                ->toArray();
        }

        return Inertia::render('Dashboard', [
            'statistiques' => $statistiques,
            'statistiquesMois' => $statistiquesMois,
            'bonsEnAttenteValidation' => $bonsEnAttenteValidation,
            'mesDerniersBons' => $mesDerniersBons,
            'derniersRapports' => $derniersRapports,
            'bonsAPayer' => $bonsAPayer,
            'bpEnRetard' => $bpEnRetard,
            'repartitionCategories' => $repartitionCategories,
            'activiteRecente' => $activiteRecente,
            'categoriesLabels' => \App\Models\CategorieDepense::libelles(),
            'actionsLabels' => HistoriqueAction::ACTIONS_LABELS,
            'statutsLabels' => BonCaisse::STATUTS_LABELS,
            /* Nouvelles données pour graphiques */
            'evolutionMensuelle' => $evolutionMensuelle,
            'repartitionStatuts' => $repartitionStatuts,
            'topBeneficiaires' => $topBeneficiaires,
            'tauxApprobation' => $tauxApprobation,
            'tauxRejet' => $tauxRejet,
            'delaiMoyen' => $delaiMoyen ? round($delaiMoyen, 1) : null,
            /* Caisse par site */
            'soldesCaisses' => $soldesCaisses,
            'mouvementsEnAttente' => $mouvementsEnAttente,
            'delegationsActives' => $delegationsActives,
            'performancesN1' => $performancesN1,
        ]);
    }

    /**
     * Détermine le statut d'attente correspondant au rôle de l'utilisateur
     */
    private function statutAttenteParRole(string $role): string
    {
        return match ($role) {
            'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
            'controle_gestion' => 'EN_ATTENTE_CDG',
            'daf' => 'EN_ATTENTE_DAF',
            'directeur_pays' => 'EN_ATTENTE_DP',
            default => '',
        };
    }
}
