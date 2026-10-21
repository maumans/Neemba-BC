<?php

namespace App\Http\Controllers;

use App\Exports\EcrituresComptablesExport;
use App\Models\BonCaisse;
use App\Models\CodeAnalytique;
use App\Models\ExportComptable;
use App\Models\Imputation;
use App\Models\LigneImputation;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\User;
use App\Services\Comptabilite\ExportEcritures;
use App\Services\Comptabilite\ImputationBon;
use App\Services\Odm\RepartitionOdm;
use App\Support\Format;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as FormatExcel;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Imputation comptable et export comptable (M16, spec v2.3 §8.11) : la Comptabilité, le Chef comptable, le DAF et son
 * adjoint imputent les bons payés, puis exportent chaque jour les écritures (Excel ou CSV générique).
 */
class ImputationController extends Controller
{
    /** Paramètres attendus de la Comptabilité (PO-17, PO-25) : signalés tant qu'ils sont vides */
    private const PARAMETRES_ATTENDUS = [
        'compte_frais_om' => 'Compte des frais Orange Money',
        'compte_frais_refacturables' => 'Compte des frais refacturables',
        'compte_odm_indemnite' => 'Compte des indemnités de mission',
        'compte_odm_hebergement' => "Compte de l'hébergement de mission",
        'code_journal_especes' => 'Code journal espèces',
        'code_journal_orange_money' => 'Code journal Orange Money',
    ];

    public function index(Request $request)
    {
        $onglet = in_array($request->query('onglet'), ['a_imputer', 'a_exporter', 'exportees'], true) ? $request->query('onglet') : 'a_imputer';
        /* Par défaut : depuis le plus ancien bon payé non imputé (au plus 30 jours en arrière s'il n'y en a pas), pour qu'aucun retard ne soit masqué */
        $plusAncien = ImputationBon::requeteAImputer()->min('date_paiement');
        $du = $request->query('du') ?: collect([today()->subDays(30), $plusAncien ? Carbon::parse($plusAncien)->startOfDay() : null])->filter()->min()->toDateString();
        $au = $request->query('au') ?: today()->toDateString();

        $lignes = match ($onglet) {
            'a_imputer' => ImputationBon::requeteAImputer()->with('imputation')
                ->whereDate('date_paiement', '>=', $du)->whereDate('date_paiement', '<=', $au)
                ->orderBy('date_paiement')->paginate(25)->withQueryString()
                ->through(fn (BonCaisse $bon) => self::ligneBon($bon)),
            default => Imputation::where('statut', $onglet === 'a_exporter' ? 'validee' : 'exportee')->with(['bonCaisse', 'validePar', 'exportComptable'])
                ->whereHas('bonCaisse', fn ($q) => $q->whereDate('date_paiement', '>=', $du)->whereDate('date_paiement', '<=', $au))
                ->latest('valide_le')->paginate(25)->withQueryString()
                ->through(fn (Imputation $i) => self::ligneBon($i->bonCaisse) + [
                    'valide_par' => $i->validePar?->nom_complet,
                    'valide_le' => $i->valide_le ? Format::dateHeure($i->valide_le) : null,
                    'export' => $i->exportComptable ? 'n° ' . $i->exportComptable->id . ' du ' . Format::dateHeure($i->exportComptable->created_at) : null,
                ]),
        };

        $enRetard = ImputationBon::requeteAImputer()->with('imputation')->get()->filter(fn (BonCaisse $b) => ImputationBon::enRetard($b))->count();

        return Inertia::render('Comptabilite/Index', [
            'onglet' => $onglet,
            'filtres' => ['du' => $du, 'au' => $au],
            'lignes' => $lignes,
            'compteurs' => [
                'a_imputer' => ImputationBon::requeteAImputer()->count(),
                'en_retard' => $enRetard,
                'a_exporter' => Imputation::where('statut', 'validee')->count(),
            ],
            'exports' => ExportComptable::with('exportePar')->latest()->limit(15)->get()->map(fn (ExportComptable $e) => [
                'id' => $e->id,
                'periode' => $e->date_debut->equalTo($e->date_fin) ? Format::date($e->date_debut) : Format::date($e->date_debut) . ' au ' . Format::date($e->date_fin),
                'ecritures' => $e->nombre_ecritures,
                'montant' => Format::montant($e->montant_total),
                'par' => $e->exportePar?->nom_complet,
                'le' => Format::dateHeure($e->created_at),
            ]),
            /* Formulaire d'export (téléchargement classique, hors Inertia) */
            'jeton' => csrf_token(),
            'parametresManquants' => collect(self::PARAMETRES_ATTENDUS)->filter(fn ($libelle, $cle) => blank(Parametre::valeur($cle, '')))->values(),
        ]);
    }

    public function edit(BonCaisse $bonCaisse)
    {
        /** @var User $auteur */
        $auteur = Auth::user();
        $imputation = ImputationBon::ouvrir($bonCaisse, $auteur);
        $odm = $bonCaisse->odm_id ? OrdreMission::find($bonCaisse->odm_id) : null;
        $lignesFrais = ImputationBon::partsOdm($bonCaisse)->map(fn ($p) => $p->ligne)->unique('id')->values();

        return Inertia::render('Comptabilite/Imputation', [
            /* array_merge et non « + » : le détail de l'ODM remplace l'indicateur « bon d'ODM » de la ligne (le « + » gardait
             * true, et l'écran plantait en cherchant l'identifiant de l'ODM) */
            'bon' => array_merge(self::ligneBon($bonCaisse), [
                'montant' => (float) $bonCaisse->montant,
                'frais_om' => $bonCaisse->frais_om !== null ? (float) $bonCaisse->frais_om : null,
                'odm' => $odm ? ['id' => $odm->id, 'libelle' => $odm->libelle, 'code_analytique' => $odm->code_analytique] : null,
            ]),
            'imputation' => [
                'statut' => $imputation->statut,
                'statut_label' => Imputation::STATUTS[$imputation->statut],
                'modifiable' => $imputation->estModifiable(),
                'motif_correction' => $imputation->motif_correction,
                'lignes' => $imputation->lignes()->with('client')->get()->map(fn (LigneImputation $l) => [
                    'nature' => $l->nature,
                    'compte' => $l->compte ?? '',
                    'libelle' => $l->libelle,
                    'montant' => (float) $l->montant,
                    'code_analytique' => $l->code_analytique ?? '',
                    'payeur' => $l->payeur,
                    'client' => $l->client?->resume(),
                    'ordre_reparation' => $l->ordre_reparation ?? '',
                    'ligne_frais_id' => $l->ligne_frais_id,
                ])->values(),
            ],
            /* RG-M16-06 : lignes de frais de l'ODM payées par ce bon, dont la répartition peut être corrigée */
            'lignesFrais' => $lignesFrais->map(fn ($l) => [
                'id' => $l->id,
                'libelle' => RepartitionOdm::libelleElement($l->element) . ' — ' . $l->participant->nom,
                'montant' => (float) $l->montant,
            ]),
            'ordres' => $odm ? $odm->ordresReparation()->with('client')->get()->map(fn ($or) => ['numero' => $or->numero, 'client' => $or->client?->resume()]) : [],
            'clientsOdm' => $odm ? $odm->clients()->get()->map(fn ($c) => $c->resume())->values() : [],
            'codesAnalytiques' => CodeAnalytique::where('actif', true)->orderBy('code')->get(['code', 'libelle']),
        ]);
    }

    public function update(Request $request, BonCaisse $bonCaisse)
    {
        /** @var User $auteur */
        $auteur = Auth::user();
        $donnees = $request->validate([
            'lignes' => ['required', 'array', 'min:1', 'max:60'],
            'lignes.*.nature' => ['sometimes', Rule::in(['depense', 'frais_om'])],
            'lignes.*.compte' => ['nullable', 'string', 'max:20'],
            'lignes.*.libelle' => ['required', 'string', 'max:150'],
            'lignes.*.montant' => ['required', 'numeric', 'min:0'],
            'lignes.*.code_analytique' => ['nullable', 'string', 'max:50'],
            'lignes.*.payeur' => ['nullable', Rule::in(['neemba', 'client'])],
            'lignes.*.client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'lignes.*.ordre_reparation' => ['nullable', 'string', 'max:20'],
            'lignes.*.ligne_frais_id' => ['nullable', 'integer'],
            'motif' => ['nullable', 'string', 'max:1000'],
            'valider' => ['sometimes', 'boolean'],
        ], [
            'lignes.required' => 'Ajoutez au moins une ligne d\'imputation.',
            'lignes.*.libelle.required' => 'Chaque ligne doit avoir un libellé.',
        ]);
        $imputation = ImputationBon::ouvrir($bonCaisse, $auteur);
        $valider = $request->boolean('valider');
        ImputationBon::enregistrer($imputation, $donnees['lignes'], $donnees['motif'] ?? null, $auteur, $valider);

        return $valider
            ? redirect()->route('comptabilite.index')->with('success', "Imputation du bon {$bonCaisse->numero} validée.")
            : back()->with('success', 'Brouillon d\'imputation enregistré.');
    }

    /** RG-M16-03 : export des écritures imputées de la période ; elles ne se modifient plus */
    public function exporter(Request $request)
    {
        /** @var User $auteur */
        $auteur = Auth::user();
        $donnees = $request->validate([
            'du' => ['required', 'date_format:Y-m-d'],
            'au' => ['required', 'date_format:Y-m-d', 'after_or_equal:du'],
            'format' => ['required', Rule::in(['xlsx', 'csv'])],
        ], ['au.after_or_equal' => 'La fin de la période précède son début.']);
        $export = ExportEcritures::exporter($donnees['du'], $donnees['au'], $auteur);

        return $this->fichier($export, $donnees['format']);
    }

    public function telecharger(ExportComptable $export, string $format)
    {
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        return $this->fichier($export, $format);
    }

    private function fichier(ExportComptable $export, string $format)
    {
        return Excel::download(new EcrituresComptablesExport(ExportEcritures::lignes($export)), ExportEcritures::nomFichier($export, $format),
            $format === 'csv' ? FormatExcel::CSV : FormatExcel::XLSX);
    }

    /** Ligne de la liste des bons à imputer */
    private static function ligneBon(BonCaisse $bon): array
    {
        $mode = $bon->mode_paiement_effectif ?? $bon->mode_paiement;

        return [
            'id' => $bon->id,
            'numero' => $bon->numero,
            'type_bon' => $bon->type_bon,
            'date_paiement' => $bon->date_paiement ? Format::date($bon->date_paiement) : null,
            'beneficiaire' => $bon->beneficiaire,
            'motif' => $bon->motif,
            'montant_verse' => ImputationBon::montantVerse($bon),
            'mode' => BonCaisse::MODES_PAIEMENT[$mode] ?? $mode,
            'code_journal' => ImputationBon::codeJournal($bon),
            'odm' => (bool) $bon->genere_par_odm,
            'statut_imputation' => $bon->imputation?->statut,
            'en_retard' => ImputationBon::enRetard($bon),
        ];
    }
}
