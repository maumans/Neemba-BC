<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Soumission d'un ODM (spec v2.2, §7.3 ; RG-M12-03, RG-M12-12, RG-M12-24).
 *
 * Dans une seule transaction, ODM verrouillé :
 * - les informations des participants sont relues dans le référentiel, le calcul refait ;
 * - les contrôles bloquants sont refaits (ReglesOdm) : un échec refuse la soumission sans consommer de numéro ;
 * - le numéro N°[séquence]/[préfixe]/[AA] est attribué ; un ODM rejeté garde le sien et passe à la version suivante ;
 * - les étapes du circuit sont créées, statut « Soumis ».
 * La même clé d'idempotence rejouée renvoie l'ODM déjà soumis (double clic).
 * Liste de diffusion du service et premiers valideurs notifiés après la transaction.
 */
final class SoumettreOdm
{
    public static function executer(OrdreMission $odm, User $auteur, ?string $cleIdempotence = null): OrdreMission
    {
        [$odm, $nouvelle] = DB::transaction(function () use ($odm, $auteur, $cleIdempotence) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();

            if ($cleIdempotence && $odm->cle_soumission === $cleIdempotence && !in_array($odm->statut, ['BROUILLON', 'REJETE'], true)) {
                return [$odm, false];
            }
            if (!in_array($odm->statut, ['BROUILLON', 'REJETE'], true)) {
                throw new ErreurMetier('ODM_DEJA_SOUMIS', 'MSG-APP-019', [], 'RG-M12-03', null, 409);
            }

            /* RG-M12-07 : barèmes en vigueur à la soumission, conservés jusqu'à la validation finale (RG-M12-25) */
            $odm->update(['parametres_figes' => ['baremes' => CalculOdm::baremesEnVigueur(), 'soumis_le' => now()->toIso8601String()]]);
            EnregistrementOdm::actualiserParticipants($odm);
            EnregistrementOdm::recalculer($odm);

            $erreurs = ReglesOdm::erreursSoumission($odm);
            if ($erreurs) {
                $premiere = $erreurs[0];
                throw new ErreurMetier($premiere['code'], $premiere['message_cle'], $premiere['valeurs'], $premiere['regle'], $premiere['champ'], 422, [
                    'erreurs' => $erreurs,
                    'chevauchement' => ReglesOdm::aUnChevauchement($erreurs),
                ]);
            }

            $statutAvant = $odm->statut;
            $resoumission = $statutAvant === 'REJETE';
            if (!$odm->numero) {
                $numero = NumeroteurOdm::prochain(NumeroteurOdm::prefixePour($odm->service));
                $odm->fill(['numero' => $numero['numero'], 'prefixe' => $numero['prefixe'], 'sequence' => $numero['sequence'], 'annee' => $numero['annee']]);
            }
            if ($resoumission) {
                $odm->version = $odm->version + 1;
            }
            $odm->fill([
                'statut' => 'SOUMIS',
                'date_soumission' => now(),
                'cle_soumission' => $cleIdempotence,
            ])->save();

            CircuitOdm::demarrer($odm);

            HistoriqueOdm::enregistrer($odm, 'soumission', $statutAvant, 'SOUMIS', $auteur->id,
                $resoumission ? "Ordre de mission corrigé et resoumis (version {$odm->version})." : "Ordre de mission soumis : n° {$odm->numero}.",
                ['total' => $odm->total, 'participants' => $odm->participantsActifs()->count()]);

            return [$odm, true];
        });

        if ($nouvelle) {
            NotificationsOdm::soumission($odm->fresh(), $auteur);
        }

        return $odm->fresh();
    }
}
