<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Visa et rejet d'un ODM (RG-M12-11, RG-M12-12, RG-M12-25).
 *
 * - Visa : l'étape en cours est visée (par un titulaire, ou un suppléant « au titre de » ce titulaire) ;
 *   l'étape suivante passe en attente. Après le dernier visa, l'ODM est « Validé » : le calcul est figé
 *   (barèmes enregistrés avec l'ODM), et le demandeur reçoit MSG-M12-08.
 * - Rejet : motif obligatoire ; l'ODM revient au demandeur, garde son numéro, est modifiable et resoumis
 *   (nouvelle version du circuit).
 */
final class ValiderOdm
{
    public const LONGUEUR_MIN_MOTIF = 10;

    public static function viser(OrdreMission $odm, User $valideur, ?string $commentaire = null): OrdreMission
    {
        [$odm, $etape, $suivante] = DB::transaction(function () use ($odm, $valideur, $commentaire) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();
            $droit = CircuitOdm::peutViser($odm, $valideur);
            if (!$droit) {
                throw new ErreurMetier('VISA_INTERDIT', 'MSG-APP-023', [], 'RG-M12-11', null, 403);
            }

            $etape = $droit['etape'];
            $etape->update([
                'statut' => 'validee',
                'valideur_id' => $valideur->id,
                'au_titre_de_id' => $droit['au_titre_de']?->id,
                'date_decision' => now(),
                'commentaire' => filled($commentaire) ? trim($commentaire) : null,
            ]);

            $statutAvant = $odm->statut;
            $suivante = CircuitOdm::activerSuivante($odm);
            $auTitreDe = $droit['au_titre_de'] ? " (au titre de {$droit['au_titre_de']->nom_complet})" : '';

            if ($suivante) {
                $odm->update(['statut' => 'EN_VALIDATION']);
                HistoriqueOdm::enregistrer($odm, 'visa', $statutAvant, 'EN_VALIDATION', $valideur->id,
                    "Visa {$etape->libelle}{$auTitreDe}." . (filled($commentaire) ? ' ' . trim($commentaire) : ''));
            } else {
                self::valider($odm, $valideur, $etape->libelle . $auTitreDe, $commentaire, $statutAvant);
            }

            return [$odm->fresh(), $etape, $suivante];
        });

        if ($suivante) {
            NotificationsOdm::aViser($odm, $suivante, $valideur);
        } else {
            NotificationsOdm::valide($odm, $valideur);
            /* RG-M12-19 : une incohérence nuits / jours sur la mission est signalée au DAF */
            $incoherences = VueMission::pour($odm)['incoherences'];
            if ($incoherences) {
                NotificationsOdm::incoherence($odm, $incoherences);
            }
        }

        return $odm;
    }

    public static function rejeter(OrdreMission $odm, User $valideur, ?string $motif): OrdreMission
    {
        $motif = trim((string) $motif);
        if (mb_strlen($motif) < self::LONGUEUR_MIN_MOTIF) {
            throw new ErreurMetier('MOTIF_REJET', 'MSG-APP-024', [], 'RG-M12-12', 'motif');
        }

        $odm = DB::transaction(function () use ($odm, $valideur, $motif) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();
            $droit = CircuitOdm::peutViser($odm, $valideur);
            if (!$droit) {
                throw new ErreurMetier('VISA_INTERDIT', 'MSG-APP-023', [], 'RG-M12-11', null, 403);
            }

            $droit['etape']->update([
                'statut' => 'rejetee',
                'valideur_id' => $valideur->id,
                'au_titre_de_id' => $droit['au_titre_de']?->id,
                'date_decision' => now(),
                'commentaire' => $motif,
            ]);
            $odm->etapes()->where('version', $odm->version)->where('statut', 'a_venir')->update(['statut' => 'annulee']);

            $statutAvant = $odm->statut;
            /* Barèmes libérés : l'ODM corrigé sera recalculé avec ceux en vigueur à sa nouvelle soumission (RG-M12-07) */
            $odm->update(['statut' => 'REJETE', 'parametres_figes' => null, 'cle_soumission' => null]);
            HistoriqueOdm::enregistrer($odm, 'rejet', $statutAvant, 'REJETE', $valideur->id,
                "Rejet au niveau {$droit['etape']->libelle} : {$motif}");

            return $odm->fresh();
        });

        NotificationsOdm::rejete($odm, $valideur, $motif);

        return $odm;
    }

    /** RG-M12-25 : validation finale, calcul figé */
    private static function valider(OrdreMission $odm, User $valideur, string $niveau, ?string $commentaire, string $statutAvant): void
    {
        $figes = $odm->parametres_figes ?? [];
        $figes['baremes'] ??= CalculOdm::baremesEnVigueur();
        $figes['valide_le'] = now()->toIso8601String();
        $odm->update(['parametres_figes' => $figes]);
        EnregistrementOdm::recalculer($odm->fresh());

        /* RG-M12-15, Q49 : « à refacturer » dès qu'une ligne du client est avancée par Neemba */
        $odm->refresh();
        $odm->update(['statut' => 'VALIDE', 'date_validation' => now(), 'a_refacturer' => (float) $odm->montant_a_refacturer > 0]);
        HistoriqueOdm::enregistrer($odm, 'validation', $statutAvant, 'VALIDE', $valideur->id,
            "Visa {$niveau} : ordre de mission validé ; calcul figé." . (filled($commentaire) ? ' ' . trim($commentaire) : ''),
            ['total' => $odm->fresh()->total, 'baremes' => $figes['baremes']]);
    }
}
