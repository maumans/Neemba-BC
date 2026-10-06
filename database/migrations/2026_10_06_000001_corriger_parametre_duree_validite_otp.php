<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Corrige le paramètre duree_validite_otp créé par 2026_03_10_152941 :
 * - type « integer » inconnu de Parametre::valeur() → « number » ;
 * - groupe perdu (la clé « categorie » n'existe pas, il était retombé sur « general ») → « securite ».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('parametres')
            ->where('cle', 'duree_validite_otp')
            ->update(['type' => 'number', 'groupe' => 'securite']);

        Cache::forget('parametre.duree_validite_otp');
    }

    public function down(): void
    {
        DB::table('parametres')
            ->where('cle', 'duree_validite_otp')
            ->update(['type' => 'integer', 'groupe' => 'general']);

        Cache::forget('parametre.duree_validite_otp');
    }
};
