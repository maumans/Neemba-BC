<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot F2b (spec v2.4, RG-M10-01) : inventaire obligatoire en fin de mois ; contrôle inopiné trimestriel pour Conakry, Boké
 * et Sangarédi, à la fréquence paramétrable (défaut : mensuelle) pour les autres caisses. Le Chef comptable est garant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caisses', function (Blueprint $table) {
            $table->string('frequence_controle', 20)->nullable()->after('reappro_par_transfert');
        });
        DB::table('caisses')->whereIn('code', ['CKY-ESP', 'BOK-ESP', 'SAN-ESP'])->update(['frequence_controle' => 'trimestrielle']);

        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'frequence_controle_inopine', 'valeur' => 'mensuelle', 'type' => 'choix', 'groupe' => 'caisse',
            'libelle' => 'Fréquence des contrôles inopinés (caisses sans fréquence propre)',
            'description' => 'RG-M10-01 : mensuelle par défaut ; Conakry, Boké et Sangarédi : trimestrielle (réglée sur la caisse)',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
        /* Les périodes terminées avant la mise en service ne déclenchent pas d'alerte de retard */
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'inventaires_suivis_depuis', 'valeur' => $maintenant->copy()->startOfMonth()->toDateString(), 'type' => 'text', 'groupe' => 'caisse',
            'libelle' => 'Suivi des inventaires et contrôles inopinés depuis le (AAAA-MM-JJ)',
            'description' => 'RG-M10-01 : aucune alerte de retard pour une période terminée avant cette date',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->whereIn('cle', ['frequence_controle_inopine', 'inventaires_suivis_depuis'])->delete();
        Schema::table('caisses', fn (Blueprint $table) => $table->dropColumn('frequence_controle'));
    }
};
