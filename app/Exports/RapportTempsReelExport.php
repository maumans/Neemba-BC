<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Export Excel des Rapports de Caisse calculés en temps réel
 * 
 * Génère un fichier Excel à partir des données calculées dynamiquement
 * depuis la table bons_caisse (pas depuis rapports_caisse).
 * 
 * Structure : En-tête → Résumé (bons, entrées, sorties, solde) → Détail par période → Total
 */
class RapportTempsReelExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    protected array $lignes;
    protected array $statsResume;
    protected string $dateDebut;
    protected string $dateFin;
    protected string $granularite;
    protected ?string $site;

    public function __construct(
        array $lignes,
        array $statsResume,
        string $dateDebut,
        string $dateFin,
        string $granularite = 'jour',
        ?string $site = null,
    ) {
        $this->lignes = $lignes;
        $this->statsResume = $statsResume;
        $this->dateDebut = $dateDebut;
        $this->dateFin = $dateFin;
        $this->granularite = $granularite;
        $this->site = $site;
    }

    public function title(): string
    {
        return 'Rapport ' . $this->dateDebut . ' au ' . $this->dateFin;
    }

    public function array(): array
    {
        $rows = [];

        /* ─── EN-TÊTE ─── */
        $rows[] = ['RAPPORT DE CAISSE — NEEMBA'];
        $rows[] = [''];
        $rows[] = ['Période', "Du {$this->dateDebut} au {$this->dateFin}"];
        if ($this->site) {
            $rows[] = ['Site', $this->site];
        }
        $rows[] = ['Édité le', now()->format('d/m/Y à H:i')];
        $rows[] = [''];

        /* ─── RÉSUMÉ ─── */
        $rows[] = ['RÉSUMÉ DE LA PÉRIODE'];
        $rows[] = ['Bons payés', $this->statsResume['total_bons'] ?? 0];
        $rows[] = ['Total entrées (approvisionnements)', $this->fmt($this->statsResume['total_entrees'] ?? 0) . ' GNF'];
        $rows[] = ['Total sorties (décaissements)', $this->fmt($this->statsResume['total_sorties'] ?? 0) . ' GNF'];
        $rows[] = ['Solde de clôture', $this->fmt($this->statsResume['solde_actuel'] ?? 0) . ' GNF'];
        $rows[] = [''];

        /* ─── DÉTAIL PAR PÉRIODE ─── */
        $rows[] = ['DÉTAIL ' . strtoupper($this->granularite === 'jour' ? 'JOURNALIER' : ($this->granularite === 'mois' ? 'MENSUEL' : 'ANNUEL'))];

        /* En-tête du tableau */
        $header = ['Période'];
        if ($this->granularite === 'jour') {
            $header[] = 'Jour';
        }
        $header = array_merge($header, ['Bons', 'Entrées (GNF)', 'Sorties (GNF)', 'Solde (GNF)']);
        $rows[] = $header;

        /* Lignes de données */
        foreach ($this->lignes as $ligne) {
            $row = [$ligne['label'] ?? $ligne['periode']];
            if ($this->granularite === 'jour') {
                $row[] = $ligne['jour_semaine'] ?? '';
            }
            $row[] = $ligne['nombre_bons'] ?? 0;
            $row[] = $this->fmt($ligne['entrees'] ?? 0);
            $row[] = $this->fmt($ligne['sorties'] ?? 0);
            $row[] = $this->fmt($ligne['solde'] ?? 0);
            $rows[] = $row;
        }

        /* Ligne total */
        $totalRow = ['TOTAL'];
        if ($this->granularite === 'jour') {
            $totalRow[] = '';
        }
        $totalRow[] = $this->statsResume['total_bons'] ?? 0;
        $totalRow[] = $this->fmt($this->statsResume['total_entrees'] ?? 0);
        $totalRow[] = $this->fmt($this->statsResume['total_sorties'] ?? 0);
        $totalRow[] = $this->fmt($this->statsResume['solde_actuel'] ?? 0);
        $rows[] = $totalRow;

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $nbCols = $this->granularite === 'jour' ? 'F' : 'E';
        $sheet->mergeCells("A1:{$nbCols}1");

        return [
            1 => [
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    protected function fmt($montant): string
    {
        return number_format((float) $montant, 0, ',', ' ');
    }
}
