<?php

namespace App\Http\Controllers;

use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\Validation;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $statutsAttendus = [];
        foreach ($rolesEffectifs as $role) {
            $statut = match ($role) {
                'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
                'controle_gestion' => 'EN_ATTENTE_CDG',
                'daf' => 'EN_ATTENTE_DAF',
                'directeur_pays' => 'EN_ATTENTE_DP',
                default => null,
            };
            if ($statut) {
                $statutsAttendus[] = $statut;
            }
        }

        $query = BonCaisse::with('demandeur')
            ->whereIn('statut', $statutsAttendus);

        /* Si l'utilisateur agit comme chef de service (en propre ou délégué), filtrer par ses services */
        if (in_array('responsable_service', $rolesEffectifs)) {
            $servicesAccessibles = [];
            if ($utilisateur->role === 'responsable_service' && $utilisateur->service) {
                $servicesAccessibles[] = $utilisateur->service;
            }
            $delegants = \App\Models\Delegation::delegantsActifsPour($utilisateur->id);
            foreach ($delegants as $delegant) {
                if ($delegant->role === 'responsable_service' && $delegant->service) {
                    $servicesAccessibles[] = $delegant->service;
                }
            }
            $servicesAccessibles = array_unique($servicesAccessibles);

            $query->where(function ($q) use ($servicesAccessibles, $statutsAttendus) {
                $q->where(function ($q1) use ($servicesAccessibles) {
                    $q1->where('statut', 'EN_ATTENTE_CHEF_SERVICE')
                       ->whereIn('service', $servicesAccessibles);
                });
                
                $autresStatuts = array_diff($statutsAttendus, ['EN_ATTENTE_CHEF_SERVICE']);
                if (!empty($autresStatuts)) {
                    $q->orWhereIn('statut', $autresStatuts);
                }
            });
        }

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

        $rolesEffectifs = $utilisateur->rolesValidationEffectifs();

        if (!$utilisateur->peutValider() || !$bonCaisse->estEnAttenteDeUnDesRoles($rolesEffectifs)) {
            abort(403, 'Ce bon n\'est pas en attente de votre validation.');
        }

        /* Trouver le rôle de validation actif pour ce bon */
        $roleValidation = null;
        foreach ($rolesEffectifs as $role) {
            if ($bonCaisse->estEnAttenteDe($role)) {
                $roleValidation = $role;
                break;
            }
        }

        /* Un chef de service ne peut valider que les bons de ses services (propres + délégués) */
        if ($roleValidation === 'responsable_service') {
            $servicesAccessibles = [];
            if ($utilisateur->role === 'responsable_service') $servicesAccessibles[] = $utilisateur->service;
            foreach (\App\Models\Delegation::delegantsActifsPour($utilisateur->id) as $delegant) {
                if ($delegant->role === 'responsable_service' && $delegant->service) {
                    $servicesAccessibles[] = $delegant->service;
                }
            }
            if (!in_array($bonCaisse->service, $servicesAccessibles)) {
                abort(403, 'Ce bon n\'appartient pas à votre service.');
            }
        }

        $bonCaisse->load([
            'demandeur',
            'validations.validateur',
            'piecesJointes',
            'ordreMission',
            'historiqueActions.utilisateur',
        ]);

        return Inertia::render('Validations/Show', [
            'bonCaisse' => $bonCaisse,
            'statutsLabels' => BonCaisse::STATUTS_LABELS,
            'categoriesDepense' => BonCaisse::CATEGORIES_DEPENSE,
            'typesBeneficiaire' => BonCaisse::TYPES_BENEFICIAIRE,
            'modesPaiement' => BonCaisse::MODES_PAIEMENT,
            'actionsLabels' => HistoriqueAction::ACTIONS_LABELS,
            'codesAnalytiques' => \App\Models\CodeAnalytique::where('actif', true)->get(),
        ]);
    }

    /**
     * Approuver un bon de caisse
     */
    public function approuver(Request $request, BonCaisse $bonCaisse)
    {
        $utilisateur = Auth::user();

        $rolesEffectifs = $utilisateur->rolesValidationEffectifs();

        if (!$utilisateur->peutValider() || !$bonCaisse->estEnAttenteDeUnDesRoles($rolesEffectifs)) {
            abort(403);
        }

        /* Trouver le rôle de validation actif pour ce bon */
        $roleValidation = null;
        foreach ($rolesEffectifs as $role) {
            if ($bonCaisse->estEnAttenteDe($role)) {
                $roleValidation = $role;
                break;
            }
        }

        /* Un chef de service ne peut valider que les bons de ses services (propres + délégués) */
        if ($roleValidation === 'responsable_service') {
            $servicesAccessibles = [];
            if ($utilisateur->role === 'responsable_service') $servicesAccessibles[] = $utilisateur->service;
            foreach (\App\Models\Delegation::delegantsActifsPour($utilisateur->id) as $delegant) {
                if ($delegant->role === 'responsable_service' && $delegant->service) {
                    $servicesAccessibles[] = $delegant->service;
                }
            }
            if (!in_array($bonCaisse->service, $servicesAccessibles)) {
                abort(403);
            }
        }

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
                $bonCaisse->update(['code_analytique' => $validated['code_analytique']]);

                \App\Models\HistoriqueAction::enregistrer(
                    $bonCaisse,
                    'modification_code_analytique',
                    $bonCaisse->statut,
                    $bonCaisse->statut,
                    $utilisateur->id,
                    "Code analytique modifié par CDG : {$ancienCode} → {$validated['code_analytique']}",
                    ['ancien_code' => $ancienCode, 'nouveau_code' => $validated['code_analytique']],
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

        $rolesEffectifs = $utilisateur->rolesValidationEffectifs();

        if (!$utilisateur->peutValider() || !$bonCaisse->estEnAttenteDeUnDesRoles($rolesEffectifs)) {
            abort(403);
        }

        $roleValidation = null;
        foreach ($rolesEffectifs as $role) {
            if ($bonCaisse->estEnAttenteDe($role)) {
                $roleValidation = $role;
                break;
            }
        }

        /* Un chef de service ne peut rejeter que les bons de ses services */
        if ($roleValidation === 'responsable_service') {
            $servicesAccessibles = [];
            if ($utilisateur->role === 'responsable_service') $servicesAccessibles[] = $utilisateur->service;
            foreach (\App\Models\Delegation::delegantsActifsPour($utilisateur->id) as $delegant) {
                if ($delegant->role === 'responsable_service' && $delegant->service) {
                    $servicesAccessibles[] = $delegant->service;
                }
            }
            if (!in_array($bonCaisse->service, $servicesAccessibles)) {
                abort(403);
            }
        }

        $request->validate([
            'commentaire' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        /* Trouver l'étape de validation en attente pour ce rôle */
        $validation = $bonCaisse->validations()
            ->where('role', $roleValidation)
            ->enAttente()
            ->first();

        if ($validation) {
            $validation->rejeter($utilisateur, $request->commentaire);
        }

        $bonCaisse->load('demandeur');
        NotificationService::notifierRejet($bonCaisse, $utilisateur, $request->commentaire);

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

        $rolesEffectifs = $utilisateur->rolesValidationEffectifs();

        if (!$utilisateur->peutValider() || !$bonCaisse->estEnAttenteDeUnDesRoles($rolesEffectifs)) {
            abort(403);
        }

        $roleValidation = null;
        foreach ($rolesEffectifs as $role) {
            if ($bonCaisse->estEnAttenteDe($role)) {
                $roleValidation = $role;
                break;
            }
        }

        /* Un chef de service ne peut demander un complément que sur les bons de ses services */
        if ($roleValidation === 'responsable_service') {
            $servicesAccessibles = [];
            if ($utilisateur->role === 'responsable_service') $servicesAccessibles[] = $utilisateur->service;
            foreach (\App\Models\Delegation::delegantsActifsPour($utilisateur->id) as $delegant) {
                if ($delegant->role === 'responsable_service' && $delegant->service) {
                    $servicesAccessibles[] = $delegant->service;
                }
            }
            if (!in_array($bonCaisse->service, $servicesAccessibles)) {
                abort(403);
            }
        }

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
