<?php

namespace App\Services\Odm;

use App\Models\Client;
use App\Models\HistoriqueOdm;
use App\Models\LigneFraisOdm;
use App\Models\OrdreMission;
use App\Models\OrdreReparationOdm;
use App\Models\PartOdm;
use App\Models\ParticipantOdm;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Collection;

/**
 * Prise en charge des frais d'un ODM (spec v2.3, RG-M12-30 à RG-M12-37) : lignes de frais et parts.
 *
 * - Les lignes suivent le calcul ; une ligne nouvelle reçoit la part par défaut : Neemba 100 %, code analytique
 *   de l'ODM (RG-M12-31). Une ligne qui disparaît (base vie cochée…) emporte ses parts.
 * - Le pourcentage d'une part fait foi ; son montant suit la ligne (décision Q52).
 * - Toute modification des parts est tracée avec l'ancienne et la nouvelle valeur (RG-M12-35) une fois l'ODM soumis ;
 *   avant la première soumission, la répartition est photographiée à la soumission (décision Q53).
 */
final class RepartitionOdm
{
    /** Part par défaut d'une ligne (RG-M12-31) */
    public const PART_DEFAUT = ['payeur' => 'neemba', 'client_id' => null, 'ordre_reparation_id' => null, 'code_analytique' => null, 'pourcentage' => 100, 'commentaire' => null];

    /**
     * Lignes d'un participant d'après son calcul ; les parts existantes sont conservées.
     *
     * @param array<string, array{quantite: float, taux: ?float, devise: string, montant: ?float}> $lignes
     * @param array<string, array<int, array>> $modeles parts à reprendre pour une ligne nouvelle (prolongation), par élément
     */
    public static function synchroniserLignes(OrdreMission $odm, ParticipantOdm $participant, array $lignes, array $modeles = []): void
    {
        $existantes = $participant->lignes()->get()->keyBy('element');
        foreach ($existantes as $element => $ligne) {
            if (!array_key_exists($element, $lignes)) {
                $ligne->delete();
            }
        }

        foreach ($lignes as $element => $valeurs) {
            $ligne = $existantes->get($element);
            /* Facture d'hébergement payée au retour : son montant, saisi à la clôture, n'est pas effacé par un recalcul */
            if ($ligne && $element === 'hebergement_exterieur' && $valeurs['montant'] === null && $ligne->montant !== null) {
                $valeurs['montant'] = (float) $ligne->montant;
                $valeurs['taux'] = (float) $ligne->montant;
            }
            if ($ligne) {
                $ligne->update($valeurs);
                continue;
            }
            $ligne = $participant->lignes()->create($valeurs + ['ordre_mission_id' => $odm->id, 'element' => $element]);
            foreach (array_values($modeles[$element] ?? [self::PART_DEFAUT]) as $rang => $part) {
                $ligne->parts()->create(self::champsPart($part) + ['rang' => $rang + 1]);
            }
        }
    }

    /** Montant de chaque part d'après son pourcentage, puis totaux de l'ODM (RG-M12-37) */
    public static function repartir(OrdreMission $odm): void
    {
        $lignes = $odm->lignesFrais()->with(['parts', 'participant'])->get();
        foreach ($lignes as $ligne) {
            $montants = CalculOdm::repartirLigne($ligne->montant !== null ? (float) $ligne->montant : null, $ligne->parts->pluck('pourcentage')->all());
            foreach ($ligne->parts->values() as $i => $part) {
                if ($part->montant === null ? $montants[$i] !== null : (float) $part->montant !== $montants[$i]) {
                    $part->update(['montant' => $montants[$i]]);
                }
            }
        }

        $recap = CalculOdm::recapitulatif(self::partsActives($odm)->map(fn (PartOdm $p) => $p->only(['payeur', 'client_id', 'ordre_reparation_id', 'montant'])));
        $odm->update(['montant_neemba' => $recap['neemba'], 'montant_a_refacturer' => $recap['client']]);
    }

    /**
     * Parts saisies (RG-M12-31, RG-M12-32) : participants.*.lignes.{élément}.parts[]. Une part client garde son client,
     * son OR (désigné par son numéro ou son identifiant) et son commentaire ; une part Neemba n'en a pas.
     */
    public static function appliquer(OrdreMission $odm, array $participants, User $auteur): void
    {
        $ors = $odm->ordresReparation()->get();
        $changements = [];

        foreach ($participants as $voulu) {
            if (!is_array($voulu) || !is_array($voulu['lignes'] ?? null) || !is_numeric($voulu['user_id'] ?? null)) {
                continue;
            }
            $participant = $odm->participantsActifs()->where('user_id', (int) $voulu['user_id'])->first();
            if (!$participant) {
                continue;
            }
            $lignes = $participant->lignes()->with('parts')->get()->keyBy('element');

            foreach ($voulu['lignes'] as $element => $saisie) {
                $ligne = $lignes->get($element);
                if (!$ligne || !is_array($saisie['parts'] ?? null) || $saisie['parts'] === []) {
                    continue;
                }
                $clientsDejaPresents = $ligne->parts->pluck('client_id')->filter()->all();
                $nouvelles = array_map(fn ($part) => self::normaliserPart((array) $part, $odm, $ors, $clientsDejaPresents), array_values($saisie['parts']));
                $avant = $ligne->parts->map(fn (PartOdm $p) => self::champsPart($p->toArray()))->values()->all();
                if ($avant == $nouvelles) {
                    continue;
                }
                $ligne->parts()->delete();
                foreach ($nouvelles as $rang => $part) {
                    $ligne->parts()->create($part + ['rang' => $rang + 1]);
                }
                $changements[] = ['participant' => $participant->nom, 'element' => $element, 'avant' => $avant, 'apres' => $nouvelles];
            }
        }

        /* RG-M12-35, décision Q53 : chaque modification tracée une fois l'ODM soumis (correction après rejet) */
        if ($changements !== [] && $odm->numero !== null) {
            HistoriqueOdm::enregistrer($odm, 'repartition', $odm->statut, $odm->statut, $auteur->id,
                'Répartition modifiée : ' . collect($changements)->map(fn ($c) => self::libelleElement($c['element']) . ' de ' . $c['participant'])->implode(', ') . '.',
                ['changements' => $changements]);
        }
    }

    /**
     * Prolongation (RG-M12-35) : parts du segment précédent reprises, élément par élément ; la nuitée de rattrapage
     * reprend celles de l'hébergement.
     */
    public static function modelesDuPrecedent(OrdreMission $precedent, int $userId): array
    {
        $participant = $precedent->participants()->where('user_id', $userId)->first();
        if (!$participant) {
            return [];
        }
        $modeles = $participant->lignes()->with('parts')->get()
            ->mapWithKeys(fn (LigneFraisOdm $l) => [$l->element => $l->parts->map(fn (PartOdm $p) => self::champsPart($p->toArray()))->all()])
            ->all();
        $modeles['rattrapage'] ??= $modeles['hebergement'] ?? $modeles['hebergement_exterieur'] ?? null;

        return array_filter($modeles);
    }

    /**
     * Récapitulatif pour les valideurs et les écrans (RG-M12-34, RG-M12-37) : part Neemba, part client,
     * par client et par OR, avec les avertissements RG-M12-33 et leurs commentaires.
     */
    public static function recapitulatif(OrdreMission $odm): array
    {
        $parts = self::partsActives($odm)->load(['client', 'ordreReparation.client', 'ligne.participant']);
        $recap = CalculOdm::recapitulatif($parts->map(fn (PartOdm $p) => $p->only(['payeur', 'client_id', 'ordre_reparation_id', 'montant'])));
        $clients = Client::whereIn('id', array_keys($recap['par_client']))->get()->keyBy('id');
        $ors = OrdreReparationOdm::whereIn('id', collect($recap['par_client'])->flatMap(fn ($c) => array_keys($c['par_or']))->filter(fn ($k) => is_int($k)))->pluck('numero', 'id');

        return [
            'neemba' => $recap['neemba'],
            'client' => $recap['client'],
            'total' => $recap['neemba'] + $recap['client'],
            'par_client' => collect($recap['par_client'])->map(fn ($c, $clientId) => [
                'client' => $clients->get($clientId)?->resume() ?? ['id' => null, 'code' => '—', 'raison_sociale' => 'Client non renseigné', 'actif' => false],
                'montant' => $c['montant'],
                'par_or' => collect($c['par_or'])->map(fn ($montant, $or) => ['or' => $or === 'sans_or' ? null : ($ors[$or] ?? null), 'montant' => $montant])->values()->all(),
            ])->values()->all(),
            'avertissements' => $parts->filter(fn (PartOdm $p) => $p->avertissement() !== null)->map(fn (PartOdm $p) => [
                'participant' => $p->ligne->participant->nom,
                'element' => self::libelleElement($p->ligne->element),
                'message' => $p->avertissement(),
                'commentaire' => $p->commentaire,
            ])->values()->all(),
        ];
    }

    /** Photographie de la répartition (soumission, validation finale) pour le journal de l'ODM */
    public static function photographie(OrdreMission $odm): array
    {
        return $odm->lignesFrais()->with(['parts.client', 'parts.ordreReparation', 'participant'])->get()
            ->reject(fn (LigneFraisOdm $l) => $l->participant->retire)
            ->map(fn (LigneFraisOdm $l) => [
                'participant' => $l->participant->nom,
                'element' => $l->element,
                'montant' => $l->montant !== null ? (float) $l->montant : null,
                'parts' => $l->parts->map(fn (PartOdm $p) => [
                    'payeur' => $p->payeur, 'client' => $p->client?->code, 'or' => $p->ordreReparation?->numero,
                    'code_analytique' => $p->code_analytique, 'pourcentage' => (float) $p->pourcentage, 'montant' => $p->montant !== null ? (float) $p->montant : null,
                ])->all(),
            ])->values()->all();
    }

    /**
     * Spec v2.3 (M03) : un bon issu d'un ODM reprend en lecture seule la répartition des lignes qu'il paie,
     * avec ses frais Orange Money répartis entre les parts (RG-M12-36). Null pour un autre bon.
     */
    public static function pourBon(\App\Models\BonCaisse $bon): ?array
    {
        if (!$bon->genere_par_odm || !$bon->odm_id || $bon->type_bon !== 'BD') {
            return null;
        }
        $odm = OrdreMission::find($bon->odm_id);
        $participants = $odm?->participants()->where('bon_caisse_id', $bon->id)
            ->with(['lignes.parts.client', 'lignes.parts.ordreReparation.client'])->get() ?? collect();
        if (!$odm || $participants->isEmpty()) {
            return null;
        }

        $parts = $participants->flatMap(fn (ParticipantOdm $p) => $p->lignes->flatMap(fn (LigneFraisOdm $l) => $l->parts))->sortBy('id')->values();
        $frais = [];
        if ($bon->frais_om !== null && (float) $bon->frais_om > 0) {
            $mode = (string) \App\Models\Parametre::valeur('odm_frais_om_repartition', 'prorata');
            $repartis = CalculOdm::repartirFrais((float) $bon->frais_om, $parts->map(fn (PartOdm $p) => ['payeur' => $p->payeur, 'montant' => (float) $p->montant])->all(), $mode);
            $frais = $parts->pluck('id')->combine($repartis)->all();
        }

        return [
            'odm' => ['id' => $odm->id, 'libelle' => $odm->libelle],
            'participants' => $participants->map(fn (ParticipantOdm $p) => [
                'nom' => $p->nom,
                'lignes' => $p->lignes->sortBy(fn (LigneFraisOdm $l) => array_search($l->element, array_keys(LigneFraisOdm::ELEMENTS), true))->map(fn (LigneFraisOdm $l) => PresentationOdm::ligneFrais($l, $odm) + [
                    'frais_om' => $l->parts->mapWithKeys(fn (PartOdm $part) => [$part->id => $frais[$part->id] ?? null])->all(),
                ])->values()->all(),
            ])->values()->all(),
            'neemba' => (float) $parts->where('payeur', 'neemba')->sum('montant'),
            'client' => (float) $parts->where('payeur', 'client')->sum('montant'),
            'frais_om_neemba' => (float) $parts->where('payeur', 'neemba')->sum(fn (PartOdm $p) => $frais[$p->id] ?? 0),
            'frais_om_client' => (float) $parts->where('payeur', 'client')->sum(fn (PartOdm $p) => $frais[$p->id] ?? 0),
        ];
    }

    /** Parts d'une ligne, telles qu'inscrites au journal (avant / après une modification) */
    public static function photographieLigne(LigneFraisOdm $ligne): array
    {
        return $ligne->parts->map(fn (PartOdm $p) => [
            'payeur' => $p->payeur, 'client' => $p->client?->code, 'or' => $p->ordreReparation?->numero,
            'code_analytique' => $p->code_analytique, 'pourcentage' => (float) $p->pourcentage, 'montant' => $p->montant !== null ? (float) $p->montant : null,
        ])->values()->all();
    }

    /** Parts des participants présents (hors retirés d'une prolongation) */
    public static function partsActives(OrdreMission $odm): Collection
    {
        return PartOdm::whereHas('ligne', fn ($q) => $q->where('ordre_mission_id', $odm->id)->whereHas('participant', fn ($p) => $p->where('retire', false)))->get();
    }

    public static function libelleElement(string $element): string
    {
        return LigneFraisOdm::ELEMENTS[$element] ?? $element;
    }

    /** Champs comparables d'une part */
    private static function champsPart(array $part): array
    {
        $client = ($part['payeur'] ?? 'neemba') === 'client';

        return [
            'payeur' => $client ? 'client' : 'neemba',
            'client_id' => $client && isset($part['client_id']) ? (int) $part['client_id'] : null,
            'ordre_reparation_id' => isset($part['ordre_reparation_id']) ? (int) $part['ordre_reparation_id'] : null,
            'code_analytique' => filled($part['code_analytique'] ?? null) ? (string) $part['code_analytique'] : null,
            'pourcentage' => round((float) ($part['pourcentage'] ?? 100), 4),
            'commentaire' => filled($part['commentaire'] ?? null) ? mb_substr(trim((string) $part['commentaire']), 0, 500) : null,
        ];
    }

    /** Part saisie : client du référentiel (actif, ou déjà présent sur la ligne), OR de l'ODM, code analytique de l'ODM par défaut */
    private static function normaliserPart(array $part, OrdreMission $odm, Collection $ors, array $clientsDejaPresents): array
    {
        $clientId = is_numeric($part['client_id'] ?? null) ? (int) $part['client_id'] : null;
        if ($clientId && !in_array($clientId, $clientsDejaPresents, true) && !Client::actifs()->whereKey($clientId)->exists()) {
            $clientId = null;
        }
        $or = null;
        if (filled($part['ordre_reparation'] ?? null)) {
            $or = $ors->firstWhere('numero', preg_replace('/\D/', '', (string) $part['ordre_reparation']));
        } elseif (is_numeric($part['ordre_reparation_id'] ?? null)) {
            $or = $ors->firstWhere('id', (int) $part['ordre_reparation_id']);
        }
        $code = filled($part['code_analytique'] ?? null) && $part['code_analytique'] !== $odm->code_analytique ? (string) $part['code_analytique'] : null;

        return self::champsPart([
            'payeur' => $part['payeur'] ?? 'neemba',
            'client_id' => $clientId,
            'ordre_reparation_id' => $or?->id,
            'code_analytique' => $code,
            'pourcentage' => max(0, min(100, (float) ($part['pourcentage'] ?? 0))),
            'commentaire' => $part['commentaire'] ?? null,
        ]);
    }

    /** « 1 250 000 GNF » */
    public static function montant(?float $montant): string
    {
        return $montant === null ? '—' : Format::montant($montant);
    }
}
