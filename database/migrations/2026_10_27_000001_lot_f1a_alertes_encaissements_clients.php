<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F1a (spec v2.4, M08 : RG-M08-03, RG-M08-10, MSG-M08-01, 05, 06) :
 * - alerte de seuil envoyée une fois au franchissement, puis rappel quotidien : dates de l'alerte et du dernier rappel ;
 * - encaissements clients (caisse de Conakry principale), avec reçu numéroté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caisses', function (Blueprint $table) {
            $table->timestamp('alerte_seuil_le')->nullable()->after('seuil_alerte');
            $table->date('alerte_seuil_rappel_le')->nullable()->after('alerte_seuil_le');
        });

        Schema::create('encaissements_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_id')->constrained('entites');
            $table->foreignId('caisse_id')->constrained('caisses');
            $table->string('numero', 40)->unique();
            $table->foreignId('client_id')->constrained('clients');
            $table->string('numero_piece', 40);
            $table->decimal('montant', 15, 2);
            $table->string('mode', 20);
            $table->string('observations', 500)->nullable();
            $table->string('piece', 255)->nullable();
            $table->foreignId('encaisse_par')->constrained('users');
            $table->timestamp('encaisse_le');
            $table->foreignId('ecriture_caisse_id')->nullable()->constrained('ecritures_caisse')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encaissements_clients');
        Schema::table('caisses', function (Blueprint $table) {
            $table->dropColumn(['alerte_seuil_le', 'alerte_seuil_rappel_le']);
        });
    }
};
