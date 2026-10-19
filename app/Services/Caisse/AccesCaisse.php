<?php

namespace App\Services\Caisse;

use App\Models\Caisse;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Qui voit et opère quelle caisse (spec v2.4, RG-M08-12) : un caissier ne voit et n'opère que sa caisse ; les rôles
 * transverses voient toutes les caisses.
 *
 * Le gérant d'une caisse est son gestionnaire, son suppléant, ou un caissier rattaché à cette caisse. Le rattachement
 * seul ne suffit pas : chaque demandeur a une caisse de rattachement, celle qui émet ses bons (lot C). Un caissier sans
 * caisse de rattachement garde les caisses qui servent son site (reprise des comptes d'avant le lot C).
 */
final class AccesCaisse
{
    public const ROLES_TRANSVERSES = ['daf', 'daf_adjoint', 'chef_comptable', 'tresorerie', 'directeur_pays', 'dp_adjoint', 'administrateur'];

    public static function estTransverse(User $utilisateur): bool
    {
        return $utilisateur->aLeRole(self::ROLES_TRANSVERSES);
    }

    public static function estGerant(User $utilisateur, Caisse $caisse): bool
    {
        if (in_array($utilisateur->id, array_filter([$caisse->gestionnaire_id, $caisse->suppleant_id]), true)) {
            return true;
        }
        if (!$utilisateur->aLeRole('caissier')) {
            return false;
        }

        return $utilisateur->caisse_id
            ? (int) $utilisateur->caisse_id === (int) $caisse->id
            : ($utilisateur->site && $caisse->sertLeSite($utilisateur->site));
    }

    /**
     * Gestionnaire ou suppléant d'une caisse active, sans être caissier (ex. : caisse Atelier). Donne le pseudo-rôle
     * « gerant_caisse » aux routes et au menu des caisses (PV d'arrêté, réapprovisionnement).
     */
    public static function gereUneCaisse(User $utilisateur): bool
    {
        return Caisse::actives()->where(fn ($q) => $q->where('gestionnaire_id', $utilisateur->id)->orWhere('suppleant_id', $utilisateur->id))->exists();
    }

    /** Caisses actives que l'utilisateur voit : toutes pour un rôle transverse, sinon celles qu'il gère */
    public static function caissesVisibles(User $utilisateur): Collection
    {
        $caisses = Caisse::actives()->with('sites')->orderBy('libelle')->get();

        return self::estTransverse($utilisateur)
            ? $caisses
            : $caisses->filter(fn (Caisse $c) => self::estGerant($utilisateur, $c))->values();
    }

    /** Gérants d'une caisse (destinataires de ses alertes) */
    public static function gerants(Caisse $caisse): Collection
    {
        return User::actifs()->get()->filter(fn (User $u) => self::estGerant($u, $caisse))->values();
    }
}
