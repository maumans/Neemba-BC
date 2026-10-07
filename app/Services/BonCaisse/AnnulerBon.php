<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\HistoriqueAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Annulation d'un bon (US-BC-15, RG-BC-31) : seulement au statut Brouillon ou Rejeté, avec un motif.
 * Le bon n'est plus modifiable ; s'il avait un numéro, ce numéro n'est jamais réutilisé
 * (le compteur ne revient pas en arrière). Sans auteur : annulation automatique d'un brouillon abandonné (RG-BC-26).
 */
class AnnulerBon
{
    public static function executer(BonCaisse $bon, ?User $auteur, string $motif): BonCaisse
    {
        return DB::transaction(function () use ($bon, $auteur, $motif) {
            $bon = BonCaisse::whereKey($bon->id)->lockForUpdate()->firstOrFail();

            if (!in_array($bon->statut, ['BROUILLON', 'REJETE'], true)) {
                throw new ErreurMetier('ANNULATION_IMPOSSIBLE', 'MSG-BC-034', [], 'RG-BC-31', null, 409);
            }

            $statutAvant = $bon->statut;
            $bon->statut = 'ANNULE';
            $bon->motif_annulation = $motif;
            $bon->date_annulation = now();
            $bon->save();

            HistoriqueAction::enregistrer($bon, HistoriqueAction::ACTION_ANNULATION, $statutAvant, 'ANNULE', $auteur?->id, $motif);

            return $bon;
        });
    }
}
