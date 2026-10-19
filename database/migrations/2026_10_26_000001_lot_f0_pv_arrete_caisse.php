<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F0 (spec v2.4, RG-M10-02, RG-M10-03, RG-M08-05, Annexe 2 ; décision du 19/10 : billetage et PV d'abord) :
 * PV d'arrêté de caisse, par caisse — date, heure, caisse, billetage par coupure, solde physique, solde comptable, écart,
 * trois signatures (gérant de caisse, trésorerie, Chef comptable). Un écart non nul crée un ajustement « En attente »,
 * passé seulement après validation du DAF avec motif. Le type des mouvements de caisse devient une chaîne (de nouveaux
 * types arrivent avec M08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->string('type', 40)->change();
        });

        Schema::create('pv_arretes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_id')->constrained('entites');
            $table->foreignId('caisse_id')->constrained('caisses');
            $table->string('numero', 40)->unique();
            $table->string('motif', 40);
            $table->timestamp('arrete_le');
            $table->json('billetage')->nullable();
            $table->decimal('solde_physique', 15, 2);
            $table->decimal('solde_comptable', 15, 2);
            $table->decimal('ecart', 15, 2);
            $table->text('observations')->nullable();
            $table->foreignId('etabli_par')->constrained('users');
            $table->foreignId('gerant_id')->nullable()->constrained('users');
            $table->timestamp('gerant_le')->nullable();
            $table->foreignId('tresorerie_id')->nullable()->constrained('users');
            $table->timestamp('tresorerie_le')->nullable();
            $table->foreignId('chef_comptable_id')->nullable()->constrained('users');
            $table->timestamp('chef_comptable_le')->nullable();
            $table->foreignId('ajustement_id')->nullable()->constrained('mouvements_caisse')->nullOnDelete();
            $table->timestamps();
            $table->index(['caisse_id', 'arrete_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pv_arretes');
        DB::table('mouvements_caisse')->whereNotIn('type', ['approvisionnement', 'retrait', 'ajustement'])->update(['type' => 'ajustement']);
        Schema::table('mouvements_caisse', function (Blueprint $table) {
            $table->enum('type', ['approvisionnement', 'retrait', 'ajustement'])->change();
        });
    }
};
