<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double validation des modifications administratives
 * 
 * Lorsqu'un admin modifie un paramètre critique, la modification
 * est mise en attente de confirmation par un second admin/DAF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifications_en_attente', function (Blueprint $table) {
            $table->id();
            $table->string('type_entite');
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->string('champ');
            $table->text('ancienne_valeur')->nullable();
            $table->text('nouvelle_valeur');
            $table->foreignId('demandeur_id')->constrained('users');
            $table->foreignId('valideur_id')->nullable()->constrained('users');
            $table->enum('statut', ['en_attente', 'approuvee', 'refusee'])->default('en_attente');
            $table->text('commentaire')->nullable();
            $table->timestamp('date_validation')->nullable();
            $table->timestamps();

            $table->index(['type_entite', 'statut']);
            $table->index('demandeur_id');
        });

        // Ajouter durée de conservation aux types de document
        Schema::table('types_document', function (Blueprint $table) {
            $table->integer('duree_conservation_mois')->default(120)->after('actif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifications_en_attente');

        Schema::table('types_document', function (Blueprint $table) {
            $table->dropColumn('duree_conservation_mois');
        });
    }
};
