<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\Caisse;
use App\Models\HistoriqueOdm;
use App\Models\ParticipantOdm;
use App\Models\User;
use App\Support\Format;
use Illuminate\Support\Facades\DB;

/**
 * Régularisation du trop-perçu d'un retour anticipé (RG-M12-20, MSG-M12-09) :
 * - reversement en caisse : le caissier encaisse le montant, inscrit au registre de la caisse espèces du site
 *   (à défaut, caisse principale de Conakry) ;
 * - retenue sur salaire : les RH confirment que la retenue est faite.
 */
final class RegulariserTropPercu
{
    public static function peutRegulariser(ParticipantOdm $participant, User $utilisateur): bool
    {
        if ($participant->regularisation_statut !== 'a_regulariser') {
            return false;
        }

        return $participant->regularisation === 'retenue' ? $utilisateur->aLeRole('rh') : $utilisateur->peutPayer();
    }

    public static function executer(ParticipantOdm $participant, User $utilisateur): ParticipantOdm
    {
        return DB::transaction(function () use ($participant, $utilisateur) {
            $participant = ParticipantOdm::whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if (!self::peutRegulariser($participant, $utilisateur)) {
                throw new ErreurMetier('REGULARISATION_INTERDITE', 'MSG-APP-040', [], 'RG-M12-20', null, 403);
            }

            $odm = $participant->ordreMission;
            $montant = (float) $participant->trop_percu;
            if ($participant->regularisation === 'reversement') {
                $caisse = Caisse::payeusePour((string) $odm->site, 'especes');
                if (!$caisse) {
                    throw new ErreurMetier('CAISSE_INTROUVABLE', 'MSG-APP-041', [], 'RG-M12-20', null, 409);
                }
                $caisse->crediter($montant, 'reversement_odm', [
                    'utilisateur_id' => $utilisateur->id,
                    'libelle' => "Reversement du trop-perçu de {$participant->nom} — ODM {$odm->numero}",
                ]);
                $detail = 'reversé en caisse (' . $caisse->libelle . ')';
            } else {
                $detail = 'retenu sur salaire';
            }

            $participant->update(['regularisation_statut' => 'regularise', 'regularise_le' => now(), 'regularise_par_id' => $utilisateur->id]);
            HistoriqueOdm::enregistrer($odm, 'cloture', $odm->statut, $odm->statut, $utilisateur->id,
                'Trop-perçu de ' . Format::montant($montant) . " de {$participant->nom} {$detail}.");

            return $participant;
        });
    }
}
