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
 * - Un BD par participant (défaut) ou un bon groupé versé au n° OM d'un participant désigné (paramètre, PO-03).
 * - Champs repris de l'ODM et verrouillés : bénéficiaire, montant, motif, site, service, code analytique, OR.
 *   Catégorie « mission », mode souhaité Orange Money (décision finale du caissier). L'ODM tient lieu de justificatif.
 * - Chaque bon est soumis aussitôt et suit son propre circuit complet (chef de service → CDG → Finance → DP).
 * - BP facultatif (paramètre « ODM générant un BP ») : avance pour frais réels, lié à la mission, à régulariser
 *   3 jours ouvrés après le retour.
 * - Prise en charge client : variante A, bons générés et ODM « à refacturer » ; variante B, aucun bon.
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
     * @param array{beneficiaire_groupe_id?: ?int, bp?: ?array{montant: int|float|string, motif: string, beneficiaire_id?: ?int}} $options
     * @return Collection<int, BonCaisse> bons générés
     */
    public static function executer(OrdreMission $odm, User $auteur, array $options = []): Collection
    {
        return DB::transaction(function () use ($odm, $auteur, $options) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();
            self::verifier($odm, $auteur);

            if ($odm->type === 'exterieur') {
                self::estimerAuDernierTaux($odm);
            }

            $bons = collect();
            if (Parametre::valeur('odm_mode_generation', 'par_participant') === 'groupe') {
                if (!self::bonsActifs($odm)->where('type_bon', 'BD')->count()) {
                    $bons->push(self::bonGroupe($odm, $auteur, $options['beneficiaire_groupe_id'] ?? null));
                }
            } else {
                foreach ($odm->participantsActifs()->with(['utilisateur', 'bonCaisse'])->get() as $participant) {
                    if (!$participant->bonCaisse || in_array($participant->bonCaisse->statut, self::STATUTS_INACTIFS, true)) {
                        $bons->push(self::bonParticipant($odm, $participant, $auteur));
                    }
                }
            }

            if (!empty($options['bp'])) {
                $bons->push(self::bonProvisoire($odm, $auteur, $options['bp']));
            }

            if ($bons->isEmpty()) {
                throw new ErreurMetier('AUCUN_BON_A_GENERER', 'MSG-APP-030', [], 'RG-M12-13', null, 409);
            }

            $statutAvant = $odm->statut;
            $odm->update(['statut' => 'BONS_GENERES', 'a_refacturer' => $odm->prise_en_charge === 'client']);
            HistoriqueOdm::enregistrer($odm, 'generation_bons', $statutAvant, 'BONS_GENERES', $auteur->id,
                'Bon(s) généré(s) et soumis : ' . $bons->map(fn (BonCaisse $b) => "{$b->numero} ({$b->type_bon}, " . Format::montant($b->montant) . ')')->implode(', ') . '.',
                ['bons' => $bons->pluck('id')->all()]);

            return $bons;
        });
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

    /** RG-M12-15, variante B : ODM à la charge du client, aucun bon */
    public static function sansBon(OrdreMission $odm): bool
    {
        return $odm->prise_en_charge === 'client' && Parametre::valeur('odm_prise_en_charge_client', 'variante_a') === 'variante_b';
    }

    /** Bons de l'ODM encore actifs (non annulés) */
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
        ] + self::partsExterieur($odm, [$participant]));
        $participant->update(['bon_caisse_id' => $bon->id]);

        return self::soumettre($bon, $auteur);
    }

    /** Bon groupé (RG-M12-14) : total de l'ODM, versé au n° OM du participant désigné */
    private static function bonGroupe(OrdreMission $odm, User $auteur, ?int $beneficiaireId): BonCaisse
    {
        $participants = $odm->participantsActifs()->with('utilisateur')->get();
        $designe = $participants->firstWhere('user_id', $beneficiaireId) ?? $participants->first();
        $total = (float) $participants->sum(fn (ParticipantOdm $p) => (float) $p->total);

        $bon = self::creer($odm, $designe->utilisateur, $total,
            self::motif($odm, "bon groupé de {$participants->count()} participant(s), versé à {$designe->nom}"), [
                'telephone_beneficiaire' => ReglesSaisie::normaliserTelephone($designe->numero_om ?? $designe->utilisateur?->telephone),
            ] + self::partsExterieur($odm, $participants->all()));
        ParticipantOdm::whereIn('id', $participants->pluck('id'))->update(['bon_caisse_id' => $bon->id]);

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
            'lie_mission' => false,
            'odm_id' => $odm->id,
            'genere_par_odm' => true,
            'demandeur_id' => $odm->demandeur_id,
            'initiateur_id' => $odm->demandeur_id,
            'date_demande' => today(),
            'statut' => 'BROUILLON',
        ], $attributs));
        $bon->caisse_id = \App\Models\Caisse::payeusePour((string) $bon->site, $bon->mode_paiement)?->id;
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

    /** ODM extérieur : part en FCFA et part fixe en GNF, pour le recalcul au paiement (RG-M12-10) */
    private static function partsExterieur(OrdreMission $odm, array $participants): array
    {
        if ($odm->type !== 'exterieur') {
            return [];
        }
        $fcfa = array_sum(array_map(fn (ParticipantOdm $p) => (float) $p->indemnite_fcfa, $participants));
        $fixe = array_sum(array_map(fn (ParticipantOdm $p) => (float) $p->hebergement + (float) $p->rattrapage, $participants));
        $total = array_sum(array_map(fn (ParticipantOdm $p) => (float) $p->total, $participants));

        return [
            'montant_fcfa' => $fcfa,
            'montant_gnf_fixe' => $fixe,
            'taux_change_estime' => $odm->parametres_figes['taux_estime'] ?? null,
            'montant_estime' => $total,
        ];
    }
}
