<?php

namespace App\Http\Controllers;

use App\Models\Tiers;
use App\Models\User;
use App\Services\Referentiels\ReferentielTiers;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Référentiel des tiers (spec v2.3, §6.5, RG-M02-06, RG-M02-07) : la Comptabilité, le CDG et la direction financière
 * valident ou refusent (avec un motif) les tiers proposés par les demandeurs, en créent directement, les modifient
 * et les désactivent. L'administrateur consulte.
 */
class TiersController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        $recherche = trim((string) $request->query('q', ''));
        $statut = array_key_exists($request->query('statut'), Tiers::STATUTS) ? $request->query('statut') : 'propose';

        $tiers = Tiers::query()
            ->with(['proposePar', 'validePar'])
            ->withCount('bons')
            ->where('statut', $statut)
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('nom', 'like', "%{$recherche}%")
                ->orWhere('telephone', 'like', "%{$recherche}%")->orWhere('numero_om', 'like', "%{$recherche}%")->orWhere('nif', 'like', "%{$recherche}%")))
            ->orderBy('nom')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Tiers $t) => self::ligne($t));

        return Inertia::render('Referentiels/Tiers', [
            'tiers' => $tiers,
            'filtres' => ['q' => $recherche, 'statut' => $statut],
            'compteurs' => collect(Tiers::STATUTS)->mapWithKeys(fn ($l, $s) => [$s => Tiers::where('statut', $s)->count()]),
            'types' => Tiers::TYPES,
            'statuts' => Tiers::STATUTS,
            'peutValider' => $utilisateur->aLeRole(Tiers::ROLES_VALIDATION),
        ]);
    }

    public function store(Request $request)
    {
        $this->autoriser();
        $tiers = ReferentielTiers::proposer($this->valider($request), Auth::user());

        return back()->with('success', "Tiers « {$tiers->nom} » ajouté et validé.");
    }

    public function update(Request $request, Tiers $tiers)
    {
        $this->autoriser();
        ReferentielTiers::modifier($tiers, $this->valider($request));

        return back()->with('success', "Tiers « {$tiers->nom} » modifié.");
    }

    /** Validation ou refus motivé (RG-M02-06) */
    public function decider(Request $request, Tiers $tiers)
    {
        $this->autoriser();
        $donnees = $request->validate([
            'decision' => ['required', Rule::in(['valider', 'refuser'])],
            'motif' => ['required_if:decision,refuser', 'nullable', 'string', 'min:10', 'max:500'],
        ], ['motif.required_if' => 'Indiquez le motif du refus.', 'motif.min' => __('MSG-BC-002')]);
        ReferentielTiers::decider($tiers, Auth::user(), $donnees['decision'] === 'valider', $donnees['motif'] ?? null);

        return back()->with('success', $donnees['decision'] === 'valider' ? "Tiers « {$tiers->nom} » validé." : "Tiers « {$tiers->nom} » refusé.");
    }

    /** Un tiers ne se supprime pas : il se désactive (il n'est plus proposé), et se réactive en validé */
    public function basculer(Tiers $tiers)
    {
        $this->autoriser();
        if ($tiers->statut === 'desactive') {
            ReferentielTiers::decider($tiers, Auth::user(), true);

            return back()->with('success', "Tiers « {$tiers->nom} » réactivé.");
        }
        $tiers->update(['statut' => 'desactive']);

        return back()->with('success', "Tiers « {$tiers->nom} » désactivé : il n'est plus proposé sur les bons.");
    }

    public static function ligne(Tiers $t): array
    {
        return [
            'id' => $t->id,
            'nom' => $t->nom,
            'type' => $t->type,
            'type_label' => Tiers::TYPES[$t->type] ?? $t->type,
            'telephone' => $t->telephone,
            'numero_om' => $t->numero_om,
            'nif' => $t->nif,
            'statut' => $t->statut,
            'statut_label' => Tiers::STATUTS[$t->statut] ?? $t->statut,
            'motif_refus' => $t->motif_refus,
            'origine' => $t->origine,
            'propose_par' => $t->proposePar?->nom_complet,
            'propose_le' => $t->propose_le ? Format::date($t->propose_le) : null,
            'valide_par' => $t->validePar?->nom_complet,
            'valide_le' => $t->valide_le ? Format::date($t->valide_le) : null,
            'bons' => $t->bons_count ?? null,
        ];
    }

    private function autoriser(): void
    {
        /** @var User $utilisateur */
        $utilisateur = Auth::user();
        abort_unless($utilisateur->aLeRole(Tiers::ROLES_VALIDATION), 403, 'Seules la Comptabilité, le CDG et la direction financière gèrent les tiers.');
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'min:3', 'max:150'],
            'type' => ['required', Rule::in(array_keys(Tiers::TYPES))],
            'telephone' => ['nullable', 'regex:/^(\+224)?\s?6[\d\s]{8,11}$/'],
            'numero_om' => ['nullable', 'regex:/^(\+224)?\s?6[\d\s]{8,11}$/'],
            'nif' => ['nullable', 'string', 'max:40'],
        ], [
            'nom.required' => __('MSG-BC-001'),
            'nom.min' => __('MSG-BC-004'),
            'telephone.regex' => __('MSG-BC-006'),
            'numero_om.regex' => __('MSG-BC-006'),
        ]);
    }
}
