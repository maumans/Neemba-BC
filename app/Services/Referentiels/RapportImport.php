<?php

namespace App\Services\Referentiels;

/**
 * Compte rendu d'un import des référentiels : ce qui est appliqué, ce qui attend la double validation,
 * ce qui reste « à confirmer » par Neemba, les anomalies et les points à trancher.
 * Rédigé pour être renvoyé tel quel à Neemba (Markdown).
 */
class RapportImport
{
    /** @var array<int, array{onglet: string, element: string, detail: string}> */
    public array $appliques = [];

    /** @var array<int, array{element: string, champ: string, avant: string, apres: string}> */
    public array $doubleValidation = [];

    /** @var array<int, array{onglet: string, ligne: int, element: string, champ: string, valeur: string}> */
    public array $aConfirmer = [];

    /** @var array<int, array{onglet: string, ligne: int, element: string, message: string}> */
    public array $anomalies = [];

    /** @var array<int, array{onglet: string, element: string, point: string}> */
    public array $aTrancher = [];

    /** @var array<int, array{element: string, action: string}> */
    public array $actions = [];

    public function applique(string $onglet, string $element, string $detail): void
    {
        $this->appliques[] = compact('onglet', 'element', 'detail');
    }

    public function enDoubleValidation(string $element, string $champ, ?string $avant, ?string $apres): void
    {
        $this->doubleValidation[] = ['element' => $element, 'champ' => $champ, 'avant' => $avant ?? 'aucun', 'apres' => $apres ?? 'aucun'];
    }

    public function aConfirmer(string $onglet, int $ligne, string $element, string $champ, ?string $valeur): void
    {
        $this->aConfirmer[] = ['onglet' => $onglet, 'ligne' => $ligne, 'element' => $element, 'champ' => $champ, 'valeur' => $valeur ?? '(à compléter)'];
    }

    public function anomalie(string $onglet, int $ligne, string $element, string $message): void
    {
        $this->anomalies[] = compact('onglet', 'ligne', 'element', 'message');
    }

    public function aTrancher(string $onglet, string $element, string $point): void
    {
        $this->aTrancher[] = compact('onglet', 'element', 'point');
    }

    public function action(string $element, string $action): void
    {
        $this->actions[] = compact('element', 'action');
    }

    /** @return array<string, int> */
    public function resume(): array
    {
        return [
            'Appliqué' => count($this->appliques),
            'En double validation' => count($this->doubleValidation),
            'À confirmer par Neemba' => count($this->aConfirmer),
            'Anomalies' => count($this->anomalies),
            'Points à trancher' => count($this->aTrancher),
            "Actions de l'administrateur" => count($this->actions),
        ];
    }

    /**
     * @param  array<string, array{responsable: string, echeance: string}>  $responsables
     */
    public function markdown(string $fichier, bool $applique, array $responsables): string
    {
        $md = ["# Import des référentiels de paramétrage", ''];
        $md[] = "- Fichier : `{$fichier}`";
        $md[] = '- Date : ' . now()->timezone('Africa/Conakry')->format('d/m/Y H:i');
        $md[] = '- Mode : ' . ($applique ? '**appliqué**' : '**simulation** (rien n\'a été enregistré)');
        $md[] = '';
        $md[] = '| Résultat | Nombre |';
        $md[] = '|---|---|';
        foreach ($this->resume() as $libelle => $nombre) {
            $md[] = "| {$libelle} | {$nombre} |";
        }

        $md[] = '';
        $md[] = '## 1. Appliqué';
        $md[] = '';
        $md = array_merge($md, $this->tableau(['Onglet', 'Élément', 'Détail'], $this->appliques, ['onglet', 'element', 'detail']));

        $md[] = '';
        $md[] = '## 2. En attente de double validation';
        $md[] = '';
        $md[] = "Plafonds, seuils et avances de caisse ne changent qu'après approbation par un second administrateur (Paramétrage › Modifications en attente).";
        $md[] = '';
        $md = array_merge($md, $this->tableau(['Caisse', 'Champ', 'Valeur actuelle', 'Nouvelle valeur'], $this->doubleValidation, ['element', 'champ', 'avant', 'apres']));

        $md[] = '';
        $md[] = "## 3. Actions de l'administrateur";
        $md[] = '';
        $md = array_merge($md, $this->tableau(['Élément', 'Action'], $this->actions, ['element', 'action']));

        $md[] = '';
        $md[] = '## 4. Anomalies';
        $md[] = '';
        $md = array_merge($md, $this->tableau(['Onglet', 'Ligne', 'Élément', 'Anomalie'], $this->anomalies, ['onglet', 'ligne', 'element', 'message']));

        $md[] = '';
        $md[] = '## 5. Commentaires du classeur et points à trancher';
        $md[] = '';
        $md = array_merge($md, $this->tableau(['Onglet', 'Élément', 'Point'], $this->aTrancher, ['onglet', 'element', 'point']));

        $md[] = '';
        $md[] = '## 6. Valeurs « à confirmer » non appliquées';
        $md[] = '';
        $parOnglet = [];
        foreach ($this->aConfirmer as $ligne) {
            $parOnglet[$ligne['onglet']][] = $ligne;
        }
        if ($parOnglet === []) {
            $md[] = '_Aucune._';
        }
        foreach ($parOnglet as $onglet => $lignes) {
            $qui = $responsables[$onglet] ?? null;
            $md[] = "### {$onglet}" . ($qui ? " — {$qui['responsable']}, échéance {$qui['echeance']}" : '');
            $md[] = '';
            $md = array_merge($md, $this->tableau(['Ligne', 'Élément', 'Champ', 'Valeur proposée'], $lignes, ['ligne', 'element', 'champ', 'valeur']));
            $md[] = '';
        }

        return implode("\n", $md) . "\n";
    }

    private function tableau(array $entetes, array $lignes, array $cles): array
    {
        if ($lignes === []) {
            return ['_Aucun._'];
        }
        $md = ['| ' . implode(' | ', $entetes) . ' |', '|' . str_repeat('---|', count($entetes))];
        foreach ($lignes as $ligne) {
            $md[] = '| ' . implode(' | ', array_map(fn ($cle) => str_replace(['|', "\n"], ['/', ' '], (string) $ligne[$cle]), $cles)) . ' |';
        }

        return $md;
    }
}
