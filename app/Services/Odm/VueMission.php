<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\OrdreMission;
use App\Models\ParticipantOdm;
use App\Support\Format;

/**
 * Vue « mission » (RG-M12-19 ; annexe B.4) : la chaîne des segments d'une mission (ODM initial et prolongations),
 * avec les jours, nuits et montants cumulés, par participant et au total.
 *
 * Contrôle : sur toute la chaîne, nuits payées (nuits + nuitées de rattrapage) = jours − 1, hors jours où le participant
 * n'est pas hébergé aux frais de Neemba (base vie, ou filiale d'accueil à l'étranger). Toute incohérence est signalée
 * au DAF (MSG-M12-07). Les segments brouillons, rejetés ou annulés ne comptent pas.
 */
final class VueMission
{
    /** Segments qui comptent dans la mission */
    public const STATUTS_RETENUS = ['SOUMIS', 'EN_VALIDATION', 'VALIDE', 'BONS_GENERES', 'PAYE', 'CLOTURE'];

    public static function pour(OrdreMission $odm): array
    {
        $initial = $odm->initial();
        $segments = $initial->segmentsDeLaMission()->filter(fn (OrdreMission $s) => in_array($s->statut, self::STATUTS_RETENUS, true))->values();

        $participants = [];
        foreach ($segments as $segment) {
            foreach ($segment->participantsActifs()->get() as $participant) {
                $ligne = &$participants[$participant->user_id];
                $ligne ??= ['user_id' => $participant->user_id, 'nom' => $participant->nom, 'jours' => 0, 'nuits' => 0, 'jours_heberges' => 0, 'montant' => 0.0, 'segments' => 0];
                $heberge = !self::sansHebergement($segment, $participant);
                $ligne['jours'] += $participant->jours;
                $ligne['nuits'] += $participant->nuits + $participant->nuit_rattrapage;
                $ligne['jours_heberges'] += $heberge ? $participant->jours : 0;
                $ligne['montant'] += (float) $participant->total;
                $ligne['segments']++;
                unset($ligne);
            }
        }

        $incoherences = [];
        foreach ($participants as &$ligne) {
            $attendues = max(0, $ligne['jours_heberges'] - 1);
            $ligne['nuits_attendues'] = $ligne['jours_heberges'] > 0 ? $attendues : 0;
            $ligne['coherent'] = $ligne['jours_heberges'] === 0 || $ligne['nuits'] === $attendues;
            if (!$ligne['coherent']) {
                $incoherences[] = ErreurMetier::texte('MSG-M12-07', [
                    'numero' => $initial->numero ?? 'en brouillon',
                    'nuits' => $ligne['nuits'],
                    'jours' => $ligne['jours_heberges'],
                ]) . " ({$ligne['nom']})";
            }
        }
        unset($ligne);

        return [
            'numero' => $initial->numero,
            'segments' => $segments->map(fn (OrdreMission $s) => [
                'id' => $s->id,
                'rang' => $s->rang,
                'libelle' => $s->libelle,
                'libelle_prolongation' => $s->libelle_prolongation,
                'periode' => PresentationOdm::periode($s),
                'jours' => CalculOdm::jours($s->date_depart, $s->dateFin()),
                'participants' => $s->participantsActifs()->count(),
                'total' => $s->total !== null ? (float) $s->total : null,
                'statut' => $s->statut,
                'statut_label' => $s->statut_label,
            ])->values()->all(),
            'participants' => array_values($participants),
            'total' => (float) collect($participants)->sum('montant'),
            'debut' => $segments->first()?->date_depart ? Format::date($segments->first()->date_depart) : null,
            'fin' => $segments->last()?->dateFin() ? Format::date($segments->last()->dateFin()) : null,
            'jours' => $segments->sum(fn (OrdreMission $s) => CalculOdm::jours($s->date_depart, $s->dateFin())),
            'incoherences' => $incoherences,
        ];
    }

    /** Le participant n'est pas hébergé aux frais de Neemba sur ce segment (base vie, ou filiale d'accueil) */
    private static function sansHebergement(OrdreMission $segment, ParticipantOdm $participant): bool
    {
        return $segment->type === 'exterieur'
            ? $segment->hebergement_exterieur === 'filiale'
            : (bool) $participant->base_vie;
    }
}
