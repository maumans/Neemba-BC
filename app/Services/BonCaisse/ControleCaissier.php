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
        /* Lot C (RG-M02-03) : un caissier rattaché à une caisse paie les bons qu'elle émet, ceux qu'elle paie dans le mode qu'il
         * retient (RG-M06-07 : le caissier décide du mode), et les anciens bons sans caisse émettrice de son site ; un
         * caissier sans rattachement reste limité à son site */
        if ($caissier->caisse_id && !$caissier->aLeRole('administrateur')) {
            if (!self::caisseDuCaissier($bon, $caissier, $caisse)) {
                $libelle = $bon->caisseEmettrice?->libelle ?? 'caisse du site ' . $bon->site;

                return "Ce bon relève de la {$libelle} : seul un caissier de cette caisse peut le payer (RG-M06-01).";
            }
        } elseif ($caissier->site && $bon->site && $caissier->site !== $bon->site && !$caissier->aLeRole('administrateur')) {
            return "Ce bon relève du site {$bon->site} : seul un caissier de ce site peut le payer (RG-M06-01).";
        }
        if ($caisse && ($caisse->gestionnaire_id || $caisse->suppleant_id) && !self::tientLaCaisse($caisse, $caissier)) {
            return "Vous n'êtes ni le gestionnaire ni le suppléant de la {$caisse->libelle} (RG-M08-12).";
        }

        return null;
    }

    /** Le bon relève-t-il de la caisse de rattachement du caissier ? (sans mode retenu : la caisse OM paie tout bon en OM) */
    public static function caisseDuCaissier(BonCaisse $bon, User $caissier, ?Caisse $payeuse = null): bool
    {
        $saCaisse = (int) $caissier->caisse_id;
        if ((int) $bon->caisse_emettrice_id === $saCaisse || ($payeuse && (int) $payeuse->id === $saCaisse)) {
            return true;
        }
        if (!$bon->caisse_emettrice_id && $bon->site && $caissier->site === $bon->site) {
            return true;
        }

        return !$payeuse && Caisse::whereKey($saCaisse)->where('type', 'orange_money')->exists();
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
