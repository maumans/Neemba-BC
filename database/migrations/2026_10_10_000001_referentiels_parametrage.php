<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiels de paramétrage de Neemba (classeur « Referentiels_Parametrage_Neemba_tous_sites », points 11 à 14).
 * Les colonnes du classeur qui n'avaient pas de place dans l'application :
 *
 * - caisses (point 14) : plafond de caisse (encaisse maximale), gestionnaire et suppléant, encaissements clients,
 *   mode et origine du réapprovisionnement, destinataires du rapport journalier ;
 * - services (point 13) : équivalent sur la fiche d'ordre de mission ;
 * - codes analytiques (point 12) : code service de la liste de référence du CDG, validation par le CDG ;
 * - utilisateurs (point 11) : entité, statut cadre / non-cadre, responsable N+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caisses', function (Blueprint $table) {
            $table->decimal('plafond_caisse', 15, 2)->nullable()->after('plafond_retrait');
            $table->foreignId('gestionnaire_id')->nullable()->after('seuil_alerte')->constrained('users')->nullOnDelete();
            $table->foreignId('suppleant_id')->nullable()->after('gestionnaire_id')->constrained('users')->nullOnDelete();
            $table->boolean('encaissements_clients')->default(false)->after('suppleant_id');
            $table->string('reapprovisionnement')->nullable()->after('encaissements_clients');
            $table->json('destinataires_rapport')->nullable()->after('reapprovisionnement');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('equivalent_odm', 40)->nullable()->after('code');
        });

        Schema::table('codes_analytiques', function (Blueprint $table) {
            $table->string('code_service_comptable', 10)->nullable()->after('service_id');
            $table->boolean('valide_cdg')->default(false)->after('code_service_comptable');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('entite', 60)->nullable()->after('site');
            $table->enum('statut_cadre', ['cadre', 'non_cadre'])->nullable()->after('poste');
            $table->foreignId('responsable_id')->nullable()->after('statut_cadre')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_id');
            $table->dropColumn(['entite', 'statut_cadre']);
        });
        Schema::table('codes_analytiques', fn (Blueprint $table) => $table->dropColumn(['code_service_comptable', 'valide_cdg']));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('equivalent_odm'));
        Schema::table('caisses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suppleant_id');
            $table->dropConstrainedForeignId('gestionnaire_id');
            $table->dropColumn(['plafond_caisse', 'encaissements_clients', 'reapprovisionnement', 'destinataires_rapport']);
        });
    }
};
