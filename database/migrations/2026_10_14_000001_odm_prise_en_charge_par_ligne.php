<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prise en charge des frais d'un ODM ligne par ligne, pour chaque participant (demande de la direction, Q49 à Q51).
 *
 * Chaque ligne (indemnités, hébergement, rattrapage, facture) est à la charge de Neemba, du client avec avance
 * de Neemba (dans le bon, à refacturer) ou du client qui la paie directement (hors bon). Le choix de l'en-tête
 * devient le défaut appliqué à toutes les lignes ; il vaut « mixte » quand elles diffèrent.
 *
 * Reprise : un ODM « client » passe toutes ses lignes en avance (variante A) ou en paiement direct (variante B),
 * selon le paramètre en vigueur.
 */
return new class extends Migration
{
    private const LIGNES = ['indemnite_1', 'indemnite_2', 'hebergement', 'rattrapage', 'indemnite', 'hebergement_retour'];

    public function up(): void
    {
        Schema::table('odm_participants', function (Blueprint $table) {
            $table->json('prises_en_charge')->nullable()->after('total');
            $table->decimal('montant_bon', 15, 2)->nullable()->after('prises_en_charge');
            $table->decimal('montant_refacturable', 15, 2)->nullable()->after('montant_bon');
            $table->decimal('montant_client_direct', 15, 2)->nullable()->after('montant_refacturable');
        });
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->enum('prise_en_charge', ['neemba', 'client', 'mixte'])->default('neemba')->change();
            $table->enum('mode_client', ['avance', 'direct'])->default('avance')->after('prise_en_charge');
            $table->decimal('montant_a_refacturer', 15, 2)->nullable()->after('a_refacturer');
        });

        $variante = DB::table('parametres')->where('cle', 'odm_prise_en_charge_client')->value('valeur') ?? 'variante_a';
        $mode = $variante === 'variante_b' ? 'direct' : 'avance';

        foreach (DB::table('ordres_mission')->get(['id', 'prise_en_charge', 'a_refacturer']) as $odm) {
            $client = $odm->prise_en_charge === 'client';
            $prise = $client ? "client_{$mode}" : 'neemba';
            $aRefacturer = 0.0;

            foreach (DB::table('odm_participants')->where('ordre_mission_id', $odm->id)->get(['id', 'total', 'retire']) as $participant) {
                $total = $participant->total;
                $refacturable = $total === null ? null : ($prise === 'client_avance' ? (float) $total : 0.0);
                DB::table('odm_participants')->where('id', $participant->id)->update([
                    'prises_en_charge' => json_encode(array_fill_keys(self::LIGNES, $prise)),
                    'montant_bon' => $total === null ? null : ($prise === 'client_direct' ? 0 : $total),
                    'montant_refacturable' => $refacturable,
                    'montant_client_direct' => $total === null ? null : ($prise === 'client_direct' ? $total : 0),
                ]);
                if (!$participant->retire) {
                    $aRefacturer += (float) $refacturable;
                }
            }

            DB::table('ordres_mission')->where('id', $odm->id)->update([
                'mode_client' => $mode,
                'montant_a_refacturer' => $aRefacturer,
                'a_refacturer' => $odm->a_refacturer && $aRefacturer > 0,
            ]);
        }

        DB::table('parametres')->where('cle', 'odm_prise_en_charge_client')->update([
            'libelle' => 'Ligne à la charge du client : mode proposé par défaut',
            'description' => 'Proposé quand une ligne de frais passe à la charge du client ; modifiable ligne par ligne sur l\'ODM (Q49). '
                . 'Avancée par Neemba : dans le bon de caisse, puis refacturée. Payée directement : hors bon de caisse.',
        ]);
    }

    public function down(): void
    {
        DB::table('ordres_mission')->where('prise_en_charge', 'mixte')->update([
            'prise_en_charge' => DB::raw("CASE WHEN montant_a_refacturer > 0 THEN 'client' ELSE 'neemba' END"),
        ]);
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->dropColumn(['mode_client', 'montant_a_refacturer']);
        });
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->enum('prise_en_charge', ['neemba', 'client'])->default('neemba')->change();
        });
        Schema::table('odm_participants', function (Blueprint $table) {
            $table->dropColumn(['prises_en_charge', 'montant_bon', 'montant_refacturable', 'montant_client_direct']);
        });

        DB::table('parametres')->where('cle', 'odm_prise_en_charge_client')->update([
            'libelle' => 'ODM à la charge du client',
            'description' => 'RG-M12-15 (PO-04)',
        ]);
    }
};
