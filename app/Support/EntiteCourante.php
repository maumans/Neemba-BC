<?php

namespace App\Support;

use App\Models\Entite;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Entité courante de l'utilisateur connecté (spec v2.4, RG-M01-08).
 *
 * - Par défaut : l'entité du compte. L'utilisateur qui a des droits dans plusieurs entités actives choisit son entité
 *   (sélecteur du bandeau) ; ce choix est gardé en session.
 * - Vue consolidée (toutes les entités) : réservée aux rôles du paramètre « roles_vue_consolidee ».
 * - Hors session (commandes, tâches planifiées) : aucune entité courante, donc aucun filtre ; les règles métier
 *   s'appuient alors sur l'entité de chaque bon ou ODM.
 */
final class EntiteCourante
{
    public const CLE_SESSION = 'entite_courante';
    public const CONSOLIDE = 'consolide';

    /** Cache gardé dans la requête en cours (un appel HTTP = un calcul) */
    private const CLE_CACHE = 'neemba.entite_filtre';

    /**
     * Entité qui filtre les données pour l'utilisateur connecté : null en vue consolidée ou hors session.
     * Sert aux portées globales des modèles (bons, ODM, caisses, référentiels).
     */
    public static function filtre(): ?int
    {
        if (!app()->bound('session') || !Auth::hasUser()) {
            return null;
        }
        $utilisateur = Auth::user();
        $attributs = request()->attributes;
        $cache = $attributs->get(self::CLE_CACHE);
        if (is_array($cache) && $cache['utilisateur'] === $utilisateur->id) {
            return $cache['filtre'];
        }
        $filtre = self::estConsolide($utilisateur) ? null : self::id($utilisateur);
        $attributs->set(self::CLE_CACHE, ['utilisateur' => $utilisateur->id, 'filtre' => $filtre]);

        return $filtre;
    }

    /** Entité de travail de l'utilisateur (jamais nulle s'il a une entité) */
    public static function id(User $utilisateur): ?int
    {
        $choisie = session(self::CLE_SESSION);
        if (is_numeric($choisie) && self::accessibles($utilisateur)->contains('id', (int) $choisie)) {
            return (int) $choisie;
        }

        return $utilisateur->entite_id ?? Entite::idParDefaut();
    }

    public static function estConsolide(User $utilisateur): bool
    {
        return session(self::CLE_SESSION) === self::CONSOLIDE && self::peutConsolider($utilisateur);
    }

    /**
     * Entités actives où l'utilisateur a des droits : celle de son compte, et celles où il a un rôle (un rôle sans
     * entité vaut pour toutes).
     *
     * @return Collection<int, Entite>
     */
    public static function accessibles(User $utilisateur): Collection
    {
        $actives = Entite::actives()->orderBy('id')->get();
        $roles = $utilisateur->exists ? $utilisateur->roles()->get(['entite_id']) : collect();
        if ($roles->contains(fn ($r) => $r->entite_id === null)) {
            return $actives;
        }
        $ids = $roles->pluck('entite_id')->push($utilisateur->entite_id)->filter()->unique();

        return $actives->whereIn('id', $ids)->values();
    }

    /** RG-M01-08 : vue consolidée pour les rôles habilités, quand plusieurs entités sont actives */
    public static function peutConsolider(User $utilisateur): bool
    {
        $roles = array_filter(array_map('trim', explode(',', (string) Parametre::valeur('roles_vue_consolidee', ''))));

        return $roles && Entite::actives()->count() > 1 && !empty(array_intersect($utilisateur->listeRolesToutesEntites(), $roles));
    }

    /** Choix de l'utilisateur (entité ou vue consolidée) ; faux si le choix n'est pas permis */
    public static function choisir(User $utilisateur, string $choix): bool
    {
        if ($choix === self::CONSOLIDE ? !self::peutConsolider($utilisateur) : !self::accessibles($utilisateur)->contains('id', (int) $choix)) {
            return false;
        }
        session([self::CLE_SESSION => $choix === self::CONSOLIDE ? self::CONSOLIDE : (int) $choix]);
        self::oublier();

        return true;
    }

    /** Données partagées avec les écrans (sélecteur du bandeau) */
    public static function pourEcran(User $utilisateur): array
    {
        $accessibles = self::accessibles($utilisateur);
        $consolide = self::estConsolide($utilisateur);
        $courante = $consolide ? null : $accessibles->firstWhere('id', self::id($utilisateur)) ?? Entite::find(self::id($utilisateur));

        return [
            'courante' => $courante ? ['id' => $courante->id, 'code' => $courante->code, 'nom' => $courante->nom] : null,
            'consolide' => $consolide,
            'accessibles' => $accessibles->map(fn (Entite $e) => ['id' => $e->id, 'code' => $e->code, 'nom' => $e->nom])->values()->all(),
            'peut_consolider' => self::peutConsolider($utilisateur),
        ];
    }

    /** Vide le cache de la requête (changement d'entité, tests) */
    public static function oublier(): void
    {
        request()->attributes->remove(self::CLE_CACHE);
    }
}
