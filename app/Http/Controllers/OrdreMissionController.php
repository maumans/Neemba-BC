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
            /* ODM qui attendent le visa de l'utilisateur (titulaire ou suppléant) */
            'aViser' => \App\Services\Odm\CircuitOdm::aViserPar($utilisateur)->map(fn (OrdreMission $odm) => PresentationOdm::ligne($odm))->values(),
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
        /* Un suppléant (délégation « visa des ODM ») voit l'ODM qu'il doit viser */
        $visa = \App\Services\Odm\CircuitOdm::peutViser($odm, $utilisateur);
        abort_unless($visa || $odm->estVisiblePar($utilisateur), 403);

        return Inertia::render('Odm/Show', [
            'odm' => PresentationOdm::detail($odm),
            'etapes' => PresentationOdm::etapes($odm),
            'bons' => PresentationOdm::bons($odm),
            'generation' => PresentationOdm::generation($odm, $utilisateur),
            /* RG-M12-15, variante B : ODM à la charge du client, sans bon */
            'sansBon' => \App\Services\Odm\GenererBonsOdm::sansBon($odm),
            /* RG-M12-11 : visa possible à l'étape en cours, « au titre de » pour un suppléant */
            'visa' => $visa ? [
                'niveau' => $visa['etape']->libelle,
                'au_titre_de' => $visa['au_titre_de']?->nom_complet,
                'dernier' => $odm->etapes()->where('version', $odm->version)->where('statut', 'a_venir')->doesntExist(),
            ] : null,
            'peutModifier' => $odm->estModifiablePar($utilisateur),
            'peutAnnuler' => in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)
                && in_array($odm->statut, \App\Services\Odm\AnnulerOdm::STATUTS_ANNULABLES_PAR_LE_DEMANDEUR, true)
                && !$odm->bons()->exists(),
            'peutDeciderDerogation' => $odm->derogation_statut === 'demandee' && $utilisateur->aLeRole(OrdreMission::ROLES_DEROGATION),
        ]);
    }

    /** POST /ordres-mission/{odm}/viser — RG-M12-11 ; validation finale : calcul figé et MSG-M12-08 */
    public function viser(Request $request, OrdreMission $odm)
    {
        $request->validate(['commentaire' => ['nullable', 'string', 'max:1000']]);
        $odm = \App\Services\Odm\ValiderOdm::viser($odm, Auth::user(), $request->input('commentaire'));

        return redirect()->route('odm.show', $odm)->with('success', $odm->statut === 'VALIDE'
            ? \App\Exceptions\ErreurMetier::texte('MSG-M12-08', ['numero' => $odm->numero])
            : "Visa enregistré : l'ordre de mission {$odm->numero} passe au niveau suivant.");
    }

    /** POST /ordres-mission/{odm}/generer-bons — US-08, RG-M12-13 à RG-M12-15 */
    public function genererBons(Request $request, OrdreMission $odm)
    {
        $donnees = $request->validate([
            'beneficiaire_groupe_id' => ['nullable', 'integer'],
            'bp' => ['nullable', 'array'],
            'bp.montant' => ['nullable'],
            'bp.motif' => ['nullable', 'string', 'max:180'],
            'bp.beneficiaire_id' => ['nullable', 'integer'],
        ]);
        $bp = !empty($donnees['bp']['montant']) || !empty($donnees['bp']['motif']) ? $donnees['bp'] : null;

        $bons = \App\Services\Odm\GenererBonsOdm::executer($odm, Auth::user(), [
            'beneficiaire_groupe_id' => $donnees['beneficiaire_groupe_id'] ?? null,
            'bp' => $bp,
        ]);

        return redirect()->route('odm.show', $odm)->with('success',
            $bons->count() . ' bon(s) de caisse généré(s) et soumis pour validation : ' . $bons->pluck('numero')->implode(', ') . '.');
    }

    /** POST /ordres-mission/{odm}/rejeter — RG-M12-12 : motif obligatoire */
    public function rejeter(Request $request, OrdreMission $odm)
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:1000']]);
        $odm = \App\Services\Odm\ValiderOdm::rejeter($odm, Auth::user(), $request->input('motif'));

        return redirect()->route('odm.show', $odm)->with('success', "Ordre de mission {$odm->numero} rejeté : le demandeur est notifié.");
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
