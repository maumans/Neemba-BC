<?php

namespace App\Http\Controllers;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\Validation;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use App\Services\BonCaisse\CircuitBon;
use Inertia\Inertia;

/**
 * Contrôleur des Validations
 * 
 * Gère le processus de validation hiérarchique des bons de caisse.
 * Chaque validateur ne voit que les bons correspondant à son niveau.
 */
class ValidationController extends Controller
{
    /**
     * Afficher la liste des bons en attente de validation pour l'utilisateur
     */
    public function index(Request $request)
    {
        $utilisateur = Auth::user();
        /* Demande du 22/10 : les justifications de BP à viser (N+1, CDG, Comptabilité) apparaissent aussi ici */
        $justifications = \App\Services\BonCaisse\JustifierBp::aViserPar($utilisateur);

        if (!$utilisateur->peutValider() && $justifications->isEmpty() && !$utilisateur->aLeRole(['comptable', 'chef_comptable', 'controle_gestion'])) {
            abort(403, 'Vous n\'avez pas les droits de validation.');
        }

        $rolesEffectifs = $utilisateur->rolesValidationEffectifs();

        /* RG-M01-04 : ni les bons dont il est demandeur ou bénéficiaire, ni ceux d'un autre service au niveau chef de service */
        $query = CircuitBon::requeteAViser($utilisateur)->with('demandeur');

        $bonsEnAttente = $query->latest('date_demande')
            ->paginate(15);

        return Inertia::render('Validations/Index', [
            'bonsEnAttente' => $bonsEnAttente,
            'justificationsAViser' => $justifications->map(fn (\App\Models\JustificationBp $j) => [
                'id' => $j->id,
                'bon_id' => $j->bon_caisse_id,
                'numero' => $j->bon->numero,
                'beneficiaire' => $j->bon->beneficiaire,
                'initiateur' => $j->initiateur?->nom_complet,
                'montant_avance' => (float) $j->montant_avance,
                'montant_justifie' => (float) $j->montant_justifie,
                'etape' => \App\Models\JustificationBp::ETAPES[$j->etape] ?? $j->etape,
                'soumise_le' => $j->soumise_le?->format('d/m/Y H:i'),
            ])->values(),
            'roleValidateur' => count($rolesEffectifs) > 0 ? $rolesEffectifs[0] : null,
            'rolesEffectifs' => $rolesEffectifs,
        ]);
    }

    /**
     * Afficher le détail d'un bon à valider
     */
    public function show(BonCaisse $bonCaisse)
    {
        $utilisateur = Auth::user();

        /* RG-M01-04, RG-M04-09 : seul un valideur de l'étape en cours, ni demandeur ni bénéficiaire, ouvre l'écran de visa */
        if (!CircuitBon::peutViser($bonCaisse, $utilisateur)) {
            return redirect()->route('bons-caisse.show', $bonCaisse)->with('error', CircuitBon::motifRefus($bonCaisse, $utilisateur));
        }

        $bonCaisse->load([
            'demandeur',
            'validations.validateur',
            'piecesJointes',
            'piecesJointes.doublonDe.bonCaisse:id,numero',   // RG-BC-19 : bandeau des pièces déjà utilisées
            'ordreMission',
            'historiqueActions.utilisateur',
        ]);

        return Inertia::render('Validations/Show', [
            'bonCaisse' => $bonCaisse,
            'statutsLabels' => BonCaisse::STATUTS_LABELS,
            'categoriesDepense' => \App\Models\CategorieDepense::libelles(),
            'typesBeneficiaire' => BonCaisse::TYPES_BENEFICIAIRE,
            'modesPaiement' => BonCaisse::MODES_PAIEMENT,
            'actionsLabels' => HistoriqueAction::ACTIONS_LABELS,
            'codesAnalytiques' => \App\Models\CodeAnalytique::where('actif', true)->get(),
            /* Spec v2.4, RG-M03-27 : sites proposés au CDG pour corriger le site analytique */
            'sitesActifs' => \App\Models\Site::actifs()->orderBy('nom')->pluck('nom'),
            'motifsRejet' => \App\Models\MotifRejet::options(),
            /* RG-M04-05 : le valideur voit le doublon probable et les BP en retard du bénéficiaire là où il vise */
            'alertes' => \App\Services\BonCaisse\AlertesBon::pourAffichage($bonCaisse),
        ]);
    }

    /**
     * Approuver un bon de caisse
     */
    public function approuver(Request $request, BonCaisse $bonCaisse)
    {
        $utilisateur = Auth::user();

        /* RG-M01-04, RG-M04-09 : valideur de l'étape en cours (titulaire ou suppléant), ni demandeur ni bénéficiaire */
        $droit = CircuitBon::peutViser($bonCaisse, $utilisateur);
        if (!$droit) {
            return back()->with('error', CircuitBon::motifRefus($bonCaisse, $utilisateur));
        }
        $roleValidation = $droit['role'];

        $regles = [
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ];

        /* Phase 1.2 : Le CDG peut modifier le code analytique lors de la validation */
        if ($roleValidation === 'controle_gestion') {
            /* RG-M04-03 : le code retenu par le CDG est contrôlé comme à la saisie — il existe, il est actif et il
             * est rattaché au service du bon. Les codes de ventilation suivent la même règle : ils alimentent
             * l'imputation comptable (M16). */
            $regleCode = [
                Rule::exists('codes_analytiques', 'code')->where('actif', true),
                \App\Services\BonCaisse\ReglesSaisie::regleCodeDuService(['service' => $bonCaisse->service]),
            ];
            $regles['code_analytique'] = ['nullable', 'string', 'max:255', ...$regleCode];
            /* Spec v2.4, RG-M03-27 : le CDG peut corriger le site analytique, avec trace */
            $regles['site'] = ['nullable', 'string', Rule::exists('sites', 'nom')->where('actif', true)];
            $regles['ventilations'] = ['nullable', 'array'];
            $regles['ventilations.*.code_analytique'] = ['required_with:ventilations', 'string', ...$regleCode];
            $regles['ventilations.*.montant'] = ['required_with:ventilations', 'numeric', 'min:0'];
            $regles['ventilations.*.pourcentage'] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        $validated = $request->validate($regles);

        /* Si le CDG a modifié le code analytique ou les ventilations */
        if ($roleValidation === 'controle_gestion') {
            if ($request->filled('site') && $validated['site'] !== $bonCaisse->site) {
                $ancienSite = $bonCaisse->site ?: 'aucun';
                $bonCaisse->modifierAvecJournal(
                    ['site' => $validated['site']],
                    \App\Models\HistoriqueAction::ACTION_MODIFICATION_SITE,
                    "Site analytique corrigé par le CDG : {$ancienSite} → {$validated['site']}",
                );
            }
            if ($request->filled('code_analytique')) {
                $ancienCode = $bonCaisse->code_analytique;
                $bonCaisse->modifierAvecJournal(
                    ['code_analytique' => $validated['code_analytique']],
                    \App\Models\HistoriqueAction::ACTION_MODIFICATION_CODE_ANALYTIQUE,
                    "Code analytique modifié par CDG : {$ancienCode} → {$validated['code_analytique']}",
                );
            }

            if (!empty($validated['ventilations'])) {
                $bonCaisse->ventilations()->delete();
                foreach ($validated['ventilations'] as $ventilation) {
                    $bonCaisse->ventilations()->create([
                        'code_analytique' => $ventilation['code_analytique'],
                        'montant' => $ventilation['montant'],
                        'pourcentage' => $ventilation['pourcentage'] ?? null,
                    ]);
                }

                \App\Models\HistoriqueAction::enregistrer(
                    $bonCaisse,
                    'modification_ventilation',
                    $bonCaisse->statut,
                    $bonCaisse->statut,
                    $utilisateur->id,
                    'Ventilation analytique modifiée par CDG lors de la validation.',
                );
            }
        }

        /* Trouver l'étape de validation en attente pour ce rôle */
        $validation = $bonCaisse->validations()
            ->where('role', $roleValidation)
            ->enAttente()
            ->first();

        if ($validation) {
            $validation->approuver($utilisateur, $request->commentaire ?? $validated['commentaire'] ?? null);
            CircuitBon::sauterEtapesSansValideur($bonCaisse->fresh());
        }

        /* Rafraîchir le bon pour récupérer le nouveau statut */
        $bonCaisse->refresh();
        $bonCaisse->load('demandeur');

        /* Si le bon est désormais APPROUVE, c'est l'approbation finale */
        if ($bonCaisse->statut === 'APPROUVE') {
            NotificationService::notifierApprobationFinale($bonCaisse, $utilisateur);
        } else {
            NotificationService::notifierValidation($bonCaisse, $utilisateur);
        }

        return redirect()
            ->route('validations.index')
            ->with('success', 'Bon approuvé avec succès.');
    }

    /**
     * Rejeter un bon de caisse
     */
    public function rejeter(Request $request, BonCaisse $bonCaisse)
    {
        $utilisateur = Auth::user();

        /* RG-M01-04, RG-M04-09 : valideur de l'étape en cours (titulaire ou suppléant), ni demandeur ni bénéficiaire */
        $droit = CircuitBon::peutViser($bonCaisse, $utilisateur);
        if (!$droit) {
            return back()->with('error', CircuitBon::motifRefus($bonCaisse, $utilisateur));
        }
        $roleValidation = $droit['role'];

        $validated = $request->validate([
            /* RG-M04-04 : motif choisi dans la liste paramétrable, complété d'un commentaire libre */
            'motif_rejet' => ['required', 'string', Rule::in(array_keys(\App\Models\MotifRejet::options()))],
            /* Q73 (§5.1) : le rejet s'explique toujours, en 10 caractères au moins, quel que soit le motif retenu */
            'commentaire' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'commentaire.required' => __('MSG-BC-002'),
            'commentaire.min' => __('MSG-BC-002'),
        ]);

        $motifStr = \App\Models\MotifRejet::options()[$validated['motif_rejet']];
        $commentaireRejetFinal = $motifStr;
        if (!empty($validated['commentaire'])) {
            $commentaireRejetFinal .= " - " . $validated['commentaire'];
        }

        /* Trouver l'étape de validation en attente pour ce rôle */
        $validation = $bonCaisse->validations()
            ->where('role', $roleValidation)
            ->enAttente()
            ->first();

        if ($validation) {
            $validation->rejeter($utilisateur, $commentaireRejetFinal);
        }

        $bonCaisse->load('demandeur');
        NotificationService::notifierRejet($bonCaisse, $utilisateur, $commentaireRejetFinal);

        return redirect()
            ->route('validations.index')
            ->with('success', 'Bon rejeté.');
    }

    /**
     * Demander un complément d'information au demandeur
     * 
     * Le validateur peut demander des pièces ou informations supplémentaires
     * sans rejeter le bon. Le bon reste dans son statut actuel.
     */
    public function demanderComplement(Request $request, BonCaisse $bonCaisse)
    {
        $utilisateur = Auth::user();

        /* RG-M01-04, RG-M04-09 : valideur de l'étape en cours (titulaire ou suppléant), ni demandeur ni bénéficiaire */
        $droit = CircuitBon::peutViser($bonCaisse, $utilisateur);
        if (!$droit) {
            return back()->with('error', CircuitBon::motifRefus($bonCaisse, $utilisateur));
        }
        $roleValidation = $droit['role'];

        $request->validate([
            'commentaire' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        /* Enregistrer la demande de complément dans l'historique */
        HistoriqueAction::enregistrer(
            $bonCaisse,
            HistoriqueAction::ACTION_DEMANDE_COMPLEMENT,
            $bonCaisse->statut,
            $bonCaisse->statut,
            $utilisateur->id,
            $request->commentaire,
            ['role_validateur' => $roleValidation],
        );

        $bonCaisse->load('demandeur');
        NotificationService::notifierDemandeComplement($bonCaisse, $utilisateur, $request->commentaire);

        return redirect()
            ->route('validations.index')
            ->with('success', 'Demande de complément envoyée au demandeur.');
    }
}
