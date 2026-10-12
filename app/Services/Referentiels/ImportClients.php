<?php

namespace App\Services\Referentiels;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import du référentiel clients (spec v2.3, §6.5, PO-26) depuis un fichier Excel ou CSV : colonnes « code »
 * et « raison sociale » (ou « nom », « client », « libellé »). Le code client sert de clé.
 *
 * Toujours en deux temps : un aperçu (rien n'est écrit), puis l'application du même fichier.
 * - code inconnu : client créé, actif ;
 * - code connu : raison sociale mise à jour si elle diffère (un client désactivé le reste) ;
 * - ligne sans code ou sans raison sociale, code trop long, code en double dans le fichier : ignorée, avec le motif.
 */
final class ImportClients
{
    /**
     * @return array{resume: array{crees: int, mis_a_jour: int, inchanges: int, ignores: int}, lignes: array, applique: bool}
     */
    public static function executer(string $chemin, string $extension, User $auteur, bool $appliquer): array
    {
        $lignes = self::analyser(self::lire($chemin, $extension));

        if ($appliquer) {
            DB::transaction(function () use ($lignes, $auteur) {
                foreach ($lignes as $ligne) {
                    if ($ligne['action'] === 'cree') {
                        Client::create(['code' => $ligne['code'], 'raison_sociale' => $ligne['raison_sociale'], 'actif' => true, 'cree_par_id' => $auteur->id]);
                    } elseif ($ligne['action'] === 'mis_a_jour') {
                        Client::where('code', $ligne['code'])->update(['raison_sociale' => $ligne['raison_sociale']]);
                    }
                }
            });
        }

        $compter = fn (string $action) => count(array_filter($lignes, fn ($l) => $l['action'] === $action));

        return [
            'resume' => ['crees' => $compter('cree'), 'mis_a_jour' => $compter('mis_a_jour'), 'inchanges' => $compter('inchange'), 'ignores' => $compter('ignore')],
            'lignes' => $lignes,
            'applique' => $appliquer,
        ];
    }

    /** Lignes du fichier (tableaux de cellules), sans les lignes vides */
    public static function lire(string $chemin, string $extension): array
    {
        if (in_array(Str::lower($extension), ['csv', 'txt'], true)) {
            $contenu = (string) file_get_contents($chemin);
            $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);   // BOM
            if (!mb_check_encoding($contenu, 'UTF-8')) {
                $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
            }
            $premiere = strtok($contenu, "\n") ?: '';
            /* Excel en français enregistre les CSV avec des points-virgules */
            $separateur = substr_count($premiere, ';') >= substr_count($premiere, ',') ? ';' : ',';
            $lignes = array_map(fn ($l) => str_getcsv($l, $separateur), preg_split('/\r\n|\r|\n/', $contenu));
        } else {
            $lignes = IOFactory::load($chemin)->getActiveSheet()->toArray(null, true, false, false);
        }

        return array_values(array_filter($lignes, fn ($l) => count(array_filter((array) $l, fn ($c) => trim((string) $c) !== '')) > 0));
    }

    /**
     * Action prévue pour chaque ligne : cree, mis_a_jour, inchange ou ignore (avec le motif).
     *
     * @param array<int, array> $lignes
     */
    public static function analyser(array $lignes): array
    {
        if ($lignes === []) {
            return [];
        }
        [$colCode, $colNom, $entete] = self::colonnes($lignes[0]);
        $donnees = $entete ? array_slice($lignes, 1) : $lignes;
        $numeroPremiere = $entete ? 2 : 1;

        $existants = Client::pluck('raison_sociale', 'code');
        $vus = [];
        $resultat = [];
        foreach ($donnees as $i => $cellules) {
            $code = Str::upper(trim((string) ($cellules[$colCode] ?? '')));
            $nom = trim(preg_replace('/\s+/', ' ', (string) ($cellules[$colNom] ?? '')));
            $ligne = ['ligne' => $numeroPremiere + $i, 'code' => $code, 'raison_sociale' => $nom, 'action' => 'ignore', 'motif' => null];

            $ligne['motif'] = match (true) {
                $code === '' => 'Code client manquant.',
                $nom === '' => 'Raison sociale manquante.',
                mb_strlen($code) > 30 => 'Code client trop long (30 caractères au plus).',
                mb_strlen($nom) > 150 => 'Raison sociale trop longue (150 caractères au plus).',
                isset($vus[$code]) => "Code en double dans le fichier (déjà ligne {$vus[$code]}).",
                default => null,
            };
            if ($ligne['motif'] === null) {
                $vus[$code] = $ligne['ligne'];
                $ligne['action'] = !$existants->has($code) ? 'cree' : ($existants[$code] === $nom ? 'inchange' : 'mis_a_jour');
            }
            $resultat[] = $ligne;
        }

        return $resultat;
    }

    /**
     * Colonnes du code et de la raison sociale, d'après l'en-tête ; sans en-tête reconnu : 1re et 2e colonnes.
     *
     * @return array{0: int, 1: int, 2: bool} colonne du code, colonne de la raison sociale, première ligne = en-tête
     */
    private static function colonnes(array $premiere): array
    {
        $normalise = array_map(fn ($c) => Str::of((string) $c)->ascii()->lower()->replaceMatches('/[^a-z]/', '')->value(), $premiere);
        $code = null;
        $nom = null;
        foreach ($normalise as $i => $titre) {
            if ($code === null && str_contains($titre, 'code')) {
                $code = $i;
            } elseif ($nom === null && preg_match('/raison|nom|client|libelle|societe/', $titre)) {
                $nom = $i;
            }
        }

        return $code !== null && $nom !== null ? [$code, $nom, true] : [0, 1, false];
    }
}
