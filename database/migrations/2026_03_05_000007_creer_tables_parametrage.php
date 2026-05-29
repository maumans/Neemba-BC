<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables de paramétrage administrables
 * 
 * Ces tables contiennent les données de référence de l'application
 * gérées par l'administrateur (DAF/Directeur Pays).
 * Elles alimentent les selects searchable dans les formulaires.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* Sites NEEMBA (ex: Conakry, Kamsar, Fria...) */
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('ville')->nullable();
            $table->string('adresse')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        /* Services (ex: Direction Générale, Finance, IT...) */
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('code')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        /* Codes analytiques (imputations comptables) */
        Schema::create('codes_analytiques', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('libelle');
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        /* Types de document (pour les pièces jointes) */
        Schema::create('types_document', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('types_document');
        Schema::dropIfExists('codes_analytiques');
        Schema::dropIfExists('services');
        Schema::dropIfExists('sites');
    }
};
