<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 5 — M03-C.
 *
 * validations.au_titre_de_id : quand un suppléant valide par délégation, le titulaire au nom duquel il agit
 * (fiche du bon, onglet Validations : « X au titre de Y »).
 *
 * La délégation d'initiation (US-BC-13) n'a pas besoin de colonne : c'est la fonctionnalité « initiation »
 * d'une délégation (delegations.fonctionnalites) ; le bon porte déjà demandeur_id (titulaire) et initiateur_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validations', function (Blueprint $table) {
            $table->foreignId('au_titre_de_id')->nullable()->after('validateur_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('validations', fn (Blueprint $table) => $table->dropConstrainedForeignId('au_titre_de_id'));
    }
};
