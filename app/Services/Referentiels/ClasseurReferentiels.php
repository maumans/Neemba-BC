<?php

namespace App\Services\Referentiels;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lecture du classeur « Référentiels de paramétrage — tous sites » préparé avec Neemba (points 11 à 14) :
 * onglets « 1-Utilisateurs », « 2-Codes analytiques », « 3-Services », « 4-Caisses », « 5-Valideurs »
 * et « Mode d'emploi » (responsable et échéance de chaque onglet).
 *
 * La ligne d'en-tête est repérée dans chaque onglet (première ligne d'au moins trois cellules remplies) et
 * les colonnes sont retrouvées par le début de leur libellé : le classeur peut gagner des lignes ou des
 * colonnes d'une version à l'autre sans casser l'import.
 */
class ClasseurReferentiels
{
    private Spreadsheet $classeur;

    public readonly string $nomFichier;

    public function __construct(string $chemin)
    {
        $lecteur = IOFactory::createReaderForFile($chemin);
        $lecteur->setReadDataOnly(true);
        $this->classeur = $lecteur->load($chemin);
        $this->nomFichier = basename($chemin);
    }

    /**
     * Lignes d'un onglet, sous la ligne d'en-tête.
     *
     * @return array<int, array<string, string>> numéro de ligne Excel => [libellé de colonne => valeur]
     */
    public function lignes(string $prefixe): array
    {
        $feuille = $this->feuille($prefixe);
        if (!$feuille) {
            return [];
        }

        $brut = $feuille->toArray(null, true, false, false);
        $entetes = null;
        $resultat = [];

        foreach ($brut as $index => $cellules) {
            $cellules = array_map(fn ($valeur) => self::texte($valeur), $cellules);
            if ($entetes === null) {
                if (count(array_filter($cellules, fn ($valeur) => $valeur !== '')) >= 3) {
                    $entetes = $cellules;
                }
                continue;
            }

            $ligne = [];
            foreach ($entetes as $colonne => $entete) {
                if ($entete !== '') {
                    $ligne[$entete] = $cellules[$colonne] ?? '';
                }
            }
            if (array_filter($ligne, fn ($valeur) => $valeur !== '') !== []) {
                $resultat[$index + 1] = $ligne;
            }
        }

        return $resultat;
    }

    /**
     * Responsable et échéance de chaque onglet (« Mode d'emploi »).
     *
     * @return array<string, array{responsable: string, echeance: string}>
     */
    public function responsables(): array
    {
        $responsables = [];
        foreach ($this->lignes('Mode') as $ligne) {
            $onglet = self::colonne($ligne, 'Onglet');
            if ($onglet !== '') {
                $responsables[$onglet] = [
                    'responsable' => self::colonne($ligne, 'Responsable'),
                    'echeance' => self::colonne($ligne, 'Échéance'),
                ];
            }
        }

        return $responsables;
    }

    /** Titre complet de l'onglet qui commence par $prefixe (« 1- » → « 1-Utilisateurs ») */
    public function titre(string $prefixe): ?string
    {
        return $this->feuille($prefixe)?->getTitle();
    }

    /** Valeur de la colonne dont le libellé commence par $debut (sans tenir compte des accents ni de la casse) */
    public static function colonne(array $ligne, string $debut): string
    {
        $cle = Normalisation::cle($debut);
        foreach ($ligne as $entete => $valeur) {
            if (str_starts_with(Normalisation::cle($entete), $cle)) {
                return $valeur;
            }
        }

        return '';
    }

    private function feuille(string $prefixe): ?Worksheet
    {
        foreach ($this->classeur->getWorksheetIterator() as $feuille) {
            if (str_starts_with(Normalisation::cle($feuille->getTitle()), Normalisation::cle($prefixe))) {
                return $feuille;
            }
        }

        return null;
    }

    private static function texte(mixed $valeur): string
    {
        if ($valeur === null) {
            return '';
        }
        if (is_float($valeur) && floor($valeur) == $valeur) {
            $valeur = (string) (int) $valeur;
        }

        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $valeur));
    }
}
