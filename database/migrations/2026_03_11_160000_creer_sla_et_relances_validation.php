<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migration : SLA par niveau de validation + relances automatiques
 * 
 * - Ajoute les paramètres SLA configurables dans la table parametres
 * - Ajoute les colonnes de suivi SLA/relance dans la table validations
 */
return new class extends Migration
{
    public function up(): void
    {
        /* --- Colonnes SLA sur la table validations --- */
        Schema::table('validations', function (Blueprint $table) {
            $table->timestamp('date_attribution')->nullable()->after('date_validation')
                ->comment('Date à laquelle ce niveau a commencé à attendre');
            $table->timestamp('date_relance')->nullable()->after('date_attribution')
                ->comment('Date de la dernière relance envoyée');
            $table->unsignedSmallInteger('nb_relances')->default(0)->after('date_relance')
                ->comment('Nombre de relances envoyées pour ce niveau');
            $table->boolean('escalade')->default(false)->after('nb_relances')
                ->comment('Si true, le niveau a été escaladé au N+1');
            $table->timestamp('date_escalade')->nullable()->after('escalade');
        });

        /* --- Paramètres SLA dans la table parametres --- */
        $parametresSla = [
            [
                'cle' => 'sla_responsable_service',
                'valeur' => '4',
                'libelle' => 'SLA Chef de Service (heures)',
                'description' => 'Délai maximum en heures pour la validation par le Chef de Service avant relance automatique.',
                'type' => 'number',
                'groupe' => 'sla',
            ],
            [
                'cle' => 'sla_controle_gestion',
                'valeur' => '8',
                'libelle' => 'SLA Contrôle de Gestion (heures)',
                'description' => 'Délai maximum en heures pour la validation par le Contrôle de Gestion avant relance automatique.',
                'type' => 'number',
                'groupe' => 'sla',
            ],
            [
                'cle' => 'sla_daf',
                'valeur' => '4',
                'libelle' => 'SLA DAF (heures)',
                'description' => 'Délai maximum en heures pour la validation par le DAF avant relance automatique.',
                'type' => 'number',
                'groupe' => 'sla',
            ],
            [
                'cle' => 'sla_directeur_pays',
                'valeur' => '8',
                'libelle' => 'SLA Directeur Pays (heures)',
                'description' => 'Délai maximum en heures pour la validation par le Directeur Pays avant relance automatique.',
                'type' => 'number',
                'groupe' => 'sla',
            ],
            [
                'cle' => 'sla_multiplicateur_escalade',
                'valeur' => '2',
                'libelle' => 'Multiplicateur escalade (× SLA)',
                'description' => 'Si un niveau n\'a pas répondu après SLA × ce multiplicateur, le bon est automatiquement escaladé au niveau N+1.',
                'type' => 'number',
                'groupe' => 'sla',
            ],
            [
                'cle' => 'sla_relance_sms',
                'valeur' => 'true',
                'libelle' => 'Relance SLA par SMS',
                'description' => 'Activer l\'envoi de SMS de relance en plus des notifications push lorsque le SLA est dépassé.',
                'type' => 'boolean',
                'groupe' => 'sla',
            ],
        ];

        foreach ($parametresSla as $p) {
            DB::table('parametres')->updateOrInsert(
                ['cle' => $p['cle']],
                array_merge($p, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        Schema::table('validations', function (Blueprint $table) {
            $table->dropColumn(['date_attribution', 'date_relance', 'nb_relances', 'escalade', 'date_escalade']);
        });

        DB::table('parametres')->whereIn('cle', [
            'sla_responsable_service',
            'sla_controle_gestion',
            'sla_daf',
            'sla_directeur_pays',
            'sla_multiplicateur_escalade',
            'sla_relance_sms',
        ])->delete();
    }
};
