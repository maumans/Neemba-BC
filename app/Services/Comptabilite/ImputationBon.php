<?php

namespace App\Services\Comptabilite;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\HistoriqueOdm;
use App\Models\Imputation;
use App\Models\LigneFraisOdm;
use App\Models\OrdreMission;
use App\Models\Parametre;
use App\Models\PartOdm;
use App\Models\ParticipantOdm;
use App\Models\User;
use App\Services\Odm\CalculOdm;
use App\Services\Odm\PaiementOdm;
use App\Services\Odm\RepartitionOdm;
use App\Support\Format;
use App\Support\JoursOuvres;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imputation comptable d'un bon payé (M16, spec v2.3 §8.11).
 *
 * - RG-M16-01 : une ou plusieurs lignes (compte, libellé, montant), la somme égale au montant versé.
 * - RG-M16-02 : pré-remplissage — BP au 421100, frais OM au compte paramétré, code journal selon le mode de paiement.
 * - RG-M16-05 : bon issu d'un ODM — une ligne par part de prise en charge ; part Neemba au compte de charge de
 *   l'élément, part client au compte des frais refacturables ; code analytique, client et OR repris de la part.
 *   Les frais OM suivent le paramètre de répartition (RG-M12-36).
 * - RG-M16-06 : la Comptabilité peut corriger la répartition tant que l'écriture n'est pas exportée ; motif obligatoire ;
 *   l'ODM reprend la répartition corrigée, tracée avec l'ancienne et la nouvelle valeur.
 */
final class ImputationBon
{
    /** Clé du paramètre de code journal, par mode de paiement (RG-M06-08) */
    public const JOURNAUX = [
        'especes' => 'code_journal_especes',
        'orange_money' => 'code_journal_orange_money',
        'mtn_money' => 'code_journal_mtn_money',
        'cheque' => 'code_journal_cheque',
        'virement' => 'code_journal_virement',
    ];

    public static function montantVerse(BonCaisse $bon): float
    {
        return round((float) ($bon->montant_verse ?? $bon->montant));
    }

    public static function codeJournal(BonCaisse $bon): ?string
    {
        $cle = self::JOURNAUX[$bon->mode_paiement_effectif ?? $bon->mode_paiement] ?? null;

        return $cle ? (self::parametre($cle) ?: null) : null;
    }

    /** Bons payés sans imputation validée (RG-M16-04) */
    public static function requeteAImputer(): Builder
    {
        return BonCaisse::query()
            ->whereIn('statut', PaiementOdm::STATUTS_PAYES)
            ->whereNotNull('date_paiement')
            ->whereDoesntHave('imputation', fn ($q) => $q->whereIn('statut', ['validee', 'exportee']));
    }

    /** RG-M16-04 : bon payé non imputé au-delà du délai (jours ouvrés après le paiement) */
    public static function enRetard(BonCaisse $bon): bool
    {
        if (!$bon->date_paiement || in_array($bon->imputation?->statut, ['validee', 'exportee'], true)) {
            return false;
        }

        return JoursOuvres::ajouter($bon->date_paiement, (int) Parametre::valeur('imputation_delai_alerte', 1))->endOfDay()->isPast();
    }

    /** Imputation du bon, créée et pré-remplie à la première ouverture */
    public static function ouvrir(BonCaisse $bon, User $auteur): Imputation
    {
        if (!in_array($bon->statut, PaiementOdm::STATUTS_PAYES, true) || !$bon->date_paiement) {
            throw new ErreurMetier('BON_NON_PAYE', 'MSG-APP-051', [], 'RG-M16-01', null, 409);
        }

        return DB::transaction(function () use ($bon, $auteur) {
            $imputation = Imputation::firstOrCreate(['bon_caisse_id' => $bon->id], [
                'statut' => 'brouillon', 'code_journal' => self::codeJournal($bon), 'saisi_par_id' => $auteur->id,
            ]);
            if ($imputation->wasRecentlyCreated) {
                foreach (self::lignesPreRemplies($bon) as $rang => $ligne) {
                    $imputation->lignes()->create($ligne + ['rang' => $rang + 1]);
                }
            }

            return $imputation->load('lignes');
        });
    }

    /**
     * RG-M16-02, RG-M16-05 : lignes proposées.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lignesPreRemplies(BonCaisse $bon): array
    {
        $lignes = [];
        $partsOdm = self::partsOdm($bon);

        if ($bon->type_bon === 'BP') {
            $lignes[] = self::ligne(self::parametre('compte_bp') ?: '421100', $bon->motif, (float) $bon->montant, $bon->code_analytique);
        } elseif ($partsOdm->isNotEmpty()) {
            $odm = OrdreMission::find($bon->odm_id);
            foreach ($partsOdm as $part) {
                $ligneFrais = $part->ligne;
                $client = $part->payeur === 'client';
                $lignes[] = array_merge(self::ligne(
                    $client ? self::parametre('compte_frais_refacturables') : self::parametre($ligneFrais->element === 'indemnite' ? 'compte_odm_indemnite' : 'compte_odm_hebergement'),
                    mb_substr(RepartitionOdm::libelleElement($ligneFrais->element) . ' — ' . $ligneFrais->participant->nom . ' — ODM ' . $odm?->numero
                        . ($client && $part->client ? ' — ' . $part->client->raison_sociale : ''), 0, 150),
                    (float) $part->montant,
                    $part->code_analytique ?? $odm?->code_analytique,
                ), [
                    'payeur' => $part->payeur,
                    'client_id' => $client ? $part->client_id : null,
                    'ordre_reparation' => $part->ordreReparation?->numero,
                    'ligne_frais_id' => $ligneFrais->id,
                ]);
            }
        } else {
            $lignes[] = self::ligne(null, $bon->motif, (float) $bon->montant, $bon->code_analytique);
        }

        /* Frais Orange Money (RG-M16-02) ; bon d'ODM : répartis entre les parts (RG-M12-36), part client refacturable */
        $frais = round((float) ($bon->frais_om ?? 0));
        if ($frais > 0) {
            $libelle = 'Frais Orange Money — bon ' . $bon->numero;
            if ($partsOdm->isEmpty()) {
                $lignes[] = array_merge(self::ligne(self::parametre('compte_frais_om'), $libelle, $frais, $bon->code_analytique), ['nature' => 'frais_om']);
            } else {
                $repartis = CalculOdm::repartirFrais($frais, $partsOdm->map(fn (PartOdm $p) => ['payeur' => $p->payeur, 'montant' => (float) $p->montant])->all(),
                    (string) Parametre::valeur('odm_frais_om_repartition', 'prorata'));
                $parPayeur = [];
                foreach ($partsOdm->values() as $i => $part) {
                    $cle = $part->payeur === 'client' ? 'client:' . $part->client_id : 'neemba';
                    $parPayeur[$cle] = ($parPayeur[$cle] ?? 0) + $repartis[$i];
                }
                foreach ($parPayeur as $cle => $montant) {
                    if ($montant <= 0) {
                        continue;
                    }
                    $clientId = str_starts_with($cle, 'client:') ? (int) substr($cle, 7) ?: null : null;
                    $lignes[] = array_merge(self::ligne(
                        self::parametre($cle === 'neemba' ? 'compte_frais_om' : 'compte_frais_refacturables'),
                        $libelle . ($cle === 'neemba' ? '' : ' — part client'),
                        $montant,
                        $bon->code_analytique,
                    ), ['nature' => 'frais_om', 'payeur' => $cle === 'neemba' ? 'neemba' : 'client', 'client_id' => $clientId]);
                }
            }
        }

        return $lignes;
    }

    /**
     * Enregistre les lignes ; « valider » contrôle et clôt l'imputation (RG-M16-01). Bon d'ODM : une répartition modifiée
     * exige un motif et corrige les parts de l'ODM (RG-M16-06).
     *
     * @param array<int, array<string, mixed>> $lignes
     */
    public static function enregistrer(Imputation $imputation, array $lignes, ?string $motif, User $auteur, bool $valider): Imputation
    {
        if (!$imputation->estModifiable()) {
            throw new ErreurMetier('IMPUTATION_EXPORTEE', 'MSG-APP-046', [], 'RG-M16-06', null, 409);
        }
        $bon = $imputation->bonCaisse;
        $lignesFraisOdm = self::partsOdm($bon)->map(fn (PartOdm $p) => $p->ligne)->unique('id')->keyBy('id');
        $normalisees = array_values(array_map(fn ($l) => self::normaliser((array) $l, $lignesFraisOdm), $lignes));
        $corrections = self::corrections($normalisees, $lignesFraisOdm, $bon);
        $motif = filled($motif) ? trim($motif) : null;

        if ($valider) {
            self::controler($normalisees, $bon, $lignesFraisOdm, $corrections !== [], $motif);
        }

        return DB::transaction(function () use ($imputation, $normalisees, $motif, $auteur, $valider, $corrections, $bon) {
            $imputation->lignes()->delete();
            foreach ($normalisees as $rang => $ligne) {
                $imputation->lignes()->create($ligne + ['rang' => $rang + 1]);
            }
            $imputation->update([
                'statut' => $valider ? 'validee' : 'brouillon',
                'code_journal' => self::codeJournal($bon),
                'motif_correction' => $motif ?? $imputation->motif_correction,
                'saisi_par_id' => $auteur->id,
                'valide_par_id' => $valider ? $auteur->id : null,
                'valide_le' => $valider ? now() : null,
            ]);

            if ($valider) {
                if ($corrections !== []) {
                    self::corrigerOdm($bon, $corrections, $motif, $auteur);
                }
                HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_IMPUTATION, $bon->statut, $bon->statut, $auteur->id,
                    'Imputation comptable validée : ' . count($normalisees) . ' ligne(s), ' . Format::montant(array_sum(array_column($normalisees, 'montant')))
                    . ($corrections !== [] ? '. Répartition de l\'ODM corrigée : ' . $motif : '.'),
                    ['lignes' => array_map(fn ($l) => array_intersect_key($l, array_flip(['compte', 'montant', 'code_analytique', 'payeur', 'client_id', 'ordre_reparation'])), $normalisees)]);
            }

            return $imputation->fresh('lignes');
        });
    }

    /** RG-M16-01, RG-M16-06 : contrôles de la validation */
    private static function controler(array $lignes, BonCaisse $bon, Collection $lignesFraisOdm, bool $corrigee, ?string $motif): void
    {
        $erreurs = [];
        foreach ($lignes as $i => $ligne) {
            if (blank($ligne['compte'])) {
                $erreurs[] = ErreurMetier::texte('MSG-APP-047', ['ligne' => $i + 1]);
            }
        }
        $somme = round(array_sum(array_column($lignes, 'montant')));
        if ($lignes === [] || abs($somme - self::montantVerse($bon)) > 0.5) {
            $erreurs[] = ErreurMetier::texte('MSG-APP-048', ['somme' => Format::montant($somme), 'montant' => Format::montant(self::montantVerse($bon))]);
        }
        /* Bon d'ODM : chaque ligne de frais reste entièrement répartie */
        foreach ($lignesFraisOdm as $id => $ligneFrais) {
            $total = round(array_sum(array_map(fn ($l) => $l['ligne_frais_id'] === $id ? $l['montant'] : 0, $lignes)));
            if (abs($total - round((float) $ligneFrais->montant)) > 0.5) {
                $erreurs[] = ErreurMetier::texte('MSG-APP-049', [
                    'element' => mb_strtolower(RepartitionOdm::libelleElement($ligneFrais->element)), 'participant' => $ligneFrais->participant->nom,
                    'somme' => Format::montant($total), 'montant' => Format::montant((float) $ligneFrais->montant),
                ]);
            }
        }
        if ($corrigee && $motif === null) {
            throw ValidationException::withMessages(['motif' => ErreurMetier::texte('MSG-APP-050')] + ($erreurs ? ['lignes' => $erreurs] : []));
        }
        if ($erreurs) {
            throw ValidationException::withMessages(['lignes' => $erreurs]);
        }
    }

    /**
     * Lignes de frais de l'ODM dont la répartition saisie diffère des parts actuelles.
     *
     * @return array<int, array> ligne_frais_id => lignes d'imputation de cette ligne de frais
     */
    private static function corrections(array $lignes, Collection $lignesFraisOdm, BonCaisse $bon): array
    {
        if ($lignesFraisOdm->isEmpty()) {
            return [];
        }
        $odm = OrdreMission::find($bon->odm_id);
        $signature = fn (array $l) => implode('|', [$l['payeur'] ?? 'neemba', $l['payeur'] === 'client' ? $l['client_id'] : '', $l['ordre_reparation'] ?? '',
            ($l['code_analytique'] ?? null) ?: $odm?->code_analytique, round((float) $l['montant'])]);

        $corrections = [];
        foreach ($lignesFraisOdm as $id => $ligneFrais) {
            $saisies = array_values(array_filter($lignes, fn ($l) => $l['ligne_frais_id'] === $id));
            $actuelles = $ligneFrais->parts->map(fn (PartOdm $p) => [
                'payeur' => $p->payeur, 'client_id' => $p->client_id, 'ordre_reparation' => $p->ordreReparation?->numero,
                'code_analytique' => $p->code_analytique, 'montant' => (float) $p->montant,
            ])->all();
            $a = array_map($signature, $saisies);
            $b = array_map($signature, $actuelles);
            sort($a);
            sort($b);
            if ($a !== $b) {
                $corrections[$id] = $saisies;
            }
        }

        return $corrections;
    }

    /** RG-M16-06 : parts de l'ODM remplacées par la répartition corrigée, tracée avec l'ancienne valeur */
    private static function corrigerOdm(BonCaisse $bon, array $corrections, string $motif, User $auteur): void
    {
        $odm = OrdreMission::findOrFail($bon->odm_id);
        $ors = $odm->ordresReparation()->pluck('id', 'numero');
        $changements = [];
        foreach ($corrections as $ligneFraisId => $saisies) {
            $ligneFrais = LigneFraisOdm::with(['parts.client', 'parts.ordreReparation', 'participant'])->findOrFail($ligneFraisId);
            $montantLigne = (float) $ligneFrais->montant;
            $avant = RepartitionOdm::photographieLigne($ligneFrais);

            $pourcentages = array_map(fn ($l) => CalculOdm::pourcentageDe((float) $l['montant'], $montantLigne), $saisies);
            /* Le total fait exactement 100 % : l'écart d'arrondi va à la part la plus forte */
            if ($pourcentages !== []) {
                $plusForte = array_keys($pourcentages, max($pourcentages))[0];
                $pourcentages[$plusForte] = round($pourcentages[$plusForte] + 100 - array_sum($pourcentages), 4);
            }
            $ligneFrais->parts()->delete();
            foreach ($saisies as $i => $l) {
                $ligneFrais->parts()->create([
                    'rang' => $i + 1,
                    'payeur' => $l['payeur'] === 'client' ? 'client' : 'neemba',
                    'client_id' => $l['payeur'] === 'client' ? $l['client_id'] : null,
                    'ordre_reparation_id' => $l['ordre_reparation'] ? ($ors[$l['ordre_reparation']] ?? null) : null,
                    'code_analytique' => $l['code_analytique'] && $l['code_analytique'] !== $odm->code_analytique ? $l['code_analytique'] : null,
                    'pourcentage' => $pourcentages[$i],
                    'montant' => $l['montant'],
                    'commentaire' => 'Corrigé par la Comptabilité à l\'imputation : ' . mb_substr($motif, 0, 400),
                ]);
            }
            $changements[] = ['participant' => $ligneFrais->participant->nom, 'element' => $ligneFrais->element, 'avant' => $avant,
                'apres' => RepartitionOdm::photographieLigne($ligneFrais->fresh(['parts.client', 'parts.ordreReparation']))];
        }

        RepartitionOdm::repartir($odm);
        if (in_array($odm->statut, ['VALIDE', 'BONS_GENERES', 'PAYE', 'CLOTURE'], true)) {
            $odm->update(['a_refacturer' => $odm->aDesPartsClient()]);
        }
        HistoriqueOdm::enregistrer($odm, 'repartition', $odm->statut, $odm->statut, $auteur->id,
            "Répartition corrigée par la Comptabilité à l'imputation du bon {$bon->numero} : {$motif}",
            ['source' => 'imputation', 'bon' => $bon->numero, 'motif' => $motif, 'changements' => $changements]);
    }

    /** Parts de l'ODM payées par ce bon (BD issu d'un ODM), dans l'ordre des participants et des éléments */
    public static function partsOdm(BonCaisse $bon): Collection
    {
        if (!$bon->genere_par_odm || !$bon->odm_id || $bon->type_bon !== 'BD') {
            return collect();
        }
        $ordre = array_keys(LigneFraisOdm::ELEMENTS);

        return ParticipantOdm::where('ordre_mission_id', $bon->odm_id)->where('bon_caisse_id', $bon->id)
            ->with(['lignes.parts.client', 'lignes.parts.ordreReparation', 'lignes.participant'])->orderBy('id')->get()
            ->flatMap(fn (ParticipantOdm $p) => $p->lignes->sortBy(fn ($l) => array_search($l->element, $ordre, true))
                ->flatMap(fn (LigneFraisOdm $l) => $l->parts->each(fn (PartOdm $part) => $part->setRelation('ligne', $l))))
            ->values();
    }

    /** Ligne saisie, nettoyée ; une ligne de frais qui n'appartient pas au bon est ignorée */
    private static function normaliser(array $l, Collection $lignesFraisOdm): array
    {
        $payeur = in_array($l['payeur'] ?? null, ['neemba', 'client'], true) ? $l['payeur'] : null;
        $ligneFraisId = is_numeric($l['ligne_frais_id'] ?? null) && $lignesFraisOdm->has((int) $l['ligne_frais_id']) ? (int) $l['ligne_frais_id'] : null;

        return [
            'nature' => ($l['nature'] ?? 'depense') === 'frais_om' ? 'frais_om' : 'depense',
            'compte' => filled($l['compte'] ?? null) ? mb_strtoupper(mb_substr(trim((string) $l['compte']), 0, 20)) : null,
            'libelle' => mb_substr(trim((string) ($l['libelle'] ?? '')), 0, 150) ?: 'Imputation',
            'montant' => round(max(0, (float) ($l['montant'] ?? 0))),
            'code_analytique' => filled($l['code_analytique'] ?? null) ? mb_substr((string) $l['code_analytique'], 0, 50) : null,
            'payeur' => $ligneFraisId ? ($payeur ?? 'neemba') : $payeur,
            'client_id' => $payeur === 'client' && is_numeric($l['client_id'] ?? null) ? (int) $l['client_id'] : null,
            'ordre_reparation' => filled($l['ordre_reparation'] ?? null) ? mb_substr(preg_replace('/\D/', '', (string) $l['ordre_reparation']), 0, 8) : null,
            'ligne_frais_id' => $ligneFraisId,
        ];
    }

    private static function ligne(?string $compte, ?string $libelle, float $montant, ?string $codeAnalytique): array
    {
        return [
            'nature' => 'depense', 'compte' => $compte ?: null, 'libelle' => mb_substr((string) ($libelle ?: 'Imputation'), 0, 150),
            'montant' => round($montant), 'code_analytique' => $codeAnalytique, 'payeur' => null, 'client_id' => null,
            'ordre_reparation' => null, 'ligne_frais_id' => null,
        ];
    }

    private static function parametre(string $cle): string
    {
        return trim((string) Parametre::valeur($cle, ''));
    }
}
