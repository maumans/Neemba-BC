<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPieceJointeOcrJob;
use App\Models\BonCaisse;
use App\Models\Caisse;
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
use App\Services\Paiement\FraisOrangeMoney;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Services\BonCaisse\ReglesSaisie;
use Illuminate\Support\Facades\DB;
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

        /* E-03.1 : du plus récent au plus ancien ; étape de validation en cours chargée pour la colonne Âge */
        $query = BonCaisse::with(['demandeur', 'validations' => fn ($q) => $q->where('statut', 'en_attente')])
            ->latest('created_at')
            ->latest('id');

        /* Filtrage par statut si spécifié */
        if ($request->filled('statut')) {
            $query->parStatut($request->statut);
        }

        /* Filtrage par type de bon */
        if ($request->filled('type_bon')) {
            $query->where('type_bon', $request->type_bon);
        }

        /* Filtres urgence et période (E-03.1) */
        if ($request->filled('niveau_urgence')) {
            $query->where('niveau_urgence', $request->niveau_urgence);
        }
        if ($request->filled('du')) {
            $query->whereDate('date_demande', '>=', $request->date('du'));
        }
        if ($request->filled('au')) {
            $query->whereDate('date_demande', '<=', $request->date('au'));
        }

        /* Recherche par numéro, bénéficiaire ou motif (collation insensible à la casse et aux accents) */
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
                  ->orWhere('initiateur_id', $utilisateur->id)
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
                /* 1. Ses bons (RG-BC-32) : demandeur ou initiateur, tous statuts ; bénéficiaire, dès la soumission */
                $q->where('demandeur_id', $utilisateur->id)
                    ->orWhere('initiateur_id', $utilisateur->id)
                    ->orWhere(fn ($q2) => $q2->where('beneficiaire_id', $utilisateur->id)->where('statut', '!=', 'BROUILLON'));

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

        /* RG-BC-33 (ANO-08) : les cartes comptent exactement les lignes du filtre courant.
         * Clone pris AVANT paginate() : paginate() pose LIMIT/OFFSET sur le builder. */
        $statsQuery = (clone $query)->reorder();
        $statsIndex = self::cartesListe($statsQuery);

        $bonsCaisse = $query->paginate(20)->withQueryString();
        $bonsCaisse->getCollection()->transform(fn (BonCaisse $bon) => self::avecAge($bon));

        return Inertia::render('BonsCaisse/Index', [
            'bonsCaisse' => $bonsCaisse,
            'filtres' => $request->only(['statut', 'type_bon', 'niveau_urgence', 'du', 'au', 'recherche']),
            'niveauxUrgence' => BonCaisse::NIVEAUX_URGENCE,
            'statuts' => BonCaisse::STATUTS_LABELS,
            'peutValider' => $utilisateur->peutValider(),
            'roleUtilisateur' => $utilisateur->role,
            'statsIndex' => $statsIndex,
        ]);
    }

    private const LIBELLES_NIVEAUX = [
        'responsable_service' => 'Chef de service',
        'controle_gestion' => 'Contrôle de gestion',
        'daf' => 'Finance',
        'directeur_pays' => 'Directeur Pays',
    ];

    private const NIVEAUX_CIRCUIT = [
        'responsable_service' => 'CHEF_SERVICE',
        'controle_gestion' => 'CDG',
        'daf' => 'FINANCE',
        'directeur_pays' => 'DIRECTEUR_PAYS',
    ];

    /**
     * Onglet Validations (E-03.8, US-BC-16) : une ligne par niveau et par version.
     * Fait : valideur (« au titre de » si suppléant), date, durée ; en cours : valideurs possibles et échéance ; à venir.
     */
    private static function etapesValidation(BonCaisse $bon): array
    {
        $circuit = collect(\App\Services\BonCaisse\CircuitPrevisionnel::pour($bon))->keyBy('niveau');
        /* Étape en cours : celle du statut du bon (même si sa date d'attribution manque, cas des données anciennes) */
        $roleEnCours = array_search($bon->statut, [
            'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
            'controle_gestion' => 'EN_ATTENTE_CDG',
            'daf' => 'EN_ATTENTE_DAF',
            'directeur_pays' => 'EN_ATTENTE_DP',
        ], true);

        return $bon->validations
            ->sortBy([['version', 'desc'], ['niveau', 'asc']])
            ->map(function (Validation $validation) use ($bon, $circuit, $roleEnCours) {
                $validation->setRelation('bonCaisse', $bon);
                $courante = (int) ($validation->version ?? 1) === (int) ($bon->version ?? 1) && $validation->role === $roleEnCours;
                $etat = match (true) {
                    $validation->statut !== 'en_attente' => 'fait',
                    in_array($bon->statut, ['REJETE', 'ANNULE'], true) => 'non_atteint',   // circuit arrêté
                    $validation->date_attribution !== null || $courante => 'en_cours',
                    default => 'a_venir',
                };

                return [
                    'id' => $validation->id,
                    'version' => (int) ($validation->version ?? 1),
                    'niveau' => $validation->niveau,
                    'libelle' => self::LIBELLES_NIVEAUX[$validation->role] ?? $validation->role,
                    'statut' => $validation->statut,
                    'etat' => $etat,
                    'valideur' => $validation->validateur?->nom_complet,
                    'au_titre_de' => $validation->auTitreDe?->nom_complet,
                    'date' => $validation->date_validation ? Format::dateHeure($validation->date_validation) : null,
                    'duree' => $etat === 'fait' && $validation->date_attribution
                        ? Format::dureeEntre($validation->date_attribution, $validation->date_validation)
                        : ($etat === 'en_cours' && $validation->date_attribution ? Format::dureeEntre($validation->date_attribution) : null),
                    'echeance' => $etat === 'en_cours' && $validation->date_attribution
                        ? Format::dateHeure($validation->date_attribution->copy()->addMinutes((int) round($validation->slaHeures() * 60)))
                        : null,
                    'en_retard' => $etat === 'en_cours' && $validation->slaDepasse(),
                    'valideurs_possibles' => in_array($etat, ['fait', 'non_atteint'], true) ? [] : ($circuit[self::NIVEAUX_CIRCUIT[$validation->role] ?? '']['valideurs'] ?? []),
                    'commentaire' => $validation->commentaire,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Bandeau de rejet (US-BC-14) : motif, commentaire, valideur et date du dernier rejet.
     */
    private static function rejet(BonCaisse $bon): ?array
    {
        if ($bon->statut !== 'REJETE') {
            return null;
        }
        $validation = $bon->validations->where('statut', 'rejete')->sortByDesc('date_validation')->first();
        $texte = $validation?->commentaire ?? $bon->commentaire_rejet ?? '';

        /* Le commentaire enregistré est « Motif - commentaire » (motifs de BonCaisse::MOTIFS_REJET) */
        $motif = collect(BonCaisse::MOTIFS_REJET)->first(fn (string $libelle) => str_starts_with($texte, $libelle));
        $commentaire = $motif ? ltrim(substr($texte, strlen($motif)), ' -') : $texte;

        return [
            'motif' => $motif,
            'commentaire' => $commentaire !== '' ? $commentaire : null,
            'valideur' => $validation?->validateur?->nom_complet,
            'niveau' => $validation ? (self::LIBELLES_NIVEAUX[$validation->role] ?? $validation->role) : null,
            'date' => $validation?->date_validation ? Format::dateHeure($validation->date_validation) : null,
        ];
    }

    /** Statuts comptés comme « payés » ; un bon archivé l'est seulement s'il a été payé */
    private const STATUTS_PAYES = ['PAYE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE'];

    /**
     * Les 4 cartes de la liste (E-03.1) : nombre et montant, sur la requête filtrée de la liste.
     * Un bon rejeté puis archivé compte parmi les rejetés, plus parmi les payés.
     */
    private static function cartesListe(\Illuminate\Database\Eloquent\Builder $requete): array
    {
        $carte = function (\Closure $filtre) use ($requete) {
            $sousRequete = $filtre(clone $requete);

            return ['nombre' => (clone $sousRequete)->count(), 'montant' => (float) (clone $sousRequete)->sum('montant')];
        };

        return [
            'total' => $carte(fn ($q) => $q),
            'a_valider' => $carte(fn ($q) => $q->whereIn('statut', ['EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP'])),
            'payes' => $carte(fn ($q) => $q->where(fn ($q2) => $q2->whereIn('statut', self::STATUTS_PAYES)
                ->orWhere(fn ($q3) => $q3->where('statut', 'ARCHIVE')->whereNotNull('date_paiement')))),
            'rejetes' => $carte(fn ($q) => $q->where(fn ($q2) => $q2->where('statut', 'REJETE')
                ->orWhere(fn ($q3) => $q3->where('statut', 'ARCHIVE')->whereNull('date_paiement')->whereNotNull('commentaire_rejet')))),
        ];
    }

    /**
     * Âge du bon depuis la soumission (E-03.1), en rouge au-delà de 2 × le délai de l'étape de validation en cours.
     */
    private static function avecAge(BonCaisse $bon): BonCaisse
    {
        $enCours = in_array($bon->statut, ['EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP', 'APPROUVE', 'EN_ATTENTE_REGULARISATION'], true);
        $age = null;
        $alerte = false;

        if ($enCours && $bon->date_soumission) {
            $age = Format::dureeEntre($bon->date_soumission);
            $etape = $bon->validations->sortBy('niveau')->first();
            if ($etape && str_starts_with($bon->statut, 'EN_ATTENTE_') && $bon->statut !== 'EN_ATTENTE_REGULARISATION') {
                $etape->setRelation('bonCaisse', $bon);
                $alerte = $bon->date_soumission->diffInMinutes(now()) > 2 * $etape->slaHeures() * 60;
            }
        }

        $bon->setAttribute('age', $age);
        $bon->setAttribute('age_alerte', $alerte);
        $bon->unsetRelation('validations');

        return $bon;
    }

    /**
     * Assistant « Nouveau bon de caisse » (US-BC-01). Aucun bon n'est créé à l'ouverture :
     * le brouillon naît au premier « Suivant » ou « Brouillon » (RG-BC-01, ANO-03).
     */
    public function create()
    {
        Gate::authorize('create', BonCaisse::class);

        return Inertia::render('BonsCaisse/Assistant', $this->propsAssistant(null));
    }

    /**
     * Reprise d'un brouillon (US-BC-11) ou correction d'un bon rejeté (US-BC-14), sur la première étape incomplète.
     */
    public function edit(BonCaisse $bonCaisse)
    {
        Gate::authorize('modifier', $bonCaisse);

        return Inertia::render('BonsCaisse/Assistant', $this->propsAssistant($bonCaisse));
    }

    /**
     * Données de l'assistant : valeurs par défaut (RG-BC-02), référentiels et bon en cours.
     */
    private function propsAssistant(?BonCaisse $bon): array
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();
        $demandeur = $bon?->demandeur ?? $utilisateur;

        return [
            'bon' => $bon ? AssistantBonController::bon($bon) : null,
            'etapeInitiale' => $bon ? ReglesSaisie::premiereEtapeIncomplete($bon) : 1,
            'demandeur' => AssistantBonController::beneficiaire($demandeur) + [
                'site' => $demandeur->site,
                'service' => $demandeur->service,
            ],
            /* US-BC-13 : collègues qui ont délégué l'initiation à l'utilisateur (« Pour le compte de ») */
            'titulaires' => Delegation::initiationsActivesPour($utilisateur->id)
                ->map(fn (Delegation $d) => AssistantBonController::beneficiaire($d->delegant) + [
                    'site' => $d->delegant->site,
                    'service' => $d->delegant->service,
                    'date_fin' => $d->date_fin->toDateString(),
                ])->values(),
            'utilisateur' => AssistantBonController::beneficiaire($utilisateur) + [
                'site' => $utilisateur->site,
                'service' => $utilisateur->service,
                'demandeur' => $utilisateur->aLeRole('demandeur'),
            ],
            'dateDuJour' => today()->toDateString(),
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::where('actif', true)->orderBy('nom')->get(['id', 'nom']),
            'codesAnalytiques' => CodeAnalytique::where('actif', true)->orderBy('code')->get(['id', 'code', 'libelle', 'service_id']),
            'categories' => \App\Models\CategorieDepense::actives()->where('proposee_assistant', true)
                ->get(['code', 'libelle', 'vehicule_obligatoire', 'vehicule_affiche', 'or_affiche']),
            'motifsUrgence' => MotifUrgence::where('actif', true)->orderBy('libelle')->pluck('libelle'),
            'typesBeneficiaire' => collect(BonCaisse::TYPES_BENEFICIAIRE)->only(BonCaisse::TYPES_BENEFICIAIRE_ASSISTANT),
            'modesPaiement' => collect(BonCaisse::MODES_PAIEMENT)->only(BonCaisse::MODES_PAIEMENT_ASSISTANT),
            'typesPiece' => collect(PieceJointe::TYPES_DOCUMENTS)->only(PieceJointe::TYPES_PIECE_ASSISTANT),
            'seuilDP' => Parametre::seuilDP(),
            'prixLitreReference' => Parametre::prixLitreReference(),
        ];
    }

    /**
     * Afficher le détail d'un bon de caisse
     */
    public function show(BonCaisse $bonCaisse)
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = Auth::user();

        /* Restriction de visibilité cohérente avec index() (strict + suivi) :
         * 1. Propriétaire (demandeur ou initiateur du bon) → toujours autorisé
         * 2. Administrateur → accès global sauf brouillons des autres
         * 3. Validateur → bon en attente à son niveau OU bon déjà validé par lui
         * 4. Caissier → bons à payer/régulariser de son site
         */
        $estProprietaire = $bonCaisse->demandeur_id === $utilisateur->id || $bonCaisse->initiateur_id === $utilisateur->id;

        if (!$estProprietaire) {
            $roles = array_unique(array_merge([$utilisateur->role], method_exists($utilisateur, 'rolesValidationEffectifs') ? $utilisateur->rolesValidationEffectifs() : []));
            $autorise = false;

            /* Administrateur : accès global sauf brouillons */
            if (in_array('administrateur', $roles) && $bonCaisse->statut !== 'BROUILLON') {
                $autorise = true;
            }

            /* Bénéficiaire (RG-BC-32) : consultation dès la soumission */
            if ($bonCaisse->beneficiaire_id === $utilisateur->id && $bonCaisse->statut !== 'BROUILLON') {
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
            'initiateur',
            'caissier',
            'validations.validateur',
            'validations.auTitreDe',
            'piecesJointes',
            'piecesJointes.doublonDe.bonCaisse:id,numero',   // RG-BC-19 : bandeau des pièces déjà utilisées
            'piecesJointes.lectureTicket',                    // E-03.8 : résultat de lecture des tickets
            'ordreMission',
            'historiqueActions.utilisateur',
            'ventilations',
        ]);

        /* Calculer les délais de traitement par étape de validation */
        $delaisValidation = [];
        foreach ($bonCaisse->validations as $validation) {
            $delai = null;
            /* Durée au format SFD §1.4 (« 2 h 15 min », « 3 j 4 h ») ; l'ancien calcul ignorait les mois */
            if ($validation->date_validation && $validation->date_attribution) {
                $delai = Format::dureeEntre($validation->date_attribution, $validation->date_validation);
            } elseif ($validation->statut === 'en_attente' && $validation->date_attribution) {
                $delai = Format::dureeEntre($validation->date_attribution) . ' (en cours)';
            }
            $delaisValidation[$validation->id] = $delai;
        }

        /* Soldes des caisses qui paieraient ce bon (RG-BC-12) : caisse espèces du site et caisse OM */
        $soldeCaisseSite = null;
        if ($utilisateur->aLeRole(['caissier', 'daf', 'directeur_pays', 'administrateur'])) {
            $caisseEspeces = Caisse::payeusePour($bonCaisse->site, 'especes');
            $caisseOm = Caisse::payeusePour($bonCaisse->site, 'orange_money');
            $montant = (float) $bonCaisse->montant;
            $total = (float) ($caisseEspeces?->solde ?? 0) + (float) ($caisseOm?->solde ?? 0);

            $soldeCaisseSite = [
                'solde'                => $total,
                'solde_format'         => Format::montant($total),
                'caisse_especes'       => $caisseEspeces?->libelle,
                'solde_especes'        => (float) ($caisseEspeces?->solde ?? 0),
                'solde_especes_format' => Format::montant($caisseEspeces?->solde ?? 0),
                'plafond_retrait'      => $caisseEspeces?->plafond_retrait !== null ? (float) $caisseEspeces->plafond_retrait : null,
                'caisse_om'            => $caisseOm?->libelle,
                'solde_om'             => (float) ($caisseOm?->solde ?? 0),
                'solde_om_format'      => Format::montant($caisseOm?->solde ?? 0),
                'sous_seuil'           => (bool) ($caisseEspeces?->sousSeuil() || $caisseOm?->sousSeuil()),
                // peut_payer dépend du mode de paiement choisi → choisi côté écran
                'peut_payer_especes'   => $caisseEspeces && $caisseEspeces->peutPayer($montant) && !$caisseEspeces->depassePlafondRetrait($montant),
                'peut_payer_om'        => $caisseOm && $caisseOm->peutPayer($montant),
            ];
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
            'categoriesDepense' => \App\Models\CategorieDepense::libelles(),
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
            /* E-03.8 : étapes de validation (fait / en cours / à venir), bandeau de rejet, âge du bon */
            'etapesValidation' => self::etapesValidation($bonCaisse),
            'rejet' => self::rejet($bonCaisse),
            'ageBon' => $bonCaisse->date_soumission && !in_array($bonCaisse->statut, ['PAYE', 'REGULARISE', 'ARCHIVE', 'ANNULE', 'REJETE'], true)
                ? Format::dureeEntre($bonCaisse->date_soumission)
                : null,
            'soldeCaisseSite' => $soldeCaisseSite,
            'codesAnalytiques' => CodeAnalytique::actifs()->with('service')->orderBy('code')->get(),
            'peutPreRegulariser' => $estProprietaire && $bonCaisse->peutPreRegulariser(),
            'aDesPiecesRegularisation' => $bonCaisse->aDesPiecesRegularisation(),
            'motifsRejet' => BonCaisse::MOTIFS_REJET,
            /* Spec v2.2 §6.6 : frais si le caissier retient Orange Money (MSG-M03-06) ; null hors paliers (frais saisis) */
            'fraisOrangeMoney' => ($frais = FraisOrangeMoney::calculer((float) $bonCaisse->montant))
                ? $frais + ['taux_texte' => str_replace('.', ',', (string) $frais['taux'])]
                : null,
        ]);
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

            /* Service non configuré : seule la clé « error » est renseignée */
            $message = $resultat['message'] ?? $resultat['error'] ?? 'erreur inconnue';
            $message = is_array($message) ? json_encode($message) : $message;

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
            'frais_om' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ], [
            'frais_om.numeric' => 'Les frais Orange Money doivent être un montant en GNF.',
            'frais_om.min' => 'Les frais Orange Money ne peuvent pas être négatifs.',
        ]);

        /* Frais Orange Money (spec v2.2, §6.6) : calculés par paliers ; hors paliers, saisis par le caissier (PO-01) */
        $frais = $bonCaisse->fraisPaiement($request->mode_paiement_effectif, $request->frais_om);
        if ($frais === null) {
            return back()->with('error', 'Montant hors des paliers Orange Money : saisissez les frais à ajouter au montant versé.');
        }

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

        /* Caisse payeuse (RG-BC-12) : espèces → caisse espèces du site (à défaut caisse principale de Conakry),
         * Orange Money → caisse OM de Conakry ; virement et autre → paiement hors caisse, rien n'est débité. */
        $caisse = Caisse::payeusePour($bonCaisse->site, $request->mode_paiement_effectif);
        $montant = (float) $bonCaisse->montant;
        /* La caisse décaisse le montant versé : montant du bon, plus les frais Orange Money */
        $montantVerse = (float) $frais['montant_verse'];

        /* Contrôles faits avant de consommer l'OTP, pour que le caissier n'ait pas à en régénérer un. */
        if (in_array($request->mode_paiement_effectif, BonCaisse::MODES_PAIEMENT_CAISSE, true) && !$caisse) {
            return back()->with('error', 'Aucune caisse active pour ce mode de paiement. Vérifiez le paramétrage des caisses.');
        }
        if ($caisse && $caisse->type === 'especes' && $caisse->depassePlafondRetrait($montant)) {
            /* RG-BC-11 */
            return back()->with('error', \App\Exceptions\ErreurMetier::texte('MSG-BC-012', [
                'plafond' => (float) $caisse->plafond_retrait,
                'caisse' => mb_strtolower(mb_substr($caisse->libelle, 0, 1)) . mb_substr($caisse->libelle, 1),
            ]));
        }
        if ($caisse && !$caisse->peutPayer($montantVerse)) {
            return back()->with('error',
                "Solde insuffisant sur la {$caisse->libelle}."
                . " Disponible : {$caisse->solde_format},"
                . ' Montant demandé : ' . \App\Support\Format::montant($montantVerse) . '.'
            );
        }

        /* Paiement, consommation de l'OTP et débit au registre dans une seule transaction ;
         * le verrou sur le bon empêche un double paiement (double clic, deux caissiers). */
        $paye = DB::transaction(function () use ($bonCaisse, $caissier, $request, $otpVerifie, $caisse) {
            $bon = BonCaisse::whereKey($bonCaisse->id)->lockForUpdate()->first();

            $fraisSaisis = $request->filled('frais_om') ? (float) $request->frais_om : null;
            if (!$bon || !$bon->marquerCommePaye($caissier, $request->mode_paiement_effectif, $fraisSaisis)) {
                return false;
            }

            $otpVerifie->marquerCommeUtilise();

            if ($caisse) {
                $bon->update(['caisse_id' => $caisse->id]);
                $caisse->debiter((float) $bon->montant_verse, 'paiement_bon', [
                    'bon_caisse_id' => $bon->id,
                    'utilisateur_id' => $caissier->id,
                    'libelle' => "Paiement du bon {$bon->numero} — {$bon->beneficiaire}",
                ]);
            }

            return true;
        });

        if ($paye) {
            /* Alerte si la caisse passe sous son seuil après paiement */
            if ($caisse && $caisse->sousSeuil()) {
                NotificationService::notifierAlerteSolde($caisse, $caissier);
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
        $bonCaisse->load(['demandeur', 'initiateur', 'validations.validateur', 'caissier', 'piecesJointes']);

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

