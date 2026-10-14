<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot E (spec v2.3, M07) : justification d'un BP conforme à l'Annexe 3 (RG-M07-03) — en-tête, jusqu'à 10 lignes, calculs,
 * visas initiateur → N+1 → CDG → Comptabilité ; complément versé par un BD lié, reliquat rétrocédé en caisse (RG-M07-08) ;
 * suivi des BP en retard transmis aux RH, information de la DRH et retenue tracées (RG-M07-05).
 */
return new class extends Migration
{
    private const TYPES_PIECE = "'facture','recu','devis','ordre_mission','proforma','email','recu_carburant','bon_commande','rapport_journalier','justificatif','demande_achat','bon_travail','autre'";

    public function up(): void
    {
        /* Pièce d'une ligne de justification */
        DB::statement('ALTER TABLE pieces_jointes MODIFY COLUMN type_document ENUM(' . self::TYPES_PIECE . ",'justificatif_bp') NULL");

        Schema::create('justifications_bp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bon_caisse_id')->unique()->constrained('bons_caisse')->cascadeOnDelete();
            $table->foreignId('initiateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('statut', ['brouillon', 'en_validation', 'rejetee', 'validee'])->default('brouillon');
            $table->enum('etape', ['n1', 'cdg', 'comptabilite'])->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->decimal('montant_avance', 15, 2);
            $table->decimal('montant_justifie', 15, 2)->default(0);
            $table->decimal('montant_a_retourner', 15, 2)->default(0);
            $table->decimal('trop_justifie', 15, 2)->default(0);
            $table->timestamp('soumise_le')->nullable();
            $table->timestamp('validee_le')->nullable();
            $table->foreignId('bon_complement_id')->nullable()->constrained('bons_caisse')->nullOnDelete();
            $table->timestamp('reliquat_encaisse_le')->nullable();
            $table->foreignId('reliquat_encaisse_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('justification_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('justification_id')->constrained('justifications_bp')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->string('intitule', 150);
            $table->string('code_analytique', 50)->nullable();
            $table->string('compte', 20)->nullable();
            $table->decimal('montant', 15, 2)->default(0);
            $table->foreignId('piece_jointe_id')->nullable()->constrained('pieces_jointes')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('justification_visas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('justification_id')->constrained('justifications_bp')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->enum('etape', ['initiateur', 'n1', 'cdg', 'comptabilite']);
            $table->enum('decision', ['soumise', 'visee', 'rejetee', 'sautee']);
            $table->foreignId('valideur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('au_titre_de_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('commentaire')->nullable();
            $table->timestamps();
        });

        Schema::table('bons_caisse', function (Blueprint $table) {
            /* BD de complément lié au BP régularisé (RG-M07-08) */
            $table->foreignId('bp_regularise_id')->nullable()->after('odm_id')->constrained('bons_caisse')->nullOnDelete();
            /* RG-M07-05 : transmission aux RH, information de la DRH, retenue sur salaire */
            $table->json('suivi_rh')->nullable()->after('derogation_bp');
        });
    }

    public function down(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bp_regularise_id');
            $table->dropColumn('suivi_rh');
        });
        Schema::dropIfExists('justification_visas');
        Schema::dropIfExists('justification_lignes');
        Schema::dropIfExists('justifications_bp');
        DB::table('pieces_jointes')->where('type_document', 'justificatif_bp')->update(['type_document' => 'justificatif']);
        DB::statement('ALTER TABLE pieces_jointes MODIFY COLUMN type_document ENUM(' . self::TYPES_PIECE . ') NULL');
    }
};
