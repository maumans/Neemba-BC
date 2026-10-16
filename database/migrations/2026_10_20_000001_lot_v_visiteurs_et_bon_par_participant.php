<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Lot V (spec v2.4) : corrections de l'existant contredit par la v2.4.
 *
 * - RG-M03-04, RG-M03-28 : un visiteur du groupe ne reçoit qu'un BD, à titre exceptionnel ; motif d'une liste fermée
 *   paramétrable (PO-30, vide au départ), commentaire obligatoire ; bon marqué « refacturation inter-filiale » avec la
 *   filiale d'origine. Les bons existants au profit d'un visiteur sont marqués ; leurs BP gardent leur cycle (décision du
 *   16/10) et sont seulement listés au journal.
 * - RG-M04-10 : étape Finance d'un bon visiteur réservée au DAF ou au DAF adjoint (paramètre, défaut : oui).
 * - RG-M12-14 : un bon par participant ; le paramètre du bon groupé est retiré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motifs_visiteur', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });

        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->string('motif_visiteur')->nullable()->after('justification_urgence');
            $table->text('commentaire_visiteur')->nullable()->after('motif_visiteur');
            $table->boolean('refacturation_inter_filiale')->default(false)->after('commentaire_visiteur');
            $table->string('filiale_refacturation', 120)->nullable()->after('refacturation_inter_filiale');
        });

        /* Bons déjà émis au profit d'un visiteur : marqués pour le reporting, avec leur filiale */
        DB::table('bons_caisse')->whereNotNull('visiteur_id')->orderBy('id')->each(function ($bon) {
            DB::table('bons_caisse')->where('id', $bon->id)->update([
                'refacturation_inter_filiale' => true,
                'filiale_refacturation' => DB::table('visiteurs')->where('id', $bon->visiteur_id)->value('filiale'),
            ]);
        });
        $bpVisiteurs = DB::table('bons_caisse')->whereNotNull('visiteur_id')->where('type_bon', 'BP')->pluck('numero');
        if ($bpVisiteurs->isNotEmpty()) {
            Log::info('Lot V : BP déjà émis au profit d\'un visiteur du groupe, conservés : ' . $bpVisiteurs->filter()->implode(', '));
        }

        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'visiteur_finance_daf_seul', 'valeur' => 'true',
            'libelle' => 'Bon visiteur : Finance réservée au DAF',
            'description' => 'RG-M04-10 : l\'étape Finance d\'un bon au profit d\'un visiteur du groupe est visée par le DAF ou le DAF adjoint, pas par le Chef comptable',
            'type' => 'boolean', 'groupe' => 'bons_caisse', 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
        DB::table('parametres')->where('cle', 'odm_mode_generation')->delete();
    }

    public function down(): void
    {
        $maintenant = now();
        DB::table('parametres')->insertOrIgnore([
            'cle' => 'odm_mode_generation', 'valeur' => 'par_participant', 'libelle' => 'Mode de génération des bons d\'un ODM',
            'description' => 'RG-M12-14 (PO-03, arbitrage du DAF attendu)', 'type' => 'choix', 'groupe' => 'odm',
            'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
        DB::table('parametres')->where('cle', 'visiteur_finance_daf_seul')->delete();
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropColumn(['motif_visiteur', 'commentaire_visiteur', 'refacturation_inter_filiale', 'filiale_refacturation']);
        });
        Schema::dropIfExists('motifs_visiteur');
    }
};
