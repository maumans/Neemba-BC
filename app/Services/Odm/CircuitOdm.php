<?php

namespace App\Services\Odm;

use App\Models\Delegation;
use App\Models\EtapeOdm;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Circuit de validation d'un ODM (RG-M12-11) : chef d'atelier ou chef d'équipe du service émetteur → DAF → DP.
 * Pas de visa du CDG ; étape RH seulement si le paramètre « odm_etape_rh » est actif.
 *
 * - Titulaires d'un niveau : rôle propre (OrdreMission::NIVEAUX) ; pour le chef d'atelier, celui du service émetteur,
 *   de préférence sur le site de l'ODM.
 * - Suppléant : délégation active couvrant le « Visa des ordres de mission », donnée par un titulaire ; il vise
 *   « au titre de » ce titulaire.
 * - RG-M01-04 : on ne vise pas un ODM dont on est le demandeur ou un participant. Si personne d'autre ne peut viser
 *   un niveau, l'étape est sautée et l'ODM passe au niveau supérieur (sauf au dernier niveau, qui reste en attente).
 */
final class CircuitOdm
{
    public const FONCTIONNALITE_DELEGATION = 'visa_odm';

    /** Délais des étapes (spec v2.2, §6.7 : mêmes délais que les étapes équivalentes du circuit des bons) */
    public const PARAMETRE_SLA = [
        'chef_atelier' => 'sla_responsable_service',
        'daf' => 'sla_daf',
        'rh' => 'sla_daf',
        'directeur_pays' => 'sla_directeur_pays',
    ];

    /** Escalade au double du délai : au niveau suivant ; pour le DP, au DAF (§6.7) */
    public const ESCALADE = [
        'chef_atelier' => 'daf',
        'daf' => 'directeur_pays',
        'rh' => 'directeur_pays',
        'directeur_pays' => 'daf',
    ];

    /** Étapes de la version courante, puis activation de la première qui peut être visée */
    public static function demarrer(OrdreMission $odm): void
    {
        foreach (OrdreMission::niveauxDuCircuit() as $index => $role) {
            EtapeOdm::create([
                'ordre_mission_id' => $odm->id,
                'version' => $odm->version,
                'niveau' => $index + 1,
                'role' => $role,
                'statut' => 'a_venir',
            ]);
        }
        self::activerSuivante($odm);
    }

    /**
     * Met en attente la prochaine étape « à venir » de la version courante. Une étape que personne ne peut viser
     * (RG-M01-04) est sautée, sauf la dernière. Renvoie l'étape activée, ou null s'il n'en reste aucune.
     */
    public static function activerSuivante(OrdreMission $odm): ?EtapeOdm
    {
        $etapes = $odm->etapes()->where('version', $odm->version)->where('statut', 'a_venir')->orderBy('niveau')->get();

        foreach ($etapes as $index => $etape) {
            $derniere = $index === $etapes->count() - 1;
            if ($derniere || self::valideursEffectifs($odm, $etape->role)->isNotEmpty()) {
                $etape->update(['statut' => 'en_attente', 'date_attribution' => now()]);

                return $etape;
            }
            $etape->update([
                'statut' => 'sautee',
                'date_decision' => now(),
                'commentaire' => 'Aucun valideur possible (demandeur ou participant, sans suppléant) : passage au niveau supérieur (RG-M01-04).',
            ]);
            HistoriqueOdm::enregistrer($odm, 'visa', $odm->statut, $odm->statut, null, "Niveau « {$etape->libelle} » sauté : aucun valideur possible (RG-M01-04).");
        }

        return null;
    }

    /** Étape en attente de la version courante */
    public static function etapeEnCours(OrdreMission $odm): ?EtapeOdm
    {
        return $odm->etapes()->where('version', $odm->version)->where('statut', 'en_attente')->first();
    }

    /** RG-M01-04 : demandeur, initiateur et participants ne visent pas l'ODM */
    public static function estIncompatible(OrdreMission $odm, User $utilisateur): bool
    {
        return in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)
            || $odm->participantsActifs()->where('user_id', $utilisateur->id)->exists();
    }

    /**
     * Titulaires d'un niveau par leur rôle propre (sans tenir compte des incompatibilités).
     * Chef d'atelier : celui du service émetteur, de préférence sur le site de l'ODM.
     */
    public static function titulaires(OrdreMission $odm, string $niveau): Collection
    {
        $roles = OrdreMission::NIVEAUX[$niveau]['roles'] ?? [];
        $requete = User::actifs()
            ->where(fn ($q) => $q->whereIn('role', $roles)->orWhereHas('roles', fn ($r) => $r->whereIn('role', $roles)))
            ->orderBy('name');

        if ($niveau !== 'chef_atelier') {
            return $requete->get();
        }

        $duService = $requete->where('service', $odm->service)->get();
        $duSite = $duService->where('site', $odm->site);

        return $duSite->isNotEmpty() ? $duSite->values() : $duService;
    }

    /**
     * Personnes qui peuvent viser un niveau : titulaires compatibles, et suppléants (délégation « visa_odm » active)
     * de tout titulaire, compatibles eux-mêmes.
     *
     * @return Collection<int, array{user: User, au_titre_de: ?User}>
     */
    public static function valideursEffectifs(OrdreMission $odm, string $niveau): Collection
    {
        $titulaires = self::titulaires($odm, $niveau);
        $valideurs = $titulaires
            ->reject(fn (User $u) => self::estIncompatible($odm, $u))
            ->map(fn (User $u) => ['user' => $u, 'au_titre_de' => null]);

        $delegations = Delegation::actives()
            ->whereIn('delegant_id', $titulaires->pluck('id'))
            ->with(['delegue', 'delegant'])
            ->get()
            ->filter(fn (Delegation $d) => $d->autorise(self::FONCTIONNALITE_DELEGATION) && $d->delegue?->actif);
        foreach ($delegations as $delegation) {
            if (!self::estIncompatible($odm, $delegation->delegue) && !$valideurs->contains(fn ($v) => $v['user']->id === $delegation->delegue_id)) {
                $valideurs->push(['user' => $delegation->delegue, 'au_titre_de' => $delegation->delegant]);
            }
        }

        return $valideurs->values();
    }

    /** Destinataires des notifications d'un niveau */
    public static function valideursPossibles(OrdreMission $odm, string $niveau): Collection
    {
        return self::valideursEffectifs($odm, $niveau)->pluck('user')->unique('id')->values();
    }

    /**
     * L'utilisateur peut-il viser l'étape en cours ? Renvoie l'étape et, pour un suppléant, le titulaire « au titre de ».
     *
     * @return array{etape: EtapeOdm, au_titre_de: ?User}|null
     */
    public static function peutViser(OrdreMission $odm, User $utilisateur): ?array
    {
        if (!in_array($odm->statut, OrdreMission::STATUTS_EN_CIRCUIT, true)) {
            return null;
        }
        $etape = self::etapeEnCours($odm);
        if (!$etape) {
            return null;
        }
        $valideur = self::valideursEffectifs($odm, $etape->role)->first(fn ($v) => $v['user']->id === $utilisateur->id);

        return $valideur ? ['etape' => $etape, 'au_titre_de' => $valideur['au_titre_de']] : null;
    }

    /** ODM que l'utilisateur peut viser maintenant */
    public static function aViserPar(User $utilisateur): Collection
    {
        return OrdreMission::whereIn('statut', OrdreMission::STATUTS_EN_CIRCUIT)
            ->with('demandeur:id,name,prenom')
            ->orderBy('date_soumission')
            ->get()
            ->filter(fn (OrdreMission $odm) => self::peutViser($odm, $utilisateur) !== null)
            ->values();
    }

    /** Délai de l'étape en heures (§6.7) */
    public static function delaiHeures(EtapeOdm $etape): float
    {
        return (float) Parametre::valeur(self::PARAMETRE_SLA[$etape->role] ?? 'sla_daf', 8);
    }

    /** Échéance de l'étape en cours (attribution + délai) */
    public static function echeance(EtapeOdm $etape): ?\Carbon\CarbonInterface
    {
        return $etape->date_attribution?->copy()->addMinutes((int) round(self::delaiHeures($etape) * 60));
    }
}
