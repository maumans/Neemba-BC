<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M16 — Imputation comptable et export comptable (spec v2.3, §8.11, RG-M16-01 à RG-M16-06).
 *
 * Après paiement, la Comptabilité impute le bon : une ou plusieurs lignes (compte, libellé, montant, code analytique),
 * la somme égale au montant versé. Pré-remplissage : BP → 421100, frais OM → compte paramétrable, bons d'ODM → une ligne
 * par part de prise en charge. Export quotidien des écritures en Excel / CSV générique ; une écriture exportée ne se
 * modifie plus. La saisie comptable reste dans Infor IE (aucun format d'import IE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports_comptables', function (Blueprint $table) {
            $table->id();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->unsignedInteger('nombre_ecritures')->default(0);
            $table->decimal('montant_total', 15, 2)->default(0);
            $table->foreignId('exporte_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('imputations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bon_caisse_id')->unique()->constrained('bons_caisse')->cascadeOnDelete();
            $table->enum('statut', ['brouillon', 'validee', 'exportee'])->default('brouillon');
            $table->string('code_journal', 20)->nullable();
            /* RG-M16-06 : motif d'une correction de la répartition d'un ODM */
            $table->text('motif_correction')->nullable();
            $table->foreignId('saisi_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('valide_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->foreignId('export_comptable_id')->nullable()->constrained('exports_comptables')->nullOnDelete();
            $table->timestamps();
            $table->index('statut');
        });

        Schema::create('lignes_imputation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imputation_id')->constrained('imputations')->cascadeOnDelete();
            $table->unsignedTinyInteger('rang')->default(1);
            $table->enum('nature', ['depense', 'frais_om'])->default('depense');
            $table->string('compte', 20)->nullable();
            $table->string('libelle', 150);
            $table->decimal('montant', 15, 2);
            $table->string('code_analytique', 50)->nullable();
            /* Bons issus d'un ODM (RG-M16-05) : la part de prise en charge et sa ligne de frais */
            $table->enum('payeur', ['neemba', 'client'])->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('ordre_reparation', 8)->nullable();
            $table->foreignId('ligne_frais_id')->nullable()->constrained('odm_lignes_frais')->nullOnDelete();
            $table->timestamps();
        });

        $maintenant = now();
        foreach ([
            ['compte_bp', '421100', 'Compte des bons provisoires', 'RG-M16-02 : BP comptabilisé au 421100 auxiliarisé (RG-M07-01)', 'compte'],
            ['compte_frais_om', '', 'Compte des frais Orange Money', 'RG-M16-02 (PO-17) : à renseigner par la Comptabilité', 'compte'],
            ['compte_odm_indemnite', '', 'Compte de charge : indemnités de mission', 'RG-M16-05 : part Neemba d\'une ligne « indemnité » d\'un ODM', 'compte'],
            ['compte_odm_hebergement', '', 'Compte de charge : hébergement de mission', 'RG-M16-05 : part Neemba des lignes d\'hébergement et de rattrapage d\'un ODM', 'compte'],
            ['code_journal_especes', '', 'Code journal : caisse espèces', 'RG-M06-08 : code journal des décaissements en espèces', 'compte'],
            ['code_journal_orange_money', '', 'Code journal : Orange Money', 'RG-M06-08 : code journal des décaissements Orange Money', 'compte'],
            ['code_journal_mtn_money', '', 'Code journal : MTN Money', 'RG-M06-08 : prévu par la procédure, mode inactif par défaut', 'compte'],
            ['code_journal_cheque', '', 'Code journal : chèque', 'Code journal des paiements par chèque', 'compte'],
            ['code_journal_virement', '', 'Code journal : virement', 'Code journal des paiements par virement', 'compte'],
            ['imputation_delai_alerte', '1', 'Alerte des bons non imputés (jours ouvrés)', 'RG-M16-04 : alerte au Chef comptable pour un bon payé non imputé après ce délai', 'number'],
        ] as [$cle, $valeur, $libelle, $description, $type]) {
            DB::table('parametres')->insertOrIgnore(compact('cle', 'valeur', 'libelle', 'description', 'type') + [
                'groupe' => 'comptabilite', 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
        }
        DB::table('parametres')->where('cle', 'compte_frais_refacturables')->update(['groupe' => 'comptabilite']);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'compte_frais_refacturables')->update(['groupe' => 'odm']);
        DB::table('parametres')->whereIn('cle', [
            'compte_bp', 'compte_frais_om', 'compte_odm_indemnite', 'compte_odm_hebergement', 'code_journal_especes', 'code_journal_orange_money',
            'code_journal_mtn_money', 'code_journal_cheque', 'code_journal_virement', 'imputation_delai_alerte',
        ])->delete();
        Schema::dropIfExists('lignes_imputation');
        Schema::dropIfExists('imputations');
        Schema::dropIfExists('exports_comptables');
    }
};
