<?php

namespace App\Http\Controllers;

use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\User;
use App\Services\Odm\NotificationsOdm;
use App\Services\Odm\PresentationOdm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Écrans du module M12 « Ordres de mission » : liste, formulaire (création, brouillon, correction), fiche,
 * décision du DAF sur une dérogation au chevauchement (RG-M12-16).
 */
class OrdreMissionController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();

        $requete = OrdreMission::visiblesPar($utilisateur)
            ->with('demandeur:id,name,prenom')
            ->withCount('participantsActifs')
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $texte = '%' . trim((string) $request->recherche) . '%';
                $q->where(fn ($r) => $r->where('numero', 'like', $texte)
                    ->orWhere('but', 'like', $texte)
                    ->orWhere('destinations', 'like', $texte)
                    ->orWhereHas('participants', fn ($p) => $p->where('nom', 'like', $texte)));
            })
            ->latest('id');

        $odms = $requete->paginate(20)->withQueryString();
        $odms->getCollection()->transform(fn (OrdreMission $odm) => PresentationOdm::ligne($odm) + ['modifiable' => $odm->estModifiablePar($utilisateur)]);

        $estDaf = $utilisateur->aLeRole(OrdreMission::ROLES_DEROGATION);

        return Inertia::render('Odm/Index', [
            'odms' => $odms,
            'filtres' => $request->only(['statut', 'type', 'recherche']),
            'statuts' => OrdreMission::STATUTS,
            'types' => OrdreMission::TYPES,
            'peutCreer' => $utilisateur->aLeRole('demandeur'),
            /* RG-M12-16 : dérogations à décider par le DAF */
            'derogations' => $estDaf
                ? OrdreMission::where('derogation_statut', 'demandee')->with('demandeur:id,name,prenom')->latest('updated_at')->get()
                    ->map(fn (OrdreMission $odm) => PresentationOdm::ligne($odm) + [
                        'derogation_demande_motif' => $odm->derogation_demande_motif,
                        'conflits' => \App\Services\Odm\ChevauchementOdm::conflits($odm),
                    ])
                : [],
        ]);
    }

    public function create()
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        abort_unless($utilisateur->aLeRole('demandeur'), 403);

        return Inertia::render('Odm/Formulaire', PresentationOdm::referentiels() + [
            'odm' => null,
            'defauts' => [
                'site' => $utilisateur->site,
                'service' => $utilisateur->service,
                'technique' => in_array($utilisateur->service, OrdreMission::SERVICES_TECHNIQUES, true),
            ],
        ]);
    }

    public function edit(OrdreMission $odm)
    {
        abort_unless($odm->estModifiablePar(Auth::user()), 403, 'Cet ordre de mission ne peut plus être modifié.');

        return Inertia::render('Odm/Formulaire', PresentationOdm::referentiels() + [
            'odm' => PresentationOdm::formulaire($odm),
            'defauts' => null,
        ]);
    }

    public function show(OrdreMission $odm)
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        abort_unless($odm->estVisiblePar($utilisateur), 403);

        return Inertia::render('Odm/Show', [
            'odm' => PresentationOdm::detail($odm),
            'peutModifier' => $odm->estModifiablePar($utilisateur),
            'peutAnnuler' => in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)
                && in_array($odm->statut, \App\Services\Odm\AnnulerOdm::STATUTS_ANNULABLES_PAR_LE_DEMANDEUR, true)
                && !$odm->bons()->exists(),
            'peutDeciderDerogation' => $odm->derogation_statut === 'demandee' && $utilisateur->aLeRole(OrdreMission::ROLES_DEROGATION),
        ]);
    }

    /** POST /ordres-mission/{odm}/derogation — le DAF accorde ou refuse, avec un motif (RG-M12-16) */
    public function deciderDerogation(Request $request, OrdreMission $odm)
    {
        /** @var User $daf */
        $daf = Auth::user();
        abort_unless($daf->aLeRole(OrdreMission::ROLES_DEROGATION), 403, 'Seul le DAF décide d\'une dérogation.');

        $donnees = $request->validate([
            'decision' => ['required', 'in:accorder,refuser'],
            'motif' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motif.required' => \App\Exceptions\ErreurMetier::texte('MSG-APP-022'),
            'motif.min' => \App\Exceptions\ErreurMetier::texte('MSG-APP-022'),
        ]);
        if ($odm->derogation_statut !== 'demandee') {
            throw ValidationException::withMessages(['decision' => 'Aucune dérogation n\'est en attente pour cet ordre de mission.']);
        }

        $accordee = $donnees['decision'] === 'accorder';
        $odm->update([
            'derogation_statut' => $accordee ? 'accordee' : 'refusee',
            'derogation_par_id' => $daf->id,
            'derogation_motif' => trim($donnees['motif']),
            'derogation_le' => now(),
        ]);
        HistoriqueOdm::enregistrer($odm, $accordee ? 'derogation_accordee' : 'derogation_refusee', $odm->statut, $odm->statut, $daf->id, trim($donnees['motif']));
        NotificationsOdm::derogationDecidee($odm->fresh('demandeur'), $daf);

        return back()->with('success', $accordee ? 'Dérogation accordée.' : 'Dérogation refusée.');
    }
}
