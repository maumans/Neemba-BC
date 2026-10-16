<?php

namespace App\Services\Odm;

use App\Models\CodeAnalytique;
use App\Models\HistoriqueOdm;
use App\Models\LigneFraisOdm;
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
            'clients' => $odm->clients()->get()->map(fn (\App\Models\Client $c) => $c->resume())->values()->all(),
            'destinations' => $odm->destinations ?? [],
            'vehicule' => $odm->vehicule ?? '',
            'date_depart' => $odm->date_depart?->toDateString() ?? '',
            'date_retour_prevue' => $odm->date_retour_prevue?->toDateString() ?? '',
            'motif_depart_passe' => $odm->motif_depart_passe ?? '',
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
            'ordres_reparation' => $odm->ordresReparation()->with('client')->get()->map(fn (OrdreReparationOdm $or) => [
                'id' => $or->id, 'numero' => $or->numero, 'type' => $or->type, 'client_id' => $or->client_id, 'client' => $or->client?->resume(),
            ])->values()->all(),
            'derogation' => self::derogation($odm),
            'rejet' => self::rejet($odm),
            /* RG-M12-17 : prolongation, départ et participants repris du segment précédent */
            'prolongation' => $odm->estProlongation() ? [
                'libelle' => $odm->libelle_prolongation,
                'precedent' => $odm->segmentPrecedent?->numero,
                'depart' => Format::date($odm->date_depart),
                'rattrapages' => $odm->participantsActifs()->where('nuit_rattrapage', '>', 0)->count(),
            ] : null,
            'calcul' => self::calcul($odm),
        ];
    }

    /**
     * Calcul affiché (US-05) : par participant, jours, nuits, deux lignes d'indemnité, hébergement, rattrapage, total,
     * et l'estimation des frais OM pour information.
     * Spec v2.3 : les lignes de frais (RG-M12-30) et leurs parts (RG-M12-31), le récapitulatif Neemba / client (RG-M12-34).
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

        $participants = $odm->participantsActifs()->with(['lignes.parts.client', 'lignes.parts.ordreReparation.client'])->get()->map(function (ParticipantOdm $p) use ($baremes, $odm) {
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
                'lignes' => $p->lignes->sortBy(fn (LigneFraisOdm $l) => array_search($l->element, array_keys(LigneFraisOdm::ELEMENTS), true))
                    ->map(fn (LigneFraisOdm $l) => self::ligneFrais($l, $odm))->values()->all(),
                'frais_om' => $frais['frais'] ?? null,
                'frais_om_taux' => isset($frais['taux']) ? str_replace('.', ',', (string) $frais['taux']) : null,
                'montant_verse_om' => $frais['montant_verse'] ?? null,
            ];
        })->values()->all();

        return [
            'participants' => $participants,
            'total' => $odm->total !== null ? (float) $odm->total : null,
            'recapitulatif' => RepartitionOdm::recapitulatif($odm),
            'jours' => CalculOdm::jours($odm->date_depart, $odm->dateFin()),
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

    /**
     * Ligne de frais et ses parts (RG-M12-30, RG-M12-31). Code analytique d'une part : le sien, sinon celui de l'ODM.
     * « avertissement » : RG-M12-33 (part client sans OR, ou client différent de celui de l'OR).
     */
    public static function ligneFrais(LigneFraisOdm $ligne, OrdreMission $odm): array
    {
        return [
            'id' => $ligne->id,
            'element' => $ligne->element,
            'libelle' => LigneFraisOdm::ELEMENTS[$ligne->element] ?? $ligne->element,
            'quantite' => (float) $ligne->quantite,
            'taux' => $ligne->taux !== null ? (float) $ligne->taux : null,
            'devise' => $ligne->devise,
            'montant' => $ligne->montant !== null ? (float) $ligne->montant : null,
            /* Facture payée au retour : montant connu à la clôture */
            'a_la_cloture' => $ligne->element === 'hebergement_exterieur' && $odm->hebergement_exterieur === 'au_retour' && $ligne->montant === null,
            'parts' => $ligne->parts->map(fn (\App\Models\PartOdm $part) => [
                'id' => $part->id,
                'payeur' => $part->payeur,
                'client' => $part->client?->resume(),
                'ordre_reparation' => $part->ordreReparation?->numero,
                'code_analytique' => $part->code_analytique,
                'code_analytique_effectif' => $part->code_analytique ?? $odm->code_analytique,
                'pourcentage' => (float) $part->pourcentage,
                'montant' => $part->montant !== null ? (float) $part->montant : null,
                'commentaire' => $part->commentaire,
                'avertissement' => $part->avertissement(),
            ])->values()->all(),
        ];
    }

    /** Fiche de l'ODM */
    public static function detail(OrdreMission $odm): array
    {
        $odm->loadMissing(['demandeur', 'initiateur', 'derogationPar']);

        $formulaire = self::formulaire($odm);

        return $formulaire + [
            'libelle' => $odm->libelle,
            'libelle_prolongation' => $odm->libelle_prolongation,
            'statut_label' => $odm->statut_label,
            'type_label' => OrdreMission::TYPES[$odm->type] ?? $odm->type,
            'montant_neemba' => (float) $odm->montant_neemba,
            'montant_a_refacturer' => (float) $odm->montant_a_refacturer,
            'montant_a_refacturer_format' => Format::montant($odm->montant_a_refacturer),
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

    /** Adresse de Neemba Guinée, reprise du formulaire papier */
    public const ADRESSE_PDF = ['Neemba Guinée', 'Km 10 – Route du Niger, Commune de Matam', 'B.P. 336 Conakry – Guinée', 'Tél. : +224 626 26 41 41 · info.guinee@neemba.com'];

    /** Intitulés des signataires du formulaire papier, par niveau du circuit */
    private const SIGNATAIRES_PDF = [
        'chef_atelier' => "Chef d'atelier, chef d'équipe ou suppléant",
        'daf' => 'Directeur Administratif et Financier',
        'rh' => 'Ressources humaines',
        'directeur_pays' => 'Directeur Pays',
    ];

    /**
     * PDF de l'ordre de mission, sur le modèle papier de Neemba (« ODM .xlsm », RG-M12-23) : rubrique « Service concerné »,
     * prise en charge par élément (parts v2.3), passagers, calcul par participant, signataires (demandeur, puis étapes).
     */
    public static function pdf(OrdreMission $odm): array
    {
        $detail = self::detail($odm);
        $calcul = $detail['calcul'];
        $valide = in_array($odm->statut, OrdreMission::STATUTS_VALIDES, true);
        $date = $odm->date_validation ?? $odm->date_soumission;

        return $detail + [
            'entite' => $odm->entite,
            'adresse' => self::ADRESSE_PDF,
            'rubrique' => OrdreMission::RUBRIQUE_PAR_SERVICE[$odm->service] ?? 'ADMINISTRATION',
            'date_titre' => $date ? Format::date($date) : null,
            'filigrane' => match (true) {
                $valide => null,
                $odm->statut === 'ANNULE' => 'ANNULÉ',
                $odm->statut === 'BROUILLON' => 'BROUILLON',
                default => 'NON VALIDÉ',
            },
            'passagers' => $odm->participantsActifs()->with('utilisateur')->get()->map(fn (ParticipantOdm $p) => [
                'nom' => $p->utilisateur?->name ?? $p->nom,
                'prenoms' => $p->utilisateur ? ($p->utilisateur->prenom ?? '') : '',
                'matricule' => $p->matricule,
                'service' => $p->service,
                'numero_om' => $p->numero_om,
            ])->values()->all(),
            'prise_en_charge' => self::priseEnCharge($odm, $calcul),
            'signataires' => self::signataires($odm, $detail),
        ];
    }

    /**
     * Grille « Prise en charge » du PDF : pour chaque rubrique (les deux indemnités, ou l'indemnité d'un ODM extérieur, puis
     * l'hébergement), ce qui est à la charge de Neemba et des clients. L'indemnité est une seule ligne de frais (Q53) :
     * ses parts s'appliquent aux deux demi-indemnités. Le pourcentage fait foi (RG-M12-31).
     */
    private static function priseEnCharge(OrdreMission $odm, array $calcul): array
    {
        $rubriques = [];
        $cumuler = function (string $cle, string $libelle, ?float $montant, array $parts) use (&$rubriques) {
            $rubriques[$cle] ??= ['libelle' => $libelle, 'neemba' => false, 'client' => false, 'montant_neemba' => 0.0, 'montant_client' => 0.0, 'clients' => [], 'a_la_cloture' => false, 'autres' => []];
            foreach ($parts as $part) {
                $rubriques[$cle][$part['payeur']] = true;
                $rubriques[$cle]['montant_' . $part['payeur']] += $montant !== null ? $montant * $part['pourcentage'] / 100 : 0;
                if ($part['payeur'] === 'client' && isset($part['client']['code'])) {
                    $rubriques[$cle]['clients'][$part['client']['code']] = $part['client']['code'];
                }
            }
            $rubriques[$cle]['a_la_cloture'] = $rubriques[$cle]['a_la_cloture'] || $montant === null;
        };

        foreach ($calcul['participants'] as $c) {
            foreach ($c['lignes'] as $ligne) {
                if ($ligne['element'] === 'indemnite' && $c['indemnite_ligne_1'] !== null) {
                    $cumuler('nourriture', $calcul['libelle_indemnite_1'], (float) $c['indemnite_ligne_1'], $ligne['parts']);
                    $cumuler('deplacement', $calcul['libelle_indemnite_2'], (float) $c['indemnite_ligne_2'], $ligne['parts']);
                } elseif ($ligne['element'] === 'indemnite') {
                    $cumuler('indemnite', 'Indemnité de mission', $ligne['montant'], $ligne['parts']);
                } elseif ((float) $ligne['montant'] > 0 || $ligne['a_la_cloture']) {
                    $cumuler('hebergement', 'Hébergement', $ligne['montant'], $ligne['parts']);
                }
            }
        }

        /* « Autres à préciser » : logement en base vie, hébergement à l'étranger, billet */
        $rubriques['hebergement'] ??= ['libelle' => 'Hébergement', 'neemba' => false, 'client' => false, 'montant_neemba' => 0.0, 'montant_client' => 0.0, 'clients' => [], 'a_la_cloture' => false, 'autres' => []];
        $baseVie = collect($calcul['participants'])->where('base_vie', true)->count();
        if ($baseVie > 0) {
            $rubriques['hebergement']['autres'][] = $baseVie === count($calcul['participants']) ? 'Logé en base vie' : "Logé en base vie ({$baseVie} pers.)";
        }
        if ($odm->type === 'exterieur' && $odm->hebergement_exterieur) {
            $rubriques['hebergement']['autres'][] = OrdreMission::HEBERGEMENTS_EXTERIEURS[$odm->hebergement_exterieur];
        }
        if (!$rubriques['hebergement']['neemba'] && !$rubriques['hebergement']['client'] && !$rubriques['hebergement']['autres']) {
            $rubriques['hebergement']['autres'][] = 'Aucune nuitée';
        }
        if ($odm->reference_billet) {
            $premiere = array_key_first($rubriques);
            $rubriques[$premiere]['autres'][] = 'Billet : ' . $odm->reference_billet;
        }

        return collect(['nourriture', 'deplacement', 'indemnite', 'hebergement'])
            ->filter(fn ($cle) => isset($rubriques[$cle]))
            ->map(fn ($cle) => array_merge($rubriques[$cle], [
                'montant_neemba' => round($rubriques[$cle]['montant_neemba']),
                'montant_client' => round($rubriques[$cle]['montant_client']),
                'clients' => array_values($rubriques[$cle]['clients']),
            ]))->values()->all();
    }

    /** Signataires du PDF : le demandeur, puis chaque étape du circuit de la version en cours (le circuit prévu, avant la soumission) */
    private static function signataires(OrdreMission $odm, array $detail): array
    {
        $signataires = [[
            'titre' => 'Le Demandeur',
            'nom' => $detail['demandeur'],
            'au_titre_de' => $detail['initiateur'] ? 'saisi par ' . $detail['initiateur'] : null,
            'date' => $detail['date_soumission_format'],
            'etat' => $odm->date_soumission ? 'vise' : 'attente',
            'etat_label' => $odm->date_soumission ? 'Soumis' : 'Non soumis',
        ]];
        $etapes = $odm->etapes()->where('version', $odm->version)->orderBy('niveau')->with(['valideur', 'auTitreDe'])->get();
        if ($etapes->isEmpty()) {
            foreach (OrdreMission::niveauxDuCircuit() as $role) {
                $signataires[] = ['titre' => self::SIGNATAIRES_PDF[$role] ?? $role, 'nom' => null, 'au_titre_de' => null, 'date' => null, 'etat' => 'attente', 'etat_label' => 'À venir'];
            }
        }
        foreach ($etapes as $etape) {
            $signataires[] = [
                'titre' => self::SIGNATAIRES_PDF[$etape->role] ?? $etape->libelle,
                'nom' => $etape->valideur?->nom_complet,
                'au_titre_de' => $etape->auTitreDe ? 'au titre de ' . $etape->auTitreDe->nom_complet : null,
                'date' => $etape->date_decision ? Format::dateHeure($etape->date_decision) : null,
                'etat' => match ($etape->statut) {
                    'validee' => 'vise',
                    'rejetee' => 'rejete',
                    'sautee' => 'saute',
                    default => 'attente',
                },
                'etat_label' => match ($etape->statut) {
                    'validee' => 'Visé',
                    'sautee' => 'Étape sautée',
                    default => \App\Models\EtapeOdm::STATUTS[$etape->statut] ?? $etape->statut,
                },
            ];
        }

        return $signataires;
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

    /** Onglet « Bons » : bons générés depuis l'ODM, avec leur statut dans leur propre circuit */
    public static function bons(OrdreMission $odm): array
    {
        return $odm->bons()->with('participantOdm')->orderBy('id')->get()->map(fn (\App\Models\BonCaisse $bon) => [
            'id' => $bon->id,
            'numero' => $bon->numero ?? 'Brouillon',
            'type_bon' => $bon->type_bon,
            'beneficiaire' => $bon->beneficiaire,
            'montant' => (float) $bon->montant,
            'montant_estime' => $bon->montant_estime !== null ? (float) $bon->montant_estime : null,
            'taux_change_estime' => $bon->taux_change_estime !== null ? (float) $bon->taux_change_estime : null,
            'taux_change_applique' => $bon->taux_change_applique !== null ? (float) $bon->taux_change_applique : null,
            'frais_om' => $bon->frais_om !== null ? (float) $bon->frais_om : null,
            'montant_verse' => $bon->montant_verse !== null ? (float) $bon->montant_verse : null,
            'statut' => $bon->statut,
            'statut_label' => \App\Models\BonCaisse::STATUTS_LABELS[$bon->statut] ?? $bon->statut,
        ])->values()->all();
    }

    /**
     * Génération des bons (US-08) : un bon par participant (RG-M12-14), avec son bon actif éventuel (génération globale ou sélective),
     * BP possible, ODM extérieur sans aucun taux. Null si l'utilisateur ne peut pas générer.
     */
    public static function generation(OrdreMission $odm, \App\Models\User $utilisateur): ?array
    {
        if (!in_array($odm->statut, GenererBonsOdm::STATUTS_AUTORISES, true)
            || !in_array($utilisateur->id, [$odm->demandeur_id, $odm->initiateur_id], true)
            || GenererBonsOdm::sansBon($odm)) {
            return null;
        }
        $actifs = GenererBonsOdm::bonsActifs($odm);
        $participants = $odm->participantsActifs()->with('bonCaisse')->get()->map(function (ParticipantOdm $p) {
            $bon = $p->bonCaisse && !in_array($p->bonCaisse->statut, GenererBonsOdm::STATUTS_INACTIFS, true) ? $p->bonCaisse : null;

            return [
                'user_id' => $p->user_id,
                'nom' => $p->nom,
                'numero_om' => $p->numero_om,
                'total' => $p->total !== null ? (float) $p->total : null,
                'a_un_bon' => $bon !== null,
                'bon' => $bon ? [
                    'id' => $bon->id,
                    'numero' => $bon->numero ?? 'Brouillon',
                    'statut' => $bon->statut,
                    'statut_label' => \App\Models\BonCaisse::STATUTS_LABELS[$bon->statut] ?? $bon->statut,
                    /* Bon groupé émis avant la v2.4 (RG-M12-14), conservé */
                    'groupe' => $bon->odm_participant_id === null,
                ] : null,
            ];
        })->values();

        return [
            'participants' => $participants->all(),
            'reste_a_generer' => $participants->where('a_un_bon', false)->count(),
            'bp_autorise' => (bool) Parametre::valeur('odm_genere_bp', true) && $actifs->where('type_bon', 'BP')->isEmpty(),
            'sans_taux' => $odm->type === 'exterieur' && !TauxChange::dernier(),
            'total' => $odm->total !== null ? (float) $odm->total : null,
        ];
    }

    /** RG-M12-20 : trop-perçus d'un retour anticipé et leur régularisation */
    public static function regularisations(OrdreMission $odm, \App\Models\User $utilisateur): array
    {
        return $odm->participants()->whereNotNull('trop_percu')->with('regularisePar')->get()
            ->map(fn (ParticipantOdm $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'trop_percu' => (float) $p->trop_percu,
                'mode' => ParticipantOdm::REGULARISATIONS[$p->regularisation] ?? $p->regularisation,
                'statut' => $p->regularisation_statut,
                'regularise_le' => $p->regularise_le ? Format::dateHeure($p->regularise_le) : null,
                'regularise_par' => $p->regularisePar?->nom_complet,
                'peut_regulariser' => RegulariserTropPercu::peutRegulariser($p, $utilisateur),
            ])->values()->all();
    }

    /** Référentiels du formulaire */
    public static function referentiels(): array
    {
        return [
            'services' => Service::actifs()->orderBy('nom')->get(['id', 'nom'])
                ->map(fn (Service $s) => ['id' => $s->id, 'nom' => $s->nom, 'technique' => in_array($s->nom, OrdreMission::SERVICES_TECHNIQUES, true)]),
            'codesAnalytiques' => CodeAnalytique::where('actif', true)->orderBy('code')->get(['code', 'libelle', 'service_id']),
            'types' => OrdreMission::TYPES,
            'elementsFrais' => LigneFraisOdm::ELEMENTS,
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
