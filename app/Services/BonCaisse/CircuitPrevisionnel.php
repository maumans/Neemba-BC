<?php

namespace App\Services\BonCaisse;

use App\Models\BonCaisse;

/**
 * Circuit de validation annoncé avant soumission (US-BC-10, contrôle 9) :
 * niveaux et noms des valideurs prévus : titulaires actifs du rôle (chef de service du service du bon) et suppléants,
 * sans le demandeur ni le bénéficiaire (RG-M01-04). Un niveau sans valideur sera sauté (RG-M04-09), sauf le dernier.
 */
class CircuitPrevisionnel
{
    /**
     * @return array<int, array{niveau: string, libelle: string, valideurs: string[]}>
     */
    public static function pour(BonCaisse $bon): array
    {
        $niveaux = [
            ['niveau' => 'CHEF_SERVICE', 'libelle' => 'Chef de service', 'role' => 'responsable_service', 'service' => $bon->service],
            ['niveau' => 'CDG', 'libelle' => 'CDG', 'role' => 'controle_gestion', 'service' => null],
            ['niveau' => 'FINANCE', 'libelle' => 'Finance', 'role' => 'daf', 'service' => null],
        ];
        if ($bon->necessite_validation_dp) {
            $niveaux[] = ['niveau' => 'DIRECTEUR_PAYS', 'libelle' => 'Directeur Pays', 'role' => 'directeur_pays', 'service' => null];
        }

        return array_map(fn (array $niveau) => [
            'niveau' => $niveau['niveau'],
            'libelle' => $niveau['libelle'],
            'valideurs' => CircuitBon::valideurs($bon, $niveau['role'])
                ->map(fn (array $v) => $v['user']->nom_complet . ($v['au_titre_de'] ? ' (suppléant de ' . $v['au_titre_de']->nom_complet . ')' : ''))
                ->values()
                ->all(),
        ], $niveaux);
    }

    /** « Chef de service : Lamine SOUMAH → CDG : Saliou BOIRO → Finance : … » */
    public static function texte(array $circuit): string
    {
        return implode(' → ', array_map(
            fn (array $niveau) => $niveau['libelle'] . ' : ' . ($niveau['valideurs'] ? implode(', ', $niveau['valideurs']) : 'aucun valideur'),
            $circuit,
        ));
    }
}
