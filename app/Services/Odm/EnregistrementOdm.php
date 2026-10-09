<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\OrdreReparationOdm;
use App\Models\ParticipantOdm;
use App\Models\TauxChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Création et enregistrement d'un ODM en brouillon (ou rejeté, à corriger) : en-tête, participants, OR,
 * puis calcul de chaque participant (le serveur fait foi, l'écran affiche ce calcul).
 *
 * Une dérogation au chevauchement (RG-M12-16) ne vaut que pour les dates et les participants pour lesquels
 * elle a été demandée : si l'un d'eux change, elle est retirée.
 *
 * Prise en charge (Q49) : chaque participant porte le choix de chacune de ses lignes de frais ; l'en-tête
 * (neemba, client ou mixte) en est déduit après le calcul. Un nouveau participant reçoit le choix de l'en-tête.
 */
final class EnregistrementOdm
{
    /** Champs de l'en-tête modifiables par le demandeur */
    public const CHAMPS = [
        'type', 'technique', 'site', 'service', 'code_analytique', 'but', 'clients', 'destinations', 'vehicule',
        'date_depart', 'date_retour_prevue', 'motif_depart_passe', 'prise_en_charge', 'mode_client', 'hebergement_exterieur', 'reference_billet',
    ];

    public static function creer(User $demandeur, array $donnees = []): OrdreMission
    {
        $odm = DB::transaction(function () use ($demandeur) {
            $odm = OrdreMission::create([
                'type' => 'interieur',
                'entite' => $demandeur->entite,
                'site' => $demandeur->site,
                'service' => $demandeur->service,
                /* RG-M12-02 (PO-06) : case pré-cochée pour Technique et Aftermarket */
                'technique' => in_array($demandeur->service, OrdreMission::SERVICES_TECHNIQUES, true),
                'prise_en_charge' => 'neemba',
                'demandeur_id' => $demandeur->id,
                'initiateur_id' => $demandeur->id,
                'statut' => 'BROUILLON',
            ]);
            HistoriqueOdm::enregistrer($odm, 'creation', null, 'BROUILLON', $demandeur->id, 'Ordre de mission créé en brouillon.');

            return $odm;
        });

        return $donnees === [] ? $odm : self::enregistrer($odm, $donnees, $demandeur);
    }

    public static function enregistrer(OrdreMission $odm, array $donnees, User $auteur): OrdreMission
    {
        return DB::transaction(function () use ($odm, $donnees, $auteur) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();
            if (!in_array($odm->statut, ['BROUILLON', 'REJETE'], true)) {
                throw new ErreurMetier('ODM_DEJA_SOUMIS', 'MSG-APP-019', [], 'RG-M12-12', null, 409);
            }

            $signatureAvant = self::signature($odm);

            /* RG-M12-17 : une prolongation garde le type et le départ fixés à sa création */
            $verrouilles = $odm->segment_precedent_id ? ['type', 'date_depart'] : [];
            foreach (array_diff(self::CHAMPS, $verrouilles) as $champ) {
                if (array_key_exists($champ, $donnees)) {
                    $odm->{$champ} = self::normaliser($champ, $donnees[$champ]);
                }
            }
            if ($odm->type !== 'exterieur') {
                $odm->hebergement_exterieur = null;
            }
            $odm->save();

            if (array_key_exists('participants', $donnees)) {
                self::synchroniserParticipants($odm, (array) $donnees['participants']);
            }
            if (array_key_exists('ordres_reparation', $donnees)) {
                self::synchroniserOr($odm, (array) $donnees['ordres_reparation']);
            }

            /* Dérogation retirée si les dates ou les participants ont changé */
            if ($odm->derogation_statut !== null && self::signature($odm->fresh()) !== $signatureAvant) {
                $odm->update(['derogation_statut' => null, 'derogation_demande_motif' => null, 'derogation_par_id' => null, 'derogation_motif' => null, 'derogation_le' => null]);
                HistoriqueOdm::enregistrer($odm, 'modification', $odm->statut, $odm->statut, $auteur->id,
                    'Dates ou participants modifiés : la dérogation au chevauchement est retirée.');
            }

            self::recalculer($odm);

            return $odm->fresh();
        });
    }

    /**
     * Calcul de chaque participant (RG-M12-07) avec les barèmes en vigueur, ou figés une fois l'ODM validé (RG-M12-25).
     * ODM extérieur : indemnité estimée avec le dernier taux saisi (RG-M12-10), recalculée au paiement.
     */
    public static function recalculer(OrdreMission $odm, ?array $baremes = null, float|string|null $taux = null): void
    {
        $baremes ??= $odm->parametres_figes['baremes'] ?? CalculOdm::baremesEnVigueur();
        if ($odm->type === 'exterieur') {
            $taux ??= $odm->parametres_figes['taux_estime'] ?? TauxChange::dernier()?->taux;
        }
        $segment = [
            'type' => $odm->type,
            /* Retour réel s'il est saisi (clôture, RG-M12-20), sinon retour prévu */
            'jours' => CalculOdm::jours($odm->date_depart, $odm->dateFin()),
            'hebergement_exterieur' => $odm->hebergement_exterieur,
            'taux' => $taux,
        ];

        /* RG-M12-18 : nuitée de rattrapage pour qui n'était pas logé sur base vie au segment précédent */
        $precedents = $odm->segment_precedent_id
            ? ParticipantOdm::where('ordre_mission_id', $odm->segment_precedent_id)->where('retire', false)->pluck('base_vie', 'user_id')
            : collect();

        $calculs = [];
        foreach ($odm->participantsActifs()->get() as $participant) {
            $calcul = CalculOdm::participant([
                'base_vie' => $odm->type === 'interieur' && $participant->base_vie,
                'statut_cadre' => $participant->statut_cadre,
                'hebergement_facture' => $participant->hebergement_facture,
                'rattrapage' => $precedents->has($participant->user_id) && !$precedents[$participant->user_id],
                'prises_en_charge' => $participant->prises_en_charge,
                'prise_defaut' => $odm->priseParDefaut(),
            ], $segment, $baremes);
            $participant->update([
                'jours' => $calcul['jours'],
                'nuits' => $calcul['nuits'],
                'nuit_rattrapage' => $calcul['nuit_rattrapage'],
                'indemnite_fcfa' => $calcul['indemnite_fcfa'],
                'indemnite' => $calcul['indemnite'],
                'hebergement' => $calcul['hebergement'],
                'rattrapage' => $calcul['rattrapage'],
                'total' => $calcul['total'],
                'prises_en_charge' => $calcul['prises_en_charge'],
                'montant_bon' => $calcul['montant_bon'],
                'montant_refacturable' => $calcul['montant_refacturable'],
                'montant_client_direct' => $calcul['montant_client_direct'],
            ]);
            $calculs[] = $calcul;
        }

        $valeurs = [
            'total' => $calculs === [] ? null : CalculOdm::total($calculs),
            'montant_a_refacturer' => $calculs === [] ? null : CalculOdm::total($calculs, 'montant_refacturable'),
        ];
        /* Q49 : en-tête déduit des lignes (neemba, client ou mixte) */
        if ($globale = CalculOdm::priseEnChargeGlobale($calculs)) {
            $valeurs['prise_en_charge'] = $globale['prise_en_charge'];
            if ($globale['mode_client']) {
                $valeurs['mode_client'] = $globale['mode_client'];
            }
        }
        $odm->update($valeurs);
    }

    /**
     * Informations du référentiel reprises sur les participants (RG-M12-04), relues à la soumission :
     * un statut cadre renseigné par les RH entre-temps est pris en compte.
     */
    public static function actualiserParticipants(OrdreMission $odm): void
    {
        foreach ($odm->participantsActifs()->with('utilisateur')->get() as $participant) {
            if ($participant->utilisateur) {
                $participant->update(collect(ParticipantOdm::depuisUtilisateur($participant->utilisateur))->except('user_id')->all());
            }
        }
    }

    private static function synchroniserParticipants(OrdreMission $odm, array $participants): void
    {
        $voulus = collect($participants)
            ->filter(fn ($p) => is_array($p) && is_numeric($p['user_id'] ?? null))
            ->keyBy(fn ($p) => (int) $p['user_id']);

        /* RG-M12-17 : une prolongation reprend les participants du segment précédent ; retrait possible, ajout impossible */
        if ($odm->segment_precedent_id) {
            $admis = ProlongerOdm::participantsAdmis($odm);
            if ($voulus->keys()->diff($admis)->isNotEmpty()) {
                throw new ErreurMetier('AJOUT_INTERDIT_PROLONGATION', 'MSG-APP-033', [], 'RG-M12-17', 'participants');
            }
            $odm->participants()->whereNotIn('user_id', $voulus->keys())->update(['retire' => true]);
        } else {
            $odm->participants()->whereNotIn('user_id', $voulus->keys())->delete();
        }

        foreach ($voulus as $userId => $voulu) {
            $existant = $odm->participants()->where('user_id', $userId)->first();
            $valeurs = [
                'base_vie' => (bool) ($voulu['base_vie'] ?? false),
                'hebergement_facture' => is_numeric($voulu['hebergement_facture'] ?? null) ? max(0, (float) $voulu['hebergement_facture']) : null,
            ];
            /* Q49 : choix par ligne ; une ligne non précisée garde son choix, ou prend celui de l'en-tête */
            $prises = is_array($voulu['prises_en_charge'] ?? null) ? $voulu['prises_en_charge'] : [];
            if ($existant) {
                $valeurs['prises_en_charge'] = CalculOdm::prisesNormalisees(array_merge((array) $existant->prises_en_charge, $prises), $odm->priseParDefaut());
                $existant->update($valeurs + ['retire' => false]);
                continue;
            }
            $valeurs['prises_en_charge'] = CalculOdm::prisesNormalisees($prises, $odm->priseParDefaut());
            $utilisateur = User::find($userId);
            if ($utilisateur) {
                $odm->participants()->create(ParticipantOdm::depuisUtilisateur($utilisateur) + $valeurs);
            }
        }
    }

    private static function synchroniserOr(OrdreMission $odm, array $ors): void
    {
        $odm->ordresReparation()->delete();
        $vus = [];
        foreach ($ors as $or) {
            $numero = preg_replace('/\D/', '', (string) (is_array($or) ? ($or['numero'] ?? '') : $or));
            if ($numero === '' || isset($vus[$numero])) {
                continue;
            }
            $vus[$numero] = true;
            $type = is_array($or) && array_key_exists($or['type'] ?? '', OrdreReparationOdm::TYPES) ? $or['type'] : 'vente';
            $odm->ordresReparation()->create(['numero' => mb_substr($numero, 0, 8), 'type' => $type]);
        }
    }

    private static function normaliser(string $champ, mixed $valeur): mixed
    {
        return match ($champ) {
            'technique' => filter_var($valeur, FILTER_VALIDATE_BOOLEAN),
            'clients', 'destinations' => array_values(array_unique(array_filter(
                array_map(fn ($v) => mb_substr(trim((string) $v), 0, 120), is_array($valeur) ? $valeur : [$valeur]),
                fn ($v) => $v !== '',
            ))),
            'date_depart', 'date_retour_prevue' => blank($valeur) ? null : $valeur,
            default => is_string($valeur) ? (trim($valeur) === '' ? null : trim($valeur)) : $valeur,
        };
    }

    /** Dates et participants : ce que couvre une dérogation au chevauchement */
    private static function signature(OrdreMission $odm): string
    {
        return implode('|', [
            $odm->date_depart?->toDateString(),
            $odm->date_retour_prevue?->toDateString(),
            $odm->participantsActifs()->orderBy('user_id')->pluck('user_id')->implode(','),
        ]);
    }
}
