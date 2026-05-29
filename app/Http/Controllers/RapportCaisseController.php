<?php

namespace App\Http\Controllers;

use App\Exports\RapportCaisseExport;
use App\Exports\RapportsPeriodeExport;
use App\Exports\RapportTempsReelExport;
use App\Models\BonCaisse;
use App\Models\MouvementCaisse;
use App\Models\RapportCaisse;
use App\Models\Site;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Contrôleur des Rapports de Caisse
 * 
 * Gère la génération et la consultation des rapports journaliers de caisse.
 * Seuls les caissiers, DAF et directeurs pays ont accès à ce module.
 */
class RapportCaisseController extends Controller
{
    /**
     * Afficher la liste des rapports de caisse
     */
    public function index(Request $request)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        /* Auto-filtrage par site pour le caissier (y compris les délégués caissier) */
        if ($utilisateur->peutPayer() && $utilisateur->site && !$request->filled('site')) {
            $request->merge(['site' => $utilisateur->site]);
        }

        $query = RapportCaisse::with('caissier')
            ->latest('date_rapport');

        /* Filtrage par site */
        if ($request->filled('site')) {
            $query->parSite($request->site);
        }

        /* Filtrage par période */
        if ($request->filled('date_debut')) {
            $query->where('date_rapport', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->where('date_rapport', '<=', $request->date_fin);
        }

        $rapports = $query->paginate(15)->withQueryString();

        /* Statistiques résumé pour les KPIs */
        $statsQuery = RapportCaisse::query();
        if ($request->filled('site')) {
            $statsQuery->parSite($request->site);
        }

        $statsResume = [
            'total_rapports' => (clone $statsQuery)->count(),
            'total_sorties_global' => (clone $statsQuery)->sum('total_sorties'),
            'total_entrees_global' => (clone $statsQuery)->sum('total_entrees'),
            'dernier_solde' => (clone $statsQuery)->latest('date_rapport')->value('solde_cloture') ?? 0,
        ];

        /* Évolution solde de clôture sur les 10 derniers rapports */
        $evolutionSolde = RapportCaisse::query()
            ->when($request->filled('site'), fn($q) => $q->parSite($request->site))
            ->latest('date_rapport')
            ->take(10)
            ->get(['date_rapport', 'solde_cloture', 'total_entrees', 'total_sorties'])
            ->reverse()
            ->values()
            ->map(fn ($r) => [
                'date' => $r->date_rapport->format('d/m'),
                'solde' => (int) $r->solde_cloture,
                'entrees' => (int) $r->total_entrees,
                'sorties' => (int) $r->total_sorties,
            ]);

        return Inertia::render('Rapports/Index', [
            'rapports' => $rapports,
            'filtres' => $request->only(['site', 'date_debut', 'date_fin']),
            'statsResume' => $statsResume,
            'evolutionSolde' => $evolutionSolde,
        ]);
    }

    /**
     * Tableau de bord des rapports calculés en temps réel
     * 
     * Calcule les entrées/sorties par jour à partir de la table bons_caisse
     * sans générer de lignes dans rapports_caisse.
     * 
     * Filtres : site, date_debut, date_fin, granularite (jour|mois|annee)
     * Par défaut : mois courant, granularité jour
     */
    public function tableauTempsReel(Request $request)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        /* Auto-filtrage par site pour le caissier (y compris les délégués caissier) */
        if ($utilisateur->peutPayer() && $utilisateur->site && !$request->filled('site')) {
            $request->merge(['site' => $utilisateur->site]);
        }

        $donnees = $this->calculerDonneesTempsReel($request);

        /* Données pour le graphe d'évolution */
        $evolutionSolde = collect($donnees['lignes'])
            ->map(fn($l) => [
                'date' => $donnees['granularite'] === 'jour' ? Carbon::parse($l['periode'])->format('d/m') : $l['label'],
                'solde' => (int) $l['solde'],
                'entrees' => (int) $l['entrees'],
                'sorties' => (int) $l['sorties'],
            ]);

        /* Solde caisse du site sélectionné (ou site du caissier) */
        $siteFiltre = $request->input('site');
        $soldeCaisseSite = null;
        if ($siteFiltre) {
            $siteModel = Site::where('nom', $siteFiltre)->first();
            if ($siteModel) {
                $soldeCaisseSite = [
                    'nom' => $siteModel->nom,
                    'solde' => (float) $siteModel->solde_caisse,
                    'solde_format' => $siteModel->solde_caisse_format,
                    'plafond_caisse' => $siteModel->plafond_caisse ? (float) $siteModel->plafond_caisse : null,
                    'plafond_format' => $siteModel->plafond_caisse_format,
                    'sous_seuil' => $siteModel->soldeSousSeuil(),
                ];
            }
        }

        return Inertia::render('Rapports/Index', [
            'lignesRapport' => $donnees['lignes'],
            'filtres' => $request->only(['site', 'date_debut', 'date_fin', 'granularite', 'categorie', 'type_bon']),
            'statsResume' => $donnees['statsResume'],
            'topCategories' => $donnees['topCategories'],
            'evolutionSolde' => $evolutionSolde,
            'categoriesDepense' => BonCaisse::CATEGORIES_DEPENSE,
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'granularite' => $donnees['granularite'],
            'modeTempsReel' => true,
            'soldeCaisseSite' => $soldeCaisseSite,
            'siteUtilisateur' => $utilisateur->site,
            'roleUtilisateur' => $utilisateur->role,
        ]);
    }

    /**
     * Exporter le rapport temps réel en Excel
     * Si date_debut == date_fin (un seul jour), utilise le format détaillé journalier
     * avec en-tête, résumé financier et détail des bons payés.
     */
    public function exportTempsReelExcel(Request $request)
    {
        /* Auto-filtrage par site pour le caissier */
        $utilisateur = Auth::user();
        if ($utilisateur->role === 'caissier' && $utilisateur->site && !$request->filled('site')) {
            $request->merge(['site' => $utilisateur->site]);
        }

        /* Export journalier détaillé (un seul jour) */
        if ($request->filled('date_debut') && $request->filled('date_fin') && $request->date_debut === $request->date_fin) {
            $rapportData = $this->construireRapportJournalier(Carbon::parse($request->date_debut), $request->input('site'));

            $nomFichier = 'rapport-caisse-' . $request->date_debut . ($request->input('site') ? '-' . $request->input('site') : '') . '.xlsx';

            return Excel::download(
                new RapportCaisseExport($rapportData['rapport'], $rapportData['bonsPaye']),
                $nomFichier
            );
        }

        $donnees = $this->calculerDonneesTempsReel($request);

        $dateDebutLabel = Carbon::parse($donnees['dateDebut'])->format('d/m/Y');
        $dateFinLabel = Carbon::parse($donnees['dateFin'])->format('d/m/Y');

        $nomFichier = 'rapport-caisse-' . $donnees['dateDebut'] . '-au-' . $donnees['dateFin'] . '.xlsx';

        return Excel::download(
            new RapportTempsReelExport(
                $donnees['lignes'],
                $donnees['statsResume'],
                $dateDebutLabel,
                $dateFinLabel,
                $donnees['granularite'],
                $request->input('site'),
            ),
            $nomFichier
        );
    }

    /**
     * Exporter le rapport temps réel en PDF
     * Si date_debut == date_fin (un seul jour), utilise le format détaillé journalier.
     */
    public function exportTempsReelPdf(Request $request)
    {
        /* Auto-filtrage par site pour le caissier */
        $utilisateur = Auth::user();
        if ($utilisateur->role === 'caissier' && $utilisateur->site && !$request->filled('site')) {
            $request->merge(['site' => $utilisateur->site]);
        }

        /* Export journalier détaillé (un seul jour) */
        if ($request->filled('date_debut') && $request->filled('date_fin') && $request->date_debut === $request->date_fin) {
            $rapportData = $this->construireRapportJournalier(Carbon::parse($request->date_debut), $request->input('site'));

            $pdf = Pdf::loadView('exports.rapport-caisse-pdf', [
                'rapport' => $rapportData['rapport'],
                'bonsPaye' => $rapportData['bonsPaye'],
            ])->setPaper('a4', 'portrait');

            $nomFichier = 'rapport-caisse-' . $request->date_debut . ($request->input('site') ? '-' . $request->input('site') : '') . '.pdf';

            return $pdf->stream($nomFichier);
        }

        $donnees = $this->calculerDonneesTempsReel($request);

        $dateDebutLabel = Carbon::parse($donnees['dateDebut'])->format('d/m/Y');
        $dateFinLabel = Carbon::parse($donnees['dateFin'])->format('d/m/Y');

        $pdf = Pdf::loadView('exports.rapports-temps-reel-pdf', [
            'lignes' => $donnees['lignes'],
            'statsResume' => $donnees['statsResume'],
            'dateDebut' => $dateDebutLabel,
            'dateFin' => $dateFinLabel,
            'granularite' => $donnees['granularite'],
            'site' => $request->input('site'),
        ])->setPaper('a4', 'portrait');

        $nomFichier = 'rapport-caisse-' . $donnees['dateDebut'] . '-au-' . $donnees['dateFin'] . '.pdf';

        return $pdf->stream($nomFichier);
    }

    /**
     * Calculer les données temps réel à partir des bons de caisse payés
     * 
     * Méthode partagée entre tableauTempsReel(), exportTempsReelExcel() et exportTempsReelPdf()
     */
    private function calculerDonneesTempsReel(Request $request): array
    {
        /* Période par défaut : mois courant */
        $granularite = $request->input('granularite', 'jour');
        $dateDebut = $request->filled('date_debut')
            ? Carbon::parse($request->date_debut)->startOfDay()
            : now()->startOfMonth();
        $dateFin = $request->filled('date_fin')
            ? Carbon::parse($request->date_fin)->endOfDay()
            : now()->endOfDay();

        /* Format SQL et label selon la granularité */
        switch ($granularite) {
            case 'mois':
                $groupeSql = "DATE_FORMAT(date_paiement, '%Y-%m')";
                $formatLabel = fn($key) => Carbon::createFromFormat('Y-m', $key)->translatedFormat('F Y');
                break;
            case 'annee':
                $groupeSql = "YEAR(date_paiement)";
                $formatLabel = fn($key) => (string) $key;
                break;
            default: /* jour */
                $groupeSql = "DATE(date_paiement)";
                $formatLabel = fn($key) => Carbon::parse($key)->format('d/m/Y');
                break;
        }

        /* Requête principale : bons payés dans la période */
        $queryBase = BonCaisse::query()
            ->whereNotNull('date_paiement')
            ->whereBetween('date_paiement', [$dateDebut, $dateFin]);

        if ($request->filled('site')) {
            $queryBase->parSite($request->site);
        }
        if ($request->filled('categorie')) {
            $queryBase->where('categorie_depense', $request->categorie);
        }
        if ($request->filled('type_bon')) {
            $queryBase->where('type_bon', $request->type_bon);
        }

        /* Sorties (paiements) groupées */
        $sorties = (clone $queryBase)
            ->select(
                DB::raw("{$groupeSql} as periode"),
                DB::raw('COUNT(*) as nombre_bons'),
                DB::raw('SUM(montant) as total_sorties'),
            )
            ->groupBy('periode')
            ->orderBy('periode')
            ->get()
            ->keyBy('periode');

        /* Détail des bons payés par jour (pour l'expansion dans le tableau) */
        $bonsParJour = [];
        if ($granularite === 'jour') {
            $bonsParJour = (clone $queryBase)
                ->with('demandeur:id,name,prenom')
                ->select('id', 'numero', 'beneficiaire', 'motif', 'montant', 'categorie_depense', 'mode_paiement', 'type_bon', 'date_paiement', 'site', 'demandeur_id')
                ->orderBy('date_paiement')
                ->get()
                ->groupBy(fn($bon) => $bon->date_paiement->format('Y-m-d'))
                ->map(fn($bons) => $bons->map(fn($b) => [
                    'id' => $b->id,
                    'numero' => $b->numero,
                    'beneficiaire' => $b->beneficiaire,
                    'demandeur' => $b->demandeur ? ($b->demandeur->prenom . ' ' . $b->demandeur->name) : null,
                    'motif' => $b->motif,
                    'montant' => (float) $b->montant,
                    'categorie' => BonCaisse::CATEGORIES_DEPENSE[$b->categorie_depense] ?? $b->categorie_depense,
                    'mode_paiement' => $b->mode_paiement,
                    'type_bon' => $b->type_bon,
                ])->values())
                ->toArray();
        }

        /* Entrées : mouvements de caisse validés (approvisionnements) + rapports existants */
        $entreesParPeriode = [];
        $retraitsParPeriode = [];

        /* Mouvements de caisse validés dans la période */
        $mouvementsQuery = MouvementCaisse::valides()
            ->whereNotNull('date_validation')
            ->whereBetween('date_validation', [$dateDebut, $dateFin]);
        if ($request->filled('site')) {
            $mouvementsQuery->parSite($request->site);
        }

        if ($granularite === 'jour') {
            $mouvements = $mouvementsQuery->get();
            foreach ($mouvements as $mvt) {
                $key = $mvt->date_validation->format('Y-m-d');
                if ($mvt->type === 'approvisionnement' || $mvt->type === 'ajustement') {
                    $entreesParPeriode[$key] = ($entreesParPeriode[$key] ?? 0) + (float) $mvt->montant;
                } elseif ($mvt->type === 'retrait') {
                    $retraitsParPeriode[$key] = ($retraitsParPeriode[$key] ?? 0) + (float) $mvt->montant;
                }
            }

            /* Rapports manuels existants (entrées supplémentaires si renseignées) */
            $rapportsExistants = RapportCaisse::query()
                ->whereBetween('date_rapport', [$dateDebut, $dateFin])
                ->when($request->filled('site'), fn($q) => $q->parSite($request->site))
                ->get()
                ->keyBy(fn($r) => $r->date_rapport->format('Y-m-d'));
            foreach ($rapportsExistants as $key => $rapport) {
                if ((float) $rapport->total_entrees > 0) {
                    $entreesParPeriode[$key] = ($entreesParPeriode[$key] ?? 0) + (float) $rapport->total_entrees;
                }
            }
        } else {
            /* Pour mois/année, agréger les mouvements par période */
            $mouvements = $mouvementsQuery->get();
            foreach ($mouvements as $mvt) {
                $key = $granularite === 'mois'
                    ? $mvt->date_validation->format('Y-m')
                    : (string) $mvt->date_validation->year;
                if ($mvt->type === 'approvisionnement' || $mvt->type === 'ajustement') {
                    $entreesParPeriode[$key] = ($entreesParPeriode[$key] ?? 0) + (float) $mvt->montant;
                } elseif ($mvt->type === 'retrait') {
                    $retraitsParPeriode[$key] = ($retraitsParPeriode[$key] ?? 0) + (float) $mvt->montant;
                }
            }
        }

        /* Construire le tableau jour par jour (ou par mois/année) */
        $lignes = [];
        $soldeCourant = 0;

        /* Récupérer le solde d'ouverture avant la période */
        if ($request->filled('site')) {
            /* Dernier rapport avant la période */
            $dernierRapportAvant = RapportCaisse::where('date_rapport', '<', $dateDebut)
                ->parSite($request->site)
                ->orderByDesc('date_rapport')
                ->first();
            if ($dernierRapportAvant) {
                $soldeCourant = (float) $dernierRapportAvant->solde_cloture;
            } else {
                /* Sinon utiliser le solde actuel du site comme référence de base */
                $siteModel = Site::where('nom', $request->site)->first();
                $soldeCourant = $siteModel ? (float) $siteModel->solde_caisse : 0;
            }
        }
        $soldeOuverture = $soldeCourant;

        /* Générer les périodes */
        if ($granularite === 'jour') {
            $periodes = CarbonPeriod::create($dateDebut, '1 day', $dateFin);
            foreach ($periodes as $date) {
                $key = $date->format('Y-m-d');
                $entrees = $entreesParPeriode[$key] ?? 0;
                $sortiesBons = isset($sorties[$key]) ? (float) $sorties[$key]->total_sorties : 0;
                $sortiesRetraits = $retraitsParPeriode[$key] ?? 0;
                $sortiesJour = $sortiesBons + $sortiesRetraits;
                $nombreBons = isset($sorties[$key]) ? (int) $sorties[$key]->nombre_bons : 0;
                $soldeCourant = $soldeCourant + $entrees - $sortiesJour;

                $lignes[] = [
                    'periode' => $key,
                    'label' => $date->format('d/m/Y'),
                    'jour_semaine' => $date->translatedFormat('l'),
                    'entrees' => $entrees,
                    'sorties' => $sortiesJour,
                    'nombre_bons' => $nombreBons,
                    'solde' => $soldeCourant,
                    'bons' => $bonsParJour[$key] ?? [],
                ];
            }
        } else {
            /* Fusionner les clés des sorties bons + mouvements */
            $toutesLesCles = collect(array_keys($sorties->toArray()))
                ->merge(array_keys($entreesParPeriode))
                ->merge(array_keys($retraitsParPeriode))
                ->unique()
                ->sort()
                ->values();

            foreach ($toutesLesCles as $key) {
                $entrees = $entreesParPeriode[$key] ?? 0;
                $sortiesBons = isset($sorties[$key]) ? (float) $sorties[$key]->total_sorties : 0;
                $sortiesRetraits = $retraitsParPeriode[$key] ?? 0;
                $sortiesPeriode = $sortiesBons + $sortiesRetraits;
                $nombreBons = isset($sorties[$key]) ? (int) $sorties[$key]->nombre_bons : 0;
                $soldeCourant = $soldeCourant + $entrees - $sortiesPeriode;

                $lignes[] = [
                    'periode' => $key,
                    'label' => $formatLabel($key),
                    'jour_semaine' => null,
                    'entrees' => $entrees,
                    'sorties' => $sortiesPeriode,
                    'nombre_bons' => $nombreBons,
                    'solde' => $soldeCourant,
                ];
            }
        }

        /* KPIs résumé */
        $totalSorties = collect($lignes)->sum('sorties');
        $totalEntrees = collect($lignes)->sum('entrees');
        $totalBons = collect($lignes)->sum('nombre_bons');
        $moyenneJournaliere = count($lignes) > 0 ? $totalSorties / count($lignes) : 0;

        /* Top 5 catégories sur la période */
        $topCategories = (clone $queryBase)
            ->select('categorie_depense', DB::raw('SUM(montant) as total'), DB::raw('COUNT(*) as nombre'))
            ->groupBy('categorie_depense')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn($c) => [
                'categorie' => $c->categorie_depense,
                'label' => BonCaisse::CATEGORIES_DEPENSE[$c->categorie_depense] ?? $c->categorie_depense,
                'total' => (float) $c->total,
                'nombre' => (int) $c->nombre,
            ]);

        return [
            'lignes' => $lignes,
            'statsResume' => [
                'total_sorties' => $totalSorties,
                'total_entrees' => $totalEntrees,
                'total_bons' => $totalBons,
                'moyenne_journaliere' => round($moyenneJournaliere),
                'solde_ouverture' => $soldeOuverture,
                'solde_actuel' => $soldeCourant,
                'nombre_jours' => count($lignes),
            ],
            'topCategories' => $topCategories,
            'granularite' => $granularite,
            'dateDebut' => $dateDebut->format('Y-m-d'),
            'dateFin' => $dateFin->format('Y-m-d'),
        ];
    }

    /**
     * Afficher le formulaire de création d'un rapport
     */
    public function create()
    {
        $utilisateur = Auth::user();
        $site = $utilisateur->site ?? '';

        /* Récupérer le solde de clôture précédent comme solde d'ouverture */
        $soldeOuverture = RapportCaisse::soldePrecedent($site);

        /* Récupérer les bons payés du jour pour ce site */
        $bonsPayeDuJour = BonCaisse::with('demandeur')
            ->parStatut('PAYE')
            ->whereDate('updated_at', today())
            ->when($site, fn($q) => $q->parSite($site))
            ->get();

        $totalSorties = $bonsPayeDuJour->sum('montant');

        /* Ventilation par catégorie */
        $detailParCategorie = $bonsPayeDuJour
            ->groupBy('categorie_depense')
            ->map(function ($group, $categorie) {
                return [
                    'categorie' => $categorie,
                    'label' => BonCaisse::CATEGORIES_DEPENSE[$categorie] ?? $categorie,
                    'nombre' => $group->count(),
                    'montant' => (float) $group->sum('montant'),
                ];
            })
            ->values();

        /* Ventilation par mode de paiement */
        $detailParMode = $bonsPayeDuJour
            ->groupBy('mode_paiement_effectif')
            ->map(function ($group, $mode) {
                return [
                    'mode' => $mode,
                    'label' => BonCaisse::MODES_PAIEMENT[$mode] ?? $mode,
                    'nombre' => $group->count(),
                    'montant' => (float) $group->sum('montant'),
                ];
            })
            ->values();

        return Inertia::render('Rapports/Create', [
            'soldeOuverture' => $soldeOuverture,
            'totalSorties' => $totalSorties,
            'nombreBons' => $bonsPayeDuJour->count(),
            'bonsPayeDuJour' => $bonsPayeDuJour,
            'detailParCategorie' => $detailParCategorie,
            'detailParMode' => $detailParMode,
            'dateRapport' => now()->toDateString(),
            'site' => $site,
        ]);
    }

    /**
     * Enregistrer un nouveau rapport de caisse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date_rapport' => ['required', 'date'],
            'site' => ['required', 'string', 'max:255'],
            'solde_ouverture' => ['required', 'numeric', 'min:0'],
            'total_entrees' => ['required', 'numeric', 'min:0'],
            'total_sorties' => ['required', 'numeric', 'min:0'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ]);

        /* Calculer le solde de clôture */
        $soldeCloture = $validated['solde_ouverture'] + $validated['total_entrees'] - $validated['total_sorties'];

        /* Récupérer les bons payés pour les statistiques détaillées */
        $bonsPayeDuJour = BonCaisse::parStatut('PAYE')
            ->whereDate('updated_at', $validated['date_rapport'])
            ->when($validated['site'], fn($q) => $q->parSite($validated['site']))
            ->get();

        $rapport = RapportCaisse::create([
            'date_rapport' => $validated['date_rapport'],
            'site' => $validated['site'],
            'solde_ouverture' => $validated['solde_ouverture'],
            'total_entrees' => $validated['total_entrees'],
            'total_sorties' => $validated['total_sorties'],
            'solde_cloture' => $soldeCloture,
            'observations' => $validated['observations'] ?? null,
            'caissier_id' => Auth::id(),
        ]);

        /* Calculer et sauvegarder les statistiques détaillées */
        $rapport->calculerStatistiques($bonsPayeDuJour);
        $rapport->save();

        return redirect()
            ->route('rapports.show', $rapport)
            ->with('success', 'Rapport de caisse créé avec succès.');
    }

    /**
     * Afficher le détail d'un rapport
     */
    public function show(RapportCaisse $rapport)
    {
        $rapport->load('caissier', 'visaDaf');

        /* Récupérer les bons payés ce jour pour ce site avec détails complets */
        $bonsPaye = BonCaisse::with('demandeur', 'ventilations')
            ->parStatut('PAYE')
            ->parSite($rapport->site)
            ->whereDate('updated_at', $rapport->date_rapport)
            ->get();

        /* Phase 5 : Enrichir chaque bon avec le délai de traitement */
        $bonsPayeDetailles = $bonsPaye->map(function ($bon) {
            return [
                'id' => $bon->id,
                'numero' => $bon->numero,
                'beneficiaire' => $bon->beneficiaire,
                'type_beneficiaire' => $bon->type_beneficiaire,
                'motif' => $bon->motif,
                'montant' => (float) $bon->montant,
                'montant_format' => $bon->montant_format,
                'categorie_depense' => $bon->categorie_depense,
                'mode_paiement_effectif' => $bon->mode_paiement_effectif,
                'code_analytique' => $bon->code_analytique,
                'site' => $bon->site,
                'service' => $bon->service,
                'type_bon' => $bon->type_bon,
                'date_soumission' => $bon->date_soumission?->format('d/m/Y H:i'),
                'date_paiement' => $bon->date_paiement?->format('d/m/Y H:i'),
                'delai_traitement' => $bon->delai_traitement,
                'demandeur_nom' => $bon->demandeur?->nom_complet,
                'ventilations' => $bon->ventilations->map(fn ($v) => [
                    'code_analytique' => $v->code_analytique,
                    'montant' => (float) $v->montant,
                    'pourcentage' => $v->pourcentage,
                ]),
            ];
        });

        /* Solde caisse du site */
        $siteModel = Site::where('nom', $rapport->site)->first();
        $soldeCaisseSite = $siteModel ? [
            'solde' => (float) $siteModel->solde_caisse,
            'solde_format' => $siteModel->solde_caisse_format,
            'sous_seuil' => $siteModel->soldeSousSeuil(),
        ] : null;

        return Inertia::render('Rapports/Show', [
            'rapport' => $rapport,
            'bonsPaye' => $bonsPaye,
            'detailsBons' => $bonsPayeDetailles,
            'soldeCaisseSite' => $soldeCaisseSite,
            'categoriesLabels' => BonCaisse::CATEGORIES_DEPENSE,
            'modesPaiementLabels' => BonCaisse::MODES_PAIEMENT,
        ]);
    }

    /**
     * Exporter le rapport en Excel
     */
    public function exportExcel(RapportCaisse $rapport)
    {
        $rapport->load('caissier', 'visaDaf');

        $bonsPaye = BonCaisse::with('demandeur')
            ->parStatut('PAYE')
            ->parSite($rapport->site)
            ->whereDate('updated_at', $rapport->date_rapport)
            ->get();

        $nomFichier = 'rapport-caisse-' . $rapport->site . '-' . $rapport->date_rapport->format('Y-m-d') . '.xlsx';

        return Excel::download(new RapportCaisseExport($rapport, $bonsPaye), $nomFichier);
    }

    /**
     * Exporter le rapport en PDF
     */
    public function exportPdf(RapportCaisse $rapport)
    {
        $rapport->load('caissier', 'visaDaf');

        $bonsPaye = BonCaisse::with('demandeur', 'ventilations')
            ->parStatut('PAYE')
            ->parSite($rapport->site)
            ->whereDate('updated_at', $rapport->date_rapport)
            ->get();

        $pdf = Pdf::loadView('exports.rapport-caisse-pdf', [
            'rapport' => $rapport,
            'bonsPaye' => $bonsPaye,
            'categoriesLabels' => BonCaisse::CATEGORIES_DEPENSE,
            'modesPaiementLabels' => BonCaisse::MODES_PAIEMENT,
        ])->setPaper('a4', 'landscape');

        $nomFichier = 'rapport-caisse-' . $rapport->site . '-' . $rapport->date_rapport->format('Y-m-d') . '.pdf';

        return $pdf->stream($nomFichier);
    }

    /**
     * Apposer le visa DAF sur un rapport
     */
    public function viserDaf(RapportCaisse $rapport)
    {
        $utilisateur = Auth::user();

        if (!in_array($utilisateur->role, ['daf', 'administrateur'])) {
            abort(403, 'Seul le DAF peut viser un rapport.');
        }

        if ($rapport->viserParDaf($utilisateur)) {
            return redirect()
                ->route('rapports.show', $rapport)
                ->with('success', 'Visa DAF apposé avec succès.');
        }

        return back()->with('error', 'Ce rapport a déjà été visé.');
    }

    /**
     * Envoyer le rapport journalier par email aux destinataires
     * (DAF, Contrôle de gestion, Caissier du site, Administrateurs)
     * 
     * Si aucun site n'est précisé, envoie pour tous les sites actifs.
     */
    public function envoyerRapportEmail(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
            'site' => ['nullable', 'string'],
        ]);

        $date = Carbon::parse($request->date);

        /* Déterminer les sites à traiter */
        $sites = $request->filled('site')
            ? collect([$request->site])
            : Site::actifs()->orderBy('nom')->pluck('nom');

        if ($sites->isEmpty()) {
            return back()->with('error', 'Aucun site actif trouvé.');
        }

        $nbEnvoyes = 0;
        $nbIgnores = 0;
        $erreurs = [];

        foreach ($sites as $site) {
            try {
                $resultat = $this->envoyerRapportPourSite($date, $site);
                if ($resultat) {
                    $nbEnvoyes++;
                } else {
                    $nbIgnores++;
                }
            } catch (\Exception $e) {
                $erreurs[] = "{$site} : {$e->getMessage()}";
                \Illuminate\Support\Facades\Log::error('Erreur envoi rapport email manuel', [
                    'date' => $date->toDateString(),
                    'site' => $site,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /* Construire le message de retour */
        if ($nbEnvoyes > 0 && empty($erreurs)) {
            $label = $nbEnvoyes === 1 ? '1 rapport envoyé' : "{$nbEnvoyes} rapports envoyés";
            $msg = "{$label} pour le {$date->format('d/m/Y')}.";
            if ($nbIgnores > 0) {
                $msg .= " ({$nbIgnores} site(s) sans mouvement ignoré(s))";
            }
            return back()->with('success', $msg);
        }

        if ($nbEnvoyes === 0 && empty($erreurs)) {
            return back()->with('error', "Aucun mouvement de caisse le {$date->format('d/m/Y')} pour " . ($sites->count() === 1 ? $sites->first() : 'les sites actifs') . '.');
        }

        $msg = $nbEnvoyes > 0 ? "{$nbEnvoyes} rapport(s) envoyé(s), " : '';
        $msg .= count($erreurs) . ' erreur(s) : ' . implode(' | ', $erreurs);
        return back()->with('error', $msg);
    }

    /**
     * Construire un RapportCaisse en mémoire (sans persister) pour un jour et un site donné.
     * Utilisé par l'export Excel/PDF journalier et l'envoi email.
     *
     * @return array{rapport: RapportCaisse, bonsPaye: \Illuminate\Support\Collection}
     */
    private function construireRapportJournalier(Carbon $date, ?string $site = null): array
    {
        /* Récupérer les bons payés ce jour */
        $query = BonCaisse::with('demandeur')
            ->whereNotNull('date_paiement')
            ->whereDate('date_paiement', $date);
        if ($site) {
            $query->parSite($site);
        }
        $bonsPaye = $query->get();

        $totalSorties = (float) $bonsPaye->sum('montant');

        /* Entrées du jour (mouvements de caisse validés) */
        $entreesQuery = MouvementCaisse::valides()
            ->whereNotNull('date_validation')
            ->whereDate('date_validation', $date)
            ->whereIn('type', ['approvisionnement', 'ajustement']);
        if ($site) {
            $entreesQuery->parSite($site);
        }
        $totalEntrees = (float) $entreesQuery->sum('montant');

        /* Solde d'ouverture */
        $soldeOuverture = $site ? RapportCaisse::soldePrecedent($site) : 0;

        /* Trouver le caissier du site */
        $caissier = $site
            ? \App\Models\User::where('actif', true)->where('role', 'caissier')->where('site', $site)->first()
            : null;

        /* Construire un objet RapportCaisse en mémoire (sans persister) */
        $rapport = new RapportCaisse([
            'date_rapport' => $date,
            'site' => $site ?? 'Tous les sites',
            'solde_ouverture' => $soldeOuverture,
            'total_entrees' => $totalEntrees,
            'total_sorties' => $totalSorties,
            'solde_cloture' => $soldeOuverture + $totalEntrees - $totalSorties,
            'caissier_id' => $caissier?->id,
        ]);
        $rapport->setRelation('caissier', $caissier);
        $rapport->calculerStatistiques($bonsPaye);

        return ['rapport' => $rapport, 'bonsPaye' => $bonsPaye];
    }

    /**
     * Envoyer le rapport pour un site donné
     * Retourne true si envoyé, false si aucun mouvement
     */
    private function envoyerRapportPourSite(Carbon $date, string $site): bool
    {
        $data = $this->construireRapportJournalier($date, $site);
        $rapport = $data['rapport'];
        $bonsPaye = $data['bonsPaye'];

        if ($bonsPaye->isEmpty() && (float) $rapport->total_sorties == 0) {
            return false;
        }

        $rapport->observations = 'Rapport envoyé manuellement par ' . Auth::user()->prenom . ' ' . Auth::user()->name;

        /* Destinataires : DAF + contrôle de gestion + administrateurs + caissier du site */
        $destinataires = \App\Models\User::where('actif', true)
            ->where(function ($q) use ($site) {
                $q->whereIn('role', ['daf', 'controle_gestion', 'administrateur'])
                  ->orWhere(function ($q2) use ($site) {
                      $q2->where('role', 'caissier')->where('site', $site);
                  });
            })
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->toArray();

        if (empty($destinataires)) {
            return false;
        }

        \Illuminate\Support\Facades\Mail::to($destinataires)
            ->send(new \App\Mail\RapportCaisseQuotidien($rapport, $bonsPaye));

        return true;
    }

    /**
     * Exporter la synthèse des rapports d'une période en Excel
     */
    public function exportPeriodeExcel(Request $request)
    {
        $request->validate([
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
        ]);

        $rapports = RapportCaisse::with('caissier')
            ->whereBetween('date_rapport', [$request->date_debut, $request->date_fin])
            ->when($request->filled('site'), fn($q) => $q->parSite($request->site))
            ->latest('date_rapport')
            ->get();

        $dateDebut = \Carbon\Carbon::parse($request->date_debut)->format('d/m/Y');
        $dateFin = \Carbon\Carbon::parse($request->date_fin)->format('d/m/Y');
        $nomFichier = 'synthese-rapports-' . $request->date_debut . '-au-' . $request->date_fin . '.xlsx';

        return Excel::download(new RapportsPeriodeExport($rapports, $dateDebut, $dateFin), $nomFichier);
    }

    /**
     * Exporter la synthèse des rapports d'une période en PDF
     */
    public function exportPeriodePdf(Request $request)
    {
        $request->validate([
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
        ]);

        $rapports = RapportCaisse::with('caissier')
            ->whereBetween('date_rapport', [$request->date_debut, $request->date_fin])
            ->when($request->filled('site'), fn($q) => $q->parSite($request->site))
            ->latest('date_rapport')
            ->get();

        $dateDebut = \Carbon\Carbon::parse($request->date_debut)->format('d/m/Y');
        $dateFin = \Carbon\Carbon::parse($request->date_fin)->format('d/m/Y');

        $pdf = Pdf::loadView('exports.rapports-periode-pdf', [
            'rapports' => $rapports,
            'dateDebut' => $dateDebut,
            'dateFin' => $dateFin,
        ])->setPaper('a4', 'landscape');

        $nomFichier = 'synthese-rapports-' . $request->date_debut . '-au-' . $request->date_fin . '.pdf';

        return $pdf->stream($nomFichier);
    }
}
