<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot E4 (spec v2.4, RG-M02-11, §6.1) : une caisse sert un ou plusieurs sites, un site peut avoir plusieurs caisses, la
 * caisse Orange Money n'a pas de site. Le lien caisse → site unique (`caisses.site_id`) devient la table `caisse_site` ;
 * la caisse OM n'y est pas reprise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisse_site', function (Blueprint $table) {
            $table->foreignId('caisse_id')->constrained('caisses')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->primary(['caisse_id', 'site_id']);
        });

        DB::table('caisse_site')->insertUsing(['caisse_id', 'site_id'],
            DB::table('caisses')->select('id', 'site_id')->whereNotNull('site_id')->where('type', '!=', 'orange_money'));

        Schema::table('caisses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('caisses', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('libelle')->constrained('sites');
        });

        /* Premier site servi ; la caisse OM (et toute caisse sans site) revient à Conakry, sinon au premier site */
        $parDefaut = DB::table('sites')->where('code', '01')->orWhere('nom', 'Conakry')->value('id') ?? DB::table('sites')->min('id');
        foreach (DB::table('caisses')->pluck('id') as $caisse) {
            $site = DB::table('caisse_site')->where('caisse_id', $caisse)->min('site_id') ?? $parDefaut;
            DB::table('caisses')->where('id', $caisse)->update(['site_id' => $site]);
        }
        Schema::table('caisses', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->nullable(false)->change();
        });

        Schema::dropIfExists('caisse_site');
    }
};
