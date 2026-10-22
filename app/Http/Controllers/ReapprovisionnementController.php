<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\DemandeReapprovisionnement as Demande;
use App\Models\Parametre;
use App\Services\Caisse\AccesCaisse;
use App\Services\Caisse\Reapprovisionnement;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Demandes de réapprovisionnement des caisses (spec v2.4, RG-M08-04 à RG-M08-09) : liste, demande, circuit, exécution,
 * réception.
 */
class ReapprovisionnementController extends Controller
{
    public function index(Request $request)
    {
        $utilisateur = Auth::user();
        $caisses = self::caisses($utilisateur);
        $demandes = Demande::with('caisse')
            ->whereIn('caisse_id', $caisses->pluck('id'))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')))
            ->latest()->paginate(20)->withQueryString()
            ->through(fn (Demande $d) => self::ligne($d) + ['a_traiter' => Reapprovisionnement::actions($utilisateur, $d) !== []]);

        return Inertia::render('Caisses/Reapprovisionnements/Index', [
            'demandes' => $demandes,
            'statuts' => Demande::STATUTS,
            'filtres' => ['statut' => $request->input('statut')],
            'peutDemander' => $caisses->contains(fn (Caisse $c) => AccesCaisse::estGerant($utilisateur, $c)),
        ]);
    }

    public function create(Request $request)
    {
        $utilisateur = Auth::user();
        $caisses = AccesCaisse::caissesVisibles($utilisateur)->filter(fn (Caisse $c) => $c->actif && AccesCaisse::estGerant($utilisateur, $c))->values();
        abort_if($caisses->isEmpty(), 403);

        return Inertia::render('Caisses/Reapprovisionnements/Create', [
            'caisses' => $caisses->map(function (Caisse $c) {
                $pv = Reapprovisionnement::pvDuJour($c);

                return [
                    'id' => $c->id,
                    'libelle' => $c->libelle,
                    'mode' => $c->mode,
                    'solde' => (float) $c->solde,
                    'plafond' => $c->mode === 'avance_fixe' ? (float) $c->montant_avance : ($c->plafond_caisse !== null ? (float) $c->plafond_caisse : null),
                    'propose' => Reapprovisionnement::montantPropose($c),
                    'pv' => $pv ? ['id' => $pv->id, 'numero' => $pv->numero, 'arrete_le' => Format::dateHeure($pv->arrete_le),
                        'ecart' => (float) $pv->ecart, 'ecart_format' => Format::montant($pv->ecart)] : null,
                ];
            }),
            'caisseChoisie' => $request->integer('caisse') ?: ($caisses->firstWhere('id', $utilisateur->caisse_id)?->id ?? $caisses->first()->id),
            'habilitation' => (float) Parametre::valeur('habilitation_dp_reappro', 500000000),
        ]);
    }

    public function store(Request $request)
    {
        $donnees = $request->validate([
            'caisse_id' => ['required', 'integer', Rule::exists('caisses', 'id')],
            'montant' => ['required', 'numeric', 'min:1', 'max:100000000000'],
            'motif_baisse' => ['nullable', 'string', 'max:500'],
            'rapprochement' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $demande = Reapprovisionnement::creer(Auth::user(), Caisse::findOrFail($donnees['caisse_id']), (float) $donnees['montant'],
            $donnees['motif_baisse'] ?? null, $request->file('rapprochement'));

        return redirect()->route('reapprovisionnements.show', $demande)
            ->with('success', "Demande {$demande->numero} envoyée à la trésorerie : " . Format::montant($demande->montant_demande) . '.');
    }

    public function show(Demande $demande)
    {
        $utilisateur = Auth::user();
        abort_unless(self::caisses($utilisateur)->contains('id', $demande->caisse_id), 403);
        $actions = Reapprovisionnement::actions($utilisateur, $demande);

        return Inertia::render('Caisses/Reapprovisionnements/Show', [
            'demande' => self::detail($demande),
            'actions' => $actions,
            'origines' => collect(Demande::ORIGINES)->only(Reapprovisionnement::origines($demande->caisse))->all(),
            'caissesSources' => in_array('executer', $actions, true) && $demande->caisse->reappro_par_transfert
                ? Caisse::actives()->where('id', '!=', $demande->caisse_id)->orderBy('libelle')->get()
                    ->map(fn (Caisse $c) => ['id' => $c->id, 'libelle' => $c->libelle, 'solde' => (float) $c->solde])->values()
                : [],
        ]);
    }

    public function agir(Request $request, Demande $demande)
    {
        abort_unless(self::caisses(Auth::user())->contains('id', $demande->caisse_id), 403);
        $donnees = $request->validate([
            'action' => ['required', Rule::in(['valider_tresorerie', 'viser_finance', 'approuver_dp', 'approuver_codir', 'refuser', 'executer', 'confirmer_reception'])],
            'montant_valide' => ['nullable', 'numeric', 'min:1'],
            'motif_reduction' => ['nullable', 'string', 'max:500'],
            'motif' => ['nullable', 'string', 'max:500'],
            'origine_fonds' => ['nullable', 'string'],
            'caisse_source_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:100'],
            'montant_emis' => ['nullable', 'numeric', 'min:1'],
            'montant_recu' => ['nullable', 'numeric', 'min:0'],
            'piece' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        $donnees['piece'] = $request->file('piece');
        $d = Reapprovisionnement::agir(Auth::user(), $demande, $donnees['action'], $donnees);

        return back()->with('success', match ($donnees['action']) {
            'refuser' => "Demande {$d->numero} refusée.",
            'approuver_codir' => $d->statut === 'approuvee' ? "Demande {$d->numero} approuvée par deux membres du CODIR." : 'Votre approbation est enregistrée ; un second membre du CODIR doit approuver.',
            'executer' => "Demande {$d->numero} exécutée : la caisse confirme la réception.",
            'confirmer_reception' => 'Réception confirmée : ' . Format::montant($d->montant_recu) . " crédités sur la {$d->caisse->libelle}.",
            default => "Demande {$d->numero} : " . Demande::STATUTS[$d->statut] . '.',
        });
    }

    /** Pièces jointes : rapprochement, exécution, réception */
    public function piece(Demande $demande, string $piece)
    {
        abort_unless(self::caisses(Auth::user())->contains('id', $demande->caisse_id), 403);
        $colonne = ['rapprochement' => 'rapprochement', 'execution' => 'piece_execution', 'reception' => 'piece_reception'][$piece] ?? abort(404);
        abort_unless($demande->{$colonne} && Storage::disk('public')->exists($demande->{$colonne}), 404);

        return Storage::disk('public')->response($demande->{$colonne});
    }

    /** Caisses visibles (RG-M08-12) ; les membres du CODIR voient les demandes de toutes les caisses (Q89) */
    private static function caisses(\App\Models\User $utilisateur): \Illuminate\Support\Collection
    {
        return $utilisateur->aLeRole('membre_codir') ? Caisse::orderBy('libelle')->get() : AccesCaisse::caissesVisibles($utilisateur);
    }

    private static function ligne(Demande $d): array
    {
        return [
            'id' => $d->id,
            'numero' => $d->numero,
            'caisse' => $d->caisse?->libelle,
            'date' => Format::dateHeure($d->created_at),
            'montant' => Format::montant($d->montantRetenu()),
            'statut' => $d->statut,
            'statut_libelle' => Demande::STATUTS[$d->statut] ?? $d->statut,
        ];
    }

    private static function detail(Demande $d): array
    {
        $d->loadMissing(['caisse', 'caisseSource', 'pvArrete']);
        $nom = fn (string $colonne) => $d->utilisateur($colonne)?->nom_complet;
        $quand = fn (string $colonne) => $d->{$colonne} ? Format::dateHeure($d->{$colonne}) : null;
        $etapes = [
            ['libelle' => 'Demande (gérant de la caisse)', 'par' => $nom('demande_par'), 'le' => Format::dateHeure($d->created_at)],
            ['libelle' => 'Validation trésorerie', 'par' => $nom('tresorerie_par'), 'le' => $quand('tresorerie_le')],
            ['libelle' => 'Visa Finance (Chef comptable / DAF)', 'par' => $nom('finance_par'), 'le' => $quand('finance_le')],
        ];
        if ($d->codir1_par) {
            $etapes[] = ['libelle' => 'Approbation CODIR (1er membre)', 'par' => $nom('codir1_par'), 'le' => $quand('codir1_le')];
            $etapes[] = ['libelle' => 'Approbation CODIR (2e membre)', 'par' => $nom('codir2_par'), 'le' => $quand('codir2_le')];
        } else {
            $etapes[] = ['libelle' => 'Approbation Directeur Pays', 'par' => $nom('dp_par'), 'le' => $quand('dp_le')];
        }
        $etapes[] = ['libelle' => 'Exécution trésorerie', 'par' => $nom('execute_par'), 'le' => $quand('execute_le')];
        $etapes[] = ['libelle' => 'Réception par la caisse', 'par' => $nom('recu_par'), 'le' => $quand('recu_le')];

        return self::ligne($d) + [
            'caisse_type' => $d->caisse?->type,
            'solde_a_la_demande' => Format::montant($d->solde_a_la_demande),
            'solde_actuel' => Format::montant($d->caisse?->solde),
            'montant_propose' => Format::montant($d->montant_propose),
            'montant_demande' => Format::montant($d->montant_demande),
            'montant_demande_brut' => (float) $d->montant_demande,
            'motif_baisse' => $d->motif_baisse,
            'montant_valide' => $d->montant_valide !== null ? Format::montant($d->montant_valide) : null,
            'motif_reduction' => $d->motif_reduction,
            'montant_retenu_brut' => $d->montantRetenu(),
            'pv' => $d->pvArrete ? ['id' => $d->pvArrete->id, 'numero' => $d->pvArrete->numero, 'arrete_le' => Format::dateHeure($d->pvArrete->arrete_le),
                'ecart' => (float) $d->pvArrete->ecart, 'ecart_format' => Format::montant($d->pvArrete->ecart)] : null,
            'pieces' => array_values(array_filter([
                $d->rapprochement ? ['cle' => 'rapprochement', 'libelle' => 'Rapprochement comptable signé'] : null,
                $d->piece_execution ? ['cle' => 'execution', 'libelle' => "Pièce d'exécution"] : null,
                $d->piece_reception ? ['cle' => 'reception', 'libelle' => 'Pièce de réception'] : null,
            ])),
            'etapes' => $etapes,
            'refus' => $d->statut === 'refusee' ? ['par' => $nom('refuse_par'), 'le' => $quand('refuse_le'), 'motif' => $d->motif_refus] : null,
            'execution' => $d->execute_par ? [
                'origine' => Demande::ORIGINES[$d->origine_fonds] ?? $d->origine_fonds,
                'caisse_source' => $d->caisseSource?->libelle,
                'reference' => $d->reference_execution,
                'montant_emis' => Format::montant($d->montant_emis),
                'montant_emis_brut' => (float) $d->montant_emis,
            ] : null,
            'reception' => $d->recu_par ? [
                'montant_recu' => Format::montant($d->montant_recu),
                'ecart' => (float) $d->montant_recu - (float) $d->montant_emis,
                'ecart_format' => Format::montant((float) $d->montant_recu - (float) $d->montant_emis),
            ] : null,
            'codir_attendu' => $d->statut === 'visee_finance' && Reapprovisionnement::codirPossible($d),
        ];
    }
}
