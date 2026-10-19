<?php

namespace App\Services\Caisse;

use App\Exceptions\ErreurMetier;
use App\Models\Caisse;
use App\Models\EcritureCaisse;
use App\Models\Parametre;
use App\Models\ReconciliationOm;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Referentiels\ImportClients;
use App\Support\Format;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Réconciliation hebdomadaire de la caisse Orange Money (spec v2.4, RG-M08-13, §5.4, SC-35).
 *
 * - Semaine du lundi au dimanche ; établie par la trésorerie avec le relevé de la plateforme (CSV ou Excel exporté). Le
 *   format du relevé n'est pas fixé (API Orange Money hors périmètre) : l'en-tête est reconnu (date, référence, libellé,
 *   montant signé ou débit / crédit, solde).
 * - Rapprochement automatique : une écriture du brouillard et une ligne du relevé de même sens et de même montant, à
 *   quelques jours d'écart au plus (paramètre), sont rapprochées. Les lignes restantes des deux côtés sont listées.
 * - Écart = solde de fin du relevé − solde de fin du brouillard.
 * - Validée par la trésorière, le Chef comptable et le DAF (ou son adjoint), trois personnes différentes. Tant qu'elle
 *   n'est pas validée, la trésorerie peut la refaire avec un nouveau relevé (les signatures repartent de zéro).
 */
final class ReconciliationOrangeMoney
{
    public const ROLES_SIGNATURES = [
        'tresorerie' => ['tresorerie'],
        'chef_comptable' => ['chef_comptable'],
        'daf' => ['daf', 'daf_adjoint'],
    ];

    /** Lundi de la semaine qui contient la date */
    public static function debutSemaine(CarbonInterface $date): Carbon
    {
        return Carbon::instance($date)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    public static function caissesOm(): Collection
    {
        return Caisse::actives()->where('type', 'orange_money')->orderBy('libelle')->get();
    }

    public static function etablir(User $auteur, Caisse $caisse, CarbonInterface $semaine, UploadedFile $releve, ?float $soldeReleve, ?string $observations): ReconciliationOm
    {
        if (!$auteur->aLeRole('tresorerie') || $caisse->type !== 'orange_money') {
            throw new ErreurMetier('RECONCILIATION_INTERDITE', 'MSG-APP-100', [], 'RG-M08-13', null, 403);
        }
        $debut = self::debutSemaine($semaine);
        $fin = $debut->copy()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        if ($debut->isFuture()) {
            throw new ErreurMetier('RECONCILIATION_PERIODE', 'MSG-APP-101', [], 'RG-M08-13', 'semaine');
        }
        $existante = ReconciliationOm::where('caisse_id', $caisse->id)->whereDate('periode_debut', $debut)->first();
        if ($existante?->estValidee()) {
            throw new ErreurMetier('RECONCILIATION_VALIDEE', 'MSG-APP-102', [], 'RG-M08-13', 'semaine');
        }

        $lignes = self::lireReleve($releve, $debut, $fin);
        $ecritures = EcritureCaisse::where('caisse_id', $caisse->id)
            ->whereBetween('date_ecriture', [$debut, $fin->copy()->endOfDay()])
            ->orderBy('date_ecriture')->orderBy('id')->get();
        [$lignes, $restantes, $rapprochees] = self::rapprocher($lignes, $ecritures);

        $soldeDebut = $caisse->soldeAu($debut) ?? 0.0;
        $soldeFin = $caisse->soldeAu($fin->copy()->addDay()) ?? 0.0;
        $soldeReleve ??= collect($lignes)->whereNotNull('solde')->last()['solde'] ?? null;
        if ($soldeReleve === null) {
            throw new ErreurMetier('RELEVE_SANS_SOLDE', 'MSG-APP-104', [], 'RG-M08-13', 'solde_releve');
        }

        return DB::transaction(function () use ($auteur, $caisse, $debut, $fin, $releve, $lignes, $restantes, $rapprochees, $soldeDebut, $soldeFin, $soldeReleve, $observations, $existante) {
            $donnees = [
                'caisse_id' => $caisse->id,
                'periode_debut' => $debut->toDateString(),
                'periode_fin' => $fin->toDateString(),
                'statut' => 'a_valider',
                'releve' => $releve->store('reconciliations-om', 'public'),
                'lignes_releve' => $lignes,
                'ecritures_non_rapprochees' => $restantes,
                'lignes_rapprochees' => $rapprochees,
                'solde_brouillard_debut' => $soldeDebut,
                'solde_brouillard_fin' => $soldeFin,
                'solde_releve_fin' => $soldeReleve,
                'ecart' => $soldeReleve !== null ? round($soldeReleve - $soldeFin, 2) : null,
                'observations' => trim((string) $observations) ?: null,
                'etabli_par' => $auteur->id,
                'tresorerie_id' => null, 'tresorerie_le' => null, 'chef_comptable_id' => null, 'chef_comptable_le' => null,
                'daf_id' => null, 'daf_le' => null,
            ];
            $reconciliation = $existante
                ? tap($existante)->update($donnees)
                : ReconciliationOm::create($donnees + ['numero' => 'ROM-' . $caisse->codeNumerotation() . '-' . $debut->isoFormat('GGGG') . '-S' . $debut->isoFormat('WW')]);

            self::notifier($reconciliation, ['chef_comptable', 'daf'], $auteur, 'Réconciliation Orange Money à valider',
                "Réconciliation {$reconciliation->numero} (semaine du " . Format::date($debut) . ') : '
                . (count($restantes) + collect($lignes)->whereNull('ecriture_id')->count()) . ' ligne(s) non rapprochée(s), écart '
                . ($reconciliation->ecart !== null ? Format::montant($reconciliation->ecart) : 'non calculé') . '.');

            return $reconciliation->fresh();
        });
    }

    public static function signaturesPossibles(User $utilisateur, ReconciliationOm $r): array
    {
        if ($r->estValidee() || in_array($utilisateur->id, array_map('intval', array_filter([$r->tresorerie_id, $r->chef_comptable_id, $r->daf_id])), true)) {
            return [];
        }

        return array_values(array_filter(array_keys(self::ROLES_SIGNATURES),
            fn (string $s) => !$r->{"{$s}_le"} && $utilisateur->aLeRole(self::ROLES_SIGNATURES[$s])));
    }

    public static function signer(User $utilisateur, ReconciliationOm $r, string $signature): ReconciliationOm
    {
        if (!in_array($signature, self::signaturesPossibles($utilisateur, $r), true)) {
            throw new ErreurMetier('SIGNATURE_IMPOSSIBLE', 'MSG-APP-089', [], 'RG-M08-13', 'signature', 403);
        }
        $r->update(["{$signature}_id" => $utilisateur->id, "{$signature}_le" => now()]);
        if ($r->fresh()->estValidee()) {
            $r->update(['statut' => 'validee']);
            self::notifier($r, ['tresorerie'], $utilisateur, 'Réconciliation Orange Money validée', "Réconciliation {$r->numero} validée par la trésorière, le Chef comptable et le DAF.");
        }

        return $r->fresh();
    }

    /** Rappel du lundi : réconciliation de la semaine écoulée absente ou non validée (trésorerie) */
    public static function rappels(): int
    {
        $semaine = self::debutSemaine(now()->subWeek());
        $envois = 0;
        foreach (self::caissesOm() as $caisse) {
            $r = ReconciliationOm::where('caisse_id', $caisse->id)->whereDate('periode_debut', $semaine)->first();
            if ($r?->estValidee()) {
                continue;
            }
            $message = $r
                ? "La réconciliation {$r->numero} de la {$caisse->libelle} attend encore " . (3 - $r->nombreSignatures()) . ' signature(s).'
                : "La réconciliation de la {$caisse->libelle} pour la semaine du " . Format::date($semaine) . " n'est pas établie.";
            foreach (User::actifs()->avecRoleDans($r ? self::rolesManquants($r) : ['tresorerie'], $caisse->entite_id)->get() as $destinataire) {
                NotificationService::creerEtDiffuserSimple($destinataire, null, 'reconciliation_om', 'Réconciliation Orange Money en attente', $message,
                    ['lien' => $r ? route('reconciliations-om.show', $r) : route('reconciliations-om.index')]);
                $envois++;
            }
        }

        return $envois;
    }

    private static function rolesManquants(ReconciliationOm $r): array
    {
        return collect(self::ROLES_SIGNATURES)->filter(fn (array $roles, string $s) => !$r->{"{$s}_le"})->flatten()->all();
    }

    /**
     * Lignes du relevé dans la période : date, référence, libellé, sens, montant, solde.
     *
     * @return array<int, array{ligne: int, date: string, reference: ?string, libelle: ?string, sens: string, montant: float, solde: ?float, ecriture_id: ?int}>
     */
    public static function lireReleve(UploadedFile $releve, CarbonInterface $debut, CarbonInterface $fin): array
    {
        try {
            $brutes = ImportClients::lire($releve->getRealPath(), $releve->getClientOriginalExtension());
        } catch (\Throwable) {
            $brutes = [];
        }
        [$colonnes, $rang] = self::colonnes($brutes);
        if ($colonnes === null) {
            throw new ErreurMetier('RELEVE_ILLISIBLE', 'MSG-APP-103', [], 'RG-M08-13', 'releve');
        }

        $lignes = [];
        foreach (array_slice($brutes, $rang + 1, null, true) as $i => $cellules) {
            $date = self::date($cellules[$colonnes['date']] ?? null);
            if (isset($colonnes['montant'])) {
                $montant = self::nombre($cellules[$colonnes['montant']] ?? null);
            } else {
                $montant = (self::nombre($cellules[$colonnes['credit']] ?? null) ?? 0) - (self::nombre($cellules[$colonnes['debit']] ?? null) ?? 0);
            }
            if (!$date || !$montant || $date->lt($debut) || $date->gt($fin->copy()->endOfDay())) {
                continue;
            }
            $lignes[] = [
                'ligne' => $i + 1,
                'date' => $date->toDateTimeString(),
                'reference' => isset($colonnes['reference']) ? (trim((string) ($cellules[$colonnes['reference']] ?? '')) ?: null) : null,
                'libelle' => isset($colonnes['libelle']) ? (Str::limit(trim((string) ($cellules[$colonnes['libelle']] ?? '')), 120) ?: null) : null,
                'sens' => $montant > 0 ? 'entree' : 'sortie',
                'montant' => abs($montant),
                'solde' => isset($colonnes['solde']) ? self::nombre($cellules[$colonnes['solde']] ?? null) : null,
                'ecriture_id' => null,
            ];
        }

        return $lignes;
    }

    /**
     * @return array{0: array<int, array>, 1: array<int, array>, 2: int} lignes du relevé (avec l'écriture rapprochée), écritures
     *         non rapprochées, nombre de rapprochements
     */
    public static function rapprocher(array $lignes, Collection $ecritures): array
    {
        $delai = (int) Parametre::valeur('reconciliation_om_delai_jours', 2);
        $restantes = [];
        $rapprochees = 0;
        foreach ($ecritures as $e) {
            $trouvee = null;
            foreach ($lignes as $i => $l) {
                if ($l['ecriture_id'] === null && $l['sens'] === $e->sens && abs($l['montant'] - (float) $e->montant) < 0.5
                    && abs(Carbon::parse($l['date'])->startOfDay()->diffInDays($e->date_ecriture->copy()->startOfDay())) <= $delai) {
                    $trouvee = $i;
                    break;
                }
            }
            if ($trouvee === null) {
                $restantes[] = [
                    'id' => $e->id, 'date' => $e->date_ecriture->toDateTimeString(), 'sens' => $e->sens, 'montant' => (float) $e->montant,
                    'libelle' => $e->libelle ?: (EcritureCaisse::NATURES[$e->nature] ?? $e->nature),
                ];
            } else {
                $lignes[$trouvee]['ecriture_id'] = $e->id;
                $rapprochees++;
            }
        }

        return [$lignes, $restantes, $rapprochees];
    }

    /** En-tête du relevé : rang de la ligne et colonnes reconnues (null si la date ou le montant manque) */
    private static function colonnes(array $brutes): array
    {
        foreach (array_slice($brutes, 0, 15, true) as $rang => $cellules) {
            $c = [];
            foreach ((array) $cellules as $i => $titre) {
                $t = Str::of((string) $titre)->ascii()->lower()->replaceMatches('/[^a-z]/', '')->value();
                $cle = match (true) {
                    $t === '' => null,
                    str_contains($t, 'date') => 'date',
                    str_contains($t, 'solde') || str_contains($t, 'balance') => 'solde',
                    str_contains($t, 'debit') || str_contains($t, 'sortie') => 'debit',
                    str_contains($t, 'credit') || str_contains($t, 'entree') => 'credit',
                    str_contains($t, 'montant') || $t === 'amount' => 'montant',
                    (bool) preg_match('/ref|transaction|^id/', $t) => 'reference',
                    (bool) preg_match('/libelle|description|detail|motif|beneficiaire/', $t) => 'libelle',
                    default => null,
                };
                if ($cle && !isset($c[$cle])) {
                    $c[$cle] = $i;
                }
            }
            if (isset($c['date']) && (isset($c['montant']) || (isset($c['debit']) && isset($c['credit'])))) {
                return [$c, $rang];
            }
        }

        return [null, null];
    }

    private static function date(mixed $valeur): ?Carbon
    {
        if ($valeur === null || trim((string) $valeur) === '') {
            return null;
        }
        if (is_numeric($valeur) && (float) $valeur > 20000 && (float) $valeur < 80000) {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $valeur));
        }
        $texte = trim((string) $valeur);
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i:s', 'd-m-Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $texte);
                if ($date !== false && $date->format($format) === $texte) {
                    return $date;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private static function nombre(mixed $valeur): ?float
    {
        if ($valeur === null) {
            return null;
        }
        if (is_int($valeur) || is_float($valeur)) {
            return (float) $valeur;
        }
        $texte = preg_replace('/[\s\x{00A0}\x{202F}]|GNF/u', '', (string) $valeur);
        if ($texte === '' || $texte === '-') {
            return null;
        }
        /* 1.250.000,50 ou 1,250,000.50 : le dernier séparateur suivi de 1 ou 2 chiffres est la virgule décimale */
        $texte = preg_match('/^(.*?)[.,](\d{1,2})$/', $texte, $m)
            ? preg_replace('/[.,]/', '', $m[1]) . '.' . $m[2]
            : preg_replace('/[.,]/', '', $texte);

        return is_numeric($texte) ? (float) $texte : null;
    }

    private static function notifier(ReconciliationOm $r, array $signatures, ?User $auteur, string $titre, string $message): void
    {
        $roles = collect($signatures)->flatMap(fn (string $s) => self::ROLES_SIGNATURES[$s])->all();
        foreach (User::actifs()->avecRoleDans($roles, $r->entite_id)->get() as $destinataire) {
            NotificationService::creerEtDiffuserSimple($destinataire, $auteur, 'reconciliation_om', $titre, $message, ['lien' => route('reconciliations-om.show', $r)]);
        }
    }
}
