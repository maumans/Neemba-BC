<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Excel du tableau de bord des ordres de mission (US-14) : un onglet par liste
 * (missions en cours, dérogations au chevauchement, ODM à refacturer).
 */
class TableauBordOdmExport implements WithMultipleSheets
{
    public function __construct(private array $donnees)
    {
    }

    public function sheets(): array
    {
        return [
            self::onglet('Missions en cours',
                ['Mission', 'Dernier segment', 'Segments', 'Service', 'Destinations', 'Participants', 'Début', 'Fin prévue', 'Jours', 'Coût (GNF)', 'Statut', 'Incohérences'],
                array_map(fn ($m) => [$m['numero'], $m['dernier_segment'], $m['segments'], $m['service'], $m['destinations'], $m['participants'],
                    $m['debut'], $m['fin_prevue'], $m['jours'], $m['cout'], $m['statut_label'], $m['incoherences']], $this->donnees['missionsEnCours'])),
            self::onglet('Dérogations',
                ['ODM', 'Période', 'Demandeur', 'Statut', 'Motif', 'Décidée par', 'Le'],
                array_map(fn ($d) => [$d['libelle'], $d['periode'], $d['demandeur'], $d['statut'], $d['motif'], $d['par'], $d['le']], $this->donnees['derogations'])),
            self::onglet('À refacturer',
                ['ODM', 'Client(s)', 'OR', 'Période', 'À refacturer (GNF)', 'Par client', 'Coût de la mission (GNF)', 'Statut'],
                array_map(fn ($r) => [$r['libelle'], $r['clients'], $r['or'], $r['periode'], $r['montant'], $r['par_client'], $r['total'], $r['statut_label']], $this->donnees['aRefacturer'])),
            /* Spec v2.3 (M14) : une ligne par part à la charge d'un client */
            self::onglet('Frais à refacturer',
                ['ODM', 'Participant', 'Segment', 'Élément', 'Client', 'OR', 'Code analytique', 'Montant (GNF)', 'Frais OM de la part (GNF)', 'Date de paiement'],
                array_map(fn ($l) => [$l['odm'], $l['participant'], $l['segment'], $l['element'], $l['client'], $l['or'], $l['code_analytique'], $l['montant'], $l['frais_om'], $l['date_paiement']],
                    $this->donnees['fraisARefacturer'] ?? \App\Services\Odm\TableauBordOdm::partsClient()->all())),
            /* Spec v2.4, RG-M12-41 : rapport « OR à régulariser » */
            self::onglet('OR à régulariser',
                ['ODM', 'Demandeur', "Chef d'atelier", 'Validation', 'Échéance', 'Retard (jours)', 'Statut'],
                array_map(fn ($o) => [$o['libelle'], $o['demandeur'], $o['chef_atelier'], $o['validation'], $o['echeance'], $o['retard'], $o['statut_label']],
                    $this->donnees['orARegulariser'] ?? \App\Services\Odm\TableauBordOdm::orARegulariser())),
        ];
    }

    private static function onglet(string $titre, array $entetes, array $lignes): object
    {
        return new class($titre, $entetes, $lignes) implements FromArray, ShouldAutoSize, WithStyles, WithTitle {
            public function __construct(private string $titre, private array $entetes, private array $lignes)
            {
            }

            public function array(): array
            {
                return array_merge([$this->entetes], $this->lignes);
            }

            public function title(): string
            {
                return $this->titre;
            }

            public function styles(Worksheet $feuille): array
            {
                return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FDC911']]]];
            }
        };
    }
}
