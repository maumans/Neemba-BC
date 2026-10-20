<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F2a (spec v2.4, RG-M09-01 à RG-M09-05) : clôture journalière par caisse et rapport « REPORTING CAISSE ».
 * - `clotures_caisse` : une journée close par caisse (soldes et totaux figés, contrôle avec la veille, envoi du rapport).
 * - `ecritures_caisse.piece` : n° de la pièce de l'écriture (reçu, demande de réapprovisionnement, mouvement, ODM), repris
 *   dans le rapport ; pour un bon, le n° du bon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecritures_caisse', function (Blueprint $table) {
            $table->string('piece', 60)->nullable()->after('libelle');
        });

        Schema::create('clotures_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_id')->constrained('entites');
            $table->foreignId('caisse_id')->constrained('caisses');
            $table->date('date_journee');
            $table->decimal('solde_initial', 15, 2);
            $table->decimal('total_encaissements', 15, 2);
            $table->decimal('total_decaissements', 15, 2);
            $table->decimal('solde_final', 15, 2);
            $table->decimal('solde_veille', 15, 2)->nullable();
            $table->decimal('ecart_controle', 15, 2)->nullable();
            $table->unsignedInteger('nombre_operations')->default(0);
            $table->boolean('automatique')->default(false);
            $table->foreignId('cloture_par')->nullable()->constrained('users');
            $table->timestamp('cloture_le');
            $table->string('observations', 1000)->nullable();
            $table->timestamp('envoye_le')->nullable();
            $table->json('destinataires')->nullable();
            $table->timestamps();
            $table->unique(['caisse_id', 'date_journee']);
        });

        /* Les journées antérieures à la mise en service ne sont pas clôturées après coup */
        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'clotures_caisse_depuis', 'valeur' => $maintenant->toDateString(), 'type' => 'text', 'groupe' => 'caisse',
            'libelle' => 'Clôture journalière des caisses depuis le (AAAA-MM-JJ)',
            'description' => 'RG-M09-03 : la clôture automatique ne remonte pas avant cette date',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'clotures_caisse_depuis')->delete();
        Schema::dropIfExists('clotures_caisse');
        Schema::table('ecritures_caisse', fn (Blueprint $table) => $table->dropColumn('piece'));
    }
};
