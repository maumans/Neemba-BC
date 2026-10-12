<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Écritures comptables (M16, RG-M16-03), en Excel ou en CSV générique : séparateur point-virgule et BOM UTF-8,
 * pour une ouverture directe dans Excel en français.
 */
class EcrituresComptablesExport implements FromArray, ShouldAutoSize, WithCustomCsvSettings, WithStyles, WithTitle
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
        return 'Écritures';
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'use_bom' => true];
    }

    public function styles(Worksheet $feuille): array
    {
        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FDC911']]]];
    }
}
