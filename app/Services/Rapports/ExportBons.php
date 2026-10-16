<?php

namespace App\Services\Rapports;

use App\Models\BonCaisse;
use App\Models\LigneFraisOdm;
use App\Models\PartOdm;
use App\Models\ParticipantOdm;
use App\Models\Validation;
use App\Services\Odm\RepartitionOdm;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Export Excel des bons au format opérationnel du client (M14, spec v2.3 §8.9) : n° de bon, date, bénéficiaire, motif,
 * montant, frais OM, montant versé, caisse, site, service, code analytique, OR, délai par étape.
 * Bons issus d'un ODM : une ligne par part de prise en charge (payeur, client, OR, code analytique, montant de la part).
 */
final class ExportBons
{
    /** Étapes du circuit, dans l'ordre (délai en heures entre l'attribution et la décision) */
    private const ETAPES = [
        'responsable_service' => 'Délai chef de service (h)',
        'controle_gestion' => 'Délai CDG (h)',
        'daf' => 'Délai Finance (h)',
        'directeur_pays' => 'Délai DP (h)',
    ];

    public static function entetes(): array
    {
        return array_merge(
            ['N° de bon', 'Date', 'Type', 'Statut', 'Bénéficiaire', 'Motif', 'Montant (GNF)', 'Frais OM (GNF)', 'Montant versé (GNF)',
                'Caisse', 'Site', 'Service', 'Code analytique', 'OR', 'Mode de paiement', 'Payé le', 'Refacturation inter-filiale', 'Filiale'],
            array_values(self::ETAPES),
            ['ODM', 'Élément', 'Payeur', 'Client', 'OR de la part', 'Code analytique de la part', 'Montant de la part (GNF)'],
        );
    }

    /** @param Collection<int, BonCaisse> $bons */
    public static function lignes(Collection $bons): array
    {
        $bons->loadMissing(['caisse', 'validations', 'ordreMission']);
        $lignes = [self::entetes()];
        foreach ($bons as $bon) {
            $commun = self::colonnesBon($bon);
            $parts = self::parts($bon);
            if ($parts->isEmpty()) {
                $lignes[] = array_merge($commun, [$bon->ordreMission?->numero, null, 'Neemba', null, null, $bon->code_analytique, (float) $bon->montant]);
                continue;
            }
            foreach ($parts as $part) {
                $ligne = $part->ligne;
                $lignes[] = array_merge($commun, [
                    $bon->ordreMission?->numero,
                    RepartitionOdm::libelleElement($ligne->element) . ' — ' . $ligne->participant->nom,
                    $part->payeur === 'client' ? 'Client' : 'Neemba',
                    $part->client?->raison_sociale,
                    $part->ordreReparation?->numero,
                    $part->code_analytique ?? $bon->ordreMission?->code_analytique,
                    $part->montant !== null ? (float) $part->montant : null,
                ]);
            }
        }

        return $lignes;
    }

    private static function colonnesBon(BonCaisse $bon): array
    {
        $mode = $bon->mode_paiement_effectif ?? $bon->mode_paiement;
        $version = (int) ($bon->version ?? 1);
        $delais = [];
        foreach (array_keys(self::ETAPES) as $role) {
            /** @var Validation|null $etape */
            $etape = $bon->validations->first(fn (Validation $v) => $v->role === $role && (int) ($v->version ?? 1) === $version);
            $delais[] = $etape && $etape->date_attribution && $etape->date_validation && $etape->statut !== 'saute'
                ? round($etape->date_attribution->diffInMinutes($etape->date_validation) / 60, 1)
                : null;
        }

        return array_merge([
            $bon->numero,
            $bon->date_demande ? Carbon::parse($bon->date_demande)->format('d/m/Y') : null,
            $bon->type_bon,
            BonCaisse::STATUTS_LABELS[$bon->statut] ?? $bon->statut,
            $bon->beneficiaire,
            $bon->motif,
            (float) $bon->montant,
            $bon->frais_om !== null ? (float) $bon->frais_om : null,
            $bon->montant_verse !== null ? (float) $bon->montant_verse : ($bon->date_paiement ? (float) $bon->montant : null),
            $bon->caisse?->libelle,
            $bon->site,
            $bon->service,
            $bon->code_analytique,
            implode(', ', $bon->references_or ?? []),
            BonCaisse::MODES_PAIEMENT[$mode] ?? $mode,
            $bon->date_paiement ? Carbon::parse($bon->date_paiement)->format('d/m/Y') : null,
            /* RG-M03-28 (v2.4) : bon au profit d'un visiteur du groupe, à refacturer à sa filiale */
            $bon->refacturation_inter_filiale ? 'Oui' : null,
            $bon->filiale_refacturation,
        ], $delais);
    }

    /** Parts des lignes de l'ODM payées par ce bon (BD issu d'un ODM) */
    private static function parts(BonCaisse $bon): Collection
    {
        if (!$bon->genere_par_odm || !$bon->odm_id || $bon->type_bon !== 'BD') {
            return collect();
        }
        $ordre = array_keys(LigneFraisOdm::ELEMENTS);

        return ParticipantOdm::where('ordre_mission_id', $bon->odm_id)->where('bon_caisse_id', $bon->id)
            ->with(['lignes.parts.client', 'lignes.parts.ordreReparation'])->orderBy('id')->get()
            ->flatMap(fn (ParticipantOdm $p) => $p->lignes->sortBy(fn ($l) => array_search($l->element, $ordre, true))
                ->each(fn (LigneFraisOdm $l) => $l->setRelation('participant', $p))
                ->flatMap(fn (LigneFraisOdm $l) => $l->parts->each(fn (PartOdm $part) => $part->setRelation('ligne', $l))))
            ->values();
    }
}
