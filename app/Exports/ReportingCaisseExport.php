<?php

namespace App\Exports;

use App\Services\BonCaisse\CircuitBon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Classeur « REPORTING CAISSE » (spec v2.4, RG-M09-01, RG-M09-02, RG-M09-05) : une feuille par jour et par caisse au
 * format de Neemba, puis l'onglet « Détail » des bons décaissés.
 *
 * @param array<int, array> $journees résultats de ReportingCaisse::journee()
 */
class ReportingCaisseExport implements WithMultipleSheets
{
    public function __construct(private array $journees, private array $societe)
    {
    }

    public function sheets(): array
    {
        $feuilles = array_map(fn (array $j) => new ReportingCaisseFeuille($j, $this->societe), $this->journees);
        $feuilles[] = new ReportingCaisseDetail($this->journees);

        return $feuilles;
    }
}

class ReportingCaisseFeuille implements FromArray, WithTitle, WithStyles, ShouldAutoSize, WithStrictNullComparison
{
    private array $gras = [];
    private ?int $ligneEcart = null;

    public function __construct(private array $j, private array $societe)
    {
    }

    public function title(): string
    {
        /* 31 caractères au plus, sans « / » */
        return mb_substr(str_replace('/', '-', $this->j['date']) . ' ' . $this->j['code'], 0, 31);
    }

    public function array(): array
    {
        $l = [];
        $ajouter = function (array $ligne, bool $gras = false) use (&$l) {
            $l[] = $ligne;
            if ($gras) {
                $this->gras[] = count($l);
            }
        };
        $ajouter(['REPORTING CAISSE'], true);
        $ajouter(['Société', $this->societe['nom']]);
        $ajouter(['Adresse', $this->societe['adresse']]);
        $ajouter(['Période', $this->j['date']]);
        $ajouter(['Caisse', $this->j['caisse']]);
        $ajouter([]);
        $ajouter(['CONTRÔLE'], true);
        $ajouter(['Solde final de la veille' . ($this->j['date_veille'] ? " ({$this->j['date_veille']})" : ''), $this->j['solde_veille'] ?? 'Aucune clôture précédente']);
        $ajouter(['Solde initial du jour', $this->j['solde_initial']]);
        $ajouter(['Écart', $this->j['ecart'] ?? '—'], true);
        $this->ligneEcart = count($l);
        $ajouter([]);
        $ajouter(['SYNTHÈSE'], true);
        $ajouter(['Solde initial', $this->j['solde_initial']]);
        $ajouter(['+ Encaissements', $this->j['total_encaissements']]);
        $ajouter(['− Décaissements', $this->j['total_decaissements']]);
        $ajouter(['Solde final', $this->j['solde_final']], true);
        $ajouter([]);
        $ajouter(['ENCAISSEMENTS'], true);
        $ajouter(['Date', 'N° pièce', 'Libellé', 'Montant (GNF)'], true);
        foreach ($this->j['encaissements'] as $e) {
            $ajouter([$e['date'], $e['piece'], $e['libelle'], $e['montant']]);
        }
        $ajouter(['Total encaissements', '', '', $this->j['total_encaissements']], true);
        $ajouter([]);
        $ajouter(['DÉCAISSEMENTS'], true);
        $ajouter(['Date', 'Libellé', 'N° pièce', 'Montant (GNF)'], true);
        foreach ($this->j['decaissements'] as $d) {
            $ajouter([$d['date'], $d['libelle'], $d['piece'], $d['montant']]);
        }
        $ajouter(['Total décaissements', '', '', $this->j['total_decaissements']], true);

        return $l;
    }

    public function styles(Worksheet $feuille): array
    {
        $styles = [1 => ['font' => ['bold' => true, 'size' => 14]]];
        foreach ($this->gras as $ligne) {
            $styles[$ligne] ??= ['font' => ['bold' => true]];
        }
        $feuille->getStyle('B1:D400')->getNumberFormat()->setFormatCode('#,##0');
        /* RG-M09-02 : écart en rouge s'il n'est pas nul */
        if ($this->ligneEcart && $this->j['ecart'] !== null && abs($this->j['ecart']) > 0.004) {
            $styles[$this->ligneEcart] = ['font' => ['bold' => true, 'color' => ['rgb' => 'B91C1C']]];
        }

        return $styles;
    }
}

class ReportingCaisseDetail implements FromArray, WithTitle, WithStyles, ShouldAutoSize, WithStrictNullComparison
{
    public function __construct(private array $journees)
    {
    }

    public function title(): string
    {
        return 'Détail';
    }

    public function array(): array
    {
        $entete = ['Date', 'Caisse', 'N° de bon', 'Bénéficiaire', 'Service', 'Mode de paiement', 'Montant versé (GNF)'];
        foreach (CircuitBon::LIBELLES as $libelle) {
            $entete[] = "{$libelle} : valideur";
            $entete[] = "{$libelle} : délai (h)";
        }
        $lignes = [$entete];
        foreach ($this->journees as $j) {
            foreach ($j['detail'] as $b) {
                $ligne = [$j['date'], $j['caisse'], $b['numero'], $b['beneficiaire'], $b['service'], $b['mode'], $b['montant']];
                foreach (array_keys(CircuitBon::LIBELLES) as $role) {
                    $ligne[] = $b['etapes'][$role]['valideur'] ?? null;
                    $ligne[] = $b['etapes'][$role]['delai'] ?? null;
                }
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    public function styles(Worksheet $feuille): array
    {
        $feuille->getStyle('G2:G2000')->getNumberFormat()->setFormatCode('#,##0');

        return [1 => ['font' => ['bold' => true]]];
    }
}
