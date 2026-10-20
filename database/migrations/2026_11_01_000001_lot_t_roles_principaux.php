<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot T (demande de l'utilisateur du 20/10, Q103) : la trésorerie, le Chef comptable, le DAF adjoint, le DP adjoint, la
 * Comptabilité, l'assistant trésorier… peuvent être le rôle principal d'un compte.
 * - `users.role` passe de l'énumération des 7 rôles du socle à une chaîne (la liste est contrôlée par l'application).
 * - Reprise : un compte affiché « Demandeur » qui a un rôle métier reçoit ce rôle comme rôle principal (ordre de
 *   User::PRIORITE_PRINCIPAL) ; « demandeur » reste parmi ses rôles.
 */
return new class extends Migration
{
    private const ANCIENS = ['demandeur', 'responsable_service', 'controle_gestion', 'daf', 'directeur_pays', 'caissier', 'administrateur'];

    private const PRIORITE = [
        'administrateur', 'directeur_pays', 'dp_adjoint', 'daf', 'daf_adjoint', 'chef_comptable', 'controle_gestion', 'comptable',
        'responsable_service', 'assistant_tresorier', 'caissier', 'tresorerie', 'chef_atelier', 'rh', 'logistique',
    ];

    public function up(): void
    {
        DB::table('users')->whereNull('role')->update(['role' => 'demandeur']);
        DB::statement("ALTER TABLE users MODIFY COLUMN role VARCHAR(40) NOT NULL DEFAULT 'demandeur'");

        $roles = DB::table('roles_utilisateurs')->whereIn('role', self::PRIORITE)->get(['user_id', 'role'])->groupBy('user_id');
        foreach (DB::table('users')->where('role', 'demandeur')->whereIn('id', $roles->keys())->pluck('id') as $id) {
            $siens = $roles[$id]->pluck('role')->all();
            $principal = collect(self::PRIORITE)->first(fn (string $r) => in_array($r, $siens, true));
            if ($principal) {
                DB::table('users')->where('id', $id)->update(['role' => $principal]);
            }
        }
    }

    public function down(): void
    {
        /* Les rôles hors de l'ancienne liste redeviennent « demandeur » (ils restent dans roles_utilisateurs) */
        DB::table('users')->whereNotIn('role', self::ANCIENS)->update(['role' => 'demandeur']);
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('" . implode("','", self::ANCIENS) . "') DEFAULT 'demandeur'");
    }
};
