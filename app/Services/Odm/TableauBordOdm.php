<?php

namespace App\Services\Odm;

use App\Models\OrdreMission;
use App\Support\Format;

/**
 * Tableau de bord des ordres de mission (US-14, RG-M12-15, RG-M12-16, RG-M12-19) :
 * missions en cours (durée cumulée, coût), dérogations au chevauchement, ODM « à refacturer ».
 */
final class TableauBordOdm
{
    /** Rôles qui consultent le tableau de bord */
    public const ROLES = ['daf', 'daf_adjoint', 'chef_comptable', 'directeur_pays', 'dp_adjoint', 'administrateur'];

    public static function donnees(): array
    {
        $enCours = self::missionsEnCours();
        $aRefacturer = self::aRefacturer();

        return [
            'indicateurs' => [
                'missions_en_cours' => count($enCours),
                'participants_en_mission' => array_sum(array_column($enCours, 'participants')),
                'cout_en_cours' => array_sum(array_column($enCours, 'cout')),
                'a_refacturer' => count($aRefacturer),
                'montant_a_refacturer' => array_sum(array_column($aRefacturer, 'montant')),
                'incoherences' => count(array_filter($enCours, fn ($m) => $m['incoherences'] > 0)),
            ],
            'missionsEnCours' => $enCours,
            'derogations' => self::derogations(),
            'aRefacturer' => $aRefacturer,
            'parClient' => self::parClientEtOr(),
        ];
    }

    /**
     * Missions en cours : une ligne par mission (ODM initial), sur son dernier segment validé.
     * Une prolongation encore en circuit ne retire pas la mission ; une mission dont un segment est clôturé est terminée.
     */
    public static function missionsEnCours(): array
    {
        $cloturees = OrdreMission::where('statut', 'CLOTURE')->get()->map(fn (OrdreMission $s) => $s->mission_id ?? $s->id)->all();
        $derniers = OrdreMission::whereIn('statut', ProlongerOdm::STATUTS_PROLONGEABLES)->get()
            ->groupBy(fn (OrdreMission $s) => $s->mission_id ?? $s->id)
            ->reject(fn ($segments, $mission) => in_array($mission, $cloturees, true))
            ->map(fn ($segments) => $segments->sortByDesc('rang')->first());

        return $derniers->map(function (OrdreMission $dernier) {
            $mission = VueMission::pour($dernier);
            $initial = $dernier->initial();

            return [
                'id' => $dernier->id,
                'numero' => $initial->numero,
                'dernier_segment' => $dernier->libelle,
                'segments' => count($mission['segments']),
                'service' => $initial->service,
                'destinations' => implode(', ', $dernier->destinations ?? []),
                'participants' => count($mission['participants']),
                'debut' => $mission['debut'],
                'fin_prevue' => $mission['fin'],
                'fin_iso' => $dernier->dateFin()?->toDateString(),
                'jours' => $mission['jours'],
                'cout' => $mission['total'],
                'cout_format' => Format::montant($mission['total']),
                'statut' => $dernier->statut,
                'statut_label' => $dernier->statut_label,
                'incoherences' => count($mission['incoherences']),
            ];
        })->sortBy('fin_iso')->values()->all();
    }

    /**
     * RG-M12-37 : montants à refacturer par client et par OR (ODM validés, non annulés), avec la part déjà payée
     * (bon du participant payé).
     */
    public static function parClientEtOr(): array
    {
        return self::partsClient()->groupBy(fn ($l) => ($l['client_id'] ?? 0) . '|' . ($l['or'] ?? ''))
            ->map(fn ($groupe) => [
                'client' => $groupe->first()['client'],
                'or' => $groupe->first()['or'],
                'odm' => $groupe->pluck('odm_id')->unique()->count(),
                'montant' => (float) $groupe->sum('montant'),
                'montant_format' => Format::montant($groupe->sum('montant')),
                'paye' => (float) $groupe->whereNotNull('date_paiement')->sum('montant'),
                'paye_format' => Format::montant($groupe->whereNotNull('date_paiement')->sum('montant')),
            ])
            ->sortBy([['client', 'asc'], ['or', 'asc']])->values()->all();
    }

    /**
     * Export « Frais à refacturer » (M14, v2.3) : une ligne par part client — ODM, participant, segment, élément, client,
     * OR, code analytique, montant, frais OM de la part (RG-M12-36), date de paiement du bon.
     */
    public static function partsClient(): \Illuminate\Support\Collection
    {
        $mode = (string) \App\Models\Parametre::valeur('odm_frais_om_repartition', 'prorata');
        $lignes = collect();
        $odms = OrdreMission::where('a_refacturer', true)->whereNotIn('statut', ['ANNULE', 'BROUILLON', 'REJETE'])
            ->with(['lignesFrais.parts.client', 'lignesFrais.parts.ordreReparation', 'lignesFrais.participant.bonCaisse'])->get();
        foreach ($odms as $odm) {
            foreach ($odm->lignesFrais as $ligne) {
                $participant = $ligne->participant;
                if ($participant->retire) {
                    continue;
                }
                $bon = $participant->bonCaisse;
                $paye = $bon && in_array($bon->statut, PaiementOdm::STATUTS_PAYES, true);
                $frais = $paye && $bon->frais_om !== null ? self::fraisParPart($bon, $odm, $mode) : [];
                foreach ($ligne->parts->where('payeur', 'client') as $part) {
                    $lignes->push([
                        'odm_id' => $odm->id,
                        'odm' => $odm->libelle,
                        'participant' => $participant->nom,
                        'segment' => $odm->rang,
                        'element' => RepartitionOdm::libelleElement($ligne->element),
                        'client_id' => $part->client_id,
                        'client' => $part->client?->raison_sociale ?? 'Client non renseigné',
                        'or' => $part->ordreReparation?->numero,
                        'code_analytique' => $part->code_analytique ?? $odm->code_analytique,
                        'montant' => (float) $part->montant,
                        'frais_om' => $frais[$part->id] ?? null,
                        'date_paiement' => $paye ? Format::date($bon->date_paiement) : null,
                    ]);
                }
            }
        }

        return $lignes;
    }

    /** RG-M12-36 : frais OM d'un bon répartis entre les parts des lignes qu'il paie */
    private static function fraisParPart(\App\Models\BonCaisse $bon, OrdreMission $odm, string $mode): array
    {
        $parts = \App\Models\PartOdm::whereHas('ligne', fn ($q) => $q->where('ordre_mission_id', $odm->id)
            ->whereHas('participant', fn ($p) => $p->where('bon_caisse_id', $bon->id)))->orderBy('id')->get();
        $repartis = CalculOdm::repartirFrais((float) $bon->frais_om, $parts->map(fn ($p) => ['payeur' => $p->payeur, 'montant' => (float) $p->montant])->all(), $mode);

        return $parts->pluck('id')->combine($repartis)->all();
    }

    /** RG-M12-16 : dérogations au chevauchement demandées ou accordées */
    public static function derogations(): array
    {
        return OrdreMission::whereIn('derogation_statut', ['demandee', 'accordee'])->with(['demandeur', 'derogationPar'])
            ->latest('updated_at')->get()
            ->map(fn (OrdreMission $odm) => [
                'id' => $odm->id,
                'libelle' => $odm->libelle,
                'periode' => PresentationOdm::periode($odm),
                'demandeur' => $odm->demandeur?->nom_complet,
                'statut' => $odm->derogation_statut === 'accordee' ? 'Accordée' : 'Demandée',
                'motif' => $odm->derogation_statut === 'accordee' ? $odm->derogation_motif : $odm->derogation_demande_motif,
                'par' => $odm->derogationPar?->nom_complet,
                'le' => $odm->derogation_le ? Format::dateHeure($odm->derogation_le) : null,
            ])->values()->all();
    }

    /** RG-M12-15, Q49 : ODM dont des lignes du client sont avancées par Neemba, à refacturer avec leurs OR */
    public static function aRefacturer(): array
    {
        return OrdreMission::where('a_refacturer', true)->where('statut', '!=', 'ANNULE')->with(['ordresReparation', 'clients'])
            ->orderBy('date_depart')->get()
            ->map(fn (OrdreMission $odm) => [
                'id' => $odm->id,
                'libelle' => $odm->libelle,
                'clients' => $odm->clients->pluck('raison_sociale')->implode(', '),
                'or' => $odm->ordresReparation->map(fn ($or) => $or->numero . ' (' . ($or->type === 'garantie' ? 'Garantie' : 'Vente') . ')')->implode(', '),
                'periode' => PresentationOdm::periode($odm),
                'montant' => (float) $odm->montant_a_refacturer,
                'montant_format' => Format::montant($odm->montant_a_refacturer),
                'par_client' => collect(RepartitionOdm::recapitulatif($odm)['par_client'])
                    ->map(fn ($c) => $c['client']['raison_sociale'] . ' : ' . Format::montant($c['montant']))->implode(' ; '),
                'total' => (float) $odm->total,
                'total_format' => $odm->total_format,
                'statut_label' => $odm->statut_label,
            ])->values()->all();
    }
}
