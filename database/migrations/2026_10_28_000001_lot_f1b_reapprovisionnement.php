<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F1b (spec v2.4, RG-M08-04 à RG-M08-09, MSG-M08-02 à 04) : demande de réapprovisionnement d'une caisse.
 * - Circuit : caissier → trésorerie → Chef comptable / DAF → DP (ou deux membres du CODIR en son absence, Q89).
 * - Exécution par la trésorerie (origine des fonds), crédit de la caisse à la confirmation de réception.
 * - Option « transfert depuis une autre caisse », activable par caisse (caisse Atelier).
 * - Paramètre : habilitation du DP (500 000 000 GNF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_reapprovisionnement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_id')->constrained('entites');
            $table->foreignId('caisse_id')->constrained('caisses');
            $table->string('numero', 40)->unique();
            $table->string('statut', 30)->default('soumise');
            $table->decimal('solde_a_la_demande', 15, 2);
            $table->decimal('montant_propose', 15, 2);
            $table->decimal('montant_demande', 15, 2);
            $table->string('motif_baisse', 500)->nullable();
            $table->decimal('montant_valide', 15, 2)->nullable();
            $table->string('motif_reduction', 500)->nullable();
            $table->foreignId('pv_arrete_id')->constrained('pv_arretes');
            $table->string('rapprochement', 255);
            $table->foreignId('demande_par')->constrained('users');
            $table->foreignId('tresorerie_par')->nullable()->constrained('users');
            $table->timestamp('tresorerie_le')->nullable();
            $table->foreignId('finance_par')->nullable()->constrained('users');
            $table->timestamp('finance_le')->nullable();
            $table->foreignId('dp_par')->nullable()->constrained('users');
            $table->timestamp('dp_le')->nullable();
            $table->foreignId('codir1_par')->nullable()->constrained('users');
            $table->timestamp('codir1_le')->nullable();
            $table->foreignId('codir2_par')->nullable()->constrained('users');
            $table->timestamp('codir2_le')->nullable();
            $table->foreignId('refuse_par')->nullable()->constrained('users');
            $table->timestamp('refuse_le')->nullable();
            $table->string('motif_refus', 500)->nullable();
            $table->string('origine_fonds', 30)->nullable();
            $table->foreignId('caisse_source_id')->nullable()->constrained('caisses');
            $table->string('reference_execution', 100)->nullable();
            $table->string('piece_execution', 255)->nullable();
            $table->decimal('montant_emis', 15, 2)->nullable();
            $table->foreignId('execute_par')->nullable()->constrained('users');
            $table->timestamp('execute_le')->nullable();
            $table->decimal('montant_recu', 15, 2)->nullable();
            $table->string('piece_reception', 255)->nullable();
            $table->foreignId('recu_par')->nullable()->constrained('users');
            $table->timestamp('recu_le')->nullable();
            $table->timestamps();
            $table->index(['caisse_id', 'statut']);
        });

        Schema::table('caisses', function (Blueprint $table) {
            $table->boolean('reappro_par_transfert')->default(false)->after('reapprovisionnement');
        });
        DB::table('caisses')->where('mode', 'avance_fixe')->update(['reappro_par_transfert' => true]);

        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'habilitation_dp_reappro', 'valeur' => '500000000', 'type' => 'number', 'groupe' => 'caisse',
            'libelle' => "Habilitation du Directeur Pays pour un réapprovisionnement (GNF)",
            'description' => 'RG-M08-07 : aucun réapprovisionnement au-delà', 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'habilitation_dp_reappro')->delete();
        Schema::table('caisses', fn (Blueprint $table) => $table->dropColumn('reappro_par_transfert'));
        Schema::dropIfExists('demandes_reapprovisionnement');
    }
};
