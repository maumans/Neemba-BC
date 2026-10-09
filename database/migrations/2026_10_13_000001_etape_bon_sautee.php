<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RG-M04-09 (spec v2.2) : quand le seul valideur d'une étape est le demandeur ou le bénéficiaire du bon,
 * sans suppléant, l'étape est « sautée » et le bon passe au niveau supérieur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validations', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'approuve', 'rejete', 'saute'])->default('en_attente')->change();
        });
    }

    public function down(): void
    {
        DB::table('validations')->where('statut', 'saute')->update(['statut' => 'approuve']);
        Schema::table('validations', function (Blueprint $table) {
            $table->enum('statut', ['en_attente', 'approuve', 'rejete'])->default('en_attente')->change();
        });
    }
};
