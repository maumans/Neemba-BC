<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot C (spec v2.3, RG-M03-18, RG-M08-11, RG-M02-03, RG-M03-11) :
 * - code de numérotation de chaque caisse (§6.1 : CKY, ATL, SAG, BOK, SGL, SGT, COR, OM), numéro de départ (reprise du
 *   carnet papier) et nombre de chiffres ;
 * - caisse de rattachement des utilisateurs (par défaut : caisse principale espèces de leur site) ;
 * - caisse émettrice du bon, qui numérote : BC-[code caisse]-[AAAA]-[00001], séquence par caisse et par année.
 * Les numéros déjà attribués (BC-AAAA-NNNN) ne changent pas (Q62).
 */
return new class extends Migration
{
    /** Codes de la spec (§6.1) pour les caisses créées par la migration des caisses (lot 2) */
    private const CODES = [
        'CKY-ESP' => 'CKY', 'CKY-ATL' => 'ATL', 'CKY-OM' => 'OM', 'BOK-ESP' => 'BOK', 'SAN-ESP' => 'SAG',
        'SIG-ESP' => 'SGL', 'MAN-ESP' => 'SGT', 'KOU-ESP' => 'COR',
    ];

    public function up(): void
    {
        Schema::table('caisses', function (Blueprint $table) {
            $table->string('code_numerotation', 10)->nullable()->after('code');
            $table->unsignedInteger('numero_depart')->nullable()->after('code_numerotation');
            $table->unsignedTinyInteger('chiffres_numero')->nullable()->after('numero_depart');
        });
        foreach (DB::table('caisses')->get(['id', 'code']) as $caisse) {
            $code = self::CODES[$caisse->code] ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $caisse->code), 0, 3));
            DB::table('caisses')->where('id', $caisse->id)->update(['code_numerotation' => $code ?: null]);
        }

        Schema::create('compteurs_bons', function (Blueprint $table) {
            $table->foreignId('caisse_id')->constrained('caisses')->cascadeOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('dernier_numero')->default(0);
            $table->timestamps();
            $table->primary(['caisse_id', 'annee']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('caisse_id')->nullable()->after('site')->constrained('caisses')->nullOnDelete();
        });
        /* Caisse de rattachement par défaut : la caisse principale (espèces, standard) du site de l'utilisateur */
        $principales = DB::table('caisses')->join('sites', 'sites.id', '=', 'caisses.site_id')
            ->where('caisses.type', 'especes')->where('caisses.mode', 'standard')->where('caisses.actif', true)
            ->orderBy('caisses.id')->get(['caisses.id', 'sites.nom'])->unique('nom')->pluck('id', 'nom');
        foreach ($principales as $site => $caisseId) {
            DB::table('users')->where('site', $site)->whereNull('caisse_id')->update(['caisse_id' => $caisseId]);
        }

        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->foreignId('caisse_emettrice_id')->nullable()->after('caisse_id')->constrained('caisses')->nullOnDelete();
        });

        DB::table('parametres')->insertOrIgnore([
            'cle' => 'numero_bon_chiffres', 'valeur' => '5', 'libelle' => 'Numéro de bon : nombre de chiffres de la séquence',
            'description' => 'RG-M03-18 : BC-[code caisse]-[AAAA]-[00001] ; une caisse peut avoir son propre nombre de chiffres (carnet Atelier)',
            'type' => 'number', 'groupe' => 'bons_caisse', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'numero_bon_chiffres')->delete();
        Schema::table('bons_caisse', fn (Blueprint $table) => $table->dropConstrainedForeignId('caisse_emettrice_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('caisse_id'));
        Schema::dropIfExists('compteurs_bons');
        Schema::table('caisses', fn (Blueprint $table) => $table->dropColumn(['code_numerotation', 'numero_depart', 'chiffres_numero']));
    }
};
