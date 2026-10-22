<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\ParticipantOdm;
use App\Models\TauxChange;
use App\Models\User;
use App\Services\BonCaisse\ReglesSaisie;
use App\Services\BonCaisse\SoumettreBon;
use App\Support\Format;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Génération des bons de caisse d'un ODM validé (RG-M12-13 à RG-M12-15, RG-M12-29 ; RG-M03-15, RG-M03-22).
 *
 * - Un BD par participant (spec v2.4, RG-M12-14, décision du 14/10) : le bon groupé est abandonné.
 * - Génération globale (tous les participants sans bon) ou sélective (les participants cochés, ou un seul) : les autres
 *   restent à générer plus tard.
 * - Champs repris de l'ODM et verrouillés : bénéficiaire, montant, motif, site, service, code analytique, OR.
 *   Catégorie « mission », mode souhaité Orange Money (décision finale du caissier). L'ODM tient lieu de justificatif.
 * - Chaque bon est soumis aussitôt et suit son propre circuit complet (chef de service → CDG → Finance → DP).
 * - BP facultatif (paramètre « ODM générant un BP ») : avance pour frais réels, lié à la mission, à régulariser
 *   3 jours ouvrés après le retour.
 * - Prise en charge (spec v2.3, RG-M12-15) : variante A, bons générés pour le montant total et ODM « à refacturer »
 *   dès qu'une part est client ; variante B, aucun bon, seulement si toutes les parts de toutes les lignes sont client.
 * - ODM extérieur : montant estimé au dernier taux saisi (RG-M12-10), recalculé au paiement (PaiementOdm).
 *
 * Tout est fait dans une transaction : un bon refusé par les contrôles annule la génération entière.
 * Un participant dont le bon a été annulé (ou rejeté puis annulé) peut recevoir un nouveau bon.
 */
final class GenererBonsOdm
{
    public const STATUTS_AUTORISES = ['VALIDE', 'BONS_GENERES'];

    /** Statuts d'un bon qui ne compte plus pour l'ODM */
    public const STATUTS_INACTIFS = ['ANNULE'];

    /**
     * @param array{participants?: ?array<int>, bp?: ?array{montant: int|float|string, motif: string, beneficiaire_id?: ?int}} $options
     *        participants : utilisateurs dont générer le bon (null : tous ceux qui n'en ont pas ; [] : aucun, BP seul)
     * @return Collection<int, BonCaisse> bons générés
     */
    public static function executer(OrdreMission $odm, User $auteur, array $options = []): Collection
    {
        return DB::transaction(function () use ($odm, $auteur, $options) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();
            self::verifier($odm, $auteur);

            $selection = $options['participants'] ?? null;
            if ($selection !== null) {
                $inconnus = array_diff($selection, $odm->participantsActifs()->pluck('user_id')->all());
                if ($inconnus) {
                    throw new ErreurMetier('PARTICIPANT_INCONNU', 'MSG-APP-053', [], 'RG-M12-13', 'participants', 422);
                }
            }
            $bons = $selection === [] ? collect() : self::bonsManquants($odm, $auteur, $selection);

            if (!empty($options['bp'])) {
                $bons->push(self::bonProvisoire($odm, $auteur, $options['bp']));
            }

            if ($bons->isEmpty()) {
                throw new ErreurMetier('AUCUN_BON_A_GENERER', 'MSG-APP-030', [], 'RG-M12-13', null, 409);
            }

            $statutAvant = $odm->statut;
            $odm->update(['statut' => 'BONS_GENERES', 'a_refacturer' => $odm->aDesPartsClient()]);
            HistoriqueOdm::enregistrer($odm, 'generation_bons', $statutAvant, 'BONS_GENERES', $auteur->id,
                'Bon(s) généré(s) et soumis : ' . $bons->map(fn (BonCaisse $b) => "{$b->numero} ({$b->type_bon}, " . Format::montant($b->montant) . ')')->implode(', ') . '.',
                ['bons' => $bons->pluck('id')->all()]);

            return $bons;
        });
    }

    /**
     * BD des participants qui n'ont pas de bon actif, créés et soumis (un par participant) ; limités à la sélection
     * si elle est donnée (identifiants d'utilisateurs). Sert à la génération et à la clôture d'un ODM (bons régénérés au
     * réel, décision Q26). À appeler dans une transaction.
     */
    public static function bonsManquants(OrdreMission $odm, User $auteur, ?array $selection = null): Collection
    {
        $sansBon = self::participantsSansBon($odm)
            ->filter(fn (ParticipantOdm $p) => $selection === null || in_array($p->user_id, $selection, true))->values();
        if ($sansBon->isEmpty()) {
            return collect();
        }
        if ($odm->type === 'exterieur') {
            self::estimerAuDernierTaux($odm);
            $sansBon->each->refresh();
        }

        return $sansBon->map(fn (ParticipantOdm $p) => self::bonParticipant($odm, $p, $auteur));
    }

    /** Participants présents sans bon actif (jamais généré, ou annulé) */
    public static function participantsSansBon(OrdreMission $odm): Collection
    {
        return $odm->participantsActifs()->with(['utilisateur', 'bonCaisse'])->get()
            ->filter(fn (ParticipantOdm $p) => !$p->bonCaisse || in_array($p->bonCaisse->statut, self::STATUTS_INACTIFS, true))
            ->values();
    }

    /** Contrôles préalables (RG-M12-13, RG-M12-15) */
    public static function verifier(OrdreMission $odm, User $auteur): void
    {
        if (!in_array($auteur->id, [$odm->demandeur_id, $odm->initiateur_id], true)) {
            throw new ErreurMetier('GENERATION_INTERDITE', 'MSG-APP-020', [], 'RG-M12-13', null, 403);
        }
        if (!in_array($odm->statut, self::STATUTS_AUTORISES, true)) {
            throw new ErreurMetier('ODM_NON_VALIDE', 'MSG-APP-027', [], 'RG-M12-13', null, 409);
        }
        if (self::sansBon($odm)) {
            throw new ErreurMetier('ODM_HORS_CAISSE', 'MSG-APP-028', [], 'RG-M12-15', null, 409);
        }
    }

    /** RG-M12-15 (v2.3), variante B : aucun bon, si toutes les parts de toutes les lignes sont à la charge de clients */
    public static function sansBon(OrdreMission $odm): bool
    {
        if (Parametre::valeur('odm_prise_en_charge_client', 'variante_a') !== 'variante_b') {
            return false;
        }
        $parts = RepartitionOdm::partsActives($odm);

        return $parts->isNotEmpty() && $parts->every(fn ($p) => $p->payeur === 'client');
    }

    /** Bons de l'ODM encore actifs (non annulés) */
    /**
     * RG-M07-02, RG-M12-17, RG-M12-20 : la date de retour des BP de la mission (et l'échéance d'un BP déjà payé) suit la
     * prolongation validée, puis le retour réel ; sinon un BP paraîtrait en retard pendant la prolongation, ou trop tard
     * après un retour anticipé. Renvoie le nombre de BP recalés.
     */
    public static function recalerBp(OrdreMission $odm, \Carbon\CarbonInterface $retour, ?User $auteur, string $raison): int
    {
        $bps = BonCaisse::whereIn('odm_id', $odm->segmentsDeLaMission()->pluck('id'))
            ->where('type_bon', 'BP')->where('lie_mission', true)
            ->whereNotIn('statut', ['REGULARISE', 'ARCHIVE', 'ANNULE', 'REJETE'])->get();
        $recales = 0;
        foreach ($bps as $bp) {
            if ($bp->date_retour_mission?->isSameDay($retour)) {
                continue;
            }
            $bp->date_retour_mission = $retour->toDateString();
            if ($bp->statut === 'EN_ATTENTE_REGULARISATION' && $bp->date_paiement) {
                $bp->date_limite_regularisation = $bp->dateLimiteRegularisation($bp->date_paiement)->toDateString();
            }
            $bp->save();
            \App\Models\HistoriqueAction::enregistrer($bp, \App\Models\HistoriqueAction::ACTION_MODIFICATION, $bp->statut, $bp->statut, $auteur?->id,
                "{$raison} : retour de mission au " . \App\Support\Format::date($retour)
                . ($bp->date_limite_regularisation ? ', régularisation attendue au plus tard le ' . \App\Support\Format::date($bp->date_limite_regularisation) : '') . '.');
            $recales++;
        }

        return $recales;
    }

    public static function bonsActifs(OrdreMission $odm): Collection
    {
        return $odm->bons()->where('genere_par_odm', true)->whereNotIn('statut', self::STATUTS_INACTIFS)->get();
    }

    /** RG-M12-10 : estimation au dernier taux saisi ; sans aucun taux, la génération est impossible */
    private static function estimerAuDernierTaux(OrdreMission $odm): void
    {
        $taux = TauxChange::dernier();
        if (!$taux) {
            throw new ErreurMetier('TAUX_INCONNU', 'MSG-APP-029', [], 'RG-M12-10', null, 409);
        }
        $figes = $odm->parametres_figes ?? [];
        $figes['taux_estime'] = (float) $taux->taux;
        $figes['taux_date'] = Format::date($taux->date_taux);
        $odm->update(['parametres_figes' => $figes]);
        EnregistrementOdm::recalculer($odm->fresh());
    }

    private static function bonParticipant(OrdreMission $odm, ParticipantOdm $participant, User $auteur): BonCaisse
    {
        $participant->refresh();
        $bon = self::creer($odm, $participant->utilisateur, (float) $participant->total, self::motif($odm, $participant->nom), [
            'odm_participant_id' => $participant->id,
            'telephone_beneficiaire' => ReglesSaisie::normaliserTelephone($participant->numero_om ?? $participant->utilisateur?->telephone),
        ] + self::partsExterieur($odm, $participant));
        $participant->update(['bon_caisse_id' => $bon->id]);

        return self::soumettre($bon, $auteur);
    }

    /** BP « avance pour frais réels de mission » (RG-M12-13, SC-37) */
    private static function bonProvisoire(OrdreMission $odm, User $auteur, array $bp): BonCaisse
    {
        if (!Parametre::valeur('odm_genere_bp', true)) {
            throw new ErreurMetier('BP_NON_AUTORISE', 'MSG-APP-031', [], 'RG-M12-13', 'bp', 409);
        }
        if ($odm->bons()->where('type_bon', 'BP')->whereNotIn('statut', self::STATUTS_INACTIFS)->exists()) {
            throw new ErreurMetier('BP_DEJA_GENERE', 'MSG-APP-032', [], 'RG-M12-13', 'bp', 409);
        }
        $montant = (int) preg_replace('/\D/', '', (string) ($bp['montant'] ?? ''));
        $motif = trim((string) ($bp['motif'] ?? ''));
        if ($montant < 1) {
            throw new ErreurMetier('MONTANT_BP', 'MSG-BC-003', [], 'RG-M12-13', 'bp_montant');
        }
        if (mb_strlen($motif) < 10) {
            throw new ErreurMetier('MOTIF_BP', 'MSG-BC-002', [], 'RG-M12-13', 'bp_motif');
        }
        $participant = $odm->participantsActifs()->with('utilisateur')->get()
            ->firstWhere('user_id', $bp['beneficiaire_id'] ?? null) ?? $odm->participantsActifs()->with('utilisateur')->first();

        $bon = self::creer($odm, $participant->utilisateur, $montant, mb_substr($motif . ' — ODM ' . $odm->numero, 0, 200), [
            'type_bon' => 'BP',
            'lie_mission' => true,
            'date_retour_mission' => $odm->date_retour_prevue,
            'telephone_beneficiaire' => ReglesSaisie::normaliserTelephone($participant->numero_om ?? $participant->utilisateur?->telephone),
        ]);

        return self::soumettre($bon, $auteur);
    }

    private static function creer(OrdreMission $odm, ?User $beneficiaire, float $montant, string $motif, array $attributs = []): BonCaisse
    {
        $bon = new BonCaisse(array_merge([
            'type_bon' => 'BD',
            'site' => $odm->site,
            'service' => $odm->service,
            'code_analytique' => $odm->code_analytique,
            'niveau_urgence' => 'normale',
            'type_beneficiaire' => 'employe',
            'beneficiaire_id' => $beneficiaire?->id,
            'beneficiaire' => $beneficiaire?->nom_complet,
            'motif' => $motif,
            'categorie_depense' => 'mission',
            'montant' => round($montant),
            'mode_paiement' => 'orange_money',
            'references_or' => $odm->ordresReparation()->pluck('numero')->all() ?: null,
            'types_or' => $odm->ordresReparation()->pluck('type', 'numero')->all() ?: null,
            'lie_mission' => false,
            'odm_id' => $odm->id,
            'entite_id' => $odm->entite_id,
            'genere_par_odm' => true,
            'demandeur_id' => $odm->demandeur_id,
            'initiateur_id' => $odm->demandeur_id,
            'date_demande' => today(),
            'statut' => 'BROUILLON',
        ], $attributs));
        /* RG-M03-10 : n° OM du participant */
        $bon->numero_om ??= $bon->mode_paiement === 'orange_money' ? $bon->telephone_beneficiaire : null;
        $bon->caisse_emettrice_id = \App\Models\Caisse::deRattachement($odm->demandeur)?->id;
        $bon->caisse_id = $bon->caissePayeuse()?->id;
        $bon->save();

        return $bon;
    }

    private static function soumettre(BonCaisse $bon, User $auteur): BonCaisse
    {
        [$bon] = SoumettreBon::executer($bon, $auteur);

        return $bon;
    }

    /** Motif du bon : « Indemnités de mission — ODM N°285/AT/26, Kouroussa, du 22/09/2026 au 26/09/2026 — BAH Thierno » */
    private static function motif(OrdreMission $odm, string $complement): string
    {
        $motif = "Indemnités de mission — ODM {$odm->numero}, " . implode(', ', $odm->destinations ?? [])
            . ', du ' . Format::date($odm->date_depart) . ' au ' . Format::date($odm->date_retour_prevue) . " — {$complement}";

        return mb_substr($motif, 0, 200);
    }

    /** ODM extérieur : part en FCFA et part fixe en GNF du participant, pour le recalcul au paiement (RG-M12-10) */
    private static function partsExterieur(OrdreMission $odm, ParticipantOdm $participant): array
    {
        if ($odm->type !== 'exterieur') {
            return [];
        }

        return [
            'montant_fcfa' => (float) $participant->indemnite_fcfa,
            'montant_gnf_fixe' => (float) $participant->hebergement + (float) $participant->rattrapage,
            'taux_change_estime' => $odm->parametres_figes['taux_estime'] ?? null,
            'montant_estime' => (float) $participant->total,
        ];
    }
}
