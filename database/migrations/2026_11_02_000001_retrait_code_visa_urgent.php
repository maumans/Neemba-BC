<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Décision de l'utilisateur du 20/10 (Q104) : plus de code SMS pour viser une demande urgente (RG-M01-07, seconde moitié).
 * Le valideur est déjà authentifié par le code de connexion ; l'urgence déclenche à la place une alerte SMS. Les deux
 * paramètres du code de visa disparaissent ; les codes de visa déjà émis restent dans codes_otp (historique).
 */
return new class extends Migration
{
    private const PARAMETRES = [
        ['otp_visa_urgent_actif', 'true', 'Code SMS pour viser une demande urgente',
            'RG-M01-07 : bon ou ODM urgent ou très urgent. À ne désactiver qu\'en secours (panne du fournisseur SMS)', 'boolean'],
        ['otp_visa_duree', '5', 'Validité du code de visa (minutes)', 'RG-M01-07', 'number'],
    ];

    public function up(): void
    {
        DB::table('parametres')->whereIn('cle', array_column(self::PARAMETRES, 0))->delete();
    }

    public function down(): void
    {
        $maintenant = now();
        foreach (self::PARAMETRES as [$cle, $valeur, $libelle, $description, $type]) {
            DB::table('parametres')->insertOrIgnore([
                'cle' => $cle, 'valeur' => $valeur, 'type' => $type, 'groupe' => 'securite', 'libelle' => $libelle,
                'description' => $description, 'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
        }
    }
};
