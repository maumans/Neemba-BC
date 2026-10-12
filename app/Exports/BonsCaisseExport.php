<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Export Excel des bons au format opérationnel du client (M14) ; une ligne par part pour les bons d'ODM */
class BonsCaisseExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(private array $lignes)
    {
    }

    public function array(): array
    {
        return $this->lignes;
    }

    public function title(): string
    {
        return 'Bons de caisse';
    }

    public function styles(Worksheet $feuille): array
    {
        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FDC911']]]];
    }
}
