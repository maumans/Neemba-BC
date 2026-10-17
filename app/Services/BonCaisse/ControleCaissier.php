<?php

namespace App\Services\BonCaisse;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\Delegation;
use App\Models\User;

/**
 * Qui peut payer un bon (spec v2.3) :
 * - RG-M06-01, RG-M08-12 : le caissier ne paie que les bons de son site et, si la caisse payeuse a un gestionnaire
 *   désigné, seulement s'il en est le gestionnaire, le suppléant, ou leur délégué pour le paiement ;
 * - RG-M01-04 : un caissier ne paie jamais un bon dont il est le bénéficiaire.
 */
final class ControleCaissier
{
    /** Motif du refus, ou null si le paiement est permis */
    public static function refus(BonCaisse $bon, User $caissier, ?Caisse $caisse = null): ?string
    {
        if (!$caissier->peutPayer()) {
            return 'Seul un caissier peut effectuer un paiement.';
        }
        if ($bon->beneficiaire_id === $caissier->id) {
            return 'Vous êtes le bénéficiaire de ce bon : un autre caissier doit le payer (RG-M01-04).';
        }
        if ($caissier->site && $bon->site && $caissier->site !== $bon->site && !$caissier->aLeRole('administrateur')) {
            return "Ce bon relève du site {$bon->site} : seul un caissier de ce site peut le payer (RG-M06-01).";
        }
        if ($caisse && ($caisse->gestionnaire_id || $caisse->suppleant_id) && !self::tientLaCaisse($caisse, $caissier)) {
            return "Vous n'êtes ni le gestionnaire ni le suppléant de la {$caisse->libelle} (RG-M08-12).";
        }

        return null;
    }

    /** Gestionnaire ou suppléant de la caisse, ou délégué pour le paiement de l'un d'eux */
    private static function tientLaCaisse(Caisse $caisse, User $caissier): bool
    {
        $titulaires = array_filter([$caisse->gestionnaire_id, $caisse->suppleant_id]);
        if (in_array($caissier->id, $titulaires, true)) {
            return true;
        }

        return Delegation::actives()->where('delegue_id', $caissier->id)->whereIn('delegant_id', $titulaires)->get()
            ->contains(fn (Delegation $d) => $d->autorise('paiement'));
    }
}
