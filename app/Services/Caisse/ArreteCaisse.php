<?php

namespace App\Services\Caisse;

use App\Exceptions\ErreurMetier;
use App\Models\Caisse;
use App\Models\MouvementCaisse;
use App\Models\PvArrete;
use App\Models\RapportCaisse;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PV d'arrêté de caisse (spec v2.4, RG-M10-02, RG-M10-03, Annexe 2).
 *
 * - Établi pour une caisse par son gérant (caissier rattaché, gestionnaire ou suppléant), la trésorerie ou le Chef
 *   comptable : billetage par coupure (caisse espèces) ou solde lu sur la plateforme (caisse Orange Money) ; le solde
 *   comptable est celui de la caisse au moment de l'arrêté ; l'écart est affiché.
 * - Signé par le gérant de la caisse, la trésorerie et le Chef comptable, chacun une fois et par trois personnes
 *   différentes.
 * - À la troisième signature, un écart non nul crée un ajustement d'inventaire « En attente », passé seulement après
 *   validation du DAF avec motif.
 */
final class ArreteCaisse
{
    /** Rôles qui voient toutes les caisses (RG-M08-12) */
    public const ROLES_TRANSVERSES = AccesCaisse::ROLES_TRANSVERSES;

    public static function estGerant(User $utilisateur, Caisse $caisse): bool
    {
        return AccesCaisse::estGerant($utilisateur, $caisse);
    }

    public static function caissesVisibles(User $utilisateur): Collection
    {
        return AccesCaisse::caissesVisibles($utilisateur);
    }

    public static function peutEtablir(User $utilisateur, Caisse $caisse): bool
    {
        return self::estGerant($utilisateur, $caisse) || $utilisateur->aLeRole(['tresorerie', 'chef_comptable']);
    }

    public static function estVisible(User $utilisateur, PvArrete $pv): bool
    {
        return $utilisateur->aLeRole(self::ROLES_TRANSVERSES) || ($pv->caisse && self::estGerant($utilisateur, $pv->caisse));
    }

    /**
     * Établit le PV ; le billetage ne retient que les coupures de l'Annexe 2.
     *
     * @param  array<string|int, int|string|null>|null  $billetage  coupure => nombre de billets ou de pièces
     */
    public static function etablir(User $auteur, Caisse $caisse, string $motif, ?array $billetage, float|int|string|null $soldeLu, ?string $observations): PvArrete
    {
        if (!self::peutEtablir($auteur, $caisse)) {
            throw new ErreurMetier('PV_INTERDIT', 'MSG-APP-088', [], 'RG-M10-02', null, 403);
        }
        /* RG-M10-01 : le contrôle inopiné est fait par la trésorerie ou le Chef comptable, pas par le gérant */
        if ($motif === 'controle_inopine' && self::estGerant($auteur, $caisse)) {
            throw new ErreurMetier('CONTROLE_PAR_LE_GERANT', 'MSG-APP-109', [], 'RG-M10-01', 'motif');
        }

        return DB::transaction(function () use ($auteur, $caisse, $motif, $billetage, $soldeLu, $observations) {
            $caisse = Caisse::whereKey($caisse->id)->lockForUpdate()->firstOrFail();
            if ($caisse->type === 'orange_money') {
                $comptage = null;
                $physique = (float) $soldeLu;
            } else {
                $comptage = collect(RapportCaisse::COUPURES)->mapWithKeys(fn (int $c) => [(string) $c => max(0, (int) ($billetage[$c] ?? $billetage[(string) $c] ?? 0))])->all();
                $physique = (float) collect($comptage)->map(fn (int $n, string $c) => (int) $c * $n)->sum();
            }
            $comptable = (float) $caisse->solde;

            return PvArrete::create([
                'caisse_id' => $caisse->id,
                'numero' => self::numero($caisse),
                'motif' => $motif,
                'arrete_le' => now(),
                'billetage' => $comptage,
                'solde_physique' => $physique,
                'solde_comptable' => $comptable,
                'ecart' => round($physique - $comptable, 2),
                'observations' => filled($observations) ? trim($observations) : null,
                'etabli_par' => $auteur->id,
            ]);
        });
    }

    /** Signatures que l'utilisateur peut encore donner sur ce PV */
    public static function signaturesPossibles(User $utilisateur, PvArrete $pv): array
    {
        if ($pv->estSigne() || in_array($utilisateur->id, [$pv->gerant_id, $pv->tresorerie_id, $pv->chef_comptable_id], true)) {
            return [];
        }
        $possibles = [];
        if (!$pv->gerant_le && $pv->caisse && self::estGerant($utilisateur, $pv->caisse)) {
            $possibles[] = 'gerant';
        }
        if (!$pv->tresorerie_le && $utilisateur->aLeRole('tresorerie')) {
            $possibles[] = 'tresorerie';
        }
        if (!$pv->chef_comptable_le && $utilisateur->aLeRole('chef_comptable')) {
            $possibles[] = 'chef_comptable';
        }

        return $possibles;
    }

    public static function signer(User $utilisateur, PvArrete $pv, string $signature): PvArrete
    {
        if (!in_array($signature, self::signaturesPossibles($utilisateur, $pv), true)) {
            throw new ErreurMetier('SIGNATURE_IMPOSSIBLE', 'MSG-APP-089', [], 'RG-M10-02', 'signature', 403);
        }

        return DB::transaction(function () use ($utilisateur, $pv, $signature) {
            $pv->update(["{$signature}_id" => $utilisateur->id, "{$signature}_le" => now()]);
            $pv->refresh();

            /* RG-M10-03 : PV signé avec un écart → ajustement d'inventaire en attente du DAF */
            if ($pv->estSigne() && (float) $pv->ecart !== 0.0 && !$pv->ajustement_id) {
                $caisse = $pv->caisse;
                $ajustement = MouvementCaisse::create([
                    'reference' => MouvementCaisse::genererReference(),
                    'type' => 'ajustement_inventaire',
                    'caisse_id' => $caisse->id,
                    'type_caisse' => $caisse->type === 'orange_money' ? 'om' : 'especes',
                    'montant' => abs((float) $pv->ecart),
                    'motif' => "Écart d'inventaire du PV {$pv->numero} : " . ((float) $pv->ecart > 0 ? 'excédent' : 'manquant') . ' de '
                        . \App\Support\Format::montant(abs((float) $pv->ecart)) . '.',
                    'site' => $caisse->sites->first()?->nom ?? '',
                    'statut' => 'en_attente',
                    'effectue_par' => $pv->etabli_par,
                    'date_mouvement' => now(),
                    'metadata' => ['sens' => (float) $pv->ecart > 0 ? 'entree' : 'sortie', 'pv_arrete_id' => $pv->id],
                ]);
                $pv->update(['ajustement_id' => $ajustement->id]);
                NotificationService::notifierMouvementCaisseCreee($ajustement, $utilisateur);
            }

            return $pv->fresh();
        });
    }

    /** PV-CKY-20261019-1 : caisse, jour, rang du jour */
    private static function numero(Caisse $caisse): string
    {
        $prefixe = 'PV-' . $caisse->codeNumerotation() . '-' . now()->format('Ymd') . '-';
        $rang = PvArrete::withoutGlobalScope('entite')->where('numero', 'like', $prefixe . '%')->count() + 1;

        return $prefixe . $rang;
    }
}
