<?php

namespace App\Http\Controllers;

use App\Models\MotifUrgence;
use App\Models\Caisse;
use App\Models\CodeAnalytique;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\TypeDocument;
use App\Models\User;
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
            'sites' => Site::with('caisses')->orderBy('nom')->get(),
            'caisses' => Caisse::with(['site', 'gestionnaire:id,name,prenom', 'suppleant:id,name,prenom'])->orderBy('site_id')->orderBy('id')->get()
                ->map(fn (Caisse $caisse) => $caisse->append(['solde_format', 'type_label'])),
            'typesCaisse' => Caisse::TYPES,
            'modesCaisse' => Caisse::MODES,
            'services' => Service::orderBy('nom')->get()->map(fn (Service $s) => $s->setAttribute(
                'dernier_numero_odm', \App\Services\Odm\NumeroteurOdm::dernier(\App\Services\Odm\NumeroteurOdm::prefixePour($s->nom), (int) now()->year),
            )),
            'codesAnalytiques' => CodeAnalytique::with('service')->orderBy('code')->get(),
            'motifsUrgence' => MotifUrgence::orderBy('libelle')->get(),
            'typesDocument' => TypeDocument::orderBy('nom')->get(),
            'parametres' => Parametre::orderBy('groupe')->orderBy('libelle')->get(),
            'choixParametres' => Parametre::CHOIX,
            /* Gestionnaire, suppléant et destinataires du rapport d'une caisse (référentiel Neemba, point 14) */
            'utilisateursActifs' => User::where('actif', true)->orderBy('name')->orderBy('prenom')->get(['id', 'name', 'prenom'])
                ->map(fn (User $u) => ['id' => $u->id, 'libelle' => trim(mb_strtoupper($u->name) . ' ' . $u->prenom)]),
        ]);
    }

    /* ─── SITES ─────────────────────────────────────────── */

    public function storeSite(Request $request)
    {
        $validated = $request->validate([
            'code'                => ['nullable', 'string', 'max:10', 'unique:sites'],
            'nom'                 => ['required', 'string', 'max:255', 'unique:sites'],
            'ville'               => ['nullable', 'string', 'max:255'],
            'adresse'             => ['nullable', 'string', 'max:500'],
        ]);

        $site = Site::create($validated);

        /* Chaque site a sa caisse espèces (RG-BC-12) ; plafond, seuil et argent se règlent ensuite sur la caisse */
        Caisse::creerCaissePrincipale($site);

        return back()->with('success', "Site ajouté avec sa caisse principale (solde 0). Réglez plafond et seuil dans l'onglet Caisses.");
    }

    public function updateSite(Request $request, Site $site)
    {
        $validated = $request->validate([
            'code'                => ['nullable', 'string', 'max:10', 'unique:sites,code,' . $site->id],
            'nom'                 => ['required', 'string', 'max:255', 'unique:sites,nom,' . $site->id],
            'ville'               => ['nullable', 'string', 'max:255'],
            'adresse'             => ['nullable', 'string', 'max:500'],
            'actif'               => ['boolean'],
        ]);

        $site->update($validated);

        return back()->with('success', 'Site mis à jour.');
    }

    /* ─── CAISSES (lot 2) ───────────────────────────────── */

    /**
     * Nouvelle caisse, créée avec un solde de 0 : l'argent arrive par un mouvement de caisse validé
     * (approvisionnement) ou par une correction de solde en double validation.
     */
    public function storeCaisse(Request $request)
    {
        $validated = $request->validate([
            'code'            => ['required', 'string', 'max:20', 'unique:caisses,code'],
            'libelle'         => ['required', 'string', 'max:255'],
            'site_id'         => ['required', 'integer', 'exists:sites,id'],
            'type'            => ['required', \Illuminate\Validation\Rule::in(array_keys(Caisse::TYPES))],
            'mode'            => ['required', \Illuminate\Validation\Rule::in(array_keys(Caisse::MODES))],
            'montant_avance'  => ['nullable', 'numeric', 'min:0', 'required_if:mode,avance_fixe'],
            'plafond_retrait' => ['nullable', 'numeric', 'min:0'],
            'seuil_alerte'    => ['nullable', 'numeric', 'min:0'],
            'plafond_caisse'  => ['nullable', 'numeric', 'min:0'],
        ] + self::reglesResponsablesCaisse());

        Caisse::create($validated + ['solde' => 0, 'actif' => true]);

        return back()->with('success', 'Caisse créée (solde 0).');
    }

    /**
     * Code et libellé : modifiés tout de suite. Plafond de retrait, seuil d'alerte, avance et solde :
     * double validation (ModificationEnAttente) ; un solde approuvé est inscrit au registre.
     */
    public function updateCaisse(Request $request, Caisse $caisse)
    {
        $validated = $request->validate([
            'code'            => ['required', 'string', 'max:20', 'unique:caisses,code,' . $caisse->id],
            'libelle'         => ['required', 'string', 'max:255'],
            'montant_avance'  => ['nullable', 'numeric', 'min:0'],
            'plafond_retrait' => ['nullable', 'numeric', 'min:0'],
            'seuil_alerte'    => ['nullable', 'numeric', 'min:0'],
            'plafond_caisse'  => ['nullable', 'numeric', 'min:0'],
            'solde'           => ['nullable', 'numeric'],
        ] + self::reglesResponsablesCaisse());

        $enAttente = 0;
        foreach ([...Caisse::CHAMPS_SENSIBLES, 'solde'] as $champ) {
            if (!array_key_exists($champ, $validated)) {
                continue;
            }
            $nouvelle = $validated[$champ];
            $ancienne = $caisse->$champ;
            $identique = ($nouvelle === null && $ancienne === null)
                || ($nouvelle !== null && $ancienne !== null && (float) $nouvelle === (float) $ancienne);
            if (!$identique && !($champ === 'solde' && $nouvelle === null)) {
                \App\Models\ModificationEnAttente::create([
                    'type_entite' => 'caisse',
                    'entite_id' => $caisse->id,
                    'champ' => $champ,
                    'ancienne_valeur' => $ancienne,
                    'nouvelle_valeur' => $nouvelle,
                    'demandeur_id' => \Illuminate\Support\Facades\Auth::id(),
                    'statut' => 'en_attente',
                ]);
                $enAttente++;
            }
        }

        /* Code, libellé, gestionnaire, suppléant, encaissements et réapprovisionnement : modifiés tout de suite */
        $caisse->update(array_intersect_key($validated, array_flip([
            'code', 'libelle', 'gestionnaire_id', 'suppleant_id', 'encaissements_clients', 'reapprovisionnement',
        ])));

        return back()->with('success', $enAttente
            ? "Caisse mise à jour. {$enAttente} modification(s) sensible(s) en attente de double validation."
            : 'Caisse mise à jour.');
    }

    /** Matrice de paramétrage par caisse (référentiel Neemba, point 14) : champs sans double validation */
    private static function reglesResponsablesCaisse(): array
    {
        return [
            'gestionnaire_id'       => ['nullable', 'integer', 'exists:users,id'],
            'suppleant_id'          => ['nullable', 'integer', 'exists:users,id', 'different:gestionnaire_id'],
            'encaissements_clients' => ['boolean'],
            'reapprovisionnement'   => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toggleCaisse(Caisse $caisse)
    {
        $caisse->update(['actif' => !$caisse->actif]);

        return back()->with('success', $caisse->actif ? 'Caisse activée.' : 'Caisse désactivée.');
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
            'equivalent_odm' => ['nullable', 'string', 'max:40'],
        ] + self::reglesOdmService());

        /* Une reprise de carnet refusée annule tout l'enregistrement */
        \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
            $service = Service::create(collect($validated)->except('reprise_carnet')->all());
            $this->repriseCarnet($service, $validated);
        });

        return back()->with('success', 'Service ajouté avec succès.');
    }

    public function updateService(Request $request, Service $service)
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255', 'unique:services,nom,' . $service->id],
            'code' => ['nullable', 'string', 'max:50'],
            'equivalent_odm' => ['nullable', 'string', 'max:40'],
            'actif' => ['boolean'],
        ] + self::reglesOdmService());

        \Illuminate\Support\Facades\DB::transaction(function () use ($service, $validated) {
            $service->update(collect($validated)->except('reprise_carnet')->all());
            $this->repriseCarnet($service, $validated);
        });

        return back()->with('success', 'Service mis à jour.');
    }

    /** M12 (Q28, RG-M12-24) : préfixe de numérotation des ODM, liste de diffusion, reprise du carnet papier */
    private static function reglesOdmService(): array
    {
        return [
            'prefixe_odm' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'diffusion_odm' => ['nullable', 'array'],
            'diffusion_odm.*' => ['integer', 'exists:users,id'],
            'reprise_carnet' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ];
    }

    /**
     * Reprise du carnet papier : le prochain ODM du service portera le n° suivant (année en cours).
     * Le compteur ne recule jamais, pour ne pas réattribuer un numéro.
     */
    private function repriseCarnet(Service $service, array $donnees): void
    {
        if (isset($donnees['prefixe_odm'])) {
            $service->update(['prefixe_odm' => mb_strtoupper($donnees['prefixe_odm'])]);
        }
        if (!isset($donnees['reprise_carnet'])) {
            return;
        }
        $prefixe = \App\Services\Odm\NumeroteurOdm::prefixePour($service->nom);
        $actuel = \App\Services\Odm\NumeroteurOdm::dernier($prefixe, (int) now()->year);
        if ((int) $donnees['reprise_carnet'] < $actuel) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reprise_carnet' => "Le compteur {$prefixe} est déjà au n° {$actuel} : il ne peut pas reculer.",
            ]);
        }
        \App\Services\Odm\NumeroteurOdm::definirDepart($prefixe, (int) now()->year, (int) $donnees['reprise_carnet']);
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
            'code_service_comptable' => ['nullable', 'string', 'max:10'],
            'valide_cdg' => ['boolean'],
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
            'code_service_comptable' => ['nullable', 'string', 'max:10'],
            'valide_cdg' => ['boolean'],
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
            'valeur' => ['required', 'string', 'max:2000'],
        ]);

        /* Valeur contrôlée selon le type (choix, dates, grille JSON…) avant la double validation */
        if ($erreur = $parametre->erreurValeur($request->valeur)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['valeur' => $erreur]);
        }

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

