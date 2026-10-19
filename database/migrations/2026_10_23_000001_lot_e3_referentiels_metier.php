<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot E3 (spec v2.4, RG-M01-09, RG-M02-10, RG-M03-13, §6.5, §6.6) : référentiels métier tenus par le gestionnaire des
 * référentiels.
 *
 * - types_or : types d'OR en référentiel (VENTE, GARANTIE au départ, PO-28) ; le type de l'OR d'un ODM n'est plus un enum.
 * - baremes_odm : barèmes ODM datés (date d'effet, historique, sans double validation). Les valeurs des paramètres du
 *   groupe « odm » sont reprises comme barèmes communs à toutes les entités, puis ces paramètres sont retirés.
 */
return new class extends Migration
{
    /** Barème => paramètre repris */
    private const REPRISE = [
        'indemnite_journaliere' => 'odm_indemnite_journaliere',
        'libelle_indemnite_1' => 'odm_libelle_indemnite_1',
        'libelle_indemnite_2' => 'odm_libelle_indemnite_2',
        'hebergement_nuit' => 'odm_hebergement_nuit',
        'bareme_fcfa_non_cadre' => 'odm_bareme_fcfa_non_cadre',
        'bareme_fcfa_cadre' => 'odm_bareme_fcfa_cadre',
    ];

    public function up(): void
    {
        $maintenant = now();
        $entite = DB::table('entites')->where('code', 'NGU')->value('id');

        Schema::create('types_or', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_id')->nullable()->constrained('entites');
            $table->string('code', 30);
            $table->string('libelle', 100);
            $table->boolean('actif')->default(true);
            $table->unsignedInteger('ordre')->default(0);
            $table->timestamps();
            $table->index(['entite_id', 'code']);
        });
        DB::table('types_or')->insert([
            ['entite_id' => $entite, 'code' => 'vente', 'libelle' => 'Vente', 'actif' => true, 'ordre' => 1, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            ['entite_id' => $entite, 'code' => 'garantie', 'libelle' => 'Garantie', 'actif' => true, 'ordre' => 2, 'created_at' => $maintenant, 'updated_at' => $maintenant],
        ]);

        Schema::table('odm_ordres_reparation', function (Blueprint $table) {
            $table->string('type', 30)->default('vente')->change();
        });

        Schema::create('baremes_odm', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entite_id')->nullable()->constrained('entites');
            $table->string('cle', 40);
            $table->string('valeur', 255);
            $table->date('date_effet');
            $table->foreignId('saisi_par')->nullable()->constrained('users');
            $table->string('commentaire', 255)->nullable();
            $table->timestamps();
            $table->index(['cle', 'date_effet']);
        });

        foreach (self::REPRISE as $cle => $parametre) {
            $ligne = DB::table('parametres')->where('cle', $parametre)->first();
            if (!$ligne) {
                continue;
            }
            DB::table('baremes_odm')->insert([
                'entite_id' => null,
                'cle' => $cle,
                'valeur' => $ligne->valeur,
                'date_effet' => substr((string) ($ligne->created_at ?? '2026-10-08'), 0, 10),
                'commentaire' => "Reprise du paramètre {$parametre}",
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
            /* Une double validation en attente sur ce paramètre n'a plus d'objet (RG-M02-10 : barèmes hors circuit) */
            DB::table('modifications_en_attente')->where('type_entite', 'parametre')->where('entite_id', $ligne->id)
                ->where('statut', 'en_attente')
                ->update(['statut' => 'refusee', 'commentaire' => 'Barème ODM repris dans le référentiel des barèmes (lot E3)', 'updated_at' => $maintenant]);
            DB::table('parametres')->where('id', $ligne->id)->delete();
        }
    }

    public function down(): void
    {
        $maintenant = now();
        $libelles = [
            'indemnite_journaliere' => ['Indemnité journalière (ODM intérieur, GNF)', 'number'],
            'libelle_indemnite_1' => ["Libellé de la première ligne d'indemnité", 'text'],
            'libelle_indemnite_2' => ["Libellé de la seconde ligne d'indemnité", 'text'],
            'hebergement_nuit' => ['Hébergement par nuit (ODM intérieur, GNF)', 'number'],
            'bareme_fcfa_non_cadre' => ['Indemnité extérieure, non-cadre (FCFA par jour)', 'number'],
            'bareme_fcfa_cadre' => ['Indemnité extérieure, cadre (FCFA par jour)', 'number'],
        ];
        foreach (self::REPRISE as $cle => $parametre) {
            $valeur = DB::table('baremes_odm')->where('cle', $cle)->whereNull('entite_id')
                ->where('date_effet', '<=', $maintenant->toDateString())->orderByDesc('date_effet')->orderByDesc('id')->value('valeur');
            if ($valeur === null) {
                continue;
            }
            DB::table('parametres')->insertOrIgnore([
                'cle' => $parametre, 'valeur' => $valeur, 'libelle' => $libelles[$cle][0], 'type' => $libelles[$cle][1],
                'groupe' => 'odm', 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
        }
        Schema::dropIfExists('baremes_odm');

        DB::table('odm_ordres_reparation')->whereNotIn('type', ['vente', 'garantie'])->update(['type' => 'vente']);
        Schema::table('odm_ordres_reparation', function (Blueprint $table) {
            $table->enum('type', ['vente', 'garantie'])->default('vente')->change();
        });
        Schema::dropIfExists('types_or');
    }
};
