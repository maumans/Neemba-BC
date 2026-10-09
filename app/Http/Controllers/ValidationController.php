<?php

namespace App\Http\Controllers;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\Validation;
use App\Services\NotificationService;
use Illuminate\Http\Request;
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

        if (!$utilisateur->peutValider()) {
            abort(403, 'Vous n\'avez pas les droits de validation.');
        }

        $rolesEffectifs = $utilisateur->rolesValidationEffectifs();

        /* RG-M01-04 : ni les bons dont il est demandeur ou bénéficiaire, ni ceux d'un autre service au niveau chef de service */
        $query = CircuitBon::requeteAViser($utilisateur)->with('demandeur');

        $bonsEnAttente = $query->latest('date_demande')
            ->paginate(15);

        return Inertia::render('Validations/Index', [
            'bonsEnAttente' => $bonsEnAttente,
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
            'motifsRejet' => BonCaisse::MOTIFS_REJET,
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
            $regles['code_analytique'] = ['nullable', 'string', 'max:255'];
            $regles['ventilations'] = ['nullable', 'array'];
            $regles['ventilations.*.code_analytique'] = ['required_with:ventilations', 'string'];
            $regles['ventilations.*.montant'] = ['required_with:ventilations', 'numeric', 'min:0'];
            $regles['ventilations.*.pourcentage'] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        $validated = $request->validate($regles);

        /* Si le CDG a modifié le code analytique ou les ventilations */
        if ($roleValidation === 'controle_gestion') {
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
            'motif_rejet' => ['required', 'string', 'in:' . implode(',', array_keys(BonCaisse::MOTIFS_REJET))],
            'commentaire' => ['nullable', 'string', 'max:1000', 'required_if:motif_rejet,autre'],
        ]);

        $motifStr = BonCaisse::MOTIFS_REJET[$validated['motif_rejet']];
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
