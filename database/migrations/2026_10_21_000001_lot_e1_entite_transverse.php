<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot E1 (spec v2.4, RG-M01-02, RG-M01-08, §2.1) : dimension « entité » transverse.
 *
 * - Table `entites` : Neemba Guinée (active), Neemba Mining (prévue, inactive : sa mise en service fait l'objet d'une
 *   proposition distincte).
 * - `entite_id` sur les utilisateurs, caisses, bons, ODM (obligatoire, repris sur Neemba Guinée) et sur les référentiels
 *   et paramètres (facultatif : vide = commun à toutes les entités ; les référentiels existants sont rattachés à Neemba
 *   Guinée).
 * - Droits par entité × rôle : `roles_utilisateurs.entite_id` (vide = toutes les entités), repris sur l'entité du compte.
 * - Numérotation des ODM par entité (`compteurs_odm`) ; les bons sont déjà numérotés par caisse, et la caisse porte
 *   l'entité.
 * - Les champs texte `users.entite` et `ordres_mission.entite` restent (import des référentiels) ; l'entité fait foi par
 *   `entite_id`.
 */
return new class extends Migration
{
    /** Tables dont l'entité est obligatoire */
    private const OBLIGATOIRES = ['users', 'caisses', 'bons_caisse', 'ordres_mission'];

    /** Référentiels et paramètres : entité facultative (vide = commun) */
    private const REFERENTIELS = ['services', 'codes_analytiques', 'clients', 'tiers', 'visiteurs', 'motifs_urgence', 'motifs_rejet', 'motifs_visiteur', 'parametres'];

    public function up(): void
    {
        Schema::create('entites', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('nom', 120);
            $table->string('raison_sociale', 150)->nullable();
            $table->text('adresse')->nullable();
            $table->string('logo', 255)->nullable();
            $table->boolean('actif')->default(false);
            $table->timestamps();
        });
        $maintenant = now();
        DB::table('entites')->insert([
            ['code' => 'NGU', 'nom' => 'Neemba Guinée', 'raison_sociale' => 'NEEMBA GUINEE',
                'adresse' => "Km 10 – Route du Niger, Commune de Matam\nB.P. 336 Conakry – Guinée\nTél. : +224 626 26 41 41 · info.guinee@neemba.com",
                'logo' => 'logo.png', 'actif' => true, 'created_at' => $maintenant, 'updated_at' => $maintenant],
            ['code' => 'NMI', 'nom' => 'Neemba Mining', 'raison_sociale' => 'NEEMBA MINING', 'adresse' => null,
                'logo' => 'logo.png', 'actif' => false, 'created_at' => $maintenant, 'updated_at' => $maintenant],
        ]);
        $guinee = DB::table('entites')->where('code', 'NGU')->value('id');

        foreach (array_merge(self::OBLIGATOIRES, self::REFERENTIELS) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('entite_id')->nullable()->after('id')->constrained('entites'));
            if ($table !== 'parametres') {
                DB::table($table)->update(['entite_id' => $guinee]);
            }
        }
        foreach (self::OBLIGATOIRES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('entite_id')->nullable(false)->change());
        }

        /* Droits par entité × rôle : les rôles existants valent dans l'entité du compte */
        Schema::table('roles_utilisateurs', function (Blueprint $table) {
            $table->foreignId('entite_id')->nullable()->after('role')->constrained('entites');
        });
        DB::statement('UPDATE roles_utilisateurs r JOIN users u ON u.id = r.user_id SET r.entite_id = u.entite_id');
        Schema::table('roles_utilisateurs', function (Blueprint $table) {
            $table->unique(['user_id', 'role', 'entite_id']);
        });
        Schema::table('roles_utilisateurs', fn (Blueprint $table) => $table->dropUnique(['user_id', 'role']));

        /* Numérotation des ODM par entité */
        Schema::table('compteurs_odm', function (Blueprint $table) {
            $table->foreignId('entite_id')->nullable()->after('id')->constrained('entites');
        });
        DB::table('compteurs_odm')->update(['entite_id' => $guinee]);
        Schema::table('compteurs_odm', function (Blueprint $table) {
            $table->unique(['entite_id', 'prefixe', 'annee']);
        });
        Schema::table('compteurs_odm', fn (Blueprint $table) => $table->dropUnique(['prefixe', 'annee']));

        DB::table('parametres')->insertOrIgnore([
            'cle' => 'roles_vue_consolidee', 'valeur' => 'daf,daf_adjoint,directeur_pays,dp_adjoint,administrateur',
            'libelle' => 'Rôles ayant la vue consolidée (toutes entités)',
            'description' => 'RG-M01-08 : rapports, tableaux de bord et exports de toutes les entités. Codes de rôles séparés par des virgules.',
            'type' => 'text', 'groupe' => 'securite', 'created_at' => $maintenant, 'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        DB::table('parametres')->where('cle', 'roles_vue_consolidee')->delete();

        /* La clé étrangère s'appuie sur l'index unique : elle part d'abord */
        Schema::table('compteurs_odm', fn (Blueprint $table) => $table->dropForeign(['entite_id']));
        Schema::table('compteurs_odm', function (Blueprint $table) {
            $table->unique(['prefixe', 'annee']);
            $table->dropUnique(['entite_id', 'prefixe', 'annee']);
            $table->dropColumn('entite_id');
        });

        DB::statement('DELETE r1 FROM roles_utilisateurs r1 JOIN roles_utilisateurs r2 ON r1.user_id = r2.user_id AND r1.role = r2.role AND r1.id > r2.id');
        Schema::table('roles_utilisateurs', fn (Blueprint $table) => $table->dropForeign(['entite_id']));
        Schema::table('roles_utilisateurs', function (Blueprint $table) {
            $table->unique(['user_id', 'role']);
            $table->dropUnique(['user_id', 'role', 'entite_id']);
            $table->dropColumn('entite_id');
        });

        foreach (array_merge(self::OBLIGATOIRES, self::REFERENTIELS) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('entite_id'));
        }
        Schema::dropIfExists('entites');
    }
};
