<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPieceJointeOcrJob;
use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\Delegation;
use App\Models\HistoriqueAction;
use App\Models\OtpValidation;
use App\Models\Parametre;
use App\Models\MotifUrgence;
use App\Models\PieceJointe;
use App\Models\Service;
use App\Models\Site;
use App\Models\Validation;
use App\Services\NimbaSmsService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Contrôleur des Bons de Caisse
 * 
 * Gère le cycle de vie complet d'un bon de caisse :
 * - Création (brouillon)
 * - Soumission pour validation
 * - Affichage (liste et détail)
 * - Paiement
 * - Régularisation (pour les BP)
 * - Archivage
 */
class BonCaisseController extends Controller
{
    /**
     * Afficher la liste des bons de caisse
     * Filtrée selon le rôle de l'utilisateur
     */
    public function index(Request $request)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        $query = BonCaisse::with('demandeur')
            ->latest('date_demande');

        /* Filtrage par statut si spécifié */
        if ($request->filled('statut')) {
            $query->parStatut($request->statut);
        }

        /* Filtrage par type de bon */
        if ($request->filled('type_bon')) {
            $query->where('type_bon', $request->type_bon);
        }

        /* Recherche par numéro ou bénéficiaire */
        if ($request->filled('recherche')) {
            $recherche = $request->recherche;
            $query->where(function ($q) use ($recherche) {
                $q->where('numero', 'like', "%{$recherche}%")
                    ->orWhere('beneficiaire', 'like', "%{$recherche}%")
                    ->orWhere('motif', 'like', "%{$recherche}%");
            });
        }

        /* Visibilité des bons selon les rôles effectifs (strict + suivi) :
         * - Ses propres bons (comme demandeur)
         * - Bons en attente à son niveau de validation (rôle natif + délégué)
         * - Bons qu'il a déjà validés (pour suivi)
         * - Administrateur : accès global
         */
        $roles = array_unique(array_merge([$utilisateur->role], method_exists($utilisateur, 'rolesValidationEffectifs') ? $utilisateur->rolesValidationEffectifs() : []));

        /* IDs des bons déjà validés par cet utilisateur */
        $bonsDejaValides = Validation::where('validateur_id', $utilisateur->id)
            ->whereIn('statut', ['approuve', 'rejete'])
            ->pluck('bon_caisse_id')
            ->unique()
            ->toArray();

        if (in_array('administrateur', $roles)) {
            /* Administrateur : accès global, voit tout sauf brouillons des autres */
            $query->where(function ($q) use ($utilisateur) {
                $q->where('demandeur_id', $utilisateur->id)
                  ->orWhere('statut', '!=', 'BROUILLON');
            });
        } else {
            /* Mapping rôle → statut en attente correspondant */
            $roleStatutMap = [
                'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
                'controle_gestion' => 'EN_ATTENTE_CDG',
                'daf' => 'EN_ATTENTE_DAF',
                'directeur_pays' => 'EN_ATTENTE_DP',
            ];

            /* Collecter les statuts en attente pour les rôles effectifs du validateur */
            $statutsEnAttenteVisibles = [];
            foreach ($roles as $r) {
                if (isset($roleStatutMap[$r])) {
                    $statutsEnAttenteVisibles[] = $roleStatutMap[$r];
                }
            }

            /* Services accessibles pour les chefs de service (natif + délégué) */
            $servicesAccessibles = [];
            if (in_array('responsable_service', $roles)) {
                if ($utilisateur->role === 'responsable_service' && $utilisateur->service) {
                    $servicesAccessibles[] = $utilisateur->service;
                }
                foreach (Delegation::delegantsActifsPour($utilisateur->id) as $delegant) {
                    if ($delegant->role === 'responsable_service' && $delegant->service) {
                        $servicesAccessibles[] = $delegant->service;
                    }
                }
                $servicesAccessibles = array_unique($servicesAccessibles);
            }

            $query->where(function ($q) use ($utilisateur, $statutsEnAttenteVisibles, $bonsDejaValides, $servicesAccessibles, $roles) {
                /* 1. Ses propres bons (tous statuts) */
                $q->where('demandeur_id', $utilisateur->id);

                /* 2. Bons en attente à son niveau de validation */
                if (!empty($statutsEnAttenteVisibles)) {
                    $q->orWhere(function ($q2) use ($statutsEnAttenteVisibles, $servicesAccessibles, $roles) {
                        $q2->whereIn('statut', $statutsEnAttenteVisibles);
                        /* Chef de service : restreindre aux services accessibles */
                        if (in_array('responsable_service', $roles) && !empty($servicesAccessibles)
                            && !array_intersect(['controle_gestion', 'daf', 'directeur_pays'], $roles)) {
                            $q2->whereIn('service', $servicesAccessibles);
                        }
                    });
                }

                /* 3. Bons déjà validés par l'utilisateur (suivi) */
                if (!empty($bonsDejaValides)) {
                    $q->orWhereIn('id', $bonsDejaValides);
                }

                /* 4. Caissier : bons de son site à payer/régulariser */
                if (in_array('caissier', $roles)) {
                    $q->orWhere(function ($q2) use ($utilisateur) {
                        $q2->whereIn('statut', ['APPROUVE', 'PAYE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE', 'ARCHIVE']);
                        if ($utilisateur->site) {
                            $q2->where('site', $utilisateur->site);
                        }
                    });
                }
            });
        }

        $bonsCaisse = $query->paginate(15)->withQueryString();

        /* ====== Statistiques contextuelles par utilisateur ====== */
        $statsQuery = clone $query;
        $debutMois = now()->startOfMonth();

        $statsIndex = [
            'total' => (clone $statsQuery)->where('statut', '!=', 'BROUILLON')->count(),
            'en_attente' => (clone $statsQuery)->whereIn('statut', [
                'EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP',
            ])->count(),
            'approuves' => (clone $statsQuery)->where('statut', 'APPROUVE')->count(),
            'payes' => (clone $statsQuery)->whereIn('statut', ['PAYE', 'ARCHIVE', 'REGULARISE', 'EN_ATTENTE_REGULARISATION'])->count(),
            'rejetes' => (clone $statsQuery)->where('statut', 'REJETE')->count(),
            'montant_total_paye' => (clone $statsQuery)->whereIn('statut', ['PAYE', 'ARCHIVE', 'REGULARISE', 'EN_ATTENTE_REGULARISATION'])->sum('montant'),
            'payes_ce_mois' => (clone $statsQuery)->whereIn('statut', ['PAYE', 'ARCHIVE', 'REGULARISE'])->where('date_paiement', '>=', $debutMois)->count(),
            'montant_paye_ce_mois' => (clone $statsQuery)->whereIn('statut', ['PAYE', 'ARCHIVE', 'REGULARISE'])->where('date_paiement', '>=', $debutMois)->sum('montant'),
            'bp_en_retard' => (clone $statsQuery)->where('statut', 'EN_ATTENTE_REGULARISATION')
                ->whereNotNull('date_limite_regularisation')
                ->where('date_limite_regularisation', '<', now())->count(),
        ];

        /* Stats spécifiques au rôle */
        if ($utilisateur->peutValider()) {
            $rolesEffectifs = $utilisateur->rolesValidationEffectifs();
            $statutsAttendus = [];
            foreach ($rolesEffectifs as $role) {
                $statut = match ($role) {
                    'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
                    'controle_gestion' => 'EN_ATTENTE_CDG',
                    'daf' => 'EN_ATTENTE_DAF',
                    'directeur_pays' => 'EN_ATTENTE_DP',
                    default => null,
                };
                if ($statut) $statutsAttendus[] = $statut;
            }
            $statsIndex['a_valider'] = !empty($statutsAttendus)
                ? BonCaisse::whereIn('statut', $statutsAttendus)->count()
                : 0;
        }

        return Inertia::render('BonsCaisse/Index', [
            'bonsCaisse' => $bonsCaisse,
            'filtres' => $request->only(['statut', 'type_bon', 'recherche']),
            'statuts' => BonCaisse::STATUTS_LABELS,
            'peutValider' => $utilisateur->peutValider(),
            'roleUtilisateur' => $utilisateur->role,
            'statsIndex' => $statsIndex,
        ]);
    }

    /**
     * Afficher le formulaire de création d'un nouveau bon
     */
    public function create()
    {
        /* Motifs d'urgence prédéfinis (Phase 1.1) */
        $motifsUrgence = MotifUrgence::where('actif', true)->pluck('libelle')->toArray();

        return Inertia::render('BonsCaisse/Create', [
            'numero' => BonCaisse::genererNumero(),
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::actifs()->orderBy('nom')->pluck('nom'),
            'codesAnalytiques' => CodeAnalytique::actifs()->with('service')->orderBy('code')->get(),
            'categoriesDepense' => BonCaisse::CATEGORIES_DEPENSE,
            'typesBeneficiaire' => BonCaisse::TYPES_BENEFICIAIRE,
            'modesPaiement' => BonCaisse::MODES_PAIEMENT,
            'montantMax' => Parametre::montantMax(),
            'seuilDP' => Parametre::seuilDP(),
            'niveauxUrgence' => BonCaisse::NIVEAUX_URGENCE,
            'motifsUrgence' => $motifsUrgence,
        ]);
    }

    /**
     * Enregistrer un nouveau bon de caisse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            /* Section 1 : Identification */
            'type_bon' => ['required', Rule::in(['BD', 'BP'])],
            'site' => ['required', 'string', 'max:255'],
            'service' => ['required', 'string', 'max:255'],
            'code_analytique' => ['nullable', 'string', 'max:255'],

            /* Section 2 : Bénéficiaire */
            'beneficiaire' => ['required', 'string', 'max:255'],
            'type_beneficiaire' => ['required', Rule::in(array_keys(BonCaisse::TYPES_BENEFICIAIRE))],
            'telephone_beneficiaire' => ['nullable', 'string', 'max:50'],
            'mode_paiement' => ['required', Rule::in(array_keys(BonCaisse::MODES_PAIEMENT))],

            /* Section 3 : Détails de la dépense */
            'motif' => ['required', 'string', 'min:10'],
            'categorie_depense' => ['required', Rule::in(array_keys(BonCaisse::CATEGORIES_DEPENSE))],
            'montant' => ['required', 'numeric', 'min:1', 'max:' . Parametre::montantMax()],
            'montant_lettres' => ['nullable', 'string', 'max:500'],
            'devise' => ['nullable', 'string', 'max:10'],

            /* Section 4 : Pièces justificatives */
            'pieces_jointes' => ['nullable', 'array'],
            'pieces_jointes.*' => [
                'file',
                'mimes:' . implode(',', BonCaisse::FORMATS_FICHIERS_AUTORISES),
                'max:' . (BonCaisse::TAILLE_MAX_FICHIER / 1024), /* max en Ko */
            ],
            'types_documents' => ['nullable', 'array'],
            'types_documents.*' => ['nullable', 'string'],

            /* Urgence */
            'niveau_urgence' => ['nullable', 'string', Rule::in(array_keys(BonCaisse::NIVEAUX_URGENCE))],
            'motif_urgence' => ['nullable', 'required_if:niveau_urgence,urgente,tres_urgente', 'string', 'max:255'],
            'justification_urgence' => ['nullable', 'required_if:niveau_urgence,urgente,tres_urgente', 'string', 'min:10', 'max:1000'],

            /* Ventilation analytique (multi-codes) */
            'ventilations' => ['nullable', 'array'],
            'ventilations.*.code_analytique' => ['required_with:ventilations', 'string'],
            'ventilations.*.montant' => ['required_with:ventilations', 'numeric', 'min:0'],
            'ventilations.*.pourcentage' => ['nullable', 'numeric', 'min:0', 'max:100'],

            /* Action */
            'soumettre' => ['boolean'],
        ]);

        /* Créer le bon de caisse */
        $bonCaisse = BonCaisse::create([
            'numero' => BonCaisse::genererNumero(),
            'type_bon' => $validated['type_bon'],
            'site' => $validated['site'],
            'service' => $validated['service'],
            'code_analytique' => $validated['code_analytique'] ?? null,
            'beneficiaire' => $validated['beneficiaire'],
            'type_beneficiaire' => $validated['type_beneficiaire'],
            'telephone_beneficiaire' => $validated['telephone_beneficiaire'] ?? null,
            'mode_paiement' => $validated['mode_paiement'],
            'motif' => $validated['motif'],
            'categorie_depense' => $validated['categorie_depense'],
            'montant' => $validated['montant'],
            'montant_lettres' => $validated['montant_lettres'] ?? null,
            'devise' => $validated['devise'] ?? 'GNF',
            'niveau_urgence' => $validated['niveau_urgence'] ?? 'normale',
            'motif_urgence' => $validated['motif_urgence'] ?? null,
            'justification_urgence' => $validated['justification_urgence'] ?? null,
            'statut' => 'BROUILLON',
            'demandeur_id' => Auth::id(),
            'date_demande' => now()->toDateString(),
        ]);

        /* Enregistrer la création dans l'historique */
        $bonCaisse->enregistrerCreation();

        /* Upload des pièces jointes si présentes */
        if ($request->hasFile('pieces_jointes')) {
            $typesDocuments = $request->input('types_documents', []);

            foreach ($request->file('pieces_jointes') as $index => $fichier) {
                $chemin = $fichier->store('pieces_jointes/' . $bonCaisse->id, 'public');
                $typeDoc = $typesDocuments[$index] ?? 'autre';

                $piece = PieceJointe::create([
                    'bon_caisse_id' => $bonCaisse->id,
                    'type_document' => $typeDoc,
                    'nom_fichier' => $fichier->getClientOriginalName(),
                    'chemin_fichier' => $chemin,
                    'taille' => $fichier->getSize(),
                    'mime_type' => $fichier->getMimeType(),
                ]);

                /* Lancer l'analyse OCR en arrière-plan */
                ProcessPieceJointeOcrJob::dispatch($piece->id);

                /* Historique pour chaque pièce ajoutée */
                $bonCaisse->enregistrerAjoutPieceJointe($fichier->getClientOriginalName(), Auth::id());
            }
        }

        /* Enregistrer les ventilations analytiques si fournies */
        if (!empty($validated['ventilations'])) {
            foreach ($validated['ventilations'] as $ventilation) {
                $bonCaisse->ventilations()->create([
                    'code_analytique' => $ventilation['code_analytique'],
                    'montant' => $ventilation['montant'],
                    'pourcentage' => $ventilation['pourcentage'] ?? null,
                ]);
            }
        }

        /* Soumettre directement si demandé */
        if ($request->boolean('soumettre')) {
            $resultat = $bonCaisse->soumettre();
            if (!$resultat['success']) {
                return redirect()
                    ->route('bons-caisse.show', $bonCaisse)
                    ->with('error', $resultat['message']);
            }
            /* Notifier les validateurs */
            NotificationService::notifierSoumission($bonCaisse, Auth::user());

            return redirect()
                ->route('bons-caisse.show', $bonCaisse)
                ->with('success', $resultat['message']);
        }

        return redirect()
            ->route('bons-caisse.show', $bonCaisse)
            ->with('success', 'Bon de caisse créé avec succès.');
    }

    /**
     * Afficher le détail d'un bon de caisse
     */
    public function show(BonCaisse $bonCaisse)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        /* Restriction de visibilité cohérente avec index() (strict + suivi) :
         * 1. Propriétaire (demandeur du bon) → toujours autorisé
         * 2. Administrateur → accès global sauf brouillons des autres
         * 3. Validateur → bon en attente à son niveau OU bon déjà validé par lui
         * 4. Caissier → bons à payer/régulariser de son site
         */
        $estProprietaire = $bonCaisse->demandeur_id === $utilisateur->id;

        if (!$estProprietaire) {
            $roles = array_unique(array_merge([$utilisateur->role], method_exists($utilisateur, 'rolesValidationEffectifs') ? $utilisateur->rolesValidationEffectifs() : []));
            $autorise = false;

            /* Administrateur : accès global sauf brouillons */
            if (in_array('administrateur', $roles) && $bonCaisse->statut !== 'BROUILLON') {
                $autorise = true;
            }

            /* Vérifier si le bon est déjà validé par cet utilisateur (suivi) */
            if (!$autorise) {
                $dejaValide = Validation::where('bon_caisse_id', $bonCaisse->id)
                    ->where('validateur_id', $utilisateur->id)
                    ->whereIn('statut', ['approuve', 'rejete'])
                    ->exists();
                if ($dejaValide) {
                    $autorise = true;
                }
            }

            /* Vérifier si le bon est en attente au niveau du validateur */
            if (!$autorise) {
                $roleStatutMap = [
                    'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
                    'controle_gestion' => 'EN_ATTENTE_CDG',
                    'daf' => 'EN_ATTENTE_DAF',
                    'directeur_pays' => 'EN_ATTENTE_DP',
                ];

                foreach ($roles as $r) {
                    if (!isset($roleStatutMap[$r])) continue;

                    if ($bonCaisse->statut === $roleStatutMap[$r]) {
                        /* Chef de service : restreindre aux services accessibles */
                        if ($r === 'responsable_service') {
                            $servicesAccessibles = [];
                            if ($utilisateur->role === 'responsable_service' && $utilisateur->service) {
                                $servicesAccessibles[] = $utilisateur->service;
                            }
                            foreach (Delegation::delegantsActifsPour($utilisateur->id) as $delegant) {
                                if ($delegant->role === 'responsable_service' && $delegant->service) {
                                    $servicesAccessibles[] = $delegant->service;
                                }
                            }
                            if (in_array($bonCaisse->service, $servicesAccessibles)) {
                                $autorise = true;
                                break;
                            }
                        } else {
                            $autorise = true;
                            break;
                        }
                    }
                }
            }

            /* Caissier : bons de son site à payer/régulariser */
            if (!$autorise && in_array('caissier', $roles)) {
                if (in_array($bonCaisse->statut, ['APPROUVE', 'PAYE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE', 'ARCHIVE'])
                    && (!$utilisateur->site || $bonCaisse->site === $utilisateur->site)) {
                    $autorise = true;
                }
            }

            if (!$autorise) {
                abort(403, 'Vous n\'avez pas les droits pour accéder à ce bon de caisse.');
            }
        }

        $bonCaisse->load([
            'demandeur',
            'caissier',
            'validations.validateur',
            'piecesJointes',
            'ordreMission',
            'historiqueActions.utilisateur',
            'ventilations',
        ]);

        /* Calculer les délais de traitement par étape de validation */
        $delaisValidation = [];
        foreach ($bonCaisse->validations as $validation) {
            $delai = null;
            if ($validation->date_validation && $validation->date_attribution) {
                $diff = $validation->date_attribution->diff($validation->date_validation);
                $parts = [];
                if ($diff->d > 0) $parts[] = $diff->d . 'j';
                if ($diff->h > 0) $parts[] = $diff->h . 'h';
                if ($diff->i > 0) $parts[] = $diff->i . 'min';
                $delai = implode(' ', $parts) ?: '< 1min';
            } elseif ($validation->statut === 'en_attente' && $validation->date_attribution) {
                $diff = $validation->date_attribution->diff(now());
                $parts = [];
                if ($diff->d > 0) $parts[] = $diff->d . 'j';
                if ($diff->h > 0) $parts[] = $diff->h . 'h';
                if ($diff->i > 0) $parts[] = $diff->i . 'min';
                $delai = (implode(' ', $parts) ?: '< 1min') . ' (en cours)';
            }
            $delaisValidation[$validation->id] = $delai;
        }

        /* Solde de la caisse du site */
        $soldeCaisseSite = null;
        if (in_array($utilisateur->role, ['caissier', 'daf', 'directeur_pays', 'administrateur'])) {
            $siteModel = Site::where('nom', $bonCaisse->site)->first();
            $soldeCaisseSite = $siteModel ? [
                'solde' => (float) $siteModel->solde_caisse,
                'solde_format' => $siteModel->solde_caisse_format,
                'plafond' => $siteModel->plafond_caisse,
                'seuil_minimum' => $siteModel->seuil_minimum_caisse,
                'sous_seuil' => $siteModel->soldeSousSeuil(),
                'peut_payer' => $siteModel->peutPayer($bonCaisse->montant),
            ] : null;
        }

        /* Trouver le rôle de validation actif pour l'utilisateur sur ce bon */
        $roleValidation = null;
        if ($utilisateur->peutValider()) {
            $rolesEffectifs = method_exists($utilisateur, 'rolesValidationEffectifs') ? $utilisateur->rolesValidationEffectifs() : [];
            foreach ($rolesEffectifs as $role) {
                if ($bonCaisse->estEnAttenteDe($role)) {
                    $roleValidation = $role;
                    break;
                }
            }
        }

        /* Vérifier si l'utilisateur connecté peut valider ce bon */
        $peutValiderCeBon = $roleValidation !== null;

        /* Trouver la validation en cours correspondante */
        $validationEnCours = null;
        if ($peutValiderCeBon) {
            $validationEnCours = $bonCaisse->validations()
                ->where('role', $roleValidation)
                ->where('statut', 'en_attente')
                ->first();
        }

        return Inertia::render('BonsCaisse/Show', [
            'bonCaisse' => $bonCaisse,
            'statutsLabels' => BonCaisse::STATUTS_LABELS,
            'categoriesDepense' => BonCaisse::CATEGORIES_DEPENSE,
            'typesBeneficiaire' => BonCaisse::TYPES_BENEFICIAIRE,
            'modesPaiement' => BonCaisse::MODES_PAIEMENT,
            'actionsLabels' => HistoriqueAction::ACTIONS_LABELS,
            'peutValiderCeBon' => $peutValiderCeBon,
            'validationEnCours' => $validationEnCours,
            'seuilDP' => Parametre::seuilDP(),
            'niveauxUrgence' => BonCaisse::NIVEAUX_URGENCE,
            'roleUtilisateur' => $utilisateur->role,
            'estProprietaire' => $estProprietaire,
            'delaisValidation' => $delaisValidation,
            'soldeCaisseSite' => $soldeCaisseSite,
            'codesAnalytiques' => CodeAnalytique::actifs()->with('service')->orderBy('code')->get(),
            'peutPreRegulariser' => $estProprietaire && $bonCaisse->peutPreRegulariser(),
            'aDesPiecesRegularisation' => $bonCaisse->aDesPiecesRegularisation(),
        ]);
    }

    /**
     * Afficher le formulaire d'édition (uniquement pour les brouillons)
     */
    public function edit(BonCaisse $bonCaisse)
    {
        /* Vérifier que le bon est modifiable (brouillon ou rejeté) et appartient au demandeur */
        if (!in_array($bonCaisse->statut, ['BROUILLON', 'REJETE']) || $bonCaisse->demandeur_id !== Auth::id()) {
            abort(403, 'Vous ne pouvez modifier que vos brouillons ou les bons retournés/rejetés.');
        }

        $bonCaisse->load('piecesJointes', 'ordreMission', 'ventilations');

        /* Motifs d'urgence prédéfinis (Phase 1.1) */
        $motifsUrgence = MotifUrgence::where('actif', true)->pluck('libelle')->toArray();

        return Inertia::render('BonsCaisse/Edit', [
            'bonCaisse' => $bonCaisse,
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::actifs()->orderBy('nom')->pluck('nom'),
            'codesAnalytiques' => CodeAnalytique::actifs()->with('service')->orderBy('code')->get(),
            'categoriesDepense' => BonCaisse::CATEGORIES_DEPENSE,
            'typesBeneficiaire' => BonCaisse::TYPES_BENEFICIAIRE,
            'modesPaiement' => BonCaisse::MODES_PAIEMENT,
            'montantMax' => Parametre::montantMax(),
            'seuilDP' => Parametre::seuilDP(),
            'niveauxUrgence' => BonCaisse::NIVEAUX_URGENCE,
            'motifsUrgence' => $motifsUrgence,
        ]);
    }

    /**
     * Mettre à jour un bon de caisse (uniquement brouillon)
     */
    public function update(Request $request, BonCaisse $bonCaisse)
    {
        if (!in_array($bonCaisse->statut, ['BROUILLON', 'REJETE']) || $bonCaisse->demandeur_id !== Auth::id()) {
            abort(403, 'Vous ne pouvez modifier que vos brouillons ou les bons retournés/rejetés.');
        }

        $validated = $request->validate([
            'type_bon' => ['required', Rule::in(['BD', 'BP'])],
            'site' => ['required', 'string', 'max:255'],
            'service' => ['required', 'string', 'max:255'],
            'code_analytique' => ['nullable', 'string', 'max:255'],
            'beneficiaire' => ['required', 'string', 'max:255'],
            'type_beneficiaire' => ['required', Rule::in(array_keys(BonCaisse::TYPES_BENEFICIAIRE))],
            'telephone_beneficiaire' => ['nullable', 'string', 'max:50'],
            'mode_paiement' => ['required', Rule::in(array_keys(BonCaisse::MODES_PAIEMENT))],
            'motif' => ['required', 'string', 'min:10'],
            'categorie_depense' => ['required', Rule::in(array_keys(BonCaisse::CATEGORIES_DEPENSE))],
            'montant' => ['required', 'numeric', 'min:1', 'max:' . Parametre::montantMax()],
            'montant_lettres' => ['nullable', 'string', 'max:500'],
            'devise' => ['nullable', 'string', 'max:10'],
            'pieces_jointes' => ['nullable', 'array'],
            'pieces_jointes.*' => [
                'file',
                'mimes:' . implode(',', BonCaisse::FORMATS_FICHIERS_AUTORISES),
                'max:' . (BonCaisse::TAILLE_MAX_FICHIER / 1024),
            ],
            'types_documents' => ['nullable', 'array'],
            'types_documents.*' => ['nullable', 'string'],
            'niveau_urgence' => ['nullable', 'string', Rule::in(array_keys(BonCaisse::NIVEAUX_URGENCE))],
            'motif_urgence' => ['nullable', 'required_if:niveau_urgence,urgente,tres_urgente', 'string', 'max:255'],
            'justification_urgence' => ['nullable', 'required_if:niveau_urgence,urgente,tres_urgente', 'string', 'min:10', 'max:1000'],
            'ventilations' => ['nullable', 'array'],
            'ventilations.*.code_analytique' => ['required_with:ventilations', 'string'],
            'ventilations.*.montant' => ['required_with:ventilations', 'numeric', 'min:0'],
            'ventilations.*.pourcentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'soumettre' => ['boolean'],
        ]);

        $bonCaisse->update([
            'type_bon' => $validated['type_bon'],
            'site' => $validated['site'],
            'service' => $validated['service'],
            'code_analytique' => $validated['code_analytique'] ?? null,
            'beneficiaire' => $validated['beneficiaire'],
            'type_beneficiaire' => $validated['type_beneficiaire'],
            'telephone_beneficiaire' => $validated['telephone_beneficiaire'] ?? null,
            'mode_paiement' => $validated['mode_paiement'],
            'motif' => $validated['motif'],
            'categorie_depense' => $validated['categorie_depense'],
            'montant' => $validated['montant'],
            'montant_lettres' => $validated['montant_lettres'] ?? null,
            'devise' => $validated['devise'] ?? 'GNF',
            'niveau_urgence' => $validated['niveau_urgence'] ?? $bonCaisse->niveau_urgence,
            'motif_urgence' => $validated['motif_urgence'] ?? null,
            'justification_urgence' => $validated['justification_urgence'] ?? null,
        ]);

        /* Mettre à jour les ventilations analytiques */
        if (isset($validated['ventilations'])) {
            $bonCaisse->ventilations()->delete();
            foreach ($validated['ventilations'] as $ventilation) {
                $bonCaisse->ventilations()->create([
                    'code_analytique' => $ventilation['code_analytique'],
                    'montant' => $ventilation['montant'],
                    'pourcentage' => $ventilation['pourcentage'] ?? null,
                ]);
            }
        }

        /* Historique de modification */
        $bonCaisse->enregistrerModification(Auth::id());

        /* Upload des nouvelles pièces jointes */
        if ($request->hasFile('pieces_jointes')) {
            $typesDocuments = $request->input('types_documents', []);

            foreach ($request->file('pieces_jointes') as $index => $fichier) {
                $chemin = $fichier->store('pieces_jointes/' . $bonCaisse->id, 'public');
                $typeDoc = $typesDocuments[$index] ?? 'autre';

                $piece = PieceJointe::create([
                    'bon_caisse_id' => $bonCaisse->id,
                    'type_document' => $typeDoc,
                    'nom_fichier' => $fichier->getClientOriginalName(),
                    'chemin_fichier' => $chemin,
                    'taille' => $fichier->getSize(),
                    'mime_type' => $fichier->getMimeType(),
                ]);

                /* Lancer l'analyse OCR en arrière-plan */
                ProcessPieceJointeOcrJob::dispatch($piece->id);

                $bonCaisse->enregistrerAjoutPieceJointe($fichier->getClientOriginalName(), Auth::id());
            }
        }

        if ($request->boolean('soumettre')) {
            $resultat = $bonCaisse->soumettre();
            if (!$resultat['success']) {
                return redirect()
                    ->route('bons-caisse.show', $bonCaisse)
                    ->with('error', $resultat['message']);
            }
            /* Notifier les validateurs */
            NotificationService::notifierSoumission($bonCaisse, Auth::user());

            return redirect()
                ->route('bons-caisse.show', $bonCaisse)
                ->with('success', $resultat['message']);
        }

        return redirect()
            ->route('bons-caisse.show', $bonCaisse)
            ->with('success', 'Bon de caisse mis à jour avec succès.');
    }

    /**
     * Soumettre un brouillon pour validation
     */
    public function soumettre(BonCaisse $bonCaisse)
    {
        if ($bonCaisse->demandeur_id !== Auth::id()) {
            abort(403);
        }

        $resultat = $bonCaisse->soumettre();

        if ($resultat['success']) {
            NotificationService::notifierSoumission($bonCaisse, Auth::user());

            return redirect()
                ->route('bons-caisse.show', $bonCaisse)
                ->with('success', $resultat['message']);
        }

        return back()->with('error', $resultat['message']);
    }

    /**
     * Générer un code OTP et l'envoyer par SMS au demandeur
     */
    public function genererOtp(Request $request, BonCaisse $bonCaisse)
    {
        /** @var \App\Models\User $caissier */
        $caissier = Auth::user();

        if (!$caissier->peutPayer()) {
            abort(403, 'Seul un caissier peut générer un code OTP.');
        }

        if ($bonCaisse->statut !== 'APPROUVE') {
            return back()->with('error', 'Le bon doit être approuvé pour générer un code OTP.');
        }

        /* Invalider les anciens codes OTP non utilisés pour ce bon */
        OtpValidation::where('bon_caisse_id', $bonCaisse->id)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        /* Générer un nouveau code OTP */
        $code = OtpValidation::genererCode();
        $dureeValidite = (int) Parametre::valeur('duree_validite_otp', 5);

        $otp = OtpValidation::create([
            'bon_caisse_id' => $bonCaisse->id,
            'code' => $code,
            'telephone' => $bonCaisse->telephone_beneficiaire ?? $bonCaisse->demandeur->telephone,
            'expires_at' => now()->addMinutes($dureeValidite),
        ]);

        /* Envoyer le code par SMS via Nimba */
        $smsService = new NimbaSmsService();
        $resultat = $smsService->envoyerCodeOtp(
            $otp->telephone,
            $code,
            $bonCaisse->beneficiaire
        );

        if (!$resultat['success']) {

            $message = is_array($resultat['message'])
                ? json_encode($resultat['message'])
                : $resultat['message'];

            return back()->with('error', "Erreur SMS : $message");
        }   

        return back()->with('success', "Code OTP envoyé au {$otp->telephone}. Valide pendant {$dureeValidite} minutes.");
    }

    /**
     * Vérifier un code OTP saisi par le caissier
     */
    public function verifierOtp(Request $request, BonCaisse $bonCaisse)
    {
        /** @var \App\Models\User $caissier */
        $caissier = Auth::user();

        if (!$caissier->peutPayer()) {
            abort(403, 'Seul un caissier peut vérifier un code OTP.');
        }

        $request->validate([
            'code_otp' => ['required', 'string', 'size:6'],
        ]);

        /* Récupérer le dernier OTP valide pour ce bon */
        $otp = OtpValidation::where('bon_caisse_id', $bonCaisse->id)
            ->valide()
            ->nonVerifie()
            ->latest()
            ->first();

        if (!$otp) {
            return back()->with('error', 'Aucun code OTP valide trouvé. Veuillez générer un nouveau code.');
        }

        if ($otp->code !== $request->code_otp) {
            return back()->with('error', 'Code OTP incorrect. Veuillez réessayer.');
        }

        /* Marquer le code comme vérifié */
        $otp->marquerCommeVerifie();

        return back()->with('success', 'Code OTP vérifié avec succès ! Vous pouvez maintenant confirmer le paiement.');
    }

    /**
     * Marquer un bon comme payé (action du caissier)
     */
    public function payer(Request $request, BonCaisse $bonCaisse)
    {
        /** @var \App\Models\User $caissier */
        $caissier = Auth::user();

        if (!$caissier->peutPayer()) {
            abort(403, 'Seul un caissier peut effectuer un paiement.');
        }

        $request->validate([
            'mode_paiement_effectif' => ['required', Rule::in(array_keys(BonCaisse::MODES_PAIEMENT))],
        ]);

        /* Vérifier qu'un code OTP a été validé pour ce bon */
        $otpVerifie = OtpValidation::where('bon_caisse_id', $bonCaisse->id)
            ->whereNotNull('verified_at')
            ->where('is_used', false)
            ->where('verified_at', '>=', now()->subMinutes(10)) // OTP vérifié dans les 10 dernières minutes
            ->latest('verified_at')
            ->first();

        if (!$otpVerifie) {
            return back()->with('error', 'Vous devez d\'abord générer et valider un code OTP avant d\'effectuer le paiement.');
        }

        /* Marquer l'OTP comme utilisé */
        $otpVerifie->marquerCommeUtilise();

        /* Phase 2.1 : Blocage si solde de caisse insuffisant */
        $siteModel = Site::where('nom', $bonCaisse->site)->first();
        if ($siteModel && !$siteModel->peutPayer($bonCaisse->montant)) {
            return back()->with('error', 
                'Solde de caisse insuffisant pour le site ' . $bonCaisse->site 
                . '. Solde actuel : ' . $siteModel->solde_caisse_format 
                . ', Montant demandé : ' . $bonCaisse->montant_format . '.'
            );
        }

        if ($bonCaisse->marquerCommePaye($caissier, $request->mode_paiement_effectif)) {
            /* Débiter la caisse du site */
            if ($siteModel) {
                $siteModel->debiter($bonCaisse->montant);

                /* Alerte si solde sous le seuil minimum après paiement */
                if ($siteModel->soldeSousSeuil()) {
                    NotificationService::notifierAlerteSolde($siteModel, $caissier);
                }
            }

            NotificationService::notifierPaiement($bonCaisse->fresh(['demandeur']), $caissier);

            return redirect()
                ->route('bons-caisse.show', $bonCaisse)
                ->with('success', 'Paiement enregistré avec succès.');
        }

        return back()->with('error', 'Impossible de marquer ce bon comme payé.');
    }

    /**
     * Régulariser un bon provisoire
     */
    public function regulariser(Request $request, BonCaisse $bonCaisse)
    {
        /* Validation du motif de régularisation (obligatoire) */
        $request->validate([
            'motif_regularisation' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'motif_regularisation.required' => 'Le motif de régularisation est obligatoire.',
            'motif_regularisation.min' => 'Le motif doit contenir au moins 5 caractères.',
        ]);

        /* Upload des pièces justificatives de régularisation */
        if ($request->hasFile('pieces_jointes')) {
            $request->validate([
                'pieces_jointes' => ['required', 'array'],
                'pieces_jointes.*' => [
                    'file',
                    'mimes:' . implode(',', BonCaisse::FORMATS_FICHIERS_AUTORISES),
                    'max:' . (BonCaisse::TAILLE_MAX_FICHIER / 1024),
                ],
            ]);

            foreach ($request->file('pieces_jointes') as $fichier) {
                $chemin = $fichier->store('pieces_jointes/' . $bonCaisse->id . '/regularisation', 'public');

                PieceJointe::create([
                    'bon_caisse_id' => $bonCaisse->id,
                    'type_document' => 'justificatif',
                    'nom_fichier' => $fichier->getClientOriginalName(),
                    'chemin_fichier' => $chemin,
                    'taille' => $fichier->getSize(),
                    'mime_type' => $fichier->getMimeType(),
                ]);

                $bonCaisse->enregistrerAjoutPieceJointe($fichier->getClientOriginalName(), Auth::id());
            }
        }

        if ($bonCaisse->regulariser(Auth::id(), $request->input('motif_regularisation'))) {
            NotificationService::notifierRegularisation($bonCaisse->fresh(['demandeur', 'caissier']), Auth::user());

            return redirect()
                ->route('bons-caisse.show', $bonCaisse)
                ->with('success', 'Bon régularisé avec succès.');
        }

        return back()->with('error', 'Impossible de régulariser ce bon.');
    }

    /**
     * Pré-régulariser un BP : uploader les justificatifs avant le paiement
     * Le statut ne change pas, mais les pièces sont enregistrées pour auto-régularisation au paiement
     */
    public function preRegulariser(Request $request, BonCaisse $bonCaisse)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        /* Seul le demandeur peut pré-régulariser son bon */
        if ($bonCaisse->demandeur_id !== $utilisateur->id) {
            abort(403, 'Seul le demandeur peut pré-régulariser ce bon.');
        }

        /* Vérifier que le bon est éligible */
        if (!$bonCaisse->peutPreRegulariser()) {
            return back()->with('error', 'Ce bon ne peut pas être pré-régularisé dans son état actuel.');
        }

        $request->validate([
            'motif_regularisation' => ['required', 'string', 'min:5', 'max:1000'],
            'pieces_jointes' => ['required', 'array', 'min:1'],
            'pieces_jointes.*' => [
                'file',
                'mimes:' . implode(',', BonCaisse::FORMATS_FICHIERS_AUTORISES),
                'max:' . (BonCaisse::TAILLE_MAX_FICHIER / 1024),
            ],
        ], [
            'motif_regularisation.required' => 'Le motif de régularisation est obligatoire.',
            'motif_regularisation.min' => 'Le motif doit contenir au moins 5 caractères.',
        ]);

        /* Sauvegarder le motif de régularisation */
        $bonCaisse->update([
            'motif_regularisation' => $request->input('motif_regularisation'),
        ]);

        foreach ($request->file('pieces_jointes') as $fichier) {
            $chemin = $fichier->store('pieces_jointes/' . $bonCaisse->id . '/regularisation', 'public');

            PieceJointe::create([
                'bon_caisse_id' => $bonCaisse->id,
                'type_document' => 'justificatif',
                'nom_fichier' => $fichier->getClientOriginalName(),
                'chemin_fichier' => $chemin,
                'taille' => $fichier->getSize(),
                'mime_type' => $fichier->getMimeType(),
            ]);

            $bonCaisse->enregistrerAjoutPieceJointe($fichier->getClientOriginalName(), $utilisateur->id);
        }

        return redirect()
            ->route('bons-caisse.show', $bonCaisse)
            ->with('success', 'Régularisation enregistrée avec succès. La finalisation sera automatique lors du paiement.');
    }

    /**
     * Exporter le bon de caisse en PDF (formulaire officiel NEEMBA)
     */
    public function exportPdf(BonCaisse $bonCaisse)
    {
        $bonCaisse->load(['demandeur', 'validations.validateur', 'caissier', 'piecesJointes']);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.bon-caisse-pdf', [
            'bon' => $bonCaisse,
            'sitesListe' => \App\Models\Site::actifs()->orderBy('nom')->pluck('nom')->toArray(),
            'servicesListe' => \App\Models\Service::actifs()->orderBy('nom')->pluck('nom')->toArray(),
            'seuilDP' => \App\Models\Parametre::seuilDP(),
        ])->setPaper('a4', 'portrait');

        $nomFichier = 'bon-caisse-' . $bonCaisse->numero . '.pdf';

        return $pdf->stream($nomFichier);
    }

    /**
     * Archiver un bon
     */
    public function archiver(BonCaisse $bonCaisse)
    {
        if ($bonCaisse->archiver(Auth::id())) {
            NotificationService::notifierArchivage($bonCaisse->fresh(['demandeur']), Auth::user());

            return redirect()
                ->route('bons-caisse.show', $bonCaisse)
                ->with('success', 'Bon archivé avec succès.');
        }

        return back()->with('error', 'Impossible d\'archiver ce bon.');
    }

    /**
     * Tableau de bord des bons provisoires en retard de régularisation
     * Accessible : DAF, Directeur Pays, Administrateur
     */
    public function bpEnRetard(Request $request)
    {
        $query = BonCaisse::where('type_bon', 'BP')
            ->enAttenteRegularisation()
            ->whereNotNull('date_limite_regularisation')
            ->where('date_limite_regularisation', '<', now())
            ->with('demandeur', 'caissier')
            ->latest('date_limite_regularisation');

        /* Filtres */
        if ($request->filled('site')) {
            $query->where('site', $request->site);
        }
        if ($request->filled('service')) {
            $query->where('service', $request->service);
        }

        $bonsEnRetard = $query->paginate(25)->withQueryString();

        /* Statistiques */
        $stats = [
            'total'          => $bonsEnRetard->total(),
            'montant_total'  => $query->sum('montant'),
            'retard_moyen_j' => round(
                $query->get()->avg(fn ($bon) => now()->diffInDays($bon->date_limite_regularisation))
            ),
        ];

        $sites    = Site::actifs()->orderBy('nom')->pluck('nom');
        $services = \App\Models\Service::actifs()->orderBy('nom')->pluck('nom');

        return Inertia::render('BonsCaisse/BPEnRetard', [
            'bonsEnRetard' => $bonsEnRetard,
            'stats'        => $stats,
            'sites'        => $sites,
            'services'     => $services,
            'filtres'      => $request->only(['site', 'service']),
        ]);
    }
}

