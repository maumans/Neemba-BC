<?php

namespace App\Services\Caisse;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\ClotureCaisse;
use App\Models\EcritureCaisse;
use App\Models\Validation;
use App\Services\BonCaisse\CircuitBon;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Rapport « REPORTING CAISSE » d'une caisse pour une journée (spec v2.4, RG-M09-01, RG-M09-02, RG-M09-05), lu dans le
 * registre de la caisse.
 *
 * - En-tête : société et adresse de l'entité, période, caisse.
 * - Contrôle : solde final de la veille (clôture précédente), solde initial du jour, ligne « Écart ».
 * - Synthèse : solde initial, + encaissements, − décaissements, solde final.
 * - Encaissements (date, n° pièce, libellé, montant) et décaissements (date, libellé, n° pièce, montant). Pour un bon :
 *   libellé = motif du bon, n° de pièce = n° du bon.
 * - Onglet « Détail » : bons décaissés avec bénéficiaire, service, mode de paiement, valideurs et délai par étape.
 *
 * Une reprise de solde (« solde initial ») du jour fait partie du solde initial, pas des flux.
 */
final class ReportingCaisse
{
    public static function journee(Caisse $caisse, CarbonInterface $jour): array
    {
        $debut = Carbon::instance($jour)->startOfDay();
        $fin = $debut->copy()->endOfDay();
        $ecritures = EcritureCaisse::with('bonCaisse.validations.validateur')
            ->where('caisse_id', $caisse->id)->whereBetween('date_ecriture', [$debut, $fin])
            ->orderBy('date_ecriture')->orderBy('id')->get();

        $soldeInitial = (float) ($caisse->soldeAu($debut) ?? 0.0);
        $encaissements = [];
        $decaissements = [];
        foreach ($ecritures as $e) {
            if ($e->nature === 'solde_initial') {
                $soldeInitial += $e->sens === 'entree' ? (float) $e->montant : -(float) $e->montant;
                continue;
            }
            $ligne = [
                'date' => $e->date_ecriture->format('d/m/Y H:i'),
                'piece' => $e->bonCaisse?->numero ?? $e->piece ?? '—',
                'libelle' => $e->bonCaisse?->motif ?: ($e->libelle ?: (EcritureCaisse::NATURES[$e->nature] ?? $e->nature)),
                'nature' => EcritureCaisse::NATURES[$e->nature] ?? $e->nature,
                'montant' => (float) $e->montant,
            ];
            $e->sens === 'entree' ? $encaissements[] = $ligne : $decaissements[] = $ligne;
        }
        $totalEncaissements = array_sum(array_column($encaissements, 'montant'));
        $totalDecaissements = array_sum(array_column($decaissements, 'montant'));

        $veille = ClotureCaisse::withoutGlobalScope('entite')->where('caisse_id', $caisse->id)
            ->whereDate('date_journee', '<', $debut->toDateString())->orderByDesc('date_journee')->first();
        $soldeVeille = $veille ? (float) $veille->solde_final : null;

        $bons = $ecritures->where('nature', 'paiement_bon')->pluck('bonCaisse')->filter()->unique('id');

        return [
            'caisse' => $caisse->libelle,
            'code' => $caisse->code,
            'type' => $caisse->type,
            'implantation' => $caisse->sites_libelle,
            'date' => $debut->format('d/m/Y'),
            'date_iso' => $debut->toDateString(),
            'solde_veille' => $soldeVeille,
            'date_veille' => $veille?->date_journee?->format('d/m/Y'),
            'solde_initial' => round($soldeInitial, 2),
            'ecart' => $soldeVeille !== null ? round($soldeInitial - $soldeVeille, 2) : null,
            'total_encaissements' => round($totalEncaissements, 2),
            'total_decaissements' => round($totalDecaissements, 2),
            'solde_final' => round($soldeInitial + $totalEncaissements - $totalDecaissements, 2),
            'encaissements' => $encaissements,
            'decaissements' => $decaissements,
            'nombre_operations' => count($encaissements) + count($decaissements),
            'detail' => $bons->map(fn (BonCaisse $b) => self::detailBon($b))->values()->all(),
        ];
    }

    /** RG-M09-05 : bénéficiaire, service, mode de paiement, valideurs et délai par étape */
    private static function detailBon(BonCaisse $bon): array
    {
        $version = (int) ($bon->version ?? 1);
        $etapes = [];
        foreach (array_keys(CircuitBon::LIBELLES) as $role) {
            /** @var Validation|null $v */
            $v = $bon->validations->first(fn (Validation $x) => $x->role === $role && (int) ($x->version ?? 1) === $version && $x->statut !== 'saute');
            $etapes[$role] = $v && $v->date_validation ? [
                'valideur' => $v->validateur?->nom_complet,
                'delai' => $v->date_attribution ? round($v->date_attribution->diffInMinutes($v->date_validation) / 60, 1) : null,
            ] : null;
        }
        $mode = $bon->mode_paiement_effectif ?? $bon->mode_paiement;

        return [
            'numero' => $bon->numero,
            'beneficiaire' => $bon->beneficiaire,
            'service' => $bon->service,
            'mode' => BonCaisse::MODES_PAIEMENT[$mode] ?? $mode,
            'montant' => (float) ($bon->montant_verse ?? $bon->montant),
            'etapes' => $etapes,
        ];
    }
}
