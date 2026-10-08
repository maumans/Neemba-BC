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
                'montant_a_refacturer' => array_sum(array_column($aRefacturer, 'total')),
                'incoherences' => count(array_filter($enCours, fn ($m) => $m['incoherences'] > 0)),
            ],
            'missionsEnCours' => $enCours,
            'derogations' => self::derogations(),
            'aRefacturer' => $aRefacturer,
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

    /** RG-M12-15 : ODM à la charge du client, à refacturer avec leurs OR */
    public static function aRefacturer(): array
    {
        return OrdreMission::where('a_refacturer', true)->where('statut', '!=', 'ANNULE')->with('ordresReparation')
            ->orderBy('date_depart')->get()
            ->map(fn (OrdreMission $odm) => [
                'id' => $odm->id,
                'libelle' => $odm->libelle,
                'clients' => implode(', ', $odm->clients ?? []),
                'or' => $odm->ordresReparation->map(fn ($or) => $or->numero . ' (' . ($or->type === 'garantie' ? 'Garantie' : 'Vente') . ')')->implode(', '),
                'periode' => PresentationOdm::periode($odm),
                'total' => (float) $odm->total,
                'total_format' => $odm->total_format,
                'statut_label' => $odm->statut_label,
            ])->values()->all();
    }
}
