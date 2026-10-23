<?php

namespace App\Services\Odm;

use App\Models\OrdreMission;
use App\Models\Parametre;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Missions ponctuelles et missions longue durée (complément du 22/10 à la spec v2.4, RG-M12-43 à 51).
 *
 * - Une mission est ponctuelle (dates de départ et de retour) ou longue durée (renouvelable par périodes) ; le choix est
 *   fait à la création et validé dans le circuit (RG-M12-43).
 * - Mission longue : fréquence choisie dans une liste paramétrable et date de fin prévisionnelle ; une période dure la
 *   fréquence, la dernière s'arrête à la fin prévisionnelle (RG-M12-44). Fréquence et fin prévisionnelle se modifient pour
 *   la période suivante, dans le circuit (RG-M12-45).
 * - Prolongation et renouvellement sont des segments liés (`ProlongerOdm`) ; aucun renouvellement automatique.
 */
final class MissionLongue
{
    /** RG-M12-44 : fréquences proposées (jours), paramètre `odm_frequences_renouvellement` */
    public static function frequences(): array
    {
        $liste = array_map('intval', array_filter(array_map('trim', explode(',', (string) Parametre::valeur('odm_frequences_renouvellement', '15,30,45')))));

        return array_values(array_unique(array_filter($liste, fn (int $j) => $j > 0)));
    }

    /** RG-M12-44 : fin d'une période qui part le $depart : départ + fréquence − 1, sans dépasser la fin prévisionnelle */
    public static function finDePeriode(CarbonInterface $depart, int $frequence, CarbonInterface $finPrevisionnelle): CarbonInterface
    {
        $fin = $depart->copy()->startOfDay()->addDays($frequence - 1);

        return $fin->greaterThan($finPrevisionnelle) ? $finPrevisionnelle->copy()->startOfDay() : $fin;
    }

    /** La période en cours est la dernière : la fin prévisionnelle est atteinte */
    public static function dernierePeriode(OrdreMission $segment): bool
    {
        return $segment->date_fin_previsionnelle !== null && $segment->dateFin() !== null
            && !$segment->dateFin()->lessThan($segment->date_fin_previsionnelle);
    }

    /** Dernier segment validé d'une mission, en cours (ni clôturé, ni annulé) */
    public static function segmentEnCours(OrdreMission $odm): ?OrdreMission
    {
        return $odm->initial()->segmentsDeLaMission()
            ->filter(fn (OrdreMission $s) => in_array($s->statut, ProlongerOdm::STATUTS_PROLONGEABLES, true))
            ->last();
    }

    /** RG-M12-48 : chefs d'atelier de la mission (titulaires du niveau, service et site de l'ODM) */
    public static function chefsAtelier(OrdreMission $odm): Collection
    {
        return CircuitOdm::titulaires($odm, 'chef_atelier');
    }

    /** RG-M12-50 : mission ponctuelle à requalifier (plus de N prolongations validées, ou plus de N jours cumulés) */
    public static function aRequalifier(OrdreMission $odm): bool
    {
        if ($odm->duree_mission === 'longue') {
            return false;
        }
        $mission = VueMission::pour($odm);
        $prolongations = collect($mission['segments'])->filter(fn (array $s) => $s['rang'] > 1
            && in_array($s['statut'], OrdreMission::STATUTS_VALIDES, true))->count();

        return $prolongations > (int) Parametre::valeur('odm_requalification_prolongations', 2)
            || $mission['jours'] > (int) Parametre::valeur('odm_requalification_jours', 30);
    }
}
