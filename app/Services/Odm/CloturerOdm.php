<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\ParticipantOdm;
use App\Models\User;
use App\Services\BonCaisse\ReglesSaisie;
use App\Support\Format;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Clôture d'une mission (RG-M12-20, RG-M12-26 ; SC-25, décisions Q24 et Q26).
 *
 * Le demandeur saisit la date de retour réelle sur le dernier segment validé de la mission :
 * - retour à la date prévue : clôture simple ;
 * - retour anticipé : calcul refait au réel ; pour chaque participant déjà payé, le trop-perçu est calculé
 *   (MSG-M12-09) et sera reversé en caisse ou retenu sur salaire ; un bon non encore payé est annulé et régénéré
 *   au montant réel (Q26) ;
 * - retour tardif : refusé, la mission doit d'abord être prolongée (MSG-APP-036).
 * ODM extérieur, hébergement payé au retour : un bon complémentaire est préparé en brouillon pour chaque facture,
 * à compléter avec la facture puis à soumettre (justificatif obligatoire, Q24).
 */
final class CloturerOdm
{
    public static function peutCloturer(OrdreMission $odm, User $utilisateur): bool
    {
        return in_array($odm->statut, ProlongerOdm::STATUTS_PROLONGEABLES, true)
            && in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)
            && !ProlongerOdm::prolongationEnCours($odm);
    }

    /**
     * @param array<int|string, string> $regularisations user_id => reversement | retenue (défaut : reversement)
     * @param array<int|string, int|float|string> $facturesRetour user_id => montant de la facture d'hébergement payée au retour
     * @return array{0: OrdreMission, 1: string[]} l'ODM clôturé et les messages (MSG-M12-09, bons)
     */
    public static function executer(OrdreMission $odm, User $auteur, ?string $dateRetourReelle, array $regularisations = [], array $facturesRetour = []): array
    {
        return DB::transaction(function () use ($odm, $auteur, $dateRetourReelle, $regularisations, $facturesRetour) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();
            if (!self::peutCloturer($odm, $auteur)) {
                throw new ErreurMetier('CLOTURE_IMPOSSIBLE', 'MSG-APP-035', [], 'RG-M12-20', null, 409);
            }
            if (!$dateRetourReelle || Carbon::parse($dateRetourReelle)->lessThan($odm->date_depart)) {
                throw new ErreurMetier('RETOUR_AVANT_DEPART', 'MSG-M12-02', [], 'RG-M12-06', 'date_retour_reelle');
            }
            $retour = Carbon::parse($dateRetourReelle)->startOfDay();
            if ($retour->greaterThan($odm->date_retour_prevue)) {
                throw new ErreurMetier('RETOUR_TARDIF', 'MSG-APP-036', [], 'RG-M12-20', 'date_retour_reelle');
            }

            $messages = [];
            $statutAvant = $odm->statut;
            $anticipe = $retour->lessThan($odm->date_retour_prevue);

            if ($anticipe) {
                $messages = self::retourAnticipe($odm, $auteur, $retour, $regularisations);
            } else {
                $odm->update(['date_retour_reelle' => $retour->toDateString()]);
            }

            /* Q26, Q47 : les participants sans bon actif (jamais généré, ou remplacé au réel) reçoivent leur bon au montant réel */
            if (!GenererBonsOdm::sansBon($odm)) {
                $nouveaux = GenererBonsOdm::bonsManquants($odm->fresh(), $auteur);
                if ($nouveaux->isNotEmpty()) {
                    $messages[] = 'Bon(s) généré(s) au montant réel : '
                        . $nouveaux->map(fn (BonCaisse $b) => "{$b->numero} (" . Format::montant($b->montant) . ')')->implode(', ') . '.';
                }
            }

            if ($odm->type === 'exterieur' && $odm->hebergement_exterieur === 'au_retour') {
                $messages = array_merge($messages, self::bonsComplementaires($odm, $facturesRetour));
            }

            $odm->update(['statut' => 'CLOTURE', 'date_cloture' => now()]);
            HistoriqueOdm::enregistrer($odm, 'cloture', $statutAvant, 'CLOTURE', $auteur->id,
                'Mission clôturée : retour réel le ' . Format::date($retour) . ($anticipe ? ' (anticipé, prévu le ' . Format::date($odm->date_retour_prevue) . ').' : '.')
                . ($messages ? ' ' . implode(' ', $messages) : ''));

            $odm = $odm->fresh();
            NotificationsOdm::cloture($odm, $auteur, $messages);

            return [$odm, $messages];
        });
    }

    /**
     * Retour anticipé : calcul au réel, trop-perçu des participants payés, bons non payés régénérés.
     *
     * @return string[]
     */
    private static function retourAnticipe(OrdreMission $odm, User $auteur, Carbon $retour, array $regularisations): array
    {
        $participants = $odm->participantsActifs()->with('bonCaisse')->get();
        $avant = $participants->mapWithKeys(fn (ParticipantOdm $p) => [$p->id => [
            'total' => (float) $p->total, 'indemnite_fcfa' => (float) $p->indemnite_fcfa,
        ]]);

        $odm->update(['date_retour_reelle' => $retour->toDateString()]);
        EnregistrementOdm::recalculer($odm->fresh());

        $messages = [];
        foreach ($odm->participantsActifs()->with('bonCaisse')->get() as $participant) {
            $bon = $participant->bonCaisse;
            if (!$bon || !in_array($bon->statut, PaiementOdm::STATUTS_PAYES, true)) {
                continue;
            }
            $trop = self::tropPercu($participant, $avant[$participant->id], $bon);
            if ($trop <= 0) {
                continue;
            }
            $mode = ($regularisations[$participant->user_id] ?? 'reversement') === 'retenue' ? 'retenue' : 'reversement';
            $participant->update(['trop_percu' => $trop, 'regularisation' => $mode, 'regularisation_statut' => 'a_regulariser']);
            $messages[] = ErreurMetier::texte('MSG-M12-09', ['montant' => $trop, 'participant' => $participant->nom])
                . ' (' . mb_strtolower(ParticipantOdm::REGULARISATIONS[$mode]) . ')';
        }

        /* Q26 : bon non payé annulé et régénéré au montant réel */
        $nonPayes = GenererBonsOdm::bonsActifs($odm)->where('type_bon', 'BD')
            ->reject(fn (BonCaisse $b) => in_array($b->statut, PaiementOdm::STATUTS_PAYES, true));
        if ($nonPayes->isNotEmpty() && !GenererBonsOdm::sansBon($odm)) {
            $annules = AnnulerOdm::annulerBonsNonPayes($nonPayes, $auteur,
                "Retour anticipé de la mission {$odm->numero} : bon remplacé par un bon au montant réel.");
            $messages[] = 'Bon(s) ' . $annules->pluck('numero')->implode(', ') . ' annulé(s) et remplacé(s) au montant réel.';
        }

        return $messages;
    }

    /** Trop-perçu d'un participant payé : montant versé moins montant réel (ODM extérieur : au taux appliqué au paiement) */
    private static function tropPercu(ParticipantOdm $participant, array $avant, BonCaisse $bon): float
    {
        /* À l'étranger, l'hébergement ne dépend pas des jours : seule l'indemnité en FCFA change, au taux du paiement */
        if ($bon->taux_change_applique !== null) {
            $taux = (float) $bon->taux_change_applique;

            return max(0, round($avant['indemnite_fcfa'] * $taux) - round((float) $participant->indemnite_fcfa * $taux));
        }

        return max(0, round($avant['total'] - (float) $participant->total));
    }

    /**
     * Q24 : hébergement à l'étranger payé au retour, un bon complémentaire en brouillon par facture,
     * à compléter avec la facture (justificatif obligatoire) puis à soumettre.
     *
     * @return string[]
     */
    private static function bonsComplementaires(OrdreMission $odm, array $factures): array
    {
        $crees = [];
        foreach ($odm->participantsActifs()->with('utilisateur')->get() as $participant) {
            $montant = (int) preg_replace('/\D/', '', (string) ($factures[$participant->user_id] ?? ''));
            if ($montant < 1) {
                continue;
            }
            $bon = BonCaisse::create([
                'type_bon' => 'BD',
                'site' => $odm->site,
                'service' => $odm->service,
                'code_analytique' => $odm->code_analytique,
                'niveau_urgence' => 'normale',
                'type_beneficiaire' => 'employe',
                'beneficiaire_id' => $participant->user_id,
                'beneficiaire' => $participant->utilisateur?->nom_complet,
                'telephone_beneficiaire' => ReglesSaisie::normaliserTelephone($participant->numero_om ?? $participant->utilisateur?->telephone),
                'motif' => mb_substr("Hébergement à l'étranger, facture payée au retour — ODM {$odm->numero} — {$participant->nom}", 0, 200),
                'categorie_depense' => 'hebergement',
                'montant' => $montant,
                'mode_paiement' => 'orange_money',
                'odm_id' => $odm->id,
                'demandeur_id' => $odm->demandeur_id,
                'initiateur_id' => $odm->demandeur_id,
                'date_demande' => today(),
                'statut' => 'BROUILLON',
            ]);
            $participant->update(['bon_complement_id' => $bon->id]);
            $crees[] = $participant->nom . ' (' . Format::montant($montant) . ')';
        }

        return $crees ? ['Bon(s) complémentaire(s) d\'hébergement préparé(s) en brouillon, à compléter avec la facture puis à soumettre : ' . implode(', ', $crees) . '.'] : [];
    }
}
