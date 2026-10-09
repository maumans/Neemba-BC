<?php

namespace App\Http\Controllers;

use App\Exceptions\ErreurMetier;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\OrdreReparationOdm;
use App\Models\User;
use App\Services\Odm\AnnulerOdm;
use App\Services\Odm\ChevauchementOdm;
use App\Services\Odm\EnregistrementOdm;
use App\Services\Odm\NotificationsOdm;
use App\Services\Odm\PresentationOdm;
use App\Services\Odm\SoumettreOdm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * API JSON du formulaire « Ordre de mission » (M12-2) : brouillon enregistré au fil de la saisie, calcul renvoyé
 * par le serveur (il fait foi), soumission avec clé d'idempotence, demande de dérogation, annulation.
 * Les refus métier suivent le format §5.7 (ErreurMetier).
 */
class OdmApiController extends Controller
{
    /** GET /api/v1/odm/employes?q= — participants : salariés actifs, avec ce que l'ODM reprend du référentiel (RG-M12-04) */
    public function employes(Request $request): JsonResponse
    {
        $recherche = trim((string) $request->query('q'));
        if (mb_strlen($recherche) < 2) {
            return response()->json(['resultats' => []]);
        }

        $resultats = User::actifs()
            ->where(fn ($q) => $q->where('name', 'like', "%{$recherche}%")
                ->orWhere('prenom', 'like', "%{$recherche}%")
                ->orWhere('matricule', 'like', "%{$recherche}%"))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (User $u) => [
                'user_id' => $u->id,
                'nom' => trim(mb_strtoupper($u->name) . ' ' . $u->prenom),
                'libelle' => trim(mb_strtoupper($u->name) . ' ' . $u->prenom) . ($u->matricule ? " — {$u->matricule}" : '') . ($u->service ? " — {$u->service}" : ''),
                'matricule' => $u->matricule,
                'service' => $u->service,
                'statut_cadre' => $u->statut_cadre,
                'numero_om' => $u->numero_om,
            ]);

        return response()->json(['resultats' => $resultats]);
    }

    /** POST /api/v1/odm — création du brouillon */
    public function creer(Request $request): JsonResponse
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        abort_unless($utilisateur->aLeRole('demandeur'), 403, 'Seul un demandeur crée un ordre de mission.');

        $odm = EnregistrementOdm::creer($utilisateur, $this->saisie($request));

        return response()->json(['odm' => PresentationOdm::formulaire($odm)], 201);
    }

    /** PUT /api/v1/odm/{odm} — enregistrement du brouillon, calcul renvoyé */
    public function enregistrer(Request $request, OrdreMission $odm): JsonResponse
    {
        $this->autoriserModification($odm);
        $odm = EnregistrementOdm::enregistrer($odm, $this->saisie($request), Auth::user());

        return response()->json(['odm' => PresentationOdm::formulaire($odm)]);
    }

    /** POST /api/v1/odm/{odm}/soumettre */
    public function soumettre(Request $request, OrdreMission $odm): JsonResponse
    {
        $this->autoriserModification($odm, avecEtatsSoumis: true);
        $request->validate(['cle_soumission' => ['nullable', 'string', 'max:64']]);

        $odm = SoumettreOdm::executer($odm, Auth::user(), $request->input('cle_soumission'));

        return response()->json([
            'odm' => ['id' => $odm->id, 'numero' => $odm->numero, 'statut' => $odm->statut],
            'message' => "Ordre de mission {$odm->numero} soumis pour validation.",
            'redirection' => route('odm.show', $odm),
        ]);
    }

    /** POST /api/v1/odm/{odm}/derogation — RG-M12-16 : le demandeur demande au DAF une dérogation au chevauchement */
    public function demanderDerogation(Request $request, OrdreMission $odm): JsonResponse
    {
        $this->autoriserModification($odm);
        $motif = trim((string) $request->input('motif'));
        if (mb_strlen($motif) < 10) {
            throw new ErreurMetier('MOTIF_DEROGATION', 'MSG-APP-022', [], 'RG-M12-16', 'motif');
        }
        if (ChevauchementOdm::conflits($odm) === []) {
            return response()->json(['message' => 'Aucun chevauchement : la dérogation est inutile.'], 409);
        }

        $odm->update(['derogation_statut' => 'demandee', 'derogation_demande_motif' => mb_substr($motif, 0, 1000),
            'derogation_par_id' => null, 'derogation_motif' => null, 'derogation_le' => null]);
        HistoriqueOdm::enregistrer($odm, 'derogation_demandee', $odm->statut, $odm->statut, Auth::id(), $motif,
            ['conflits' => ChevauchementOdm::conflits($odm)]);
        NotificationsOdm::derogationDemandee($odm->fresh(), Auth::user());

        return response()->json(['odm' => PresentationOdm::formulaire($odm->fresh()), 'message' => 'Dérogation demandée au DAF.']);
    }

    /** POST /api/v1/odm/{odm}/annuler — RG-M12-22 */
    public function annuler(Request $request, OrdreMission $odm): JsonResponse
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:1000']]);
        $odm = AnnulerOdm::parLeDemandeur($odm, Auth::user(), $request->input('motif'));

        return response()->json(['message' => 'Ordre de mission annulé.', 'redirection' => route('odm.index')]);
    }

    /* ------------------------------------------------------------------ */

    private function autoriserModification(OrdreMission $odm, bool $avecEtatsSoumis = false): void
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        if (!in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)) {
            throw new ErreurMetier('ODM_NON_MODIFIABLE', 'MSG-APP-020', [], 'RG-M12-12', null, 403);
        }
        /* La soumission rejouée d'un ODM déjà soumis est traitée par SoumettreOdm (idempotence) */
        if (!$avecEtatsSoumis && !$odm->estModifiablePar($utilisateur)) {
            throw new ErreurMetier('ODM_DEJA_SOUMIS', 'MSG-APP-019', [], 'RG-M12-12', null, 409);
        }
    }

    /** Saisie du formulaire : formats vérifiés ; les règles de fond sont contrôlées à la soumission (ReglesOdm) */
    private function saisie(Request $request): array
    {
        $donnees = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(OrdreMission::TYPES))],
            'technique' => ['sometimes', 'boolean'],
            'site' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service' => ['sometimes', 'nullable', 'string', 'max:255', Rule::exists('services', 'nom')],
            'code_analytique' => ['sometimes', 'nullable', 'string', 'max:50'],
            'but' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'clients' => ['sometimes', 'array', 'max:20'],
            'clients.*' => ['nullable', 'string', 'max:120'],
            'destinations' => ['sometimes', 'array', 'max:20'],
            'destinations.*' => ['nullable', 'string', 'max:120'],
            'vehicule' => ['sometimes', 'nullable', 'string', 'max:60'],
            'date_depart' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_retour_prevue' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'motif_depart_passe' => ['sometimes', 'nullable', 'string', 'max:500'],
            'prise_en_charge' => ['sometimes', Rule::in(array_keys(OrdreMission::PRISES_EN_CHARGE))],
            'mode_client' => ['sometimes', Rule::in(array_keys(OrdreMission::MODES_CLIENT))],
            'hebergement_exterieur' => ['sometimes', 'nullable', Rule::in(array_keys(OrdreMission::HEBERGEMENTS_EXTERIEURS))],
            'reference_billet' => ['sometimes', 'nullable', 'string', 'max:100'],
            'participants' => ['sometimes', 'array', 'max:50'],
            'participants.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'participants.*.base_vie' => ['sometimes', 'boolean'],
            'participants.*.hebergement_facture' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'participants.*.prises_en_charge' => ['sometimes', 'array'],
            'participants.*.prises_en_charge.*' => [Rule::in(array_keys(\App\Services\Odm\CalculOdm::PRISES_EN_CHARGE))],
            'ordres_reparation' => ['sometimes', 'array', 'max:20'],
            'ordres_reparation.*.numero' => ['required', 'string', 'max:20'],
            'ordres_reparation.*.type' => ['sometimes', Rule::in(array_keys(OrdreReparationOdm::TYPES))],
        ], [
            'date_depart.date_format' => 'Date invalide.',
            'date_retour_prevue.date_format' => 'Date invalide.',
        ]);

        return $donnees;
    }
}
