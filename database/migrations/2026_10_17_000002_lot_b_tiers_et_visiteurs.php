<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot B (spec v2.3, §6.5, RG-M02-06, RG-M02-07, RG-M03-03, RG-M03-04) : référentiels des tiers (proposés, validés par la
 * Comptabilité ou le CDG) et des visiteurs du groupe ; plus de bénéficiaire en texte libre.
 * Reprise (Q62) : les tiers déjà saisis en texte libre deviennent des tiers « proposés », à valider ; les anciens bons
 * gardent leur texte et sont rattachés au tiers repris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 150);
            $table->enum('type', ['fournisseur', 'prestataire', 'particulier']);
            $table->string('telephone', 20)->nullable();
            $table->string('numero_om', 20)->nullable();
            $table->string('nif', 40)->nullable();
            $table->enum('statut', ['propose', 'valide', 'refuse', 'desactive'])->default('propose');
            $table->string('motif_refus', 500)->nullable();
            $table->enum('origine', ['saisie', 'reprise'])->default('saisie');
            $table->foreignId('propose_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('propose_le')->nullable();
            $table->foreignId('valide_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->timestamps();
            $table->index(['statut', 'nom']);
            $table->index('telephone');
            $table->index('numero_om');
            $table->index('nif');
        });

        Schema::create('visiteurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('filiale', 120);
            $table->string('matricule_groupe', 40)->nullable();
            $table->string('numero_om', 20)->nullable();
            $table->string('departement_accueil', 100);
            $table->foreignId('directeur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_debut');
            $table->date('date_fin');
            $table->boolean('actif')->default(true);
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['actif', 'nom']);
        });

        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->string('type_beneficiaire', 20)->default('employe')->change();
            $table->foreignId('tiers_id')->nullable()->after('beneficiaire_id')->constrained('tiers')->nullOnDelete();
            $table->foreignId('visiteur_id')->nullable()->after('tiers_id')->constrained('visiteurs')->nullOnDelete();
        });

        /* Reprise : un tiers « proposé » par nom distinct, rattaché à ses anciens bons */
        $types = ['fournisseur' => 'fournisseur', 'prestataire' => 'prestataire', 'autre' => 'particulier'];
        $bons = DB::table('bons_caisse')->whereIn('type_beneficiaire', array_keys($types))->whereNotNull('beneficiaire')
            ->orderBy('id')->get(['id', 'beneficiaire', 'type_beneficiaire', 'telephone_beneficiaire', 'demandeur_id', 'created_at']);
        $maintenant = now();
        $repris = [];
        foreach ($bons as $bon) {
            $cle = mb_strtolower(trim(preg_replace('/\s+/', ' ', $bon->beneficiaire)));
            if ($cle === '') {
                continue;
            }
            if (!isset($repris[$cle])) {
                $repris[$cle] = DB::table('tiers')->insertGetId([
                    'nom' => mb_substr(trim(preg_replace('/\s+/', ' ', $bon->beneficiaire)), 0, 150),
                    'type' => $types[$bon->type_beneficiaire],
                    'telephone' => $bon->telephone_beneficiaire,
                    'statut' => 'propose',
                    'origine' => 'reprise',
                    'propose_par_id' => $bon->demandeur_id,
                    'propose_le' => $bon->created_at ?? $maintenant,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            }
            DB::table('bons_caisse')->where('id', $bon->id)->update(['tiers_id' => $repris[$cle]]);
        }
    }

    public function down(): void
    {
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visiteur_id');
            $table->dropConstrainedForeignId('tiers_id');
        });
        DB::table('bons_caisse')->whereIn('type_beneficiaire', ['visiteur', 'tiers'])->update(['type_beneficiaire' => 'autre']);
        Schema::table('bons_caisse', function (Blueprint $table) {
            $table->enum('type_beneficiaire', ['employe', 'fournisseur', 'prestataire', 'autre'])->default('employe')->change();
        });
        Schema::dropIfExists('visiteurs');
        Schema::dropIfExists('tiers');
    }
};
