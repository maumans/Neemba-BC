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
 * Spec v2.3 : le calcul produit les lignes de frais de chaque participant ; leurs parts de prise en charge sont saisies
 * ligne par ligne (participants.*.lignes.{élément}.parts), Neemba 100 % par défaut (RepartitionOdm).
 */
final class EnregistrementOdm
{
    /** Champs de l'en-tête modifiables par le demandeur */
    public const CHAMPS = [
        'type', 'technique', 'site', 'service', 'code_analytique', 'but', 'destinations', 'vehicule',
        'date_depart', 'date_retour_prevue', 'motif_depart_passe', 'hebergement_exterieur', 'reference_billet',
        /* Spec v2.4, RG-M01-07, Q76 : urgence de l'ODM (code SMS au visa) */
        'niveau_urgence', 'motif_urgence',
        /* Spec v2.4, RG-M12-39 : OR à régulariser ; RG-M12-42 : besoin carburant */
        'or_a_regulariser', 'immatriculation', 'itineraire',
    ];

    public static function creer(User $demandeur, array $donnees = []): OrdreMission
    {
        /* Spec v2.4, RG-M12-40 : blocage du prochain ODM si un OR à régulariser est en retard (paramètre, désactivé par défaut) */
        RegulariserOr::verifierBlocage($demandeur);

        $odm = DB::transaction(function () use ($demandeur) {
            $odm = OrdreMission::create([
                'type' => 'interieur',
                /* Spec v2.4, RG-M01-02 : entité du demandeur (le champ texte reste pour l'impression) */
                'entite_id' => $demandeur->entite_id,
                'entite' => $demandeur->entite()->value('nom') ?? $demandeur->entite,
                'site' => $demandeur->site,
                'service' => $demandeur->service,
                /* RG-M12-02 (PO-06) : case pré-cochée pour Technique et Aftermarket */
                'technique' => in_array($demandeur->service, OrdreMission::SERVICES_TECHNIQUES, true),
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
            if (($odm->niveau_urgence ?? 'normale') === 'normale') {
                $odm->motif_urgence = null;
            }
            /* RG-M12-39 : l'indicateur ne vaut que pour une mission technique encore sans OR (voir après la mise à jour des OR) */
            if (!$odm->technique) {
                $odm->or_a_regulariser = false;
            }
            $odm->save();

            if (array_key_exists('participants', $donnees)) {
                self::synchroniserParticipants($odm, (array) $donnees['participants']);
            }
            if (array_key_exists('clients', $donnees)) {
                self::synchroniserClients($odm, (array) $donnees['clients']);
            }
            if (array_key_exists('ordres_reparation', $donnees)) {
                self::synchroniserOr($odm, (array) $donnees['ordres_reparation']);
            }
            /* Spec v2.4, RG-M12-39 : un OR rattaché fait tomber l'indicateur « OR à régulariser » */
            if ($odm->or_a_regulariser && $odm->ordresReparation()->exists()) {
                $odm->update(['or_a_regulariser' => false]);
            }

            /* Dérogation retirée si les dates ou les participants ont changé */
            if ($odm->derogation_statut !== null && self::signature($odm->fresh()) !== $signatureAvant) {
                $odm->update(['derogation_statut' => null, 'derogation_demande_motif' => null, 'derogation_par_id' => null, 'derogation_motif' => null, 'derogation_le' => null]);
                HistoriqueOdm::enregistrer($odm, 'modification', $odm->statut, $odm->statut, $auteur->id,
                    'Dates ou participants modifiés : la dérogation au chevauchement est retirée.');
            }

            self::recalculer($odm);

            /* RG-M12-31 : parts saisies, sur les lignes que le calcul vient de mettre à jour */
            if (array_key_exists('participants', $donnees)) {
                RepartitionOdm::appliquer($odm, (array) $donnees['participants'], $auteur);
                RepartitionOdm::repartir($odm);
            }

            return $odm->fresh();
        });
    }

    /**
     * Calcul de chaque participant (RG-M12-07) avec les barèmes en vigueur, ou figés une fois l'ODM validé (RG-M12-25).
     * ODM extérieur : indemnité estimée avec le dernier taux saisi (RG-M12-10), recalculée au paiement.
     * Spec v2.3 : lignes de frais mises à jour (RG-M12-30), parts recalculées d'après leur pourcentage.
     *
     * @param array<int, array> $modeles parts à reprendre sur les lignes nouvelles, par user_id puis élément (prolongation)
     */
    public static function recalculer(OrdreMission $odm, ?array $baremes = null, float|string|null $taux = null, array $modeles = []): void
    {
        $baremes ??= $odm->parametres_figes['baremes'] ?? CalculOdm::baremesEnVigueur(null, $odm->entite_id);
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
            ]);
            RepartitionOdm::synchroniserLignes($odm, $participant, $calcul['lignes'], $modeles[$participant->user_id] ?? []);
            $calculs[] = $calcul;
        }

        $odm->update(['total' => $calculs === [] ? null : CalculOdm::total($calculs)]);
        RepartitionOdm::repartir($odm);
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
                /* Spec v2.4, RG-M12-38 : aucune saisie hors barème ; seule la facture d'un hébergement à l'étranger payé avant
                 * le départ est saisie (RG-M12-26), ignorée ailleurs */
                'hebergement_facture' => $odm->type === 'exterieur' && $odm->hebergement_exterieur === 'avant_depart' && is_numeric($voulu['hebergement_facture'] ?? null)
                    ? max(0, (float) $voulu['hebergement_facture']) : null,
            ];
            if ($existant) {
                $existant->update($valeurs + ['retire' => false]);
                continue;
            }
            $utilisateur = User::find($userId);
            if ($utilisateur) {
                $odm->participants()->create(ParticipantOdm::depuisUtilisateur($utilisateur) + $valeurs);
            }
        }
    }

    /** Spec v2.3, §6.5 : clients pris dans le référentiel ; un client désactivé déjà rattaché reste, aucun nouveau n'est ajouté */
    private static function synchroniserClients(OrdreMission $odm, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $dejaRattaches = $odm->clients()->pluck('clients.id')->all();
        $admis = \App\Models\Client::whereIn('id', $ids)
            ->where(fn ($q) => $q->where('actif', true)->orWhereIn('id', $dejaRattaches))
            ->pluck('id')->all();
        $odm->clients()->sync($admis);
    }

    /**
     * OR mis à jour par numéro (et non recréés) : les parts de prise en charge les référencent (RG-M12-31).
     * Client facultatif de l'OR, pris dans le référentiel (RG-M12-33).
     */
    private static function synchroniserOr(OrdreMission $odm, array $ors): void
    {
        $voulus = [];
        /* Spec v2.4, §6.5 : type du référentiel des types d'OR ; à défaut, le premier type actif */
        $codesTypes = \App\Models\TypeOr::codesActifs();
        foreach ($ors as $or) {
            $numero = mb_substr(preg_replace('/\D/', '', (string) (is_array($or) ? ($or['numero'] ?? '') : $or)), 0, 8);
            if ($numero === '' || isset($voulus[$numero])) {
                continue;
            }
            $clientId = is_array($or) && is_numeric($or['client_id'] ?? null) ? (int) $or['client_id'] : null;
            $voulus[$numero] = [
                'type' => is_array($or) && in_array($or['type'] ?? null, $codesTypes, true) ? $or['type'] : ($codesTypes[0] ?? 'vente'),
                'client_id' => $clientId && \App\Models\Client::whereKey($clientId)->exists() ? $clientId : null,
            ];
        }

        $odm->ordresReparation()->whereNotIn('numero', array_keys($voulus))->delete();
        foreach ($voulus as $numero => $valeurs) {
            $odm->ordresReparation()->updateOrCreate(['numero' => (string) $numero], $valeurs);
        }
    }

    private static function normaliser(string $champ, mixed $valeur): mixed
    {
        return match ($champ) {
            'technique' => filter_var($valeur, FILTER_VALIDATE_BOOLEAN),
            'destinations' => array_values(array_unique(array_filter(
                array_map(fn ($v) => mb_substr(trim((string) $v), 0, 120), is_array($valeur) ? $valeur : [$valeur]),
                fn ($v) => $v !== '',
            ))),
            'date_depart', 'date_retour_prevue' => blank($valeur) ? null : $valeur,
            'niveau_urgence' => blank($valeur) ? 'normale' : $valeur,
            'or_a_regulariser' => filter_var($valeur, FILTER_VALIDATE_BOOLEAN),
            'immatriculation' => blank($valeur) ? null : mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) $valeur))),
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
