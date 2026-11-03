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
        /* Spec v2.4, RG-M01-09 : chacun ne voit que ses onglets (un compte qui a les deux rôles les voit tous) */
        $utilisateur = \Illuminate\Support\Facades\Auth::user();
        $admin = $utilisateur->aLeRole('administrateur');
        $gestion = $utilisateur->aLeRole('gestionnaire_referentiels');
        $onglets = array_merge(
            $admin ? ['entites', 'sites', 'caisses'] : [],
            $gestion ? ['services', 'codes', 'types', 'motifs', 'motifs-rejet', 'motifs-visiteur', 'types-or', 'baremes-odm'] : [],
            $admin ? ['parametres', 'securite'] : [],
        );

        return Inertia::render('Parametrage/Index', [
            'onglets' => $onglets,
            'sites' => $admin ? Site::with('caisses')->orderBy('nom')->get() : [],
            'caisses' => $admin ? Caisse::with(['sites:id,nom', 'gestionnaire:id,name,prenom', 'suppleant:id,name,prenom'])->orderBy('id')->get()
                ->map(fn (Caisse $caisse) => $caisse->append(['solde_format', 'type_label', 'sites_libelle'])) : [],
            'typesCaisse' => Caisse::TYPES,
            'modesCaisse' => Caisse::MODES,
            'services' => $gestion ? Service::orderBy('nom')->get()->map(fn (Service $s) => $s->setAttribute(
                'dernier_numero_odm', \App\Services\Odm\NumeroteurOdm::dernier(\App\Services\Odm\NumeroteurOdm::prefixePour($s->nom), (int) now()->year,
                    \App\Support\EntiteCourante::filtre()),
            )) : [],
            'codesAnalytiques' => $gestion ? CodeAnalytique::with('service')->orderBy('code')->get() : [],
            'motifsUrgence' => $gestion ? MotifUrgence::orderBy('libelle')->get() : [],
            'motifsVisiteur' => $gestion ? \App\Models\MotifVisiteur::orderBy('ordre')->orderBy('libelle')->get() : [],
            /* Spec v2.4, RG-M01-02 : entités (mise en service de Neemba Mining : proposition distincte) */
            'entites' => $admin ? \App\Models\Entite::orderBy('id')->get() : [],
            'motifsRejet' => $gestion ? \App\Models\MotifRejet::orderBy('ordre')->orderBy('libelle')->get() : [],
            'typesDocument' => $gestion ? TypeDocument::orderBy('nom')->get() : [],
            /* Spec v2.4, §6.5 : types d'OR ; RG-M02-10 : barèmes ODM datés */
            'typesOr' => $gestion ? \App\Models\TypeOr::orderBy('ordre')->orderBy('libelle')->get() : [],
            'baremesOdm' => $gestion ? self::baremesOdm() : null,
            /* Les réglages de la double authentification ont leur onglet « Sécurité » (effet immédiat) */
            'parametres' => $admin ? Parametre::whereNotIn('cle', \App\Services\Securite\ReglagesSecurite::cles())->orderBy('groupe')->orderBy('libelle')->get() : [],
            'securite' => $admin ? \App\Services\Securite\ReglagesSecurite::etat() : null,
            'choixParametres' => Parametre::CHOIX,
            /* Gestionnaire, suppléant et destinataires du rapport d'une caisse (référentiel Neemba, point 14) */
            'utilisateursActifs' => $admin ? User::where('actif', true)->orderBy('name')->orderBy('prenom')->get(['id', 'name', 'prenom'])
                ->map(fn (User $u) => ['id' => $u->id, 'libelle' => trim(mb_strtoupper($u->name) . ' ' . $u->prenom)]) : [],
        ]);
    }

    /** Barèmes ODM : valeur en vigueur, prochaine valeur datée, historique (spec v2.4, RG-M02-10, RG-M02-01) */
    private static function baremesOdm(): array
    {
        $entite = \App\Support\EntiteCourante::filtre();
        $aujourdhui = now()->startOfDay();
        $libelle = fn (string $cle) => \App\Models\BaremeOdm::CLES[$cle]['libelle'] ?? $cle;

        return [
            'cles' => collect(\App\Models\BaremeOdm::CLES)->map(fn (array $c) => ['libelle' => $c['libelle'], 'type' => $c['type']])->all(),
            'aujourdhui' => $aujourdhui->toDateString(),
            'enVigueur' => collect(array_keys(\App\Models\BaremeOdm::CLES))->mapWithKeys(function (string $cle) use ($entite, $aujourdhui) {
                $ligne = \App\Models\BaremeOdm::ligneEnVigueur($cle, $aujourdhui, $entite);
                $prochaine = \App\Models\BaremeOdm::where('cle', $cle)->whereDate('date_effet', '>', $aujourdhui)->orderBy('date_effet')->first();

                return [$cle => [
                    'valeur' => \App\Models\BaremeOdm::enVigueur($cle, $aujourdhui, $entite),
                    'depuis' => $ligne?->date_effet?->format('d/m/Y'),
                    'prochaine' => $prochaine ? ['valeur' => $prochaine->valeur, 'date' => $prochaine->date_effet->format('d/m/Y')] : null,
                ]];
            })->all(),
            'historique' => \App\Models\BaremeOdm::with('auteur:id,name,prenom')->orderByDesc('date_effet')->orderByDesc('id')->limit(60)->get()
                ->map(fn (\App\Models\BaremeOdm $b) => [
                    'id' => $b->id,
                    'cle' => $b->cle,
                    'libelle' => $libelle($b->cle),
                    'valeur' => $b->valeur,
                    'date_effet' => $b->date_effet->format('d/m/Y'),
                    'auteur' => $b->auteur?->nom_complet ?? 'Reprise des paramètres',
                    'commentaire' => $b->commentaire,
                    'saisi_le' => $b->created_at?->format('d/m/Y H:i'),
                    'a_venir' => $b->date_effet->greaterThan($aujourdhui),
                ])->values()->all(),
        ];
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
            /* Spec v2.4, RG-M02-11 : une caisse espèces sert un ou plusieurs sites ; la caisse OM n'en sert aucun */
            'site_ids'        => ['array', 'required_unless:type,orange_money', 'prohibited_if:type,orange_money'],
            'site_ids.*'      => ['integer', 'exists:sites,id'],
            'type'            => ['required', \Illuminate\Validation\Rule::in(array_keys(Caisse::TYPES))],
            'mode'            => ['required', \Illuminate\Validation\Rule::in(array_keys(Caisse::MODES))],
            'montant_avance'  => ['nullable', 'numeric', 'min:0', 'required_if:mode,avance_fixe'],
            'plafond_retrait' => ['nullable', 'numeric', 'min:0'],
            'seuil_alerte'    => ['nullable', 'numeric', 'min:0'],
            'plafond_caisse'  => ['nullable', 'numeric', 'min:0'],
        ] + self::reglesResponsablesCaisse() + self::reglesNumerotation());

        $caisse = Caisse::create(\Illuminate\Support\Arr::except($validated, ['site_ids']) + ['solde' => 0, 'actif' => true]);
        $caisse->sites()->sync($validated['site_ids'] ?? []);

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
            /* Spec v2.4, RG-M02-11 : sites servis, modifiés tout de suite (la caisse OM n'en sert aucun) */
            'site_ids'        => ['sometimes', 'array', $caisse->type === 'orange_money' ? 'max:0' : 'min:1'],
            'site_ids.*'      => ['integer', 'exists:sites,id'],
        ] + self::reglesResponsablesCaisse() + self::reglesNumerotation(), [
            'site_ids.min' => 'Choisissez au moins un site servi par cette caisse.',
            'site_ids.max' => "La caisse Orange Money ne sert aucun site.",
            'code_numerotation.unique' => 'Ce code de numérotation est déjà utilisé par une autre caisse de l\'entité.',
        ]);
        /* RG-M01-08 : les bons sont numérotés par caisse ; le code reste unique dans l'entité */
        if (filled($validated['code_numerotation'] ?? null)) {
            $request->validate(['code_numerotation' => [\Illuminate\Validation\Rule::unique('caisses', 'code_numerotation')->where('entite_id', $caisse->entite_id)->ignore($caisse->id)]],
                ['code_numerotation.unique' => 'Ce code de numérotation est déjà utilisé par une autre caisse de l\'entité.']);
        }

        if (array_key_exists('site_ids', $validated)) {
            $caisse->sites()->sync($validated['site_ids']);
        }

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
            'reappro_par_transfert', 'frequence_controle', 'code_numerotation', 'numero_depart', 'chiffres_numero',
        ])));

        return back()->with('success', $enAttente
            ? "Caisse mise à jour. {$enAttente} modification(s) sensible(s) en attente de double validation."
            : 'Caisse mise à jour.');
    }

    /**
     * Lot C (RG-M03-18, RG-M08-11) : code de la caisse dans les numéros de bons, numéro de départ (reprise du carnet
     * papier, pris en compte à la première séquence de la caisse) et nombre de chiffres (sinon le paramètre général)
     */
    private static function reglesNumerotation(): array
    {
        return [
            'code_numerotation' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'numero_depart' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'chiffres_numero' => ['nullable', 'integer', 'min:3', 'max:9'],
        ];
    }

    /** Matrice de paramétrage par caisse (référentiel Neemba, point 14) : champs sans double validation */
    private static function reglesResponsablesCaisse(): array
    {
        return [
            'gestionnaire_id'       => ['nullable', 'integer', 'exists:users,id'],
            'suppleant_id'          => ['nullable', 'integer', 'exists:users,id', 'different:gestionnaire_id'],
            'encaissements_clients' => ['boolean'],
            'reapprovisionnement'   => ['nullable', 'string', 'max:255'],
            'reappro_par_transfert' => ['boolean'],
            'frequence_controle'    => ['nullable', 'in:mensuelle,trimestrielle'],
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
        $actuel = \App\Services\Odm\NumeroteurOdm::dernier($prefixe, (int) now()->year, \App\Support\EntiteCourante::filtre());
        if ((int) $donnees['reprise_carnet'] < $actuel) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reprise_carnet' => "Le compteur {$prefixe} est déjà au n° {$actuel} : il ne peut pas reculer.",
            ]);
        }
        \App\Services\Odm\NumeroteurOdm::definirDepart($prefixe, (int) now()->year, (int) $donnees['reprise_carnet'], \App\Support\EntiteCourante::filtre());
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

    /* ─── MOTIFS DU BON VISITEUR (spec v2.4, RG-M03-28, PO-30) ─ */

    public function storeMotifVisiteur(Request $request)
    {
        $validated = $request->validate(['libelle' => ['required', 'string', 'max:255', 'unique:motifs_visiteur']]);
        \App\Models\MotifVisiteur::create($validated + ['actif' => true, 'ordre' => (int) \App\Models\MotifVisiteur::max('ordre') + 1]);

        return back()->with('success', 'Motif du bon visiteur ajouté.');
    }

    public function updateMotifVisiteur(Request $request, \App\Models\MotifVisiteur $motifVisiteur)
    {
        $motifVisiteur->update($request->validate(['libelle' => ['required', 'string', 'max:255', 'unique:motifs_visiteur,libelle,' . $motifVisiteur->id]]));

        return back()->with('success', 'Motif du bon visiteur mis à jour.');
    }

    public function toggleMotifVisiteur(\App\Models\MotifVisiteur $motifVisiteur)
    {
        $motifVisiteur->update(['actif' => !$motifVisiteur->actif]);

        return back()->with('success', $motifVisiteur->actif ? 'Motif activé.' : 'Motif désactivé.');
    }

    /* ─── MOTIFS DE REJET (spec v2.3, RG-M04-04) ─────────── */

    public function storeMotifRejet(Request $request)
    {
        $validated = $request->validate(['libelle' => ['required', 'string', 'max:255', 'unique:motifs_rejet']]);
        \App\Models\MotifRejet::create($validated + [
            'code' => \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($validated['libelle'], '_'), 30, '') . '_' . substr(uniqid(), -5),
            'actif' => true,
            'ordre' => (int) \App\Models\MotifRejet::max('ordre') + 1,
        ]);

        return back()->with('success', 'Motif de rejet ajouté.');
    }

    public function updateMotifRejet(Request $request, \App\Models\MotifRejet $motifRejet)
    {
        $motifRejet->update($request->validate(['libelle' => ['required', 'string', 'max:255', 'unique:motifs_rejet,libelle,' . $motifRejet->id]]));

        return back()->with('success', 'Motif de rejet mis à jour.');
    }

    public function toggleMotifRejet(\App\Models\MotifRejet $motifRejet)
    {
        $motifRejet->update(['actif' => !$motifRejet->actif]);

        return back()->with('success', $motifRejet->actif ? 'Motif de rejet activé.' : 'Motif de rejet désactivé.');
    }

    /* ─── TYPES D'OR (spec v2.4, §6.5, RG-M03-13, PO-28) ─────── */

    public function storeTypeOr(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', \Illuminate\Validation\Rule::unique('types_or', 'code')],
            'libelle' => ['required', 'string', 'max:100'],
        ], ['code.regex' => 'Code en minuscules, chiffres et « _ » seulement (ex. : interne).']);
        \App\Models\TypeOr::create($validated + ['actif' => true, 'ordre' => (int) \App\Models\TypeOr::max('ordre') + 1]);

        return back()->with('success', "Type d'OR ajouté.");
    }

    /** Le code reste celui des OR déjà saisis : seul le libellé change */
    public function updateTypeOr(Request $request, \App\Models\TypeOr $typeOr)
    {
        $typeOr->update($request->validate(['libelle' => ['required', 'string', 'max:100']]));

        return back()->with('success', "Type d'OR mis à jour.");
    }

    public function toggleTypeOr(\App\Models\TypeOr $typeOr)
    {
        if ($typeOr->actif && \App\Models\TypeOr::where('actif', true)->count() <= 1) {
            return back()->with('error', "Au moins un type d'OR doit rester actif.");
        }
        $typeOr->update(['actif' => !$typeOr->actif]);

        return back()->with('success', $typeOr->actif ? "Type d'OR activé." : "Type d'OR désactivé : il n'est plus proposé, les OR déjà saisis le gardent.");
    }

    /* ─── BARÈMES ODM (spec v2.4, RG-M02-10, RG-M02-01, §6.6) ── */

    /** Nouvelle valeur datée, sans circuit de validation ; une valeur n'est jamais modifiée (historique) */
    public function storeBaremeOdm(Request $request)
    {
        $cles = \App\Models\BaremeOdm::CLES;
        $request->validate([
            'cle' => ['required', \Illuminate\Validation\Rule::in(array_keys($cles))],
            'date_effet' => ['required', 'date', 'after_or_equal:today'],
            'commentaire' => ['nullable', 'string', 'max:255'],
        ], ['date_effet.after_or_equal' => "La date d'effet ne peut pas être passée : un barème ne réécrit pas le passé."]);
        $nombre = ($cles[$request->input('cle')]['type'] ?? null) === 'number';
        $request->validate(['valeur' => $nombre ? ['required', 'integer', 'min:1', 'max:100000000'] : ['required', 'string', 'max:60']],
            ['valeur.integer' => 'Saisissez un montant entier.']);

        $bareme = \App\Models\BaremeOdm::create([
            'entite_id' => \App\Support\EntiteCourante::id(\Illuminate\Support\Facades\Auth::user()),
            'cle' => $request->input('cle'),
            'valeur' => $nombre ? (string) (int) $request->input('valeur') : trim((string) $request->input('valeur')),
            'date_effet' => $request->input('date_effet'),
            'saisi_par' => \Illuminate\Support\Facades\Auth::id(),
            'commentaire' => $request->input('commentaire'),
        ]);

        $valeur = $nombre ? number_format((int) $bareme->valeur, 0, ',', ' ') : $bareme->valeur;

        return back()->with('success', "Barème enregistré : « {$cles[$bareme->cle]['libelle']} » = {$valeur}, à partir du {$bareme->date_effet->format('d/m/Y')}.");
    }

    /** Seule une valeur à venir peut être retirée ; une valeur entrée en vigueur reste dans l'historique */
    public function destroyBaremeOdm(\App\Models\BaremeOdm $baremeOdm)
    {
        if (!$baremeOdm->date_effet->isFuture()) {
            return back()->with('error', "Ce barème est déjà en vigueur : saisissez une nouvelle valeur plutôt que de l'effacer.");
        }
        $baremeOdm->delete();

        return back()->with('success', 'Barème à venir retiré.');
    }

    /* ─── PARAMETRES SYSTEME ──────────────────────────────── */

    public function updateParametre(Request $request, Parametre $parametre)
    {
        $request->validate([
            'valeur' => ['required', 'string', 'max:2000'],
        ]);

        if (in_array($parametre->cle, \App\Services\Securite\ReglagesSecurite::cles(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['valeur' => 'Ce réglage se modifie dans l\'onglet « Sécurité ».']);
        }

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

    /**
     * PUT /parametrage/securite — spec v2.4, RG-M01-07 : l'administrateur active ou désactive la double authentification
     * (code de connexion, code de visa urgent) et en règle la durée et les essais. Effet immédiat, tracé, administrateurs
     * prévenus.
     */
    public function updateSecurite(Request $request)
    {
        $valeurs = $request->validate(\App\Services\Securite\ReglagesSecurite::regles());
        $modifies = \App\Services\Securite\ReglagesSecurite::appliquer($valeurs, \Illuminate\Support\Facades\Auth::user());

        return back()->with('success', $modifies
            ? 'Réglages de sécurité appliqués : ' . implode(', ', $modifies) . '.'
            : 'Aucune modification détectée.');
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
    public function modificationsEnAttente(\Illuminate\Http\Request $request)
    {
        /* Filtre par statut côté serveur (en attente par défaut) : filtrer la seule page affichée cachait les demandes
         * en attente des pages suivantes */
        $statut = in_array($request->input('statut'), ['en_attente', 'approuvee', 'refusee', 'tous'], true) ? $request->input('statut') : 'en_attente';
        $modifications = \App\Models\ModificationEnAttente::with('demandeur', 'valideur')
            ->when($statut !== 'tous', fn ($q) => $q->where('statut', $statut))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'en_attente' => \App\Models\ModificationEnAttente::where('statut', 'en_attente')->count(),
            'approuvees'  => \App\Models\ModificationEnAttente::where('statut', 'approuvee')->count(),
            'refusees'    => \App\Models\ModificationEnAttente::where('statut', 'refusee')->count(),
        ];

        return \Inertia\Inertia::render('Admin/ModificationsEnAttente', [
            'modifications' => $modifications,
            'filtreStatut'  => $statut,
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

