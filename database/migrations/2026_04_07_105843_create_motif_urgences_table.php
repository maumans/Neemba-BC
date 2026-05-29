<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('motifs_urgence', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // Migration des données depuis l'ancien paramètre JSON
        $param = DB::table('parametres')->where('cle', 'motifs_urgence_predefinis')->first();
        if ($param && $param->valeur) {
            $motifsJson = json_decode($param->valeur, true);
            if (is_array($motifsJson)) {
                $insertData = [];
                foreach ($motifsJson as $m) {
                    $insertData[] = [
                        'libelle' => $m,
                        'actif' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('motifs_urgence')->insert($insertData);
            }
            // Supprimer l'ancien paramètre
            DB::table('parametres')->where('cle', 'motifs_urgence_predefinis')->delete();
        } else {
            // Insérer des valeurs par défaut si aucun JSON n'existait
            DB::table('motifs_urgence')->insert([
                ['libelle' => 'Panne machine/équipement', 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
                ['libelle' => 'Accident/incident sécurité', 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
                ['libelle' => 'Urgence médicale', 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
                ['libelle' => 'Rupture de stock critique', 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
                ['libelle' => 'Obligation légale/réglementaire', 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
                ['libelle' => 'Autre (préciser)', 'actif' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('motifs_urgence');
    }
};
