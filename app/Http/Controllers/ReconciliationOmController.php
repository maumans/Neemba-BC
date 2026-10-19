<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\EcritureCaisse;
use App\Models\ReconciliationOm;
use App\Services\Caisse\ReconciliationOrangeMoney;
use App\Support\Format;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Réconciliation hebdomadaire de la caisse Orange Money (spec v2.4, RG-M08-13, §5.4) : liste, import du relevé,
 * rapprochement, trois signatures.
 */
class ReconciliationOmController extends Controller
{
    public function index()
    {
        $utilisateur = Auth::user();
        $semaine = ReconciliationOrangeMoney::debutSemaine(now()->subWeek());

        return Inertia::render('Caisses/ReconciliationOm/Index', [
            'reconciliations' => ReconciliationOm::with('caisse')->latest('periode_debut')->latest('id')->paginate(20)
                ->through(fn (ReconciliationOm $r) => self::ligne($r)),
            'caisses' => ReconciliationOrangeMoney::caissesOm()->map(fn (Caisse $c) => ['id' => $c->id, 'libelle' => $c->libelle])->values(),
            'semaineProposee' => $semaine->toDateString(),
            'peutEtablir' => $utilisateur->aLeRole('tresorerie'),
        ]);
    }

    public function store(Request $request)
    {
        $donnees = $request->validate([
            'caisse_id' => ['required', 'integer', Rule::exists('caisses', 'id')],
            'semaine' => ['required', 'date'],
            'releve' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'solde_releve' => ['nullable', 'numeric'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ], ['releve.required' => 'Joignez le relevé de la plateforme Orange Money (CSV ou Excel).']);
        $r = ReconciliationOrangeMoney::etablir(Auth::user(), Caisse::findOrFail($donnees['caisse_id']), Carbon::parse($donnees['semaine']), $request->file('releve'),
            isset($donnees['solde_releve']) ? (float) $donnees['solde_releve'] : null, $donnees['observations'] ?? null);

        return redirect()->route('reconciliations-om.show', $r)->with('success', "Réconciliation {$r->numero} établie : à valider par la trésorière, le Chef comptable et le DAF.");
    }

    public function show(ReconciliationOm $reconciliation)
    {
        $reconciliation->loadMissing('caisse', 'etabliPar');
        $ecritures = EcritureCaisse::whereIn('id', collect($reconciliation->lignes_releve)->pluck('ecriture_id')->filter())->get()->keyBy('id');

        return Inertia::render('Caisses/ReconciliationOm/Show', [
            'reconciliation' => self::ligne($reconciliation) + [
                'etabli_par' => $reconciliation->etabliPar?->nom_complet,
                'observations' => $reconciliation->observations,
                'solde_brouillard_debut' => Format::montant($reconciliation->solde_brouillard_debut),
                'solde_brouillard_fin' => Format::montant($reconciliation->solde_brouillard_fin),
                'solde_releve_fin' => $reconciliation->solde_releve_fin !== null ? Format::montant($reconciliation->solde_releve_fin) : null,
                'lignes_rapprochees' => $reconciliation->lignes_rapprochees,
                'releve_non_rapproche' => collect($reconciliation->lignes_releve)->whereNull('ecriture_id')->map(fn (array $l) => self::ligneReleve($l))->values(),
                'releve_rapproche' => collect($reconciliation->lignes_releve)->whereNotNull('ecriture_id')->map(fn (array $l) => self::ligneReleve($l) + [
                    'ecriture' => $ecritures->get($l['ecriture_id'])?->libelle ?: (EcritureCaisse::NATURES[$ecritures->get($l['ecriture_id'])?->nature ?? ''] ?? null),
                ])->values(),
                'brouillard_non_rapproche' => collect($reconciliation->ecritures_non_rapprochees)->map(fn (array $e) => [
                    'date' => Format::dateHeure($e['date']), 'libelle' => $e['libelle'], 'sens' => $e['sens'], 'montant' => Format::montant($e['montant']),
                ])->values(),
                'signes' => collect(ReconciliationOm::SIGNATURES)->map(fn (string $libelle, string $cle) => [
                    'cle' => $cle, 'libelle' => $libelle, 'par' => $reconciliation->signataire($cle)?->nom_complet,
                    'le' => $reconciliation->{"{$cle}_le"} ? Format::dateHeure($reconciliation->{"{$cle}_le"}) : null,
                ])->values(),
            ],
            'signaturesPossibles' => ReconciliationOrangeMoney::signaturesPossibles(Auth::user(), $reconciliation),
        ]);
    }

    public function signer(Request $request, ReconciliationOm $reconciliation)
    {
        $request->validate(['signature' => ['required', Rule::in(array_keys(ReconciliationOm::SIGNATURES))]]);
        $r = ReconciliationOrangeMoney::signer(Auth::user(), $reconciliation, $request->input('signature'));

        return back()->with('success', $r->estValidee() ? "Réconciliation {$r->numero} validée." : 'Signature enregistrée (' . $r->nombreSignatures() . ' / 3).');
    }

    public function releve(ReconciliationOm $reconciliation)
    {
        abort_unless(Storage::disk('public')->exists($reconciliation->releve), 404);

        return Storage::disk('public')->download($reconciliation->releve);
    }

    private static function ligne(ReconciliationOm $r): array
    {
        return [
            'id' => $r->id,
            'numero' => $r->numero,
            'caisse' => $r->caisse?->libelle,
            'periode' => 'Du ' . Format::date($r->periode_debut) . ' au ' . Format::date($r->periode_fin),
            'statut' => $r->statut,
            'statut_libelle' => ReconciliationOm::STATUTS[$r->statut] ?? $r->statut,
            'ecart' => $r->ecart !== null ? (float) $r->ecart : null,
            'ecart_format' => $r->ecart !== null ? Format::montant($r->ecart) : '—',
            'non_rapprochees' => collect($r->lignes_releve)->whereNull('ecriture_id')->count() + count($r->ecritures_non_rapprochees ?? []),
            'signatures' => $r->nombreSignatures(),
        ];
    }

    private static function ligneReleve(array $l): array
    {
        return [
            'ligne' => $l['ligne'], 'date' => Format::dateHeure($l['date']), 'reference' => $l['reference'], 'libelle' => $l['libelle'],
            'sens' => $l['sens'], 'montant' => Format::montant($l['montant']),
        ];
    }
}
