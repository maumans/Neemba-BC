<?php

namespace App\Http\Controllers;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\HistoriqueAction;
use App\Models\JustificationBp;
use App\Models\JustificationVisa;
use App\Models\PieceJointe;
use App\Models\User;
use App\Services\BonCaisse\ControleCaissier;
use App\Services\BonCaisse\JustifierBp;
use App\Services\BonCaisse\VisibiliteBon;
use App\Services\NotificationService;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Lot E (spec v2.3, M07) : écran de justification d'un BP conforme à l'Annexe 3 (RG-M07-03), visas, reliquat rétrocédé
 * en caisse (RG-M07-08) ; suivi RH des BP en retard (RG-M07-05).
 */
class JustificationBpController extends Controller
{
    /** GET /bons-caisse/{bon}/justification */
    public function show(BonCaisse $bonCaisse)
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        abort_unless($bonCaisse->type_bon === 'BP' && VisibiliteBon::peutVoir($bonCaisse, $utilisateur), 403);

        $j = $bonCaisse->justification;
        if (!$j && self::peutCommencer($bonCaisse, $utilisateur)) {
            $j = JustifierBp::ouvrir($bonCaisse, $utilisateur);
        }
        $j?->load(['lignes.piece', 'visas.valideur', 'visas.auTitreDe', 'initiateur', 'bonComplement', 'reliquatEncaissePar']);
        $bonCaisse->load(['demandeur', 'beneficiaireUtilisateur', 'visiteur']);
        $personne = $bonCaisse->beneficiaireUtilisateur ?? $bonCaisse->demandeur;
        $modifiable = $j && JustifierBp::peutModifier($j, $utilisateur);
        $droitVisa = $j ? JustifierBp::peutViser($j, $utilisateur) : null;

        return Inertia::render('BonsCaisse/Justification', [
            'bon' => [
                'id' => $bonCaisse->id,
                'numero' => $bonCaisse->numero,
                'statut' => $bonCaisse->statut,
                'statut_label' => $bonCaisse->statut_label,
                'motif' => $bonCaisse->motif,
                'montant' => (float) $bonCaisse->montant,
                'date_paiement' => $bonCaisse->date_paiement?->toIso8601String(),
                'date_limite_regularisation' => $bonCaisse->date_limite_regularisation?->toDateString(),
                /* En-tête de l'Annexe 3 : nom, prénom, matricule, département de la personne qui a reçu l'avance */
                'nom' => $personne?->name ?? $bonCaisse->beneficiaire,
                'prenom' => $personne?->prenom,
                'matricule' => $personne?->matricule,
                'departement' => $personne?->service ?? $bonCaisse->service,
                'visiteur' => $bonCaisse->visiteur?->nom_complet,
            ],
            'justification' => $j ? self::presenter($j, $modifiable) : null,
            'piecesDuBon' => $bonCaisse->piecesJointes()->latest()->get()->map(fn (PieceJointe $p) => [
                'id' => $p->id,
                'nom' => $p->nom_fichier,
                'type' => PieceJointe::TYPES_DOCUMENTS[$p->type_document] ?? $p->type_document,
                'url' => $p->url,
                'doublon' => (bool) $p->doublon_de_id,
            ])->values(),
            'codesAnalytiques' => CodeAnalytique::actifs()->orderBy('code')->get(['code', 'libelle']),
            'lignesMax' => JustificationBp::LIGNES_MAX,
            'droits' => [
                'modifier' => $modifiable,
                'viser' => $droitVisa !== null,
                'au_titre_de' => $droitVisa['au_titre_de']?->nom_complet ?? null,
                'encaisser' => $j && $j->reliquatAEncaisser() && $utilisateur->peutPayer(),
            ],
        ]);
    }

    /** PUT : lignes et montant à retourner (brouillon) */
    public function enregistrer(Request $request, BonCaisse $bonCaisse)
    {
        $donnees = $request->validate([
            'lignes' => ['present', 'array', 'max:' . JustificationBp::LIGNES_MAX],
            'lignes.*.intitule' => ['nullable', 'string', 'max:150'],
            'lignes.*.code_analytique' => ['nullable', 'string', 'max:50'],
            'lignes.*.compte' => ['nullable', 'string', 'max:20'],
            'lignes.*.montant' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lignes.*.piece_jointe_id' => ['nullable', 'integer'],
            'montant_a_retourner' => ['nullable', 'numeric', 'min:0'],
        ], ['lignes.max' => __('MSG-APP-068')]);
        $j = $this->justification($bonCaisse);
        JustifierBp::enregistrer($j, $donnees['lignes'] ?? [], isset($donnees['montant_a_retourner']) ? (float) $donnees['montant_a_retourner'] : null, Auth::user());

        return back()->with('success', 'Justification enregistrée.');
    }

    /** POST : pièce d'une ligne (ajoutée au BP) */
    public function ajouterPiece(Request $request, BonCaisse $bonCaisse)
    {
        $request->validate(['fichier' => ['required', 'file', 'mimes:' . implode(',', BonCaisse::FORMATS_FICHIERS_AUTORISES), 'max:' . (BonCaisse::TAILLE_MAX_FICHIER / 1024)]]);
        $piece = JustifierBp::ajouterPiece($this->justification($bonCaisse), $request->file('fichier'), Auth::user());

        return back()->with('success', 'Pièce ajoutée : ' . $piece->nom_fichier . '.')->with('pieceAjoutee', $piece->id);
    }

    public function soumettre(BonCaisse $bonCaisse)
    {
        JustifierBp::soumettre($this->justification($bonCaisse), Auth::user());

        return back()->with('success', 'Justification soumise aux visas.');
    }

    public function viser(Request $request, BonCaisse $bonCaisse)
    {
        $donnees = $request->validate([
            'decision' => ['required', Rule::in(['visee', 'rejetee'])],
            'commentaire' => ['nullable', 'string', 'max:1000'],
        ]);
        $j = JustifierBp::viser($this->justification($bonCaisse), Auth::user(), $donnees['decision'] === 'visee', $donnees['commentaire'] ?? null);

        return back()->with('success', match (true) {
            $j->statut === 'rejetee' => 'Justification rejetée : renvoyée à l\'initiateur.',
            $j->statut === 'validee' => 'Justification visée : le BP est régularisé.',
            default => 'Visa enregistré.',
        });
    }

    /** POST : le caissier encaisse le reliquat rendu (RG-M07-08) */
    public function encaisserReliquat(BonCaisse $bonCaisse)
    {
        JustifierBp::encaisserReliquat($this->justification($bonCaisse), Auth::user());

        return back()->with('success', 'Reliquat encaissé.');
    }

    /**
     * POST /bons-caisse/{bon}/suivi-rh — RG-M07-05 : les RH tracent l'email d'information de la DRH à l'intéressé, puis la
     * retenue sur salaire (jamais avant l'information).
     */
    public function suiviRh(Request $request, BonCaisse $bonCaisse)
    {
        $donnees = $request->validate([
            'action' => ['required', Rule::in(['information_drh', 'retenue'])],
            'commentaire' => ['nullable', 'string', 'max:1000'],
            'montant' => ['nullable', 'numeric', 'min:1'],
        ]);
        abort_unless($bonCaisse->type_bon === 'BP' && $bonCaisse->statut === 'EN_ATTENTE_REGULARISATION' && $bonCaisse->suivi_rh !== null, 409);
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        $suivi = $bonCaisse->suivi_rh;
        if ($donnees['action'] === 'retenue' && empty($suivi['information_drh'])) {
            throw new ErreurMetier('RETENUE_PREMATUREE', 'MSG-APP-075', [], 'RG-M07-05', 'action', 422);
        }
        if (!empty($suivi[$donnees['action']])) {
            return back()->with('error', 'Cette étape du suivi RH est déjà tracée.');
        }
        $montant = $donnees['action'] === 'retenue' ? (float) ($donnees['montant'] ?? $bonCaisse->montant) : null;
        $suivi[$donnees['action']] = array_filter([
            'le' => now()->toIso8601String(),
            'par_id' => $utilisateur->id,
            'par' => $utilisateur->nom_complet,
            'commentaire' => $donnees['commentaire'] ?? null,
            'montant' => $montant,
        ], fn ($v) => $v !== null);
        $bonCaisse->update(['suivi_rh' => $suivi]);

        $personne = JustifierBp::initiateurDe($bonCaisse) ?? $bonCaisse->demandeur;
        if ($donnees['action'] === 'information_drh') {
            $texte = "Email d'information de la DRH envoyé à {$personne?->nom_complet} (BP en retard de régularisation)";
            NotificationService::notifierSuiviBp($bonCaisse, $utilisateur, collect([$personne]), 'suivi_rh', 'BP en retard : information de la DRH',
                "Votre bon provisoire {$bonCaisse->numero} n'est pas régularisé dans les délais. Sans justification, une retenue sur salaire sera opérée.");
        } else {
            $texte = 'Retenue sur salaire de ' . Format::montant($montant) . " opérée pour {$personne?->nom_complet}";
            NotificationService::notifierSuiviBp($bonCaisse, $utilisateur,
                collect([$personne])->merge(User::actifs()->where(fn ($q) => $q->where('role', 'daf')->orWhereHas('roles', fn ($r) => $r->where('role', 'daf')))->get()),
                'suivi_rh', 'Retenue sur salaire', "Retenue sur salaire de " . Format::montant($montant) . " pour le BP {$bonCaisse->numero} non régularisé.");
        }
        HistoriqueAction::enregistrer($bonCaisse, HistoriqueAction::ACTION_SUIVI_RH, $bonCaisse->statut, $bonCaisse->statut, $utilisateur->id,
            $texte . (filled($donnees['commentaire'] ?? null) ? ' : ' . trim($donnees['commentaire']) : '') . '.');

        return back()->with('success', $donnees['action'] === 'information_drh' ? 'Information de la DRH tracée.' : 'Retenue sur salaire tracée.');
    }

    /** Encart de la fiche du bon : état de la justification, reliquat, complément */
    public static function resume(BonCaisse $bon, User $utilisateur): ?array
    {
        if ($bon->type_bon !== 'BP') {
            return null;
        }
        $j = $bon->justification;
        if (!$j && $bon->statut !== 'EN_ATTENTE_REGULARISATION') {
            return null;
        }
        $refusEncaissement = $j?->reliquatAEncaisser() && $utilisateur->peutPayer()
            ? ControleCaissier::refus($bon, $utilisateur, $bon->caissePayeuse('especes')) : 'non';

        return [
            'existe' => (bool) $j,
            'statut' => $j?->statut,
            'statut_label' => $j ? JustificationBp::STATUTS[$j->statut] : 'À établir',
            'etape_label' => $j?->etape ? JustificationBp::ETAPES[$j->etape] : null,
            'montant_justifie' => (float) ($j?->montant_justifie ?? 0),
            'montant_a_retourner' => (float) ($j?->montant_a_retourner ?? 0),
            'trop_justifie' => (float) ($j?->trop_justifie ?? 0),
            'peut_modifier' => $j ? JustifierBp::peutModifier($j, $utilisateur) : self::peutCommencer($bon, $utilisateur),
            'peut_viser' => $j && JustifierBp::peutViser($j, $utilisateur) !== null,
            'reliquat_a_encaisser' => (bool) $j?->reliquatAEncaisser(),
            'reliquat_encaisse_le' => $j?->reliquat_encaisse_le?->toIso8601String(),
            'peut_encaisser' => $refusEncaissement === null,
            'complement' => $j?->bonComplement ? ['id' => $j->bonComplement->id, 'numero' => $j->bonComplement->numero, 'statut_label' => $j->bonComplement->statut_label] : null,
            'suivi_rh' => $bon->suivi_rh,
            'peut_suivre_rh' => $bon->suivi_rh !== null && $bon->statut === 'EN_ATTENTE_REGULARISATION' && $utilisateur->aLeRole(['rh', 'administrateur']),
        ];
    }

    /** L'initiateur (directeur d'accueil pour un visiteur), le demandeur ou l'initiateur du bon ouvre la justification */
    private static function peutCommencer(BonCaisse $bon, User $utilisateur): bool
    {
        return $bon->statut === 'EN_ATTENTE_REGULARISATION'
            && in_array($utilisateur->id, array_filter([JustifierBp::initiateurDe($bon)?->id, $bon->demandeur_id, $bon->initiateur_id]), true);
    }

    private function justification(BonCaisse $bon): JustificationBp
    {
        abort_unless($bon->type_bon === 'BP' && VisibiliteBon::peutVoir($bon, Auth::user()), 403);
        if ($j = $bon->justification) {
            return $j;
        }
        abort_unless(self::peutCommencer($bon, Auth::user()), 403);

        return JustifierBp::ouvrir($bon, Auth::user());
    }

    private static function presenter(JustificationBp $j, bool $modifiable): array
    {
        $calculs = JustifierBp::calculs((float) $j->montant_avance, (float) $j->montant_justifie, (float) $j->montant_a_retourner);

        return [
            'id' => $j->id,
            'statut' => $j->statut,
            'statut_label' => JustificationBp::STATUTS[$j->statut],
            'etape' => $j->etape,
            'etape_label' => $j->etape ? JustificationBp::ETAPES[$j->etape] : null,
            'version' => $j->version,
            'initiateur' => $j->initiateur?->nom_complet,
            'montant_avance' => (float) $j->montant_avance,
            'calculs' => $calculs,
            'soumise_le' => $j->soumise_le?->toIso8601String(),
            'validee_le' => $j->validee_le?->toIso8601String(),
            'lignes' => $j->lignes->map(fn ($l) => [
                'intitule' => $l->intitule,
                'code_analytique' => $l->code_analytique,
                'compte' => $l->compte,
                'montant' => (float) $l->montant,
                'piece_jointe_id' => $l->piece_jointe_id,
                'piece' => $l->piece ? ['nom' => $l->piece->nom_fichier, 'url' => $l->piece->url] : null,
            ])->values(),
            'erreurs' => $modifiable ? JustifierBp::erreurs($j) : [],
            'visas' => $j->visas->map(fn (JustificationVisa $v) => [
                'version' => $v->version,
                'etape' => $v->etape === 'initiateur' ? 'Initiateur' : JustificationBp::ETAPES[$v->etape],
                'decision' => $v->decision,
                'valideur' => $v->valideur?->nom_complet,
                'au_titre_de' => $v->auTitreDe?->nom_complet,
                'commentaire' => $v->commentaire,
                'le' => $v->created_at?->toIso8601String(),
            ])->values(),
            'complement' => $j->bonComplement ? ['id' => $j->bonComplement->id, 'numero' => $j->bonComplement->numero, 'statut_label' => $j->bonComplement->statut_label] : null,
            'reliquat_encaisse_le' => $j->reliquat_encaisse_le?->toIso8601String(),
            'reliquat_encaisse_par' => $j->reliquatEncaissePar?->nom_complet,
        ];
    }
}
