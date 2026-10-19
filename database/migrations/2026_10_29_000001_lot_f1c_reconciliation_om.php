<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F1c (spec v2.4, RG-M08-13, §5.4, SC-35) : réconciliation hebdomadaire de la caisse Orange Money entre le brouillard
 * de caisse (registre) et le relevé de la plateforme (import), validée par la trésorière, le Chef comptable et le DAF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations_om', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_id')->constrained('entites');
            $table->foreignId('caisse_id')->constrained('caisses');
            $table->string('numero', 40)->unique();
            $table->date('periode_debut');
            $table->date('periode_fin');
            $table->string('statut', 20)->default('a_valider');
            $table->string('releve', 255);
            $table->json('lignes_releve');
            $table->json('ecritures_non_rapprochees');
            $table->unsignedInteger('lignes_rapprochees')->default(0);
            $table->decimal('solde_brouillard_debut', 15, 2);
            $table->decimal('solde_brouillard_fin', 15, 2);
            $table->decimal('solde_releve_fin', 15, 2)->nullable();
            $table->decimal('ecart', 15, 2)->nullable();
            $table->string('observations', 1000)->nullable();
            $table->foreignId('etabli_par')->constrained('users');
            $table->foreignId('tresorerie_id')->nullable()->constrained('users');
            $table->timestamp('tresorerie_le')->nullable();
            $table->foreignId('chef_comptable_id')->nullable()->constrained('users');
            $table->timestamp('chef_comptable_le')->nullable();
            $table->foreignId('daf_id')->nullable()->constrained('users');
            $table->timestamp('daf_le')->nullable();
            $table->timestamps();
            $table->unique(['caisse_id', 'periode_debut']);
        });

        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'reconciliation_om_delai_jours', 'valeur' => '2', 'type' => 'number', 'groupe' => 'caisse',
            'libelle' => 'Réconciliation OM : écart de dates admis entre le brouillard et le relevé (jours)',
            'description' => 'RG-M08-13 : une écriture et une ligne du relevé de même sens et de même montant sont rapprochées si leurs dates sont aussi proches',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'reconciliation_om_delai_jours')->delete();
        Schema::dropIfExists('reconciliations_om');
    }
};
