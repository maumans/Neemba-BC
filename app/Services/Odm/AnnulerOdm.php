<?php

namespace App\Services\Odm;

use App\Exceptions\ErreurMetier;
use App\Models\HistoriqueOdm;
use App\Models\OrdreMission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un ODM par son demandeur (RG-M12-22) : possible tant qu'aucun bon n'est généré.
 * Au-delà, l'annulation relève du DAF (lot M12-6). Les étapes encore ouvertes du circuit sont closes.
 */
final class AnnulerOdm
{
    public const STATUTS_ANNULABLES_PAR_LE_DEMANDEUR = ['BROUILLON', 'SOUMIS', 'EN_VALIDATION', 'REJETE', 'VALIDE'];

    public static function parLeDemandeur(OrdreMission $odm, User $auteur, ?string $motif): OrdreMission
    {
        return DB::transaction(function () use ($odm, $auteur, $motif) {
            $odm = OrdreMission::whereKey($odm->id)->lockForUpdate()->firstOrFail();

            if (!in_array($auteur->id, [$odm->demandeur_id, $odm->initiateur_id], true)) {
                throw new ErreurMetier('ANNULATION_INTERDITE', 'MSG-APP-020', [], 'RG-M12-22', null, 403);
            }
            if (!in_array($odm->statut, self::STATUTS_ANNULABLES_PAR_LE_DEMANDEUR, true) || $odm->bons()->exists()) {
                throw new ErreurMetier('ANNULATION_INTERDITE', 'MSG-APP-020', [], 'RG-M12-22', null, 409);
            }

            $statutAvant = $odm->statut;
            $odm->etapes()->whereIn('statut', ['en_attente', 'a_venir'])->update(['statut' => 'annulee']);
            $odm->update([
                'statut' => 'ANNULE',
                'date_annulation' => now(),
                'annule_par_id' => $auteur->id,
                'motif_annulation' => $motif,
            ]);
            HistoriqueOdm::enregistrer($odm, 'annulation', $statutAvant, 'ANNULE', $auteur->id, $motif ?: 'Brouillon annulé par le demandeur.');

            return $odm;
        });
    }
}
