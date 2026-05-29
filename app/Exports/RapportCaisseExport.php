<?php

namespace App\Exports;

use App\Models\BonCaisse;
use App\Models\RapportCaisse;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Export Excel du Rapport Journalier de Caisse
 * 
 * Génère un fichier Excel structuré avec :
 * - En-tête avec date, site, caissier
 * - Résumé financier (solde ouverture, entrées, sorties, clôture)
 * - Détail des bons payés
 * - Ventilation par catégorie
 * - Ventilation par mode de paiement
 */
class RapportCaisseExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    protected RapportCaisse $rapport;
    protected $bonsPaye;

    public function __construct(RapportCaisse $rapport, $bonsPaye)
    {
        $this->rapport = $rapport;
        $this->bonsPaye = $bonsPaye;
    }

    public function title(): string
    {
        return 'Rapport ' . $this->rapport->date_rapport->format('d-m-Y');
    }

    public function array(): array
    {
        $rows = [];

        /* ─── EN-TÊTE ─── */
        $rows[] = ['RAPPORT JOURNALIER DE CAISSE — NEEMBA'];
        $rows[] = [''];
        $rows[] = ['Date', $this->rapport->date_rapport->format('d/m/Y')];
        $rows[] = ['Site', $this->rapport->site];
        $rows[] = ['Caissier', $this->rapport->caissier ? $this->rapport->caissier->prenom . ' ' . $this->rapport->caissier->name : '-'];
        $rows[] = ['Statut', $this->rapport->visa_daf_id ? 'Visé DAF' : 'En cours'];
        if ($this->rapport->visaDaf) {
            $rows[] = ['Visa DAF', $this->rapport->visaDaf->prenom . ' ' . $this->rapport->visaDaf->name . ' — ' . $this->rapport->date_visa_daf->format('d/m/Y H:i')];
        }
        $rows[] = [''];

        /* ─── RÉSUMÉ FINANCIER ─── */
        $rows[] = ['RÉSUMÉ FINANCIER'];
        $rows[] = ['Solde d\'ouverture', $this->formatMontant($this->rapport->solde_ouverture)];
        $rows[] = ['Total entrées', $this->formatMontant($this->rapport->total_entrees)];
        $rows[] = ['Total sorties', $this->formatMontant($this->rapport->total_sorties)];
        $rows[] = ['Solde fin de journée', $this->formatMontant($this->rapport->solde_cloture)];
        $rows[] = ['Nombre de bons payés', $this->rapport->nombre_bons ?? $this->bonsPaye->count()];
        $rows[] = [''];

        /* ─── VENTILATION PAR CATÉGORIE ─── */
        if (!empty($this->rapport->detail_par_categorie)) {
            $rows[] = ['VENTILATION PAR CATÉGORIE'];
            $rows[] = ['Catégorie', 'Nombre', 'Montant (GNF)'];
            foreach ($this->rapport->detail_par_categorie as $item) {
                $rows[] = [$item['label'] ?? $item['categorie'], $item['nombre'], $this->formatMontant($item['montant'])];
            }
            $rows[] = [''];
        }

        /* ─── VENTILATION PAR MODE DE PAIEMENT ─── */
        if (!empty($this->rapport->detail_par_mode_paiement)) {
            $rows[] = ['VENTILATION PAR MODE DE PAIEMENT'];
            $rows[] = ['Mode', 'Nombre', 'Montant (GNF)'];
            foreach ($this->rapport->detail_par_mode_paiement as $item) {
                $rows[] = [$item['label'] ?? $item['mode'], $item['nombre'], $this->formatMontant($item['montant'])];
            }
            $rows[] = [''];
        }

        /* ─── DÉTAIL DES BONS PAYÉS ─── */
        if ($this->bonsPaye->count() > 0) {
            $rows[] = ['DÉTAIL DES BONS PAYÉS'];
            $rows[] = ['N° Bon', 'Type', 'Bénéficiaire', 'Motif', 'Catégorie', 'Mode paiement', 'Montant (GNF)'];
            foreach ($this->bonsPaye as $bon) {
                $rows[] = [
                    $bon->numero,
                    $bon->type_bon,
                    $bon->beneficiaire,
                    mb_substr($bon->motif, 0, 60),
                    BonCaisse::CATEGORIES_DEPENSE[$bon->categorie_depense] ?? $bon->categorie_depense,
                    BonCaisse::MODES_PAIEMENT[$bon->mode_paiement_effectif ?? $bon->mode_paiement] ?? ($bon->mode_paiement_effectif ?? $bon->mode_paiement),
                    $this->formatMontant($bon->montant),
                ];
            }
            $rows[] = ['', '', '', '', '', 'TOTAL', $this->formatMontant($this->bonsPaye->sum('montant'))];
        }

        /* ─── OBSERVATIONS ─── */
        if ($this->rapport->observations) {
            $rows[] = [''];
            $rows[] = ['OBSERVATIONS'];
            $rows[] = [$this->rapport->observations];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        /* Titre principal */
        $sheet->mergeCells('A1:G1');

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
