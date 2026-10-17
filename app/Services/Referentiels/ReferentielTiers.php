<?php

namespace App\Services\Referentiels;

use App\Exceptions\ErreurMetier;
use App\Models\Tiers;
use App\Models\User;
use App\Services\BonCaisse\ReglesSaisie;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'un tiers (spec v2.3) :
 * - RG-M02-07 : à la proposition (et à toute modification), même téléphone, même n° OM ou même NIF qu'un tiers existant
 *   (non refusé) → blocage avec renvoi vers ce tiers (MSG-M03-04) ;
 * - RG-M02-06 : un tiers proposé n'est pas sélectionnable ; la Comptabilité ou le CDG le valide, ou le refuse avec un motif ;
 *   le demandeur est notifié. Un tiers créé directement par un valideur est validé d'emblée.
 */
final class ReferentielTiers
{
    /** Données normalisées : téléphones au format +224, NIF en majuscules */
    public static function normaliser(array $donnees): array
    {
        return [
            'nom' => trim(preg_replace('/\s+/', ' ', (string) $donnees['nom'])),
            'type' => $donnees['type'],
            'telephone' => ReglesSaisie::normaliserTelephone($donnees['telephone'] ?? null),
            'numero_om' => ReglesSaisie::normaliserTelephone($donnees['numero_om'] ?? null),
            'nif' => filled($donnees['nif'] ?? null) ? mb_strtoupper(trim($donnees['nif'])) : null,
        ];
    }

    /** RG-M02-07 : tiers existant (non refusé) au même téléphone, n° OM ou NIF */
    public static function doublon(array $donnees, ?int $sauf = null): ?Tiers
    {
        $numeros = array_values(array_filter([$donnees['telephone'] ?? null, $donnees['numero_om'] ?? null]));
        if (!$numeros && empty($donnees['nif'])) {
            return null;
        }

        return Tiers::where('statut', '!=', 'refuse')
            ->when($sauf, fn ($q) => $q->whereKeyNot($sauf))
            ->where(function ($q) use ($numeros, $donnees) {
                if ($numeros) {
                    $q->whereIn('telephone', $numeros)->orWhereIn('numero_om', $numeros);
                }
                if (!empty($donnees['nif'])) {
                    $q->orWhere('nif', $donnees['nif']);
                }
            })
            ->first();
    }

    /** Proposition d'un demandeur (ou création directe par un valideur, alors validée) */
    public static function proposer(array $saisie, User $auteur): Tiers
    {
        $donnees = self::normaliser($saisie);
        if ($existant = self::doublon($donnees)) {
            throw new ErreurMetier('TIERS_EXISTANT', 'MSG-M03-04', ['nom' => $existant->nom], 'RG-M02-07', 'nom', 422, ['tiers' => ['id' => $existant->id, 'statut' => $existant->statut]]);
        }
        $valideur = $auteur->aLeRole(Tiers::ROLES_VALIDATION);
        $tiers = Tiers::create($donnees + [
            'statut' => $valideur ? 'valide' : 'propose',
            'origine' => 'saisie',
            'propose_par_id' => $auteur->id,
            'propose_le' => now(),
            'valide_par_id' => $valideur ? $auteur->id : null,
            'valide_le' => $valideur ? now() : null,
        ]);

        if (!$valideur) {
            NotificationService::notifierTiers(self::valideurs(), $auteur, 'tiers_propose', 'Tiers proposé à valider',
                "{$auteur->nom_complet} propose le tiers « {$tiers->nom} » (" . Tiers::TYPES[$tiers->type] . ').');
        }

        return $tiers;
    }

    public static function modifier(Tiers $tiers, array $saisie): Tiers
    {
        $donnees = self::normaliser($saisie);
        if ($existant = self::doublon($donnees, $tiers->id)) {
            throw new ErreurMetier('TIERS_EXISTANT', 'MSG-M03-04', ['nom' => $existant->nom], 'RG-M02-07', 'nom', 422);
        }
        $tiers->update($donnees);

        return $tiers;
    }

    /** Validation, ou refus motivé, par la Comptabilité ou le CDG ; le demandeur est notifié */
    public static function decider(Tiers $tiers, User $valideur, bool $valider, ?string $motif = null): Tiers
    {
        return DB::transaction(function () use ($tiers, $valideur, $valider, $motif) {
            $tiers = Tiers::whereKey($tiers->id)->lockForUpdate()->firstOrFail();
            if (!in_array($tiers->statut, ['propose', 'refuse'], true) && !($valider && $tiers->statut === 'desactive')) {
                throw new ErreurMetier('TIERS_DEJA_TRAITE', 'MSG-APP-058', [], 'RG-M02-06', null, 409);
            }
            $tiers->update($valider
                ? ['statut' => 'valide', 'motif_refus' => null, 'valide_par_id' => $valideur->id, 'valide_le' => now()]
                : ['statut' => 'refuse', 'motif_refus' => trim((string) $motif), 'valide_par_id' => $valideur->id, 'valide_le' => now()]);

            if ($tiers->proposePar && $tiers->origine === 'saisie') {
                NotificationService::notifierTiers(collect([$tiers->proposePar]), $valideur, 'tiers_decide',
                    $valider ? 'Tiers validé' : 'Tiers refusé',
                    $valider
                        ? "Le tiers « {$tiers->nom} » est validé : vous pouvez le choisir comme bénéficiaire."
                        : "Le tiers « {$tiers->nom} » est refusé : {$tiers->motif_refus}");
            }

            return $tiers;
        });
    }

    /** Valideurs des tiers, pour la notification d'une proposition */
    public static function valideurs()
    {
        return User::actifs()->where(fn ($q) => $q->whereIn('role', Tiers::ROLES_VALIDATION)
            ->orWhereHas('roles', fn ($r) => $r->whereIn('role', Tiers::ROLES_VALIDATION)))->get();
    }
}
