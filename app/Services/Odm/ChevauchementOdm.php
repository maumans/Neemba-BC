<?php

namespace App\Services\Odm;

use App\Models\OrdreMission;
use App\Models\ParticipantOdm;
use App\Support\Format;

/**
 * RG-M12-16 : un participant ne peut figurer sur deux ODM non annulés dont les périodes se chevauchent
 * (risque de double paiement). Blocage à la soumission ; le DAF peut accorder une dérogation motivée et tracée.
 *
 * ODM retenus (décision Q25) : tous, sauf les brouillons et les ODM annulés. Une période va du départ
 * au retour réel s'il est saisi, sinon au retour prévu ; les deux bornes comptent (un même jour = chevauchement).
 */
final class ChevauchementOdm
{
    /**
     * Conflits de l'ODM, un par participant concerné (MSG-M12-03).
     *
     * @return array<int, array{participant: string, user_id: int, debut: string, fin: string, numero: string, odm_id: int}>
     */
    public static function conflits(OrdreMission $odm): array
    {
        $debut = $odm->date_depart;
        $fin = $odm->dateFin();
        if (!$debut || !$fin || $fin->lessThan($debut)) {
            return [];
        }

        $participants = $odm->participantsActifs()->get();
        if ($participants->isEmpty()) {
            return [];
        }

        $autres = ParticipantOdm::query()
            ->whereIn('user_id', $participants->pluck('user_id'))
            ->where('retire', false)
            ->where('ordre_mission_id', '!=', $odm->id)
            ->whereHas('ordreMission', fn ($q) => $q->pourChevauchement()
                ->whereDate('date_depart', '<=', $fin)
                ->whereRaw('COALESCE(date_retour_reelle, date_retour_prevue) >= ?', [$debut->toDateString()]))
            ->with('ordreMission')
            ->orderBy('id')
            ->get();

        $conflits = [];
        foreach ($participants as $participant) {
            $autre = $autres->firstWhere('user_id', $participant->user_id);
            if (!$autre) {
                continue;
            }
            $conflits[] = [
                'participant' => $participant->nom,
                'user_id' => $participant->user_id,
                'debut' => Format::date($autre->ordreMission->date_depart),
                'fin' => Format::date($autre->ordreMission->dateFin()),
                'numero' => $autre->ordreMission->numero ?? 'en brouillon',
                'odm_id' => $autre->ordreMission->id,
            ];
        }

        return $conflits;
    }
}
