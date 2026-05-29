<?php

namespace App\Http\Controllers;

use App\Models\MotifUrgence;
use App\Models\CodeAnalytique;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\TypeDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Contrôleur de Paramétrage
 * 
 * Gère les tables de référence administrables :
 * Sites, Services, Codes analytiques, Types de document.
 * Accessible uniquement par DAF et Directeur Pays.
 */
class ParametrageController extends Controller
{
    /**
     * Page principale de paramétrage — affiche toutes les tables
     */
    public function index()
    {
        return Inertia::render('Parametrage/Index', [
            'sites' => Site::orderBy('nom')->get(),
            'services' => Service::orderBy('nom')->get(),
            'codesAnalytiques' => CodeAnalytique::with('service')->orderBy('code')->get(),
            'motifsUrgence' => MotifUrgence::orderBy('libelle')->get(),
            'typesDocument' => TypeDocument::orderBy('nom')->get(),
            'parametres' => Parametre::orderBy('groupe')->orderBy('libelle')->get(),
        ]);
    }

    /* ─── SITES ─────────────────────────────────────────── */

    public function storeSite(Request $request)
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'unique:sites'],
            'nom' => ['required', 'string', 'max:255', 'unique:sites'],
            'ville' => ['nullable', 'string', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'solde_caisse' => ['nullable', 'numeric', 'min:0'],
            'plafond_caisse' => ['nullable', 'numeric', 'min:0'],
            'seuil_minimum_caisse' => ['nullable', 'numeric', 'min:0'],
        ]);

        Site::create($validated);

        return back()->with('success', 'Site ajouté avec succès.');
    }

    public function updateSite(Request $request, Site $site)
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'unique:sites,code,' . $site->id],
            'nom' => ['required', 'string', 'max:255', 'unique:sites,nom,' . $site->id],
            'ville' => ['nullable', 'string', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'actif' => ['boolean'],
            'solde_caisse' => ['nullable', 'numeric', 'min:0'],
            'plafond_caisse' => ['nullable', 'numeric', 'min:0'],
            'seuil_minimum_caisse' => ['nullable', 'numeric', 'min:0'],
        ]);

        $pendingCreated = false;
        $champsSensibles = ['solde_caisse', 'plafond_caisse', 'seuil_minimum_caisse'];

        foreach ($champsSensibles as $champ) {
            if (array_key_exists($champ, $validated) && $validated[$champ] != $site->$champ) {
                // Créer modification en attente
                \App\Models\ModificationEnAttente::create([
                    'type_entite' => 'site_caisse',
                    'entite_id' => $site->id,
                    'champ' => $champ,
                    'ancienne_valeur' => $site->$champ,
                    'nouvelle_valeur' => $validated[$champ],
                    'demandeur_id' => \Illuminate\Support\Facades\Auth::id(),
                    'statut' => 'en_attente',
                ]);
                $pendingCreated = true;
                // Retirer du tableau des modifications immédiates
                unset($validated[$champ]);
            }
        }

        $site->update($validated);

        if ($pendingCreated) {
            return back()->with('success', 'Site mis à jour. Les seuils de caisse modifiés ont été mis en attente de double validation.');
        }

        return back()->with('success', 'Site mis à jour.');
    }

    public function toggleSite(Site $site)
    {
        $site->update(['actif' => !$site->actif]);
        return back()->with('success', $site->actif ? 'Site activé.' : 'Site désactivé.');
    }

    /* ─── SERVICES ──────────────────────────────────────── */

    public function storeService(Request $request)
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:services'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        Service::create($validated);

        return back()->with('success', 'Service ajouté avec succès.');
    }

    public function updateService(Request $request, Service $service)
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:services,nom,' . $service->id],
            'code' => ['nullable', 'string', 'max:50'],
            'actif' => ['boolean'],
        ]);

        $service->update($validated);

        return back()->with('success', 'Service mis à jour.');
    }

    public function toggleService(Service $service)
    {
        $service->update(['actif' => !$service->actif]);
        return back()->with('success', $service->actif ? 'Service activé.' : 'Service désactivé.');
    }

    /* ─── CODES ANALYTIQUES ─────────────────────────────── */

    public function storeCodeAnalytique(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:codes_analytiques'],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'categorie_depense_defaut' => ['nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'exists:services,id'],
        ]);

        CodeAnalytique::create($validated);

        return back()->with('success', 'Code analytique ajouté avec succès.');
    }

    public function updateCodeAnalytique(Request $request, CodeAnalytique $codeAnalytique)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:codes_analytiques,code,' . $codeAnalytique->id],
            'libelle' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'categorie_depense_defaut' => ['nullable', 'string', 'max:255'],
            'service_id' => ['nullable', 'exists:services,id'],
            'actif' => ['boolean'],
        ]);

        $codeAnalytique->update($validated);

        return back()->with('success', 'Code analytique mis à jour.');
    }

    public function toggleCodeAnalytique(CodeAnalytique $codeAnalytique)
    {
        $codeAnalytique->update(['actif' => !$codeAnalytique->actif]);
        return back()->with('success', $codeAnalytique->actif ? 'Code activé.' : 'Code désactivé.');
    }

    /* ─── TYPES DE DOCUMENT ─────────────────────────────── */

    public function storeTypeDocument(Request $request)
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:types_document'],
        ]);

        TypeDocument::create($validated);

        return back()->with('success', 'Type de document ajouté avec succès.');
    }

    public function updateTypeDocument(Request $request, TypeDocument $typeDocument)
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:types_document,nom,' . $typeDocument->id],
            'actif' => ['boolean'],
        ]);

        $typeDocument->update($validated);

        return back()->with('success', 'Type de document mis à jour.');
    }

    public function toggleTypeDocument(TypeDocument $typeDocument)
    {
        $typeDocument->update(['actif' => !$typeDocument->actif]);
        return back()->with('success', $typeDocument->actif ? 'Type activé.' : 'Type désactivé.');
    }

    /* ─── MOTIFS D'URGENCE ──────────────────────────────── */

    public function storeMotifUrgence(Request $request)
    {
        $validated = $request->validate([
            'libelle' => ['required', 'string', 'max:255', 'unique:motifs_urgence'],
        ]);

        MotifUrgence::create($validated);

        return back()->with('success', 'Motif d\'urgence ajouté avec succès.');
    }

    public function updateMotifUrgence(Request $request, MotifUrgence $motifUrgence)
    {
        $validated = $request->validate([
            'libelle' => ['required', 'string', 'max:255', 'unique:motifs_urgence,libelle,' . $motifUrgence->id],
            'actif' => ['boolean'],
        ]);

        $motifUrgence->update($validated);

        return back()->with('success', 'Motif d\'urgence mis à jour.');
    }

    public function toggleMotifUrgence(MotifUrgence $motifUrgence)
    {
        $motifUrgence->update(['actif' => !$motifUrgence->actif]);
        return back()->with('success', $motifUrgence->actif ? 'Motif activé.' : 'Motif désactivé.');
    }

    /* ─── PARAMETRES SYSTEME ──────────────────────────────── */

    public function updateParametre(Request $request, Parametre $parametre)
    {
        $request->validate([
            'valeur' => ['required', 'string', 'max:255'],
        ]);

        if ($request->valeur != $parametre->valeur) {
            \App\Models\ModificationEnAttente::create([
                'type_entite' => 'parametre',
                'entite_id' => $parametre->id,
                'champ' => 'valeur',
                'ancienne_valeur' => $parametre->valeur,
                'nouvelle_valeur' => $request->valeur,
                'demandeur_id' => \Illuminate\Support\Facades\Auth::id(),
                'statut' => 'en_attente',
            ]);
            return back()->with('success', "Modification du paramètre \"{$parametre->libelle}\" mise en attente de double validation.");
        }

        return back()->with('success', "Aucune modification détectée.");
    }

    /* ─── API JSON (pour les selects searchable) ────────── */

    /**
     * Retourne les listes actives au format JSON pour les Combobox
     * Accessible par tous les utilisateurs authentifiés
     */
    public function apiListes()
    {
        return response()->json([
            'sites' => Site::actifs()->orderBy('nom')->pluck('nom'),
            'services' => Service::actifs()->orderBy('nom')->pluck('nom'),
            'codesAnalytiques' => CodeAnalytique::actifs()->with('service')->orderBy('code')->get(),
            'typesDocument' => TypeDocument::actifs()->orderBy('nom')->pluck('nom'),
            'motifsUrgence' => MotifUrgence::where('actif', true)->orderBy('libelle')->pluck('libelle'),
        ]);
    }

    /* ─── DOUBLE VALIDATION ADMIN ────────────────────────── */

    /**
     * Liste des modifications en attente de validation
     * Accessible : administrateur uniquement
     */
    public function modificationsEnAttente()
    {
        $modifications = \App\Models\ModificationEnAttente::with('demandeur', 'valideur')
            ->latest()
            ->paginate(20);

        $stats = [
            'en_attente' => \App\Models\ModificationEnAttente::where('statut', 'en_attente')->count(),
            'approuvees'  => \App\Models\ModificationEnAttente::where('statut', 'approuvee')->count(),
            'refusees'    => \App\Models\ModificationEnAttente::where('statut', 'refusee')->count(),
        ];

        return \Inertia\Inertia::render('Admin/ModificationsEnAttente', [
            'modifications' => $modifications,
            'stats'         => $stats,
            'types'         => \App\Models\ModificationEnAttente::TYPES_CRITIQUES,
        ]);
    }

    /**
     * Approuver une modification en attente
     */
    public function approuverModification(\Illuminate\Http\Request $request, \App\Models\ModificationEnAttente $modification)
    {
        if ($modification->statut !== 'en_attente') {
            return back()->with('error', 'Cette modification a déjà été traitée.');
        }

        /* Anti-auto-validation : l'approbateur ne peut pas être le demandeur */
        if ($modification->demandeur_id === \Illuminate\Support\Facades\Auth::id()) {
            return back()->with('error', 'Vous ne pouvez pas approuver votre propre modification.');
        }

        $request->validate([
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        $modification->approuver(\Illuminate\Support\Facades\Auth::user(), $request->commentaire);

        return back()->with('success', 'Modification approuvée avec succès.');
    }

    /**
     * Refuser une modification en attente
     */
    public function refuserModification(\Illuminate\Http\Request $request, \App\Models\ModificationEnAttente $modification)
    {
        if ($modification->statut !== 'en_attente') {
            return back()->with('error', 'Cette modification a déjà été traitée.');
        }

        $request->validate([
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        $modification->refuser(\Illuminate\Support\Facades\Auth::user(), $request->commentaire);

        return back()->with('success', 'Modification refusée.');
    }
}

