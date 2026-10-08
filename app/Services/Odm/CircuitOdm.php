<?php

namespace App\Services\Odm;

use App\Models\EtapeOdm;
use App\Models\OrdreMission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Circuit de validation d'un ODM (RG-M12-11) : chef d'atelier ou chef d'équipe du service émetteur → DAF → DP.
 * Pas de visa du CDG ; étape RH seulement si le paramètre « odm_etape_rh » est actif.
 *
 * RG-M01-04 : on ne vise pas un ODM dont on est le demandeur ou un participant.
 */
final class CircuitOdm
{
    /** Étapes de la version courante : la première est en attente, les suivantes à venir */
    public static function demarrer(OrdreMission $odm): void
    {
        foreach (OrdreMission::niveauxDuCircuit() as $index => $role) {
            EtapeOdm::create([
                'ordre_mission_id' => $odm->id,
                'version' => $odm->version,
                'niveau' => $index + 1,
                'role' => $role,
                'statut' => $index === 0 ? 'en_attente' : 'a_venir',
                'date_attribution' => $index === 0 ? now() : null,
            ]);
        }
    }

    /** Étape en attente de la version courante */
    public static function etapeEnCours(OrdreMission $odm): ?EtapeOdm
    {
        return $odm->etapes()->where('version', $odm->version)->where('statut', 'en_attente')->first();
    }

    /**
     * Personnes habilitées à viser un niveau (rôle propre), hors demandeur et participants (RG-M01-04).
     * Chef d'atelier : celui du service émetteur, de préférence sur le site de l'ODM.
     */
    public static function valideursPossibles(OrdreMission $odm, string $niveau): Collection
    {
        $roles = OrdreMission::NIVEAUX[$niveau]['roles'] ?? [];
        $exclus = array_merge([$odm->demandeur_id], $odm->participantsActifs()->pluck('user_id')->all());

        $requete = User::actifs()
            ->whereNotIn('id', $exclus)
            ->where(fn ($q) => $q->whereIn('role', $roles)->orWhereHas('roles', fn ($r) => $r->whereIn('role', $roles)))
            ->orderBy('name');

        if ($niveau !== 'chef_atelier') {
            return $requete->get();
        }

        $duService = $requete->where('service', $odm->service)->get();
        $duSite = $duService->where('site', $odm->site);

        return $duSite->isNotEmpty() ? $duSite->values() : $duService;
    }
}
