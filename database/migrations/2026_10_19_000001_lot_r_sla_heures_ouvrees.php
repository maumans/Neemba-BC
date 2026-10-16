<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lot R (spec v2.3, §6.7) : délais de visa conformes et comptés en heures ouvrées.
 *
 * - SLA portés aux valeurs de la spec : 4 h (chef de service), 24 h (CDG), 8 h (Finance), 24 h (DP).
 *   La base portait 4 / 8 / 4 / 8 h, hérités de la SFD v1.3.
 * - Bornes de la journée de travail (8 h – 17 h), nouveaux paramètres.
 * - Seuil du visa du Directeur Pays ramené à 1 500 000 GNF (RG-M03-17) : la migration d'origine posait
 *   5 000 000, et seul le seeder corrigeait la valeur.
 */
return new class extends Migration
{
    /** Valeurs d'avant le lot R, pour le retour en arrière */
    private const ANCIENNES = [
        'sla_controle_gestion' => '8',
        'sla_daf' => '4',
        'sla_directeur_pays' => '8',
        'seuil_validation_dp' => '5000000',
    ];

    private const NOUVELLES = [
        'sla_controle_gestion' => '24',
        'sla_daf' => '8',
        'sla_directeur_pays' => '24',
        'seuil_validation_dp' => '1500000',
    ];

    public function up(): void
    {
        foreach (self::NOUVELLES as $cle => $valeur) {
            DB::table('parametres')->where('cle', $cle)->update(['valeur' => $valeur, 'updated_at' => now()]);
        }

        foreach ([
            [
                'cle' => 'heure_ouverture',
                'valeur' => '08:00',
                'libelle' => 'Heure d\'ouverture des bureaux',
                'description' => 'Début de la journée ouvrée (format HH:MM). Les délais de visa ne courent qu\'entre cette heure et l\'heure de fermeture, du lundi au vendredi, hors jours fériés.',
                'type' => 'text',
                'groupe' => 'sla',
            ],
            [
                'cle' => 'heure_fermeture',
                'valeur' => '17:00',
                'libelle' => 'Heure de fermeture des bureaux',
                'description' => 'Fin de la journée ouvrée (format HH:MM). Un délai entamé en fin de journée reprend à l\'ouverture suivante.',
                'type' => 'text',
                'groupe' => 'sla',
            ],
        ] as $parametre) {
            DB::table('parametres')->updateOrInsert(
                ['cle' => $parametre['cle']],
                array_merge($parametre, ['created_at' => now(), 'updated_at' => now()]),
            );
        }

        $this->oublierCache();
    }

    public function down(): void
    {
        foreach (self::ANCIENNES as $cle => $valeur) {
            DB::table('parametres')->where('cle', $cle)->update(['valeur' => $valeur, 'updated_at' => now()]);
        }

        DB::table('parametres')->whereIn('cle', ['heure_ouverture', 'heure_fermeture'])->delete();

        $this->oublierCache();
    }

    private function oublierCache(): void
    {
        foreach ([...array_keys(self::NOUVELLES), 'heure_ouverture', 'heure_fermeture'] as $cle) {
            Cache::forget("parametre.{$cle}");
        }
    }
};
