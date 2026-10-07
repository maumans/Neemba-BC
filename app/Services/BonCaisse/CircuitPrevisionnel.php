<?php

namespace App\Services\BonCaisse;

use App\Models\BonCaisse;
use App\Models\User;

/**
 * Circuit de validation annoncé avant soumission (US-BC-10, contrôle 9) :
 * niveaux et noms des valideurs prévus (comptes actifs ayant le rôle du niveau).
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
            'valideurs' => User::actifs()
                ->parRole($niveau['role'])
                ->when($niveau['service'], fn ($q, $service) => $q->where('service', $service))
                ->orderBy('name')
                ->get()
                ->map(fn (User $valideur) => $valideur->nom_complet)
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
