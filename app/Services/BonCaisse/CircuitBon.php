<?php

namespace App\Services\BonCaisse;

use App\Models\BonCaisse;
use App\Models\Delegation;
use App\Models\User;
use App\Models\Validation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Qui vise un bon de caisse, et à quelle étape (spec v2.2 : RG-M01-04, RG-M03-24, RG-M04-09).
 *
 * - Titulaires d'une étape : rôle propre ou complémentaire ; pour le chef de service, celui du service du bon.
 * - Suppléant : délégation active qui couvre la validation, donnée par un titulaire ; il vise « au titre de » ce titulaire.
 * - Incompatibilité : on ne vise jamais un bon dont on est le demandeur, l'initiateur ou le bénéficiaire.
 * - RG-M04-09 : si les seuls titulaires d'une étape sont incompatibles et sans suppléant, le bon passe automatiquement
 *   au niveau supérieur ; l'étape est notée « sautée ». Il en va de même quand aucun titulaire actif n'est désigné
 *   (service sans chef de service, Q48). Le dernier niveau n'est jamais sauté (visa de la Finance requis avant tout
 *   décaissement) : il attend un titulaire ou un suppléant.
 */
final class CircuitBon
{
    public const ROLES_PAR_STATUT = [
        'EN_ATTENTE_CHEF_SERVICE' => 'responsable_service',
        'EN_ATTENTE_CDG' => 'controle_gestion',
        'EN_ATTENTE_DAF' => 'daf',
        'EN_ATTENTE_DP' => 'directeur_pays',
    ];

    public const LIBELLES = [
        'responsable_service' => 'Chef de service',
        'controle_gestion' => 'CDG',
        'daf' => 'Finance',
        'directeur_pays' => 'Directeur Pays',
    ];

    public static function roleEnCours(BonCaisse $bon): ?string
    {
        return self::ROLES_PAR_STATUT[$bon->statut] ?? null;
    }

    /** RG-M01-04, RG-M03-24 : demandeur, initiateur et bénéficiaire ne visent pas le bon */
    public static function estIncompatible(BonCaisse $bon, User $utilisateur): bool
    {
        return in_array($utilisateur->id, array_filter([$bon->demandeur_id, $bon->initiateur_id, $bon->beneficiaire_id]), true);
    }

    /** Titulaires d'une étape, sans tenir compte des incompatibilités */
    public static function titulaires(BonCaisse $bon, string $role): Collection
    {
        return User::actifs()
            ->where(fn ($q) => $q->where('role', $role)->orWhereHas('roles', fn ($r) => $r->where('role', $role)))
            ->when($role === 'responsable_service', fn ($q) => $q->where('service', $bon->service))
            ->orderBy('name')
            ->get();
    }

    /**
     * Personnes qui peuvent viser l'étape : titulaires compatibles, puis suppléants compatibles d'un titulaire.
     *
     * @return Collection<int, array{user: User, au_titre_de: ?User}>
     */
    public static function valideurs(BonCaisse $bon, string $role): Collection
    {
        $titulaires = self::titulaires($bon, $role);
        $valideurs = $titulaires->reject(fn (User $u) => self::estIncompatible($bon, $u))
            ->map(fn (User $u) => ['user' => $u, 'au_titre_de' => null]);

        $delegations = Delegation::actives()->whereIn('delegant_id', $titulaires->pluck('id'))->with(['delegue', 'delegant'])->get()
            ->filter(fn (Delegation $d) => $d->autorise('validation') && $d->delegue?->actif);
        foreach ($delegations as $delegation) {
            if (!self::estIncompatible($bon, $delegation->delegue) && !$valideurs->contains(fn ($v) => $v['user']->id === $delegation->delegue_id)) {
                $valideurs->push(['user' => $delegation->delegue, 'au_titre_de' => $delegation->delegant]);
            }
        }

        return $valideurs->values();
    }

    /**
     * L'utilisateur peut-il viser l'étape en cours ? Renvoie le rôle de l'étape et, pour un suppléant, le titulaire.
     *
     * @return array{role: string, au_titre_de: ?User}|null
     */
    public static function peutViser(BonCaisse $bon, User $utilisateur): ?array
    {
        $role = self::roleEnCours($bon);
        if (!$role) {
            return null;
        }
        $valideur = self::valideurs($bon, $role)->first(fn ($v) => $v['user']->id === $utilisateur->id);

        return $valideur ? ['role' => $role, 'au_titre_de' => $valideur['au_titre_de']] : null;
    }

    /** Pourquoi l'utilisateur ne peut pas viser : message affiché au lieu d'un refus muet */
    public static function motifRefus(BonCaisse $bon, User $utilisateur): string
    {
        $role = self::roleEnCours($bon);
        if (!$role) {
            return 'Ce bon n\'est pas en attente de validation.';
        }
        if (self::estIncompatible($bon, $utilisateur)) {
            return 'Vous êtes demandeur ou bénéficiaire de ce bon : vous ne pouvez pas le viser (RG-M01-04).';
        }
        if ($role === 'responsable_service' && $utilisateur->aLeRole('responsable_service')) {
            return "Ce bon relève du chef de service {$bon->service} : vous ne pouvez pas le viser.";
        }

        return 'Ce bon n\'est pas en attente de votre validation (étape en cours : ' . self::LIBELLES[$role] . ').';
    }

    /**
     * Services dont l'utilisateur vise les bons au niveau chef de service : le sien s'il est chef de service
     * (rôle principal ou complémentaire), et ceux des chefs de service qu'il supplée.
     *
     * @return string[]
     */
    public static function servicesChef(User $utilisateur): array
    {
        $services = [];
        if ($utilisateur->aLeRole('responsable_service') && $utilisateur->service) {
            $services[] = $utilisateur->service;
        }
        foreach (Delegation::delegantsActifsPour($utilisateur->id) as $delegant) {
            if ($delegant?->actif && $delegant->aLeRole('responsable_service') && $delegant->service) {
                $services[] = $delegant->service;
            }
        }

        return array_values(array_unique($services));
    }

    /** Bons qui attendent le visa de l'utilisateur : étape en cours à sa portée, hors bons dont il est demandeur ou bénéficiaire */
    public static function requeteAViser(User $utilisateur): Builder
    {
        $statuts = array_keys(array_intersect(self::ROLES_PAR_STATUT, $utilisateur->rolesValidationEffectifs()));
        $services = self::servicesChef($utilisateur);

        return BonCaisse::query()
            ->whereIn('statut', $statuts)
            ->where(fn ($q) => $q->where('statut', '!=', 'EN_ATTENTE_CHEF_SERVICE')->orWhereIn('service', $services))
            ->where('demandeur_id', '!=', $utilisateur->id)
            ->where(fn ($q) => $q->whereNull('initiateur_id')->orWhere('initiateur_id', '!=', $utilisateur->id))
            ->where(fn ($q) => $q->whereNull('beneficiaire_id')->orWhere('beneficiaire_id', '!=', $utilisateur->id));
    }

    /** Dernière étape du circuit : jamais sautée */
    private static function estDerniereEtape(BonCaisse $bon): bool
    {
        return $bon->statut === 'EN_ATTENTE_DP' || ($bon->statut === 'EN_ATTENTE_DAF' && !$bon->necessite_validation_dp);
    }

    /**
     * RG-M04-09 : saute les étapes où personne ne peut viser (titulaires incompatibles sans suppléant, ou aucun titulaire).
     * À appeler à la soumission et après chaque visa. Renvoie les libellés des étapes sautées.
     *
     * @return string[]
     */
    public static function sauterEtapesSansValideur(BonCaisse $bon): array
    {
        $sautees = [];
        while (($role = self::roleEnCours($bon)) && !self::estDerniereEtape($bon)) {
            if (self::valideurs($bon, $role)->isNotEmpty()) {
                break;
            }

            $titulaires = self::titulaires($bon, $role);
            $etape = 'Étape ' . self::LIBELLES[$role] . ' sautée : ';
            $motif = $titulaires->isEmpty()
                ? $etape . ($role === 'responsable_service' ? "aucun chef de service n'est désigné pour le service {$bon->service}" : 'aucun valideur actif')
                    . ' ; le bon passe au niveau supérieur.'
                : $etape . $titulaires->map(fn (User $u) => $u->nom_complet)->implode(', ')
                    . ' est demandeur ou bénéficiaire du bon et n\'a pas de suppléant ; le bon passe au niveau supérieur (RG-M04-09).';
            Validation::where('bon_caisse_id', $bon->id)->where('role', $role)->where('statut', 'en_attente')
                ->update(['statut' => 'saute', 'commentaire' => $motif, 'date_validation' => now(), 'date_attribution' => now()]);
            $bon->passerAuNiveauSuivant(null, $motif);
            $sautees[] = self::LIBELLES[$role];
        }

        return $sautees;
    }
}
