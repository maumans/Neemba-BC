<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot D2 (spec v2.3) : motifs de rejet paramétrables, avec la liste initiale de la spec (RG-M04-04) ; référence du
 * paiement selon le mode (RG-M06-04) ; mode MTN Money paramétré, inactif par défaut (§6.2).
 * Les anciens motifs (constante de la SFD v1.3) sont repris désactivés : les rejets passés restent lisibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motifs_rejet', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle', 255);
            $table->boolean('actif')->default(true);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();
        });
        $maintenant = now();
        $motifs = [
            ['piece_manquante', 'Absence de pièce justificative', true],
            ['manque_authentification', "Manque d'authentification (bon non signé par le bénéficiaire, le demandeur ou le responsable habilité)", true],
            ['montant_errone', 'Montant erroné ou illisible', true],
            ['non_conforme_politique', 'Non-respect de la politique interne (plafond espèces)', true],
            ['mention_obligatoire', "Absence de mention obligatoire", true],
            ['autre', 'Autre (préciser en commentaire)', true],
            /* SFD v1.3, repris pour les rejets passés */
            ['budget_insuffisant', 'Budget insuffisant ou non alloué', false],
            ['montant_incorrect', 'Montant incorrect', false],
            ['code_analytique_errone', 'Code analytique erroné', false],
            ['piece_manquante_v13', 'Pièce justificative manquante', false],
            ['non_conforme_politique_v13', 'Non conforme à la politique de dépenses', false],
        ];
        foreach ($motifs as $ordre => [$code, $libelle, $actif]) {
            DB::table('motifs_rejet')->insert(compact('code', 'libelle', 'actif', 'ordre') + ['created_at' => $maintenant, 'updated_at' => $maintenant]);
        }

        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->string('reference_paiement', 100)->nullable()->after('mode_paiement_effectif');
            /* MTN Money (§6.2) : les modes passent d'une énumération à une chaîne */
            $table->string('mode_paiement', 20)->default('especes')->change();
            $table->string('mode_paiement_effectif', 20)->nullable()->change();
        });

        DB::table('parametres')->insertOrIgnore([
            'cle' => 'mode_mtn_actif', 'valeur' => 'false', 'libelle' => 'Paiement par MTN Money',
            'description' => '§6.2 : mode proposé au caissier une fois activé (paiement hors caisse, code journal MTN)',
            'type' => 'boolean', 'groupe' => 'orange_money', 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'mode_mtn_actif')->delete();
        DB::table('bons_caisse')->where('mode_paiement_effectif', 'mtn_money')->update(['mode_paiement_effectif' => 'autre']);
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropColumn('reference_paiement');
            $table->enum('mode_paiement', ['especes', 'orange_money', 'cheque', 'virement', 'autre'])->default('especes')->change();
            $table->enum('mode_paiement_effectif', ['especes', 'orange_money', 'cheque', 'virement', 'autre'])->nullable()->change();
        });
        Schema::dropIfExists('motifs_rejet');
    }
};
