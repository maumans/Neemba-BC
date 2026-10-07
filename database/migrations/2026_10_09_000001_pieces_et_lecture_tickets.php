<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 4 — Pièces justificatives et lecture des tickets carburant (SFD v1.3, US-BC-08, US-BC-09).
 *
 * - Qualité d'une pièce en trois niveaux (RG-BC-16) : conforme, qualité moyenne, illisible.
 *   `qualite_ok` est conservé pour l'archivage (faux seulement pour une pièce illisible).
 * - Pièce déjà utilisée sur un autre bon (RG-BC-19) : lien vers la première pièce identique,
 *   confirmation et justification du demandeur. Les doublons existants sont repérés.
 * - Nouvelle version d'une pièce après soumission (E-03.6) : l'ancienne reste dans l'historique.
 * - Lecture des tickets carburant (RG-BC-20, RG-BC-21) : valeurs lues, confiance par champ,
 *   valeurs validées par l'utilisateur et champs corrigés.
 * - Paramètre prix_litre_reference (RG-BC-23) : 12 000 GNF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pieces_jointes', function (Blueprint $table) {
            $table->enum('qualite', ['conforme', 'moyenne', 'illisible'])->nullable()->after('qualite_ok');
            $table->foreignId('doublon_de_id')->nullable()->after('checksum')->constrained('pieces_jointes')->nullOnDelete();
            $table->boolean('doublon_confirme')->default(false)->after('doublon_de_id');
            $table->text('justification_doublon')->nullable()->after('doublon_confirme');
            $table->foreignId('remplacee_par_id')->nullable()->after('version')->constrained('pieces_jointes')->nullOnDelete();
        });

        /* Les pièces déjà marquées de faible qualité restent « illisibles » ; les autres ne sont pas mesurées */
        DB::table('pieces_jointes')->where('qualite_ok', false)->update(['qualite' => 'illisible']);

        /* Doublons existants : une pièce identique à une pièce plus ancienne d'un autre bon non annulé */
        $pieces = DB::table('pieces_jointes')
            ->join('bons_caisse', 'bons_caisse.id', '=', 'pieces_jointes.bon_caisse_id')
            ->whereNotNull('pieces_jointes.checksum')
            ->where('bons_caisse.statut', '!=', 'ANNULE')
            ->orderBy('pieces_jointes.id')
            ->get(['pieces_jointes.id', 'pieces_jointes.bon_caisse_id', 'pieces_jointes.checksum']);
        $premieres = [];
        foreach ($pieces as $piece) {
            $premiere = $premieres[$piece->checksum] ?? null;
            if ($premiere === null) {
                $premieres[$piece->checksum] = $piece;
            } elseif ($premiere->bon_caisse_id !== $piece->bon_caisse_id) {
                DB::table('pieces_jointes')->where('id', $piece->id)->update(['doublon_de_id' => $premiere->id]);
            }
        }

        Schema::create('lectures_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piece_jointe_id')->unique()->constrained('pieces_jointes')->cascadeOnDelete();
            $table->enum('statut', ['en_cours', 'terminee', 'indisponible', 'validee'])->default('en_cours');
            $table->string('lecteur', 40);
            $table->json('valeurs_lues')->nullable();
            $table->json('confiances')->nullable();
            $table->json('valeurs_validees')->nullable();
            $table->json('champs_corriges')->nullable();
            $table->timestamp('demarree_le')->nullable();
            $table->timestamp('terminee_le')->nullable();
            $table->foreignId('validee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validee_le')->nullable();
            $table->timestamps();
        });

        DB::table('parametres')->insertOrIgnore([
            'cle' => 'prix_litre_reference',
            'valeur' => '12000',
            'libelle' => 'Prix de référence du carburant (GNF/L)',
            'description' => 'Un ticket dont le prix au litre s\'écarte de plus de 10 % de cette valeur est signalé (RG-BC-22)',
            'type' => 'number',
            'groupe' => 'carburant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'prix_litre_reference')->delete();
        Schema::dropIfExists('lectures_tickets');

        Schema::table('pieces_jointes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remplacee_par_id');
            $table->dropConstrainedForeignId('doublon_de_id');
            $table->dropColumn(['qualite', 'doublon_confirme', 'justification_doublon']);
        });
    }
};
