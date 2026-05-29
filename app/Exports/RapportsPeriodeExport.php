<?php

namespace App\Exports;

use App\Models\BonCaisse;
use App\Models\RapportCaisse;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Export Excel de synthèse des Rapports de Caisse sur une période
 * 
 * Génère un fichier Excel avec :
 * - En-tête avec la période sélectionnée
 * - Résumé financier global de la période
 * - Tableau récapitulatif de chaque rapport
 */
class RapportsPeriodeExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    protected $rapports;
    protected string $dateDebut;
    protected string $dateFin;

    public function __construct($rapports, string $dateDebut, string $dateFin)
    {
        $this->rapports = $rapports;
        $this->dateDebut = $dateDebut;
        $this->dateFin = $dateFin;
    }

    public function title(): string
    {
        return 'Synthèse ' . $this->dateDebut . ' au ' . $this->dateFin;
    }

    public function array(): array
    {
        $rows = [];

        /* ─── EN-TÊTE ─── */
        $rows[] = ['SYNTHÈSE DES RAPPORTS DE CAISSE — NEEMBA'];
        $rows[] = [''];
        $rows[] = ['Période', "Du {$this->dateDebut} au {$this->dateFin}"];
        $rows[] = ['Nombre de rapports', $this->rapports->count()];
        $rows[] = [''];

        /* ─── RÉSUMÉ GLOBAL ─── */
        $rows[] = ['RÉSUMÉ GLOBAL DE LA PÉRIODE'];
        $rows[] = ['Total entrées', $this->formatMontant($this->rapports->sum('total_entrees'))];
        $rows[] = ['Total sorties', $this->formatMontant($this->rapports->sum('total_sorties'))];
        $rows[] = ['Nombre total de bons payés', $this->rapports->sum('nombre_bons')];

        if ($this->rapports->count() > 0) {
            $premier = $this->rapports->sortBy('date_rapport')->first();
            $dernier = $this->rapports->sortByDesc('date_rapport')->first();
            $rows[] = ['Solde ouverture (début période)', $this->formatMontant($premier->solde_ouverture)];
            $rows[] = ['Solde clôture (fin période)', $this->formatMontant($dernier->solde_cloture)];
        }
        $rows[] = [''];

        /* ─── DÉTAIL PAR RAPPORT ─── */
        $rows[] = ['DÉTAIL PAR RAPPORT'];
        $rows[] = ['Date', 'Site', 'Caissier', 'Solde ouverture', 'Entrées', 'Sorties', 'Solde clôture', 'Bons payés', 'Statut'];

        foreach ($this->rapports->sortBy('date_rapport') as $rapport) {
            $rows[] = [
                $rapport->date_rapport->format('d/m/Y'),
                $rapport->site,
                $rapport->caissier ? $rapport->caissier->prenom . ' ' . $rapport->caissier->name : '-',
                $this->formatMontant($rapport->solde_ouverture),
                $this->formatMontant($rapport->total_entrees),
                $this->formatMontant($rapport->total_sorties),
                $this->formatMontant($rapport->solde_cloture),
                $rapport->nombre_bons ?? 0,
                $rapport->cloture ? 'Clôturé' : 'En cours',
            ];
        }

        /* Ligne totale */
        $rows[] = [
            '', '', 'TOTAL',
            '',
            $this->formatMontant($this->rapports->sum('total_entrees')),
            $this->formatMontant($this->rapports->sum('total_sorties')),
            '',
            $this->rapports->sum('nombre_bons'),
            '',
        ];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:I1');

        return [
            1 => [
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    protected function formatMontant($montant): string
    {
        return number_format((float) $montant, 0, ',', ' ');
    }
}
