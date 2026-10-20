<?php

namespace App\Services\Caisse;

use App\Exports\ReportingCaisseExport;
use App\Models\Caisse;
use App\Models\ClotureCaisse;
use App\Services\BonCaisse\CircuitBon;
use App\Support\Format;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Fichiers du rapport « REPORTING CAISSE » (PDF, Excel) pour une caisse et une période, et envoi quotidien
 * (spec v2.4, RG-M09-01 à RG-M09-05).
 */
final class RapportsCaisse
{
    /** Au plus 62 journées par fichier */
    public const JOURS_MAX = 62;

    /** @return array<int, array> une journée par jour de la période */
    public static function journees(Caisse $caisse, CarbonInterface $du, CarbonInterface $au): array
    {
        $journees = [];
        for ($j = Carbon::instance($du)->startOfDay(); $j->lte($au) && count($journees) < self::JOURS_MAX; $j->addDay()) {
            $journees[] = ReportingCaisse::journee($caisse, $j);
        }

        return $journees;
    }

    /** Société et adresse de l'entité de la caisse (en-tête du rapport) */
    public static function societe(Caisse $caisse): array
    {
        $entite = $caisse->entite()->first();

        return [
            'nom' => $entite?->raison_sociale ?: ($entite?->nom ?? 'NEEMBA GUINEE'),
            'adresse' => $entite ? implode(', ', $entite->lignesAdresse()) : '',
        ];
    }

    public static function pdf(Caisse $caisse, CarbonInterface $du, CarbonInterface $au): \Barryvdh\DomPDF\PDF
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.reporting-caisse-pdf', [
            'journees' => self::journees($caisse, $du, $au),
            'societe' => self::societe($caisse),
            'etapes' => CircuitBon::LIBELLES,
            'edite_le' => Format::dateHeure(now()),
        ])->setPaper('a4', 'portrait');
    }

    public static function excel(Caisse $caisse, CarbonInterface $du, CarbonInterface $au): string
    {
        return Excel::raw(new ReportingCaisseExport(self::journees($caisse, $du, $au), self::societe($caisse)), \Maatwebsite\Excel\Excel::XLSX);
    }

    public static function nomFichier(Caisse $caisse, CarbonInterface $du, CarbonInterface $au): string
    {
        return 'reporting-caisse-' . $caisse->code . '-' . $du->format('Y-m-d') . ($au->isSameDay($du) ? '' : '-au-' . $au->format('Y-m-d'));
    }

    /**
     * RG-M09-04 : envoi des journées closes de la date donnée, pas encore envoyées. Une journée sans opération n'est pas
     * envoyée (Q100).
     *
     * @return array{envoyes: int, sans_operation: int, sans_destinataire: int}
     */
    public static function envoyer(CarbonInterface $jour, ?int $caisseId = null): array
    {
        $compte = ['envoyes' => 0, 'sans_operation' => 0, 'sans_destinataire' => 0];
        $clotures = ClotureCaisse::withoutGlobalScope('entite')->with('caisse')
            ->whereDate('date_journee', $jour->toDateString())->whereNull('envoye_le')
            ->when($caisseId, fn ($q) => $q->where('caisse_id', $caisseId))->get();
        foreach ($clotures as $cloture) {
            if ($cloture->nombre_operations === 0) {
                $compte['sans_operation']++;
                continue;
            }
            $destinataires = ClotureJournaliere::destinataires($cloture->caisse);
            if ($destinataires->isEmpty()) {
                $compte['sans_destinataire']++;
                continue;
            }
            Mail::to($destinataires->pluck('email')->all())->send(new \App\Mail\ReportingCaisseQuotidien($cloture));
            $cloture->update(['envoye_le' => now(), 'destinataires' => $destinataires->map(fn ($u) => $u->nom_complet)->values()->all()]);
            $compte['envoyes']++;
        }

        return $compte;
    }
}
