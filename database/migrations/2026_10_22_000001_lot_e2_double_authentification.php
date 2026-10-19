<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot E2 (spec v2.4, RG-M01-07, SC-40) : double authentification.
 *
 * - `codes_otp` : codes à usage unique de connexion (email) et de visa d'une demande urgente (SMS), stockés hachés, avec
 *   expiration et nombre d'essais. Distincts de l'OTP de paiement (`otp_validations`, RG-M06-02).
 * - Niveau d'urgence et motif sur l'ODM (Q76), pour l'OTP de visa des ODM urgents.
 * - Paramètres : activation (secours en cas de panne SMTP ou SMS), durées, nombre d'essais.
 */
return new class extends Migration
{
    private const PARAMETRES = [
        ['double_authentification_active', 'true', 'Double authentification à la connexion',
            'RG-M01-07 : code envoyé par email à chaque connexion. À ne désactiver qu\'en secours (panne du serveur d\'email)', 'boolean'],
        ['otp_connexion_duree', '10', 'Validité du code de connexion (minutes)', 'RG-M01-07', 'number'],
        ['otp_connexion_tentatives', '5', 'Essais autorisés par code de connexion', 'RG-M01-07 : au-delà, un nouveau code doit être demandé', 'number'],
        ['otp_visa_urgent_actif', 'true', 'Code SMS pour viser une demande urgente',
            'RG-M01-07 : bon ou ODM urgent ou très urgent. À ne désactiver qu\'en secours (panne du fournisseur SMS)', 'boolean'],
        ['otp_visa_duree', '5', 'Validité du code de visa (minutes)', 'RG-M01-07', 'number'],
    ];

    public function up(): void
    {
        Schema::create('codes_otp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('objet', 20);
            $table->nullableMorphs('cible');
            $table->string('canal', 10);
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('expire_le');
            $table->timestamp('consomme_le')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'objet']);
        });

        Schema::table('ordres_mission', function (Blueprint $table) {
            $table->string('niveau_urgence', 20)->default('normale')->after('type');
            $table->string('motif_urgence')->nullable()->after('niveau_urgence');
        });

        $maintenant = now();
        foreach (self::PARAMETRES as [$cle, $valeur, $libelle, $description, $type]) {
            DB::table('parametres')->insertOrIgnore(compact('cle', 'valeur', 'libelle', 'description', 'type')
                + ['groupe' => 'securite', 'created_at' => $maintenant, 'updated_at' => $maintenant]);
        }
    }

    public function down(): void
    {
        DB::table('parametres')->whereIn('cle', array_column(self::PARAMETRES, 0))->delete();
        Schema::table('ordres_mission', fn (Blueprint $table) => $table->dropColumn(['niveau_urgence', 'motif_urgence']));
        Schema::dropIfExists('codes_otp');
    }
};
