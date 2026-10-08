<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ODM intérieur en brouillon (exemple B.1 de la spec v2.2 : Conakry → Kouroussa, du 22/09 au 26/09/2026).
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrdreMission>
 */
class OrdreMissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'interieur',
            'technique' => false,
            'entite' => 'Neemba Guinée',
            'site' => 'Conakry',
            'service' => 'Technique',
            'code_analytique' => 'ADAZZZ',
            'demandeur_id' => User::factory(),
            'but' => 'Dépannage d\'une machine chez le client',
            'clients' => ['SMD'],
            'destinations' => ['Kouroussa'],
            'date_depart' => '2026-09-22',
            'date_retour_prevue' => '2026-09-26',
            'prise_en_charge' => 'neemba',
            'statut' => 'BROUILLON',
        ];
    }

    public function statut(string $statut): static
    {
        return $this->state(fn (array $attributes) => ['statut' => $statut]);
    }

    public function exterieur(string $hebergement = 'avant_depart'): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'exterieur', 'hebergement_exterieur' => $hebergement, 'destinations' => ['Abidjan']]);
    }

    /** Numéroté comme un ODM soumis */
    public function numerote(int $sequence, string $prefixe = 'AT', int $annee = 2026): static
    {
        return $this->state(fn (array $attributes) => [
            'numero' => sprintf('N°%d/%s/%02d', $sequence, $prefixe, $annee % 100),
            'prefixe' => $prefixe, 'sequence' => $sequence, 'annee' => $annee,
        ]);
    }
}
