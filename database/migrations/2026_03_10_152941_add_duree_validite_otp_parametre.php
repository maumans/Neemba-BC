<?php

use App\Models\Parametre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Parametre::create([
            'cle' => 'duree_validite_otp',
            'libelle' => 'Durée validité OTP (min)',
            'valeur' => '5',
            'type' => 'integer',
            'description' => 'Durée de validité du code OTP en minutes (pour validation paiement)',
            'categorie' => 'securite',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Parametre::where('cle', 'duree_validite_otp')->delete();
    }
};
