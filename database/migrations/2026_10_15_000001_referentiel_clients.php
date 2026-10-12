<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Spec v2.3, §6.5 : référentiel des clients (code, raison sociale, actif), alimenté par import puis par la Comptabilité
 * ou le CDG. Plus de saisie libre sur l'ODM : ses clients et le client facultatif de chaque OR viennent du référentiel.
 *
 * Reprise (décision Q54) : chaque nom saisi librement sur un ODM devient un client « REP-… », rattaché à l'ODM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('raison_sociale', 150);
            $table->boolean('actif')->default(true);
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('raison_sociale');
        });

        Schema::create('odm_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ordre_mission_id')->constrained('ordres_mission')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['ordre_mission_id', 'client_id']);
        });

        Schema::table('odm_ordres_reparation', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('type')->constrained('clients')->nullOnDelete();
        });

        /* Reprise du texte libre */
        $maintenant = now();
        foreach (DB::table('ordres_mission')->whereNotNull('clients')->get(['id', 'clients']) as $odm) {
            foreach (array_filter((array) json_decode($odm->clients, true), 'filled') as $nom) {
                $nom = mb_substr(trim((string) $nom), 0, 150);
                $code = mb_substr('REP-' . Str::upper(Str::slug($nom)), 0, 30);
                $clientId = DB::table('clients')->where('code', $code)->value('id')
                    ?? DB::table('clients')->insertGetId(['code' => $code, 'raison_sociale' => $nom, 'actif' => true, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
                DB::table('odm_clients')->insertOrIgnore(['ordre_mission_id' => $odm->id, 'client_id' => $clientId, 'created_at' => $maintenant, 'updated_at' => $maintenant]);
            }
        }

        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->dropColumn('clients');
        });
    }

    public function down(): void
    {
        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->json('clients')->nullable()->after('but');
        });
        foreach (DB::table('odm_clients')->join('clients', 'clients.id', '=', 'odm_clients.client_id')
            ->get(['odm_clients.ordre_mission_id', 'clients.raison_sociale'])->groupBy('ordre_mission_id') as $odmId => $lignes) {
            DB::table('ordres_mission')->where('id', $odmId)->update(['clients' => json_encode($lignes->pluck('raison_sociale')->values()->all())]);
        }

        Schema::table('odm_ordres_reparation', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
        Schema::dropIfExists('odm_clients');
        Schema::dropIfExists('clients');
    }
};
