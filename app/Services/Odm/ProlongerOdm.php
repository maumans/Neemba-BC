<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\ParticipantOdm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Prolongation d'une mission (RG-M12-17, RG-M12-18 ; SC-24, annexe B.3).
 *
 * Depuis le dernier segment validé d'une mission, crée un nouveau segment en brouillon, lié au précédent :
 * - départ = retour du segment précédent + 1 jour (non modifiable) ; même type d'ODM ;
 * - en-tête, OR et participants repris ; un participant peut être retiré, aucun ne peut être ajouté ;
 * - nuitée de rattrapage du segment précédent pour chaque participant qui n'y était pas logé sur base vie (MSG-M12-06) ;
 * - même circuit ; numéro propre à la soumission et libellé « Prolongation n de N°xxx » (décision Q23).
 */
final class ProlongerOdm
{
    /** Un segment se prolonge une fois validé, tant que la mission n'est ni clôturée ni annulée */
    public const STATUTS_PROLONGEABLES = ['VALIDE', 'BONS_GENERES', 'PAYE'];

    public static function peutProlonger(OrdreMission $odm, User $utilisateur): bool
    {
        return in_array($odm->statut, self::STATUTS_PROLONGEABLES, true)
            && in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)
            && !self::prolongationEnCours($odm);
    }

    /** Prolongation déjà créée (non annulée) à partir de ce segment */
    public static function prolongationEnCours(OrdreMission $odm): ?OrdreMission
    {
        return OrdreMission::where('segment_precedent_id', $odm->id)->where('statut', '!=', 'ANNULE')->first();
    }

    /**
     * @return array{0: OrdreMission, 1: string} le nouveau segment et le message MSG-M12-06
     */
    public static function executer(OrdreMission $precedent, User $auteur, ?string $dateRetour): array
    {
        return DB::transaction(function () use ($precedent, $auteur, $dateRetour) {
            $precedent = OrdreMission::whereKey($precedent->id)->lockForUpdate()->firstOrFail();
            if (!self::peutProlonger($precedent, $auteur)) {
                throw new ErreurMetier('PROLONGATION_IMPOSSIBLE', 'MSG-APP-034', [], 'RG-M12-17', null, 409);
            }

            $depart = $precedent->dateFin()->copy()->addDay();
            if (!$dateRetour || \Carbon\Carbon::parse($dateRetour)->lessThan($depart)) {
                throw new ErreurMetier('RETOUR_AVANT_DEPART', 'MSG-M12-02', [], 'RG-M12-06', 'date_retour_prevue');
            }

            $initial = $precedent->initial();
            $segment = OrdreMission::create($precedent->only([
                'type', 'technique', 'entite', 'site', 'service', 'code_analytique', 'but', 'clients', 'destinations', 'vehicule',
                'prise_en_charge', 'mode_client', 'hebergement_exterieur', 'reference_billet',
            ]) + [
                'demandeur_id' => $precedent->demandeur_id,
                'initiateur_id' => $auteur->id,
                'mission_id' => $initial->id,
                'segment_precedent_id' => $precedent->id,
                'rang' => $precedent->rang + 1,
                'date_depart' => $depart->toDateString(),
                'date_retour_prevue' => $dateRetour,
                'statut' => 'BROUILLON',
            ]);

            foreach ($precedent->participantsActifs()->get() as $participant) {
                /* Q49 : choix repris ; la nuitée de rattrapage suit celui de l'hébergement du segment précédent */
                $prises = CalculOdm::prisesNormalisees($participant->prises_en_charge, $precedent->priseParDefaut());
                $prises['rattrapage'] = $prises['hebergement'];
                $segment->participants()->create($participant->only([
                    'user_id', 'nom', 'matricule', 'service', 'statut_cadre', 'numero_om', 'base_vie', 'hebergement_facture',
                ]) + ['prises_en_charge' => $prises]);
            }
            foreach ($precedent->ordresReparation()->get() as $or) {
                $segment->ordresReparation()->create($or->only(['numero', 'type']));
            }
            EnregistrementOdm::recalculer($segment);

            $rattrapages = $segment->participantsActifs()->where('nuit_rattrapage', '>', 0)->count();
            $message = \App\Exceptions\ErreurMetier::texte('MSG-M12-06', ['segment' => $precedent->numero, 'nombre' => $rattrapages]);

            HistoriqueOdm::enregistrer($segment, 'creation', null, 'BROUILLON', $auteur->id,
                "Prolongation {$segment->rang} créée depuis l'ordre de mission {$precedent->numero}. {$message}");
            HistoriqueOdm::enregistrer($precedent, 'prolongation', $precedent->statut, $precedent->statut, $auteur->id,
                'Prolongation en préparation : départ le ' . \App\Support\Format::date($depart) . ', retour prévu le ' . \App\Support\Format::date($dateRetour) . '.');

            return [$segment->fresh(), $message];
        });
    }

    /** Participants d'une prolongation : ceux du segment précédent (retrait possible, ajout impossible) */
    public static function participantsAdmis(OrdreMission $segment): array
    {
        return ParticipantOdm::where('ordre_mission_id', $segment->segment_precedent_id)->where('retire', false)->pluck('user_id')->all();
    }
}
