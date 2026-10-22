<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\PvArrete;
use App\Models\RapportCaisse;
use App\Services\Caisse\ArreteCaisse;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * PV d'arrêté de caisse (spec v2.4, RG-M10-02, RG-M10-03, Annexe 2) : liste, établissement, signatures, impression.
 */
class PvArreteController extends Controller
{
    public function index(Request $request)
    {
        $utilisateur = Auth::user();
        $caisses = ArreteCaisse::caissesVisibles($utilisateur);
        $pvs = PvArrete::with(['caisse', 'etabliPar'])
            ->whereIn('caisse_id', $caisses->pluck('id'))
            ->when($request->filled('caisse'), fn ($q) => $q->where('caisse_id', (int) $request->input('caisse')))
            ->latest('arrete_le')->paginate(20)->withQueryString()
            /* Actions de la ligne : signer depuis la liste quand l'utilisateur le peut */
            ->through(fn (PvArrete $pv) => self::ligne($pv) + ['signatures_possibles' => ArreteCaisse::signaturesPossibles($utilisateur, $pv)]);

        return Inertia::render('Caisses/PvArrete/Index', [
            'pvs' => $pvs,
            'caisses' => $caisses->map(fn (Caisse $c) => ['id' => $c->id, 'libelle' => $c->libelle])->values(),
            'filtres' => ['caisse' => $request->input('caisse')],
            'peutEtablir' => $caisses->contains(fn (Caisse $c) => ArreteCaisse::peutEtablir($utilisateur, $c)),
            /* RG-M10-01 : suivi des inventaires et des contrôles inopinés, pour les rôles transverses seulement */
            'suivi' => \App\Services\Caisse\AccesCaisse::estTransverse($utilisateur) ? \App\Services\Caisse\InventairesCaisse::suivi($caisses) : null,
        ]);
    }

    public function create(Request $request)
    {
        $utilisateur = Auth::user();
        $caisses = ArreteCaisse::caissesVisibles($utilisateur)->filter(fn (Caisse $c) => ArreteCaisse::peutEtablir($utilisateur, $c))->values();
        abort_if($caisses->isEmpty(), 403);

        return Inertia::render('Caisses/PvArrete/Create', [
            'caisses' => $caisses->map(fn (Caisse $c) => [
                'id' => $c->id, 'libelle' => $c->libelle, 'type' => $c->type, 'solde' => (float) $c->solde, 'sites' => $c->sites_libelle,
            ]),
            'caisseChoisie' => $request->integer('caisse') ?: ($utilisateur->caisse_id && $caisses->contains('id', $utilisateur->caisse_id) ? $utilisateur->caisse_id : $caisses->first()['id']),
            'coupures' => RapportCaisse::COUPURES,
            'motifs' => PvArrete::MOTIFS,
        ]);
    }

    public function store(Request $request)
    {
        $caisse = Caisse::findOrFail($request->integer('caisse_id'));
        $om = $caisse->type === 'orange_money';
        $donnees = $request->validate([
            'caisse_id' => ['required', 'integer'],
            'motif' => ['required', Rule::in(array_keys(PvArrete::MOTIFS))],
            'billetage' => ['nullable', 'array'],
            'billetage.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'solde_physique' => $om ? ['required', 'numeric', 'min:0'] : ['nullable'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);
        $pv = ArreteCaisse::etablir(Auth::user(), $caisse, $donnees['motif'], $donnees['billetage'] ?? [], $donnees['solde_physique'] ?? null, $donnees['observations'] ?? null);

        return redirect()->route('pv-arrete.show', $pv)->with('success', "PV {$pv->numero} établi : à signer par le gérant, la trésorerie et le Chef comptable.");
    }

    public function show(PvArrete $pvArrete)
    {
        $utilisateur = Auth::user();
        abort_unless(ArreteCaisse::estVisible($utilisateur, $pvArrete), 403);

        return Inertia::render('Caisses/PvArrete/Show', [
            'pv' => self::detail($pvArrete),
            'signaturesPossibles' => ArreteCaisse::signaturesPossibles($utilisateur, $pvArrete),
        ]);
    }

    public function signer(Request $request, PvArrete $pvArrete)
    {
        abort_unless(ArreteCaisse::estVisible(Auth::user(), $pvArrete), 403);
        $request->validate(['signature' => ['required', Rule::in(array_keys(PvArrete::SIGNATURES))]]);
        $pv = ArreteCaisse::signer(Auth::user(), $pvArrete, $request->input('signature'));

        return back()->with('success', $pv->estSigne()
            ? ($pv->ajustement_id ? "PV {$pv->numero} signé. L'écart de " . Format::montant(abs((float) $pv->ecart)) . ' attend la validation du DAF.' : "PV {$pv->numero} signé, sans écart.")
            : 'Signature enregistrée (' . $pv->nombreSignatures() . ' / 3).');
    }

    public function pdf(PvArrete $pvArrete)
    {
        abort_unless(ArreteCaisse::estVisible(Auth::user(), $pvArrete), 403);
        $pvArrete->loadMissing('caisse.sites', 'caisse.entite');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.pv-arrete-pdf', [
            'pv' => self::detail($pvArrete),
            'entite' => $pvArrete->caisse?->entite()->first(),
            'edite_le' => Format::dateHeure(now()),
        ])->setPaper('a4', 'portrait')->stream("{$pvArrete->numero}.pdf");
    }

    private static function ligne(PvArrete $pv): array
    {
        return [
            'id' => $pv->id,
            'numero' => $pv->numero,
            'caisse' => $pv->caisse?->libelle,
            'arrete_le' => Format::dateHeure($pv->arrete_le),
            'motif' => PvArrete::MOTIFS[$pv->motif] ?? $pv->motif,
            'solde_physique' => Format::montant($pv->solde_physique),
            'solde_comptable' => Format::montant($pv->solde_comptable),
            'ecart' => (float) $pv->ecart,
            'ecart_format' => Format::montant($pv->ecart),
            'signatures' => $pv->nombreSignatures(),
            'manquantes' => collect(PvArrete::SIGNATURES)->filter(fn (string $libelle, string $cle) => !$pv->{"{$cle}_le"})->values()->all(),
            'etabli_par' => $pv->etabliPar?->nom_complet,
        ];
    }

    private static function detail(PvArrete $pv): array
    {
        $pv->loadMissing(['caisse.sites', 'etabliPar', 'gerant', 'tresorerie', 'chefComptable', 'ajustement']);

        return self::ligne($pv) + [
            'type_caisse' => $pv->caisse?->type,
            'implantation' => $pv->caisse?->sites_libelle,
            'date' => Format::date($pv->arrete_le),
            'heure' => $pv->arrete_le->format('H:i'),
            /* Annexe 2 : coupures de 20 000 à 50 (le JSON en base ne garde pas l'ordre) */
            'billetage' => $pv->billetage === null ? [] : collect(RapportCaisse::COUPURES)
                ->map(fn (int $c) => ['coupure' => $c, 'nombre' => (int) ($pv->billetage[(string) $c] ?? 0), 'montant' => $c * (int) ($pv->billetage[(string) $c] ?? 0)])->all(),
            'solde_comptable' => Format::montant($pv->solde_comptable),
            'observations' => $pv->observations,
            'signes' => collect(PvArrete::SIGNATURES)->map(fn (string $libelle, string $cle) => [
                'cle' => $cle,
                'libelle' => $libelle,
                'par' => $pv->{$cle === 'chef_comptable' ? 'chefComptable' : $cle}?->nom_complet,
                'le' => $pv->{"{$cle}_le"} ? Format::dateHeure($pv->{"{$cle}_le"}) : null,
            ])->values()->all(),
            'ajustement' => $pv->ajustement ? [
                'reference' => $pv->ajustement->reference,
                'statut' => \App\Models\MouvementCaisse::STATUTS[$pv->ajustement->statut] ?? $pv->ajustement->statut,
            ] : null,
        ];
    }
}
