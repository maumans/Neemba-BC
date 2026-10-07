<?php

namespace App\Services\BonCaisse;

use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\User;

/**
 * Création et enregistrement d'un bon depuis l'assistant (US-BC-01, US-BC-11).
 *
 * - Le brouillon est créé au premier « Suivant » ou « Brouillon », jamais à l'ouverture (RG-BC-01).
 * - Valeurs par défaut (RG-BC-02) : site et service du demandeur, urgence normale, bénéficiaire employé = demandeur,
 *   mode espèces.
 * - Règles appliquées à l'enregistrement : bénéficiaire employé repris du référentiel (RG-BC-06), urgence normale
 *   = motif et justification effacés (US-BC-03), mission seulement pour un BP (RG-BC-14), caisse payeuse (RG-BC-12),
 *   téléphone au format +224, véhicule en majuscules, numéros d'OR sans doublon.
 */
class EnregistrementBon
{
    public static function creer(User $demandeur, array $saisie): BonCaisse
    {
        $bon = new BonCaisse([
            'statut' => 'BROUILLON',
            'demandeur_id' => $demandeur->id,
            'initiateur_id' => $demandeur->id,
            'date_demande' => today(),
            'devise' => 'GNF',
            'version' => 1,
            /* RG-BC-02 */
            'site' => $demandeur->site,
            'service' => $demandeur->service,
            'niveau_urgence' => 'normale',
            'type_beneficiaire' => 'employe',
            'beneficiaire_id' => $demandeur->id,
            'mode_paiement' => 'especes',
        ]);

        self::appliquer($bon, $saisie);
        $bon->enregistrerCreation();

        return $bon;
    }

    public static function appliquer(BonCaisse $bon, array $saisie): BonCaisse
    {
        $bon->fill(array_intersect_key($saisie, array_flip(ReglesSaisie::champs())));

        /* US-BC-03 : retour à « Normale » → motif et justification effacés */
        if (($bon->niveau_urgence ?? 'normale') === 'normale') {
            $bon->motif_urgence = null;
            $bon->justification_urgence = null;
        }

        /* RG-BC-06 / RG-BC-07 : employé repris du référentiel, tiers saisi librement */
        if ($bon->type_beneficiaire === 'employe') {
            $employe = $bon->beneficiaire_id ? User::find($bon->beneficiaire_id) : null;
            $bon->beneficiaire = $employe?->nom_complet;
            $bon->telephone_beneficiaire = ReglesSaisie::normaliserTelephone($employe?->telephone);
        } else {
            $bon->beneficiaire_id = null;
            $bon->telephone_beneficiaire = ReglesSaisie::normaliserTelephone($bon->telephone_beneficiaire);
        }

        /* RG-BC-13 : véhicule en majuscules, OR sans doublon */
        if ($bon->vehicule !== null) {
            $bon->vehicule = mb_strtoupper(trim($bon->vehicule)) ?: null;
        }
        if (is_array($bon->references_or)) {
            $bon->references_or = array_values(array_unique(array_map('trim', $bon->references_or))) ?: null;
        }

        /* RG-BC-14 : mission seulement pour un BP */
        if ($bon->type_bon !== 'BP') {
            $bon->lie_mission = false;
        }
        if (!$bon->lie_mission) {
            $bon->date_retour_mission = null;
            $bon->odm_id = null;
        }

        /* RG-BC-12 : caisse payeuse prévue (le paiement inscrit la caisse réellement débitée) */
        $bon->caisse_id = $bon->site && $bon->mode_paiement
            ? Caisse::payeusePour($bon->site, $bon->mode_paiement)?->id
            : null;

        $bon->save();

        return $bon;
    }
}
