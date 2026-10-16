<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\User;
use App\Models\Visiteur;
use App\Services\BonCaisse\ReglesSaisie;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Référentiel des visiteurs du groupe (spec v2.4, §6.5) : salariés d'autres filiales en mission en Guinée. Ils ne
 * reçoivent qu'un BD, à titre exceptionnel, refacturé à leur filiale (RG-M03-04, RG-M03-28).
 * Tenu par les RH, la direction financière et l'administrateur. Un visiteur se désactive, il ne se supprime pas.
 */
class VisiteurController extends Controller
{
    public function index(Request $request)
    {
        $recherche = trim((string) $request->query('q', ''));
        $statut = $request->query('statut', 'actifs');

        $visiteurs = Visiteur::query()
            ->with('directeur')
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q2) => $q2->where('nom', 'like', "%{$recherche}%")
                ->orWhere('prenom', 'like', "%{$recherche}%")->orWhere('filiale', 'like', "%{$recherche}%")
                ->orWhere('matricule_groupe', 'like', "%{$recherche}%")))
            ->when($statut === 'actifs', fn ($q) => $q->where('actif', true))
            ->when($statut === 'inactifs', fn ($q) => $q->where('actif', false))
            ->orderByDesc('date_fin')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Visiteur $v) => self::ligne($v));

        return Inertia::render('Referentiels/Visiteurs', [
            'visiteurs' => $visiteurs,
            'filtres' => ['q' => $recherche, 'statut' => $statut],
            'services' => Service::where('actif', true)->orderBy('nom')->pluck('nom'),
            'directeurs' => User::actifs()->orderBy('name')->get()->map(fn (User $u) => ['id' => $u->id, 'nom' => $u->nom_complet, 'service' => $u->service]),
        ]);
    }

    public function store(Request $request)
    {
        $visiteur = Visiteur::create($this->valider($request) + ['actif' => true, 'cree_par_id' => Auth::id()]);

        return back()->with('success', "Visiteur {$visiteur->nom_complet} ajouté.");
    }

    public function update(Request $request, Visiteur $visiteur)
    {
        $visiteur->update($this->valider($request));

        return back()->with('success', "Visiteur {$visiteur->nom_complet} modifié.");
    }

    public function basculer(Visiteur $visiteur)
    {
        $visiteur->update(['actif' => !$visiteur->actif]);

        return back()->with('success', $visiteur->actif ? "Visiteur {$visiteur->nom_complet} réactivé." : "Visiteur {$visiteur->nom_complet} désactivé : il n'est plus proposé sur les bons.");
    }

    public static function ligne(Visiteur $v): array
    {
        return [
            'id' => $v->id,
            'nom' => $v->nom,
            'prenom' => $v->prenom,
            'nom_complet' => $v->nom_complet,
            'filiale' => $v->filiale,
            'matricule_groupe' => $v->matricule_groupe,
            'numero_om' => $v->numero_om,
            'departement_accueil' => $v->departement_accueil,
            'directeur_id' => $v->directeur_id,
            'directeur' => $v->directeur?->nom_complet,
            'date_debut' => $v->date_debut->toDateString(),
            'date_fin' => $v->date_fin->toDateString(),
            'sejour' => Format::date($v->date_debut) . ' → ' . Format::date($v->date_fin),
            'en_sejour' => $v->enSejour(),
            'actif' => $v->actif,
        ];
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'filiale' => ['required', 'string', 'max:120'],
            'matricule_groupe' => ['nullable', 'string', 'max:40'],
            'numero_om' => ['nullable', 'regex:/^(\+224)?\s?6[\d\s]{8,11}$/'],
            'departement_accueil' => ['required', Rule::exists('services', 'nom')],
            'directeur_id' => ['required', Rule::exists('users', 'id')->where('actif', true)],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
        ], [
            'required' => __('MSG-BC-001'),
            'numero_om.regex' => __('MSG-BC-006'),
            'date_fin.after_or_equal' => 'La fin du séjour précède son début.',
        ]);
        $donnees['numero_om'] = ReglesSaisie::normaliserTelephone($donnees['numero_om'] ?? null);

        return $donnees;
    }
}
