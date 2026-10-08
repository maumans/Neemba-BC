<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M12-1 — Modèle de données des ordres de mission (spec v2.2, §7.3).
 *
 * La table « ordres_mission » d'origine n'était qu'une ébauche (un collaborateur, un bon), jamais alimentée :
 * elle est remplacée. La clé étrangère bons_caisse.odm_id (lot 3) est reconstruite vers la nouvelle table.
 *
 * - ordres_mission : en-tête de l'ODM ; un segment de mission (l'ODM initial ou une prolongation) ;
 * - odm_participants : un salarié et son calcul (jours, nuits, indemnités, hébergement, total) ;
 * - odm_ordres_reparation : OR liés (8 chiffres commençant par 110, VENTE ou GARANTIE) ;
 * - odm_etapes : circuit propre à l'ODM (chef d'atelier → DAF → DP, RH en option) ;
 * - odm_historique : journal de l'ODM ;
 * - compteurs_odm : N°[séquence]/[préfixe]/[AA], une séquence par préfixe et par année (RG-M12-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('ordres_mission')->exists()) {
            throw new RuntimeException('La table ordres_mission contient des données : migration M12 à adapter avant de la remplacer.');
        }

        Schema::table('bons_caisse', fn (Blueprint $table) => $table->dropForeign(['odm_id']));
        Schema::drop('ordres_mission');

        Schema::create('ordres_mission', function (Blueprint $table) {
            $table->id();

            /* RG-M12-03 : numéro attribué à la soumission */
            $table->string('numero', 30)->nullable()->unique();
            $table->string('prefixe', 10)->nullable();
            $table->unsignedInteger('sequence')->nullable();
            $table->unsignedSmallInteger('annee')->nullable();

            /* RG-M12-01, RG-M12-02 */
            $table->enum('type', ['interieur', 'exterieur'])->default('interieur');
            $table->boolean('technique')->default(false);

            /* Émetteur (repris sur les bons générés, comme les champs des bons : nom du site et du service, code analytique) */
            $table->string('entite', 60)->nullable();
            $table->string('site')->nullable();
            $table->string('service')->nullable();
            $table->string('code_analytique')->nullable();     // décision Q22
            $table->foreignId('demandeur_id')->constrained('users');
            $table->foreignId('initiateur_id')->nullable()->constrained('users')->nullOnDelete();

            /* RG-M12-05 */
            $table->text('but')->nullable();
            $table->json('clients')->nullable();
            $table->json('destinations')->nullable();
            $table->string('vehicule')->nullable();

            /* RG-M12-06, RG-M12-20 */
            $table->date('date_depart')->nullable();
            $table->date('date_retour_prevue')->nullable();
            $table->date('date_retour_reelle')->nullable();
            $table->string('motif_depart_passe', 500)->nullable();

            /* RG-M12-15 */
            $table->enum('prise_en_charge', ['neemba', 'client'])->default('neemba');
            $table->boolean('a_refacturer')->default(false);

            /* RG-M12-26, RG-M12-27 : ODM extérieur */
            $table->enum('hebergement_exterieur', ['filiale', 'avant_depart', 'au_retour'])->nullable();
            $table->string('reference_billet')->nullable();

            /* RG-M12-17 : segments d'une mission */
            $table->foreignId('mission_id')->nullable()->constrained('ordres_mission')->nullOnDelete();
            $table->foreignId('segment_precedent_id')->nullable()->constrained('ordres_mission')->nullOnDelete();
            $table->unsignedTinyInteger('rang')->default(1);

            /* RG-M12-21 */
            $table->enum('statut', ['BROUILLON', 'SOUMIS', 'EN_VALIDATION', 'REJETE', 'VALIDE', 'BONS_GENERES', 'PAYE', 'CLOTURE', 'ANNULE'])
                ->default('BROUILLON');
            $table->unsignedInteger('version')->default(1);
            $table->string('cle_soumission', 64)->nullable()->unique();
            $table->decimal('total', 15, 2)->nullable();

            /* RG-M12-25 : barèmes et paramètres figés à la validation finale */
            $table->json('parametres_figes')->nullable();

            /* RG-M12-16 : dérogation au chevauchement, demandée par le demandeur, accordée par le DAF */
            $table->enum('derogation_statut', ['demandee', 'accordee', 'refusee'])->nullable();
            $table->text('derogation_demande_motif')->nullable();
            $table->foreignId('derogation_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('derogation_motif')->nullable();
            $table->timestamp('derogation_le')->nullable();

            $table->timestamp('date_soumission')->nullable();
            $table->timestamp('date_validation')->nullable();
            $table->timestamp('date_cloture')->nullable();
            $table->timestamp('date_annulation')->nullable();
            $table->foreignId('annule_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_annulation')->nullable();
            $table->timestamp('rappel_envoye_le')->nullable();      // RG-M12-28
            $table->timestamps();

            $table->index(['statut', 'date_depart']);
            $table->index('demandeur_id');
        });

        Schema::create('odm_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordre_mission_id')->constrained('ordres_mission')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');

            /* RG-M12-04 : repris du référentiel au moment de l'ajout */
            $table->string('nom');
            $table->string('matricule')->nullable();
            $table->string('service')->nullable();
            $table->enum('statut_cadre', ['cadre', 'non_cadre'])->nullable();
            $table->string('numero_om', 9)->nullable();
            $table->boolean('base_vie')->default(false);           // RG-M12-09

            /* RG-M12-07 : calcul du participant */
            $table->unsignedSmallInteger('jours')->default(0);
            $table->unsignedSmallInteger('nuits')->default(0);
            $table->unsignedTinyInteger('nuit_rattrapage')->default(0);   // RG-M12-18
            $table->decimal('indemnite_fcfa', 15, 2)->nullable();
            $table->decimal('indemnite', 15, 2)->nullable();
            $table->decimal('hebergement', 15, 2)->default(0);
            $table->decimal('rattrapage', 15, 2)->default(0);
            $table->decimal('hebergement_facture', 15, 2)->nullable();   // extérieur, facture payée avant le départ (Q24)
            $table->decimal('total', 15, 2)->nullable();

            /* RG-M12-17 : retiré d'une prolongation */
            $table->boolean('retire')->default(false);

            /* RG-M12-20 : retour anticipé */
            $table->decimal('trop_percu', 15, 2)->nullable();
            $table->enum('regularisation', ['reversement', 'retenue'])->nullable();
            $table->enum('regularisation_statut', ['a_regulariser', 'regularise'])->nullable();

            $table->foreignId('bon_caisse_id')->nullable()->constrained('bons_caisse')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ordre_mission_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('odm_ordres_reparation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordre_mission_id')->constrained('ordres_mission')->cascadeOnDelete();
            $table->string('numero', 8);
            $table->enum('type', ['vente', 'garantie'])->default('vente');
            $table->timestamps();

            $table->unique(['ordre_mission_id', 'numero']);
        });

        Schema::create('odm_etapes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordre_mission_id')->constrained('ordres_mission')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedTinyInteger('niveau');
            $table->string('role', 40);
            $table->enum('statut', ['a_venir', 'en_attente', 'validee', 'rejetee', 'annulee'])->default('a_venir');
            $table->foreignId('valideur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('au_titre_de_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_attribution')->nullable();
            $table->timestamp('date_decision')->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamp('derniere_relance')->nullable();
            $table->boolean('escalade')->default(false);
            $table->timestamps();

            $table->index(['ordre_mission_id', 'version', 'niveau']);
            $table->index(['role', 'statut']);
        });

        Schema::create('odm_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordre_mission_id')->constrained('ordres_mission')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('statut_avant', 20)->nullable();
            $table->string('statut_apres', 20)->nullable();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('commentaire')->nullable();
            $table->json('metadonnees')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['ordre_mission_id', 'created_at']);
        });

        Schema::create('compteurs_odm', function (Blueprint $table) {
            $table->id();
            $table->string('prefixe', 10);
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('dernier_numero')->default(0);
            $table->timestamps();

            $table->unique(['prefixe', 'annee']);
        });

        /* Q28 : préfixe de numérotation et liste de diffusion des ODM, par service */
        Schema::table('services', function (Blueprint $table) {
            $table->string('prefixe_odm', 10)->nullable()->after('equivalent_odm');
            $table->json('diffusion_odm')->nullable()->after('prefixe_odm');
        });
        DB::table('services')->where('nom', 'Technique')->whereNull('prefixe_odm')->update(['prefixe_odm' => 'AT']);

        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->foreign('odm_id')->references('id')->on('ordres_mission')->nullOnDelete();
            $table->foreignId('odm_participant_id')->nullable()->after('odm_id')->constrained('odm_participants')->nullOnDelete();
            $table->boolean('genere_par_odm')->default(false)->after('odm_participant_id');
        });
    }

    public function down(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropForeign(['odm_id']);
            $table->dropConstrainedForeignId('odm_participant_id');
            $table->dropColumn('genere_par_odm');
        });
        DB::table('bons_caisse')->update(['odm_id' => null]);

        Schema::table('services', fn (Blueprint $table) => $table->dropColumn(['prefixe_odm', 'diffusion_odm']));
        Schema::dropIfExists('compteurs_odm');
        Schema::dropIfExists('odm_historique');
        Schema::dropIfExists('odm_etapes');
        Schema::dropIfExists('odm_ordres_reparation');
        Schema::dropIfExists('odm_participants');
        Schema::drop('ordres_mission');

        /* Ébauche d'origine (2026_03_05_000005) */
        Schema::create('ordres_mission', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('collaborateur');
            $table->string('destination');
            $table->text('objet')->nullable();
            $table->date('date_depart');
            $table->date('date_retour');
            $table->decimal('montant_indemnites', 15, 2)->default(0);
            $table->foreignId('bon_caisse_id')->nullable()->constrained('bons_caisse')->onDelete('set null');
            $table->timestamps();
        });
        Schema::table('bons_caisse', fn (Blueprint $table) => $table->foreign('odm_id')->references('id')->on('ordres_mission')->nullOnDelete());
    }
};
