<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajouter le champ fonctionnalites à la table delegations
 * pour permettre la délégation granulaire par fonctionnalité
 * (null = toutes les fonctionnalités, pour rétrocompatibilité)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->json('fonctionnalites')->nullable()->after('motif')
                ->comment('Fonctionnalités déléguées (null = tout)');
        });
    }

    public function down(): void
    {
        Schema::table('delegations', function (Blueprint $table) {
            $table->dropColumn('fonctionnalites');
        });
    }
};
