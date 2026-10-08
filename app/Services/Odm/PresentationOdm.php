<?php

namespace App\Services\Odm;

use App\Models\CodeAnalytique;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\OrdreReparationOdm;
use App\Models\Parametre;
use App\Models\ParticipantOdm;
use App\Models\Service;
use App\Models\TauxChange;
use App\Services\Paiement\FraisOrangeMoney;
use App\Support\Format;

/**
 * Données des écrans ODM (liste, formulaire, fiche) : le calcul affiché est celui du serveur.
 */
final class PresentationOdm
{
    /** Ligne de la liste des ODM */
    public static function ligne(OrdreMission $odm): array
    {
        return [
            'id' => $odm->id,
            'numero' => $odm->numero,
            'libelle' => $odm->libelle,
            'libelle_prolongation' => $odm->libelle_prolongation,
            'type' => $odm->type,
            'type_label' => OrdreMission::TYPES[$odm->type] ?? $odm->type,
            'service' => $odm->service,
            'destinations' => $odm->destinations ?? [],
            'periode' => self::periode($odm),
            'participants' => $odm->participants_actifs_count ?? $odm->participantsActifs()->count(),
            'total_format' => $odm->total_format,
            'statut' => $odm->statut,
            'statut_label' => $odm->statut_label,
            'demandeur' => $odm->demandeur?->nom_complet,
            'derogation_statut' => $odm->derogation_statut,
        ];
    }

    /** Données du formulaire (création, brouillon, correction après rejet) */
    public static function formulaire(OrdreMission $odm): array
    {
        return [
            'id' => $odm->id,
            'numero' => $odm->numero,
            'statut' => $odm->statut,
            'version' => $odm->version,
            'type' => $odm->type,
            'technique' => (bool) $odm->technique,
            'site' => $odm->site,
            'service' => $odm->service,
            'code_analytique' => $odm->code_analytique,
            'but' => $odm->but ?? '',
            'clients' => $odm->clients ?? [],
            'destinations' => $odm->destinations ?? [],
            'vehicule' => $odm->vehicule ?? '',
            'date_depart' => $odm->date_depart?->toDateString() ?? '',
            'date_retour_prevue' => $odm->date_retour_prevue?->toDateString() ?? '',
            'motif_depart_passe' => $odm->motif_depart_passe ?? '',
            'prise_en_charge' => $odm->prise_en_charge,
            'hebergement_exterieur' => $odm->hebergement_exterieur,
            'reference_billet' => $odm->reference_billet ?? '',
            'participants' => $odm->participantsActifs()->get()->map(fn (ParticipantOdm $p) => [
                'user_id' => $p->user_id,
                'nom' => $p->nom,
                'matricule' => $p->matricule,
                'service' => $p->service,
                'statut_cadre' => $p->statut_cadre,
                'numero_om' => $p->numero_om,
                'base_vie' => (bool) $p->base_vie,
                'hebergement_facture' => $p->hebergement_facture !== null ? (float) $p->hebergement_facture : null,
            ])->values()->all(),
            'ordres_reparation' => $odm->ordresReparation()->get()->map(fn (OrdreReparationOdm $or) => [
                'numero' => $or->numero, 'type' => $or->type,
            ])->values()->all(),
            'derogation' => self::derogation($odm),
            'rejet' => self::rejet($odm),
            'calcul' => self::calcul($odm),
        ];
    }

    /**
     * Calcul affiché (US-05) : par participant, jours, nuits, deux lignes d'indemnité, hébergement, rattrapage, total,
     * et l'estimation des frais OM pour information.
     */
    public static function calcul(OrdreMission $odm): array
    {
        $baremes = $odm->parametres_figes['baremes'] ?? CalculOdm::baremesEnVigueur();
        $taux = null;
        if ($odm->type === 'exterieur') {
            $taux = isset($odm->parametres_figes['taux_estime'])
                ? ['taux' => (float) $odm->parametres_figes['taux_estime'], 'date' => $odm->parametres_figes['taux_date'] ?? null, 'fige' => true]
                : (($dernier = TauxChange::dernier()) ? ['taux' => (float) $dernier->taux, 'date' => Format::date($dernier->date_taux), 'fige' => false] : null);
        }

        $participants = $odm->participantsActifs()->get()->map(function (ParticipantOdm $p) use ($baremes) {
            $frais = $p->total !== null ? FraisOrangeMoney::calculer((float) $p->total, $baremes['frais_om_paliers'] ?? null) : null;
            $ligne1 = $p->indemnite_fcfa === null && $p->indemnite !== null ? $p->jours * intdiv($baremes['indemnite_journaliere'], 2) : null;

            return [
                'user_id' => $p->user_id,
                'nom' => $p->nom,
                'jours' => $p->jours,
                'nuits' => $p->nuits,
                'nuit_rattrapage' => $p->nuit_rattrapage,
                'base_vie' => (bool) $p->base_vie,
                'indemnite_fcfa' => $p->indemnite_fcfa !== null ? (float) $p->indemnite_fcfa : null,
                'indemnite' => $p->indemnite !== null ? (float) $p->indemnite : null,
                'indemnite_ligne_1' => $ligne1,
                'indemnite_ligne_2' => $ligne1 !== null ? (float) $p->indemnite - $ligne1 : null,
                'hebergement' => (float) $p->hebergement,
                'rattrapage' => (float) $p->rattrapage,
                'total' => $p->total !== null ? (float) $p->total : null,
                'frais_om' => $frais['frais'] ?? null,
                'frais_om_taux' => isset($frais['taux']) ? str_replace('.', ',', (string) $frais['taux']) : null,
                'montant_verse_om' => $frais['montant_verse'] ?? null,
            ];
        })->values()->all();

        return [
            'participants' => $participants,
            'total' => $odm->total !== null ? (float) $odm->total : null,
            'jours' => CalculOdm::jours($odm->date_depart, $odm->date_retour_prevue),
            'libelle_indemnite_1' => $baremes['libelle_indemnite_1'],
            'libelle_indemnite_2' => $baremes['libelle_indemnite_2'],
            'indemnite_journaliere' => $baremes['indemnite_journaliere'],
            'hebergement_nuit' => $baremes['hebergement_nuit'],
            'bareme_fcfa_cadre' => $baremes['bareme_fcfa_cadre'],
            'bareme_fcfa_non_cadre' => $baremes['bareme_fcfa_non_cadre'],
            'taux' => $taux,
            'fige' => isset($odm->parametres_figes['valide_le']),
        ];
    }

    /** Fiche de l'ODM */
    public static function detail(OrdreMission $odm): array
    {
        $odm->loadMissing(['demandeur', 'initiateur', 'derogationPar']);

        return self::formulaire($odm) + [
            'libelle' => $odm->libelle,
            'libelle_prolongation' => $odm->libelle_prolongation,
            'statut_label' => $odm->statut_label,
            'type_label' => OrdreMission::TYPES[$odm->type] ?? $odm->type,
            'prise_en_charge_label' => OrdreMission::PRISES_EN_CHARGE[$odm->prise_en_charge] ?? $odm->prise_en_charge,
            'hebergement_exterieur_label' => OrdreMission::HEBERGEMENTS_EXTERIEURS[$odm->hebergement_exterieur] ?? null,
            'code_analytique_libelle' => CodeAnalytique::where('code', $odm->code_analytique)->value('libelle'),
            'demandeur' => $odm->demandeur?->nom_complet,
            'initiateur' => $odm->initiateur_id !== $odm->demandeur_id ? $odm->initiateur?->nom_complet : null,
            'date_depart_format' => Format::date($odm->date_depart),
            'date_retour_prevue_format' => Format::date($odm->date_retour_prevue),
            'date_retour_reelle_format' => $odm->date_retour_reelle ? Format::date($odm->date_retour_reelle) : null,
            'date_soumission_format' => $odm->date_soumission ? Format::dateHeure($odm->date_soumission) : null,
            'total_format' => $odm->total_format,
            'a_refacturer' => (bool) $odm->a_refacturer,
            'historique' => $odm->historique()->with('utilisateur')->get()->map(fn (HistoriqueOdm $h) => [
                'id' => $h->id,
                'action' => HistoriqueOdm::ACTIONS[$h->action] ?? $h->action,
                'statut_apres' => $h->statut_apres ? (OrdreMission::STATUTS[$h->statut_apres] ?? $h->statut_apres) : null,
                'utilisateur' => $h->utilisateur?->nom_complet,
                'commentaire' => $h->commentaire,
                'date' => Format::dateHeure($h->created_at),
            ])->values()->all(),
        ];
    }

    /**
     * Onglet « Validations » : une ligne par niveau et par version (visé, rejeté, en cours, à venir, sauté, non atteint),
     * avec « au titre de » pour un suppléant et l'échéance de l'étape en cours (§6.7).
     */
    public static function etapes(OrdreMission $odm): array
    {
        return $odm->etapes()->with(['valideur', 'auTitreDe'])->get()
            ->map(function (\App\Models\EtapeOdm $etape) use ($odm) {
                $echeance = $etape->statut === 'en_attente' ? CircuitOdm::echeance($etape) : null;

                return [
                    'id' => $etape->id,
                    'version' => $etape->version,
                    'niveau' => $etape->niveau,
                    'libelle' => $etape->libelle,
                    'statut' => $etape->statut,
                    'statut_label' => \App\Models\EtapeOdm::STATUTS[$etape->statut] ?? $etape->statut,
                    'valideur' => $etape->valideur?->nom_complet,
                    'au_titre_de' => $etape->auTitreDe?->nom_complet,
                    'date_attribution' => $etape->date_attribution ? Format::dateHeure($etape->date_attribution) : null,
                    'date_decision' => $etape->date_decision ? Format::dateHeure($etape->date_decision) : null,
                    'duree' => $etape->date_attribution && $etape->date_decision && $etape->statut !== 'sautee'
                        ? Format::dureeEntre($etape->date_attribution, $etape->date_decision) : null,
                    'attente' => $etape->statut === 'en_attente' && $etape->date_attribution ? Format::dureeEntre($etape->date_attribution) : null,
                    'echeance' => $echeance ? Format::dateHeure($echeance) : null,
                    'en_retard' => $echeance?->isPast() ?? false,
                    'valideurs_possibles' => $etape->statut === 'en_attente' && $etape->version === $odm->version
                        ? CircuitOdm::valideursEffectifs($odm, $etape->role)->map(fn ($v) => $v['user']->nom_complet
                            . ($v['au_titre_de'] ? " (suppléant de {$v['au_titre_de']->nom_complet})" : ''))->values()->all()
                        : [],
                    'commentaire' => $etape->commentaire,
                ];
            })->values()->all();
    }

    /** Référentiels du formulaire */
    public static function referentiels(): array
    {
        return [
            'services' => Service::actifs()->orderBy('nom')->get(['id', 'nom'])
                ->map(fn (Service $s) => ['id' => $s->id, 'nom' => $s->nom, 'technique' => in_array($s->nom, OrdreMission::SERVICES_TECHNIQUES, true)]),
            'codesAnalytiques' => CodeAnalytique::where('actif', true)->orderBy('code')->get(['code', 'libelle', 'service_id']),
            'types' => OrdreMission::TYPES,
            'prisesEnCharge' => OrdreMission::PRISES_EN_CHARGE,
            'hebergementsExterieurs' => OrdreMission::HEBERGEMENTS_EXTERIEURS,
            'typesOr' => OrdreReparationOdm::TYPES,
            'maxParticipants' => (int) Parametre::valeur('odm_participants_max', 10),
            'dateDuJour' => today()->toDateString(),
        ];
    }

    public static function periode(OrdreMission $odm): string
    {
        if (!$odm->date_depart) {
            return '—';
        }

        return Format::date($odm->date_depart) . ' → ' . Format::date($odm->dateFin());
    }

    private static function derogation(OrdreMission $odm): ?array
    {
        if (!$odm->derogation_statut) {
            return null;
        }

        return [
            'statut' => $odm->derogation_statut,
            'demande_motif' => $odm->derogation_demande_motif,
            'par' => $odm->derogationPar?->nom_complet,
            'motif' => $odm->derogation_motif,
            'le' => $odm->derogation_le ? Format::dateHeure($odm->derogation_le) : null,
        ];
    }

    /** Dernier rejet, pour le bandeau (RG-M12-12) */
    private static function rejet(OrdreMission $odm): ?array
    {
        if ($odm->statut !== 'REJETE') {
            return null;
        }
        $etape = $odm->etapes()->where('statut', 'rejetee')->with('valideur')->latest('date_decision')->first();

        return $etape ? [
            'niveau' => $etape->libelle,
            'valideur' => $etape->valideur?->nom_complet,
            'date' => Format::dateHeure($etape->date_decision),
            'motif' => $etape->commentaire,
        ] : null;
    }
}
