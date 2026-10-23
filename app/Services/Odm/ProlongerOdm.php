<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\ParticipantOdm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Prolongation et renouvellement d'une mission (RG-M12-17, RG-M12-18 ; complément du 22/10, RG-M12-45, 46, 50 ;
 * SC-24, annexe B.3).
 *
 * Depuis le dernier segment validé d'une mission, crée un nouveau segment en brouillon, lié au précédent :
 * - départ = retour du segment précédent + 1 jour (non modifiable) ; même type d'ODM ;
 * - en-tête, OR et participants repris ; un participant peut être retiré, aucun ne peut être ajouté ;
 * - nuitée de rattrapage du segment précédent pour chaque participant qui n'y était pas logé sur base vie (MSG-M12-06) ;
 * - indicateur « OR à régulariser » repris (Q111) ;
 * - même circuit ; numéro propre à la soumission et libellé « Prolongation n » ou « Renouvellement n de N°xxx » (Q23).
 * Prolongation : retour choisi, motif obligatoire (RG-M12-50) ; sur une mission longue, une prolongation courte qui passe
 * la fin prévisionnelle la reporte (visible et validée dans le circuit).
 * Renouvellement : période suivante d'une mission longue, dates selon la fréquence et la fin prévisionnelle, toutes deux
 * modifiables (RG-M12-45) ; depuis une mission ponctuelle, il la requalifie en longue durée (RG-M12-50).
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
     * @param array{type?: string, motif?: ?string, frequence?: int|string|null, fin_previsionnelle?: ?string} $options
     * @return array{0: OrdreMission, 1: string} le nouveau segment et le message (MSG-M12-06)
     */
    public static function executer(OrdreMission $precedent, User $auteur, ?string $dateRetour, array $options = []): array
    {
        return DB::transaction(function () use ($precedent, $auteur, $dateRetour, $options) {
            $precedent = OrdreMission::whereKey($precedent->id)->lockForUpdate()->firstOrFail();
            if (!self::peutProlonger($precedent, $auteur)) {
                throw new ErreurMetier('PROLONGATION_IMPOSSIBLE', 'MSG-APP-034', [], 'RG-M12-17', null, 409);
            }

            $depart = $precedent->dateFin()->copy()->addDay();
            $type = ($options['type'] ?? 'prolongation') === 'renouvellement' ? 'renouvellement' : 'prolongation';
            $duree = $precedent->estLongue() ? 'longue' : 'ponctuelle';
            $frequence = $precedent->frequence_renouvellement;
            $fin = $precedent->date_fin_previsionnelle;
            $motif = trim((string) ($options['motif'] ?? ''));
            $notes = [];

            if ($type === 'renouvellement') {
                /* RG-M12-45 : fréquence et fin prévisionnelle reprises, modifiables pour cette période et les suivantes */
                $frequence = (int) (filled($options['frequence'] ?? null) ? $options['frequence'] : $frequence);
                $fin = filled($options['fin_previsionnelle'] ?? null) ? \Carbon\Carbon::parse($options['fin_previsionnelle'])->startOfDay() : $fin;
                if (!in_array($frequence, MissionLongue::frequences(), true)) {
                    throw new ErreurMetier('FREQUENCE_RENOUVELLEMENT', 'MSG-APP-115', [], 'RG-M12-44', 'frequence_renouvellement');
                }
                if (!$fin || $fin->lessThan($depart)) {
                    throw new ErreurMetier('FIN_PREVISIONNELLE_ATTEINTE', 'MSG-APP-119', ['date' => ($fin ?? $precedent->dateFin())->toDateString()],
                        'RG-M12-45', 'date_fin_previsionnelle');
                }
                if ($duree === 'ponctuelle') {
                    $notes[] = 'Mission requalifiée en longue durée (RG-M12-50).';
                }
                if ($precedent->date_fin_previsionnelle && !$fin->isSameDay($precedent->date_fin_previsionnelle)) {
                    $notes[] = 'Date de fin prévisionnelle reportée du ' . \App\Support\Format::date($precedent->date_fin_previsionnelle)
                        . ' au ' . \App\Support\Format::date($fin) . '.';
                }
                $duree = 'longue';
                $retour = MissionLongue::finDePeriode($depart, $frequence, $fin);
            } else {
                /* RG-M12-50 : une prolongation porte son motif */
                if (mb_strlen($motif) < 10) {
                    throw new ErreurMetier('MOTIF_PROLONGATION', 'MSG-APP-118', [], 'RG-M12-50', 'motif_prolongation');
                }
                if (!$dateRetour || \Carbon\Carbon::parse($dateRetour)->lessThan($depart)) {
                    throw new ErreurMetier('RETOUR_AVANT_DEPART', 'MSG-M12-02', [], 'RG-M12-06', 'date_retour_prevue');
                }
                $retour = \Carbon\Carbon::parse($dateRetour)->startOfDay();
                /* Prolongation courte d'une mission longue au-delà de sa fin prévisionnelle : la fin est reportée, dans le circuit */
                if ($duree === 'longue' && $fin && $retour->greaterThan($fin)) {
                    $notes[] = 'Date de fin prévisionnelle reportée du ' . \App\Support\Format::date($fin) . ' au ' . \App\Support\Format::date($retour) . '.';
                    $fin = $retour->copy();
                }
            }
            $dateRetour = $retour->toDateString();

            $initial = $precedent->initial();
            $segment = OrdreMission::create($precedent->only([
                'type', 'technique', 'entite_id', 'entite', 'site', 'service', 'code_analytique', 'but', 'destinations', 'vehicule',
                'hebergement_exterieur', 'reference_billet',
            ]) + [
                'demandeur_id' => $precedent->demandeur_id,
                'initiateur_id' => $auteur->id,
                'mission_id' => $initial->id,
                'segment_precedent_id' => $precedent->id,
                'rang' => $precedent->rang + 1,
                'date_depart' => $depart->toDateString(),
                'date_retour_prevue' => $dateRetour,
                'duree_mission' => $duree,
                'frequence_renouvellement' => $duree === 'longue' ? $frequence : null,
                'date_fin_previsionnelle' => $duree === 'longue' ? $fin?->toDateString() : null,
                'type_segment' => $type,
                'motif_prolongation' => $type === 'prolongation' ? $motif : null,
                /* Q111 : la mission attend encore son OR ; l'indicateur tombe dès qu'un OR est rattaché */
                'or_a_regulariser' => (bool) $precedent->or_a_regulariser,
                'statut' => 'BROUILLON',
            ]);

            $modeles = [];
            foreach ($precedent->participantsActifs()->get() as $participant) {
                $segment->participants()->create($participant->only([
                    'user_id', 'nom', 'matricule', 'service', 'statut_cadre', 'numero_om', 'base_vie', 'hebergement_facture',
                ]));
                /* RG-M12-35 : répartition du segment précédent reprise par défaut ; le rattrapage suit l'hébergement */
                $modeles[$participant->user_id] = RepartitionOdm::modelesDuPrecedent($precedent, $participant->user_id);
            }
            $segment->clients()->sync($precedent->clients()->pluck('clients.id'));
            /* Les parts désignent les OR du segment : même numéro, nouvel identifiant */
            $correspondanceOr = [];
            foreach ($precedent->ordresReparation()->get() as $or) {
                $correspondanceOr[$or->id] = $segment->ordresReparation()->create($or->only(['numero', 'type', 'client_id']))->id;
            }
            foreach ($modeles as &$parElement) {
                foreach ($parElement as &$parts) {
                    $parts = array_map(fn ($p) => ['ordre_reparation_id' => $correspondanceOr[$p['ordre_reparation_id']] ?? null] + $p, $parts);
                }
            }
            unset($parElement, $parts);
            EnregistrementOdm::recalculer($segment, modeles: $modeles);

            $rattrapages = $segment->participantsActifs()->where('nuit_rattrapage', '>', 0)->count();
            $message = \App\Exceptions\ErreurMetier::texte('MSG-M12-06', ['segment' => $precedent->numero, 'nombre' => $rattrapages]);
            if ($type === 'renouvellement') {
                $message = 'Renouvellement : période du ' . \App\Support\Format::date($depart) . ' au ' . \App\Support\Format::date($retour) . '. ' . $message;
            }
            $libelle = OrdreMission::TYPES_SEGMENT[$type];

            HistoriqueOdm::enregistrer($segment, 'creation', null, 'BROUILLON', $auteur->id,
                "{$libelle} {$segment->rang} créé(e) depuis l'ordre de mission {$precedent->numero}. {$message}"
                . ($motif !== '' && $type === 'prolongation' ? " Motif : {$motif}." : '') . ($notes ? ' ' . implode(' ', $notes) : ''));
            HistoriqueOdm::enregistrer($precedent, 'prolongation', $precedent->statut, $precedent->statut, $auteur->id,
                "{$libelle} en préparation : départ le " . \App\Support\Format::date($depart) . ', retour prévu le ' . \App\Support\Format::date($dateRetour) . '.');

            return [$segment->fresh(), $message];
        });
    }

    /** Participants d'une prolongation : ceux du segment précédent (retrait possible, ajout impossible) */
    public static function participantsAdmis(OrdreMission $segment): array
    {
        return ParticipantOdm::where('ordre_mission_id', $segment->segment_precedent_id)->where('retire', false)->pluck('user_id')->all();
    }
}
