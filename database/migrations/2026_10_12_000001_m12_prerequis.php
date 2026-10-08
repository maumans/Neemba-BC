<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M12-0 — Prérequis du module « Ordres de mission » (spec v2.2 du 07/10/2026) :
 *
 * - n° Orange Money des salariés (RG-M02-05), repris sur les participants d'un ODM ;
 * - taux de change FCFA → GNF saisi chaque jour par la Trésorerie (§6.6, RG-M12-10) ;
 * - frais Orange Money calculés au paiement et ajoutés au montant versé (§6.6, RG-M06-07) ;
 * - paramètres des ODM (§6.6, points ouverts PO-02 à PO-04) et jours fériés (jours ouvrés, RG-M07-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('numero_om', 9)->nullable()->after('telephone');
        });

        /* Les listes (jours fériés, paliers des frais OM) dépassent vite 255 caractères */
        Schema::table('parametres', function (Blueprint $table) {
            $table->text('valeur')->change();
        });

        Schema::create('taux_change', function (Blueprint $table) {
            $table->id();
            $table->date('date_taux');
            $table->string('devise', 3)->default('XOF');
            $table->decimal('taux', 12, 4);
            $table->foreignId('saisi_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('commentaire')->nullable();
            $table->timestamps();

            $table->unique(['date_taux', 'devise']);
        });

        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->decimal('frais_om', 15, 2)->nullable()->after('mode_paiement_effectif');
            $table->decimal('frais_om_taux', 5, 2)->nullable()->after('frais_om');
            $table->boolean('frais_om_saisis')->default(false)->after('frais_om_taux');
            $table->decimal('montant_verse', 15, 2)->nullable()->after('frais_om_saisis');
        });

        $maintenant = now();
        $parametres = [
            /* §6.6 : le palier se détermine sur le total du bon, pas par tranche ; hors paliers, frais saisis au paiement (PO-01) */
            ['frais_om_paliers', '[{"de":100001,"a":5000000,"taux":1},{"de":5000001,"a":15000000,"taux":0.8}]', 'Grille des frais Orange Money',
                'Paliers appliqués au total du bon (et non par tranche) : de, à (GNF), taux (%). Frais arrondis au franc supérieur. '
                . 'Hors paliers, les frais sont saisis au paiement.', 'json', 'orange_money'],
            ['jours_feries', '01-01, 05-01, 08-15, 10-02, 12-25', 'Jours fériés',
                'Dates séparées par des virgules : MM-JJ pour une fête à date fixe, AAAA-MM-JJ pour une fête mobile (lundi de Pâques, '
                . 'Aïd el-Fitr, Tabaski, Maouloud…). Les samedis et dimanches ne sont jamais ouvrés.', 'dates', 'delais'],
            ['odm_indemnite_journaliere', '250000', 'Indemnité journalière (ODM intérieur, GNF)',
                'Montant par jour de mission, affiché en deux lignes égales', 'number', 'odm'],
            ['odm_libelle_indemnite_1', 'Indemnité de repas', 'Libellé de la première ligne d\'indemnité', 'RG-M12-08 (PO-02)', 'text', 'odm'],
            ['odm_libelle_indemnite_2', 'Indemnité de déplacement', 'Libellé de la seconde ligne d\'indemnité', 'RG-M12-08 (PO-02)', 'text', 'odm'],
            ['odm_hebergement_nuit', '500000', 'Hébergement par nuit (ODM intérieur, GNF)',
                'Nul pour un participant logé sur base vie', 'number', 'odm'],
            ['odm_bareme_fcfa_non_cadre', '22000', 'Indemnité extérieure, non-cadre (FCFA par jour)',
                'Convertie en GNF au taux du jour du paiement ; barème unique, y compris hors zone CFA', 'number', 'odm'],
            ['odm_bareme_fcfa_cadre', '34000', 'Indemnité extérieure, cadre (FCFA par jour)',
                'Convertie en GNF au taux du jour du paiement ; barème unique, y compris hors zone CFA', 'number', 'odm'],
            ['odm_participants_max', '10', 'Nombre maximal de participants par ODM', 'RG-M12-04', 'number', 'odm'],
            ['odm_genere_bp', 'true', 'ODM générant un BP',
                'Le demandeur peut générer, en plus des BD d\'indemnités, un BP d\'avance pour frais réels de mission', 'boolean', 'odm'],
            ['odm_mode_generation', 'par_participant', 'Mode de génération des bons d\'un ODM', 'RG-M12-14 (PO-03, arbitrage du DAF attendu)', 'choix', 'odm'],
            ['odm_prise_en_charge_client', 'variante_a', 'ODM à la charge du client', 'RG-M12-15 (PO-04)', 'choix', 'odm'],
            ['odm_etape_rh', 'false', 'Visa RH sur les ODM',
                'Étape RH ajoutée au circuit de l\'ODM. Inactive : pas de visa RH (comité du 06/10/2026)', 'boolean', 'odm'],
            ['odm_delai_rappel', '2', 'Rappel avant la fin d\'un segment (jours ouvrés)',
                'RG-M12-28 : le demandeur est prévenu pour prolonger ou clôturer la mission', 'number', 'odm'],
        ];
        foreach ($parametres as [$cle, $valeur, $libelle, $description, $type, $groupe]) {
            DB::table('parametres')->insertOrIgnore(compact('cle', 'valeur', 'libelle', 'description', 'type', 'groupe') + [
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }

        /* RG-M07-02 (v2.2) : l'échéance d'un BP se compte en jours ouvrés */
        DB::table('parametres')->where('cle', 'delai_regularisation_mission')->update([
            'libelle' => 'Délai régularisation mission (jours ouvrés)',
            'description' => 'Nombre de jours ouvrés accordés pour régulariser un bon provisoire de mission après le retour',
        ]);
        DB::table('parametres')->where('cle', 'delai_regularisation_autre')->update([
            'libelle' => 'Délai régularisation autre (jours ouvrés)',
            'description' => 'Nombre de jours ouvrés accordés pour régulariser un bon provisoire (hors mission) après paiement',
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'delai_regularisation_mission')->update([
            'libelle' => 'Délai régularisation mission (jours)',
            'description' => 'Nombre de jours accordés pour régulariser un bon provisoire de mission après paiement',
        ]);
        DB::table('parametres')->where('cle', 'delai_regularisation_autre')->update([
            'libelle' => 'Délai régularisation autre (jours)',
            'description' => 'Nombre de jours accordés pour régulariser un bon provisoire (hors mission) après paiement',
        ]);
        DB::table('parametres')->whereIn('cle', [
            'frais_om_paliers', 'jours_feries', 'odm_indemnite_journaliere', 'odm_libelle_indemnite_1', 'odm_libelle_indemnite_2',
            'odm_hebergement_nuit', 'odm_bareme_fcfa_non_cadre', 'odm_bareme_fcfa_cadre', 'odm_participants_max', 'odm_genere_bp',
            'odm_mode_generation', 'odm_prise_en_charge_client', 'odm_etape_rh', 'odm_delai_rappel',
        ])->delete();

        Schema::table('bons_caisse', fn (Blueprint $table) => $table->dropColumn(['frais_om', 'frais_om_taux', 'frais_om_saisis', 'montant_verse']));
        Schema::dropIfExists('taux_change');
        Schema::table('parametres', function (Blueprint $table) {
            $table->string('valeur')->change();
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('numero_om'));
    }
};
