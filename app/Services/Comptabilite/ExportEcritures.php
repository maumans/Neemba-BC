<?php

namespace App\Services\Comptabilite;

use App\Exceptions\ErreurMetier;
use App\Models\ExportComptable;
use App\Models\Imputation;
use App\Models\LigneImputation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Export des écritures comptables (M16, RG-M16-03) : Excel ou CSV générique, une ligne par ligne d'imputation —
 * date, n° de bon, compte, libellé, montant, code journal, code analytique, payeur, client, OR. Aucun format d'import Helium (ERP de Neemba).
 * Les imputations exportées sont figées (RG-M16-06) ; un export reste téléchargeable.
 */
final class ExportEcritures
{
    /** Spec v2.4, RG-M16-03 : site analytique et entité en plus */
    public const COLONNES = ['Date', 'N° de bon', 'Compte', 'Libellé', 'Montant (GNF)', 'Code journal', 'Code analytique', 'Site analytique', 'Entité',
        'Payeur', 'Client', 'OR'];

    /** Imputations validées, non exportées, des bons payés sur la période */
    public static function aExporter(string $du, string $au): Builder
    {
        return Imputation::where('statut', 'validee')
            ->whereHas('bonCaisse', fn ($q) => $q->whereDate('date_paiement', '>=', $du)->whereDate('date_paiement', '<=', $au));
    }

    public static function exporter(string $du, string $au, User $auteur): ExportComptable
    {
        return DB::transaction(function () use ($du, $au, $auteur) {
            $imputations = self::aExporter($du, $au)->lockForUpdate()->with('lignes')->get();
            if ($imputations->isEmpty()) {
                throw new ErreurMetier('RIEN_A_EXPORTER', 'MSG-APP-052', [], 'RG-M16-03', null, 409);
            }
            $lignes = $imputations->flatMap->lignes;
            $export = ExportComptable::create([
                'date_debut' => $du, 'date_fin' => $au, 'nombre_ecritures' => $lignes->count(),
                'montant_total' => $lignes->sum('montant'), 'exporte_par_id' => $auteur->id,
            ]);
            Imputation::whereIn('id', $imputations->pluck('id'))->update(['statut' => 'exportee', 'export_comptable_id' => $export->id]);

            return $export;
        });
    }

    /** Lignes du fichier (en-tête compris) */
    public static function lignes(ExportComptable $export): array
    {
        $imputations = $export->imputations()->with(['bonCaisse.entite', 'lignes.client'])->get()
            ->sortBy(fn (Imputation $i) => [$i->bonCaisse->date_paiement, $i->bonCaisse->numero]);

        return array_merge([self::COLONNES], $imputations->flatMap(fn (Imputation $i) => $i->lignes->map(fn (LigneImputation $l) => [
            Carbon::parse($i->bonCaisse->date_paiement)->format('d/m/Y'),
            $i->bonCaisse->numero,
            $l->compte,
            $l->libelle,
            (float) $l->montant,
            $i->code_journal,
            $l->code_analytique,
            $i->bonCaisse->site ?? '',
            $i->bonCaisse->entite?->code ?? '',
            ['neemba' => 'Neemba', 'client' => 'Client'][$l->payeur] ?? '',
            $l->client?->code ?? '',
            $l->ordre_reparation ?? '',
        ]))->values()->all());
    }

    public static function nomFichier(ExportComptable $export, string $format): string
    {
        $du = $export->date_debut->format('Y-m-d');
        $au = $export->date_fin->format('Y-m-d');

        return 'ecritures-comptables-' . ($du === $au ? $du : "{$du}-au-{$au}") . "-n{$export->id}.{$format}";
    }
}
