<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->id();
            $table->string('cle')->unique();
            $table->string('valeur');
            $table->string('libelle');
            $table->string('description')->nullable();
            $table->string('type')->default('number'); // number, string, boolean
            $table->string('groupe')->default('general');
            $table->timestamps();
        });

        /* Insérer les paramètres par défaut */
        DB::table('parametres')->insert([
            [
                'cle' => 'montant_max_bon',
                'valeur' => '20000000',
                'libelle' => 'Montant maximum d\'un bon de caisse',
                'description' => 'Montant maximum autorisé pour un bon de caisse (en GNF)',
                'type' => 'number',
                'groupe' => 'seuils',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cle' => 'seuil_validation_dp',
                'valeur' => '5000000',
                'libelle' => 'Seuil validation Directeur Pays',
                'description' => 'Montant à partir duquel la validation du Directeur Pays est requise (en GNF)',
                'type' => 'number',
                'groupe' => 'seuils',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cle' => 'delai_regularisation_mission',
                'valeur' => '3',
                'libelle' => 'Délai régularisation mission (jours)',
                'description' => 'Nombre de jours accordés pour régulariser un bon provisoire de mission après paiement',
                'type' => 'number',
                'groupe' => 'delais',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cle' => 'delai_regularisation_autre',
                'valeur' => '2',
                'libelle' => 'Délai régularisation autre (jours)',
                'description' => 'Nombre de jours accordés pour régulariser un bon provisoire (hors mission) après paiement',
                'type' => 'number',
                'groupe' => 'delais',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'cle' => 'taille_max_fichier',
                'valeur' => '10485760',
                'libelle' => 'Taille maximale fichier (octets)',
                'description' => 'Taille maximale autorisée pour une pièce jointe en octets (10 Mo par défaut)',
                'type' => 'number',
                'groupe' => 'fichiers',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
