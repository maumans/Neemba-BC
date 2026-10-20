<?php

namespace App\Http\Controllers;

use App\Models\Caisse;
use App\Models\ClotureCaisse;
use App\Services\Caisse\AccesCaisse;
use App\Services\Caisse\ClotureJournaliere;
use App\Services\Caisse\RapportsCaisse;
use App\Services\Caisse\ReportingCaisse;
use App\Support\Format;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Clôture journalière par caisse et rapport « REPORTING CAISSE » (spec v2.4, RG-M09-01 à RG-M09-05) : journée en cours de
 * chaque caisse visible, clôture par le gérant, historique des clôtures, rapport PDF ou Excel sur une période.
 */
class ClotureCaisseController extends Controller
{
    public function index(Request $request)
    {
        $utilisateur = Auth::user();
        $caisses = AccesCaisse::caissesVisibles($utilisateur);
        $filtre = $request->integer('caisse') ?: null;

        return Inertia::render('Caisses/Clotures/Index', [
            'caisses' => $caisses->map(function (Caisse $c) use ($utilisateur) {
                $jour = ReportingCaisse::journee($c, today());
                $close = ClotureCaisse::estClose($c->id, today());

                return [
                    'id' => $c->id,
                    'libelle' => $c->libelle,
                    'solde_initial' => Format::montant($jour['solde_initial']),
                    'encaissements' => Format::montant($jour['total_encaissements']),
                    'decaissements' => Format::montant($jour['total_decaissements']),
                    'solde_final' => Format::montant($jour['solde_final']),
                    'operations' => $jour['nombre_operations'],
                    'ecart' => $jour['ecart'],
                    'ecart_format' => $jour['ecart'] !== null ? Format::montant($jour['ecart']) : null,
                    'close' => $close,
                    'peut_cloturer' => !$close && AccesCaisse::estGerant($utilisateur, $c),
                ];
            })->values(),
            'clotures' => ClotureCaisse::with(['caisse', 'cloturePar'])
                ->whereIn('caisse_id', $caisses->pluck('id'))
                ->when($filtre, fn ($q) => $q->where('caisse_id', $filtre))
                ->orderByDesc('date_journee')->orderBy('caisse_id')->paginate(25)->withQueryString()
                ->through(fn (ClotureCaisse $c) => [
                    'id' => $c->id,
                    'caisse_id' => $c->caisse_id,
                    'caisse' => $c->caisse?->libelle,
                    'date' => $c->date_journee->format('d/m/Y'),
                    'date_iso' => $c->date_journee->toDateString(),
                    'solde_initial' => Format::montant($c->solde_initial),
                    'encaissements' => Format::montant($c->total_encaissements),
                    'decaissements' => Format::montant($c->total_decaissements),
                    'solde_final' => Format::montant($c->solde_final),
                    'ecart' => $c->ecart_controle !== null ? (float) $c->ecart_controle : null,
                    'ecart_format' => $c->ecart_controle !== null ? Format::montant($c->ecart_controle) : '—',
                    'operations' => $c->nombre_operations,
                    'par' => $c->automatique ? 'Clôture automatique' : $c->cloturePar?->nom_complet,
                    'le' => Format::dateHeure($c->cloture_le),
                    'envoye_le' => $c->envoye_le ? Format::dateHeure($c->envoye_le) : null,
                ]),
            'filtres' => ['caisse' => $filtre],
        ]);
    }

    public function store(Request $request)
    {
        $donnees = $request->validate([
            'caisse_id' => ['required', 'integer'],
            'observations' => ['nullable', 'string', 'max:1000'],
        ]);
        $caisse = Caisse::findOrFail($donnees['caisse_id']);
        $cloture = ClotureJournaliere::cloturer(Auth::user(), $caisse, today(), $donnees['observations'] ?? null);

        return back()->with('success', "Journée du {$cloture->date_journee->format('d/m/Y')} clôturée pour la {$caisse->libelle} : solde final "
            . Format::montant($cloture->solde_final) . '. Plus aucune opération n\'est possible sur cette journée.');
    }

    /** Rapport sur une période : PDF ou Excel (une feuille par jour et onglet « Détail ») */
    public function reporting(Request $request, string $format)
    {
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);
        $donnees = $request->validate([
            'caisse' => ['required', 'integer'],
            'du' => ['required', 'date'],
            'au' => ['required', 'date', 'after_or_equal:du'],
        ]);
        $caisse = AccesCaisse::caissesVisibles(Auth::user())->firstWhere('id', (int) $donnees['caisse']);
        abort_unless($caisse, 403);
        $du = Carbon::parse($donnees['du'])->startOfDay();
        $au = Carbon::parse($donnees['au'])->startOfDay()->min(today())->min($du->copy()->addDays(RapportsCaisse::JOURS_MAX - 1));
        $nom = RapportsCaisse::nomFichier($caisse, $du, $au);

        return $format === 'pdf'
            ? RapportsCaisse::pdf($caisse, $du, $au)->stream("{$nom}.pdf")
            : response(RapportsCaisse::excel($caisse, $du, $au), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$nom}.xlsx\"",
            ]);
    }
}
