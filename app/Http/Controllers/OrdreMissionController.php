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
            'peutVoirTableauDeBord' => $utilisateur->aLeRole(\App\Services\Odm\TableauBordOdm::ROLES),
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
        /* Le caissier qui encaisse un reversement (RG-M12-20) ouvre aussi la fiche */
        $reversement = $utilisateur->peutPayer() && $odm->participants()->where('regularisation', 'reversement')->where('regularisation_statut', 'a_regulariser')->exists();
        abort_unless($visa || $reversement || $odm->estVisiblePar($utilisateur), 403);

        return Inertia::render('Odm/Show', [
            'odm' => PresentationOdm::detail($odm),
            'etapes' => PresentationOdm::etapes($odm),
            'bons' => PresentationOdm::bons($odm),
            'generation' => PresentationOdm::generation($odm, $utilisateur),
            /* RG-M12-17, RG-M12-19 : prolongation et vue de la mission */
            'peutProlonger' => \App\Services\Odm\ProlongerOdm::peutProlonger($odm, $utilisateur),
            'prolongation' => ($suite = \App\Services\Odm\ProlongerOdm::prolongationEnCours($odm)) ? ['id' => $suite->id, 'libelle' => $suite->libelle, 'statut' => $suite->statut] : null,
            'mission' => \App\Services\Odm\VueMission::pour($odm),
            /* RG-M12-15, variante B : ODM à la charge du client, sans bon */
            'sansBon' => \App\Services\Odm\GenererBonsOdm::sansBon($odm),
            /* RG-M12-11 : visa possible à l'étape en cours, « au titre de » pour un suppléant */
            'visa' => $visa ? [
                'niveau' => $visa['etape']->libelle,
                'au_titre_de' => $visa['au_titre_de']?->nom_complet,
                'dernier' => $odm->etapes()->where('version', $odm->version)->where('statut', 'a_venir')->doesntExist(),
            ] : null,
            'peutModifier' => $odm->estModifiablePar($utilisateur),
            /* RG-M12-20, RG-M12-22 : clôture, régularisation des trop-perçus, annulation par le DAF */
            'peutCloturer' => \App\Services\Odm\CloturerOdm::peutCloturer($odm, $utilisateur),
            'peutAnnulerDaf' => \App\Services\Odm\AnnulerOdm::peutAnnulerParLeDaf($odm, $utilisateur)
                && ($odm->bons()->exists() || !in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)),
            'regularisations' => PresentationOdm::regularisations($odm, $utilisateur),
            'regularisationsPossibles' => \App\Models\ParticipantOdm::REGULARISATIONS,
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

    /** POST /ordres-mission/{odm}/prolonger — RG-M12-17 : nouveau segment en brouillon, à compléter puis soumettre */
    public function prolonger(Request $request, OrdreMission $odm)
    {
        $request->validate(['date_retour_prevue' => ['required', 'date_format:Y-m-d']], [
            'date_retour_prevue.required' => \App\Exceptions\ErreurMetier::texte('MSG-BC-001'),
        ]);
        [$segment, $message] = \App\Services\Odm\ProlongerOdm::executer($odm, Auth::user(), $request->input('date_retour_prevue'));

        return redirect()->route('odm.edit', $segment)->with('success', $message);
    }

    /** POST /ordres-mission/{odm}/cloturer — RG-M12-20 : retour réel, trop-perçu, bons régénérés au réel */
    public function cloturer(Request $request, OrdreMission $odm)
    {
        $donnees = $request->validate([
            'date_retour_reelle' => ['required', 'date_format:Y-m-d'],
            'regularisations' => ['nullable', 'array'],
            'regularisations.*' => ['in:reversement,retenue'],
            'factures_retour' => ['nullable', 'array'],
        ], ['date_retour_reelle.required' => \App\Exceptions\ErreurMetier::texte('MSG-BC-001')]);

        [$odm, $messages] = \App\Services\Odm\CloturerOdm::executer($odm, Auth::user(), $donnees['date_retour_reelle'],
            $donnees['regularisations'] ?? [], $donnees['factures_retour'] ?? []);

        return redirect()->route('odm.show', $odm)->with('success', trim("Mission {$odm->numero} clôturée. " . implode(' ', $messages)));
    }

    /** POST /ordres-mission/{odm}/annuler-daf — RG-M12-22 : annulation par le DAF, bons non payés annulés */
    public function annulerDaf(Request $request, OrdreMission $odm)
    {
        $request->validate(['motif' => ['nullable', 'string', 'max:1000']]);
        $odm = \App\Services\Odm\AnnulerOdm::parLeDaf($odm, Auth::user(), $request->input('motif'));

        return redirect()->route('odm.show', $odm)->with('success', "Ordre de mission {$odm->numero} annulé.");
    }

    /** POST /ordres-mission/{odm}/participants/{participant}/regulariser — reversement (caissier) ou retenue (RH) */
    public function regulariser(OrdreMission $odm, \App\Models\ParticipantOdm $participant)
    {
        abort_unless($participant->ordre_mission_id === $odm->id, 404);
        \App\Services\Odm\RegulariserTropPercu::executer($participant, Auth::user());

        return redirect()->route('odm.show', $odm)->with('success', "Trop-perçu de {$participant->nom} régularisé.");
    }

    /** GET /ordres-mission/{odm}/pdf — RG-M12-23 : ordre de mission (autorisation de circuler) et fiche d'indemnités, visas horodatés */
    public function pdf(OrdreMission $odm)
    {
        abort_unless($odm->estVisiblePar(Auth::user()), 403);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.odm-pdf', [
            'odm' => PresentationOdm::detail($odm) + ['entite' => $odm->entite],
            'etapes' => PresentationOdm::etapes($odm),
            'edite_le' => \App\Support\Format::dateHeure(now()),
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('ordre-de-mission-' . str_replace(['°', '/'], ['', '-'], $odm->numero ?? "brouillon-{$odm->id}") . '.pdf');
    }

    /** GET /ordres-mission/tableau-de-bord — US-14 : missions en cours, dérogations, ODM à refacturer */
    public function tableauDeBord()
    {
        abort_unless(Auth::user()->aLeRole(\App\Services\Odm\TableauBordOdm::ROLES), 403);

        return Inertia::render('Odm/TableauDeBord', \App\Services\Odm\TableauBordOdm::donnees());
    }

    /** GET /ordres-mission/tableau-de-bord/export — export Excel du tableau de bord */
    public function exportTableauDeBord()
    {
        abort_unless(Auth::user()->aLeRole(\App\Services\Odm\TableauBordOdm::ROLES), 403);

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\TableauBordOdmExport(\App\Services\Odm\TableauBordOdm::donnees()),
            'ordres-de-mission-' . now()->format('Y-m-d') . '.xlsx',
        );
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
