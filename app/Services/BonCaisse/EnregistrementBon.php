<?php

namespace App\Services\BonCaisse;

use App\Exceptions\ErreurMetier;
use App\Models\BonCaisse;
use App\Models\Caisse;
use App\Models\Delegation;
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
 * - « Pour le compte de » (US-BC-13, RG-BC-29) : le titulaire qui a délégué l'initiation devient le demandeur,
 *   l'auteur réel reste l'initiateur ; les valeurs par défaut sont celles du titulaire.
 */
class EnregistrementBon
{
    public static function creer(User $auteur, array $saisie): BonCaisse
    {
        $demandeur = self::titulaire($auteur, $saisie['demandeur_id'] ?? null);

        $bon = new BonCaisse([
            'statut' => 'BROUILLON',
            'demandeur_id' => $demandeur->id,
            'initiateur_id' => $auteur->id,
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

    /**
     * Titulaire du bon : l'auteur lui-même, ou le collègue qui lui a délégué l'initiation (délégation active).
     * Sans le rôle demandeur, l'auteur doit choisir un titulaire.
     */
    public static function titulaire(User $auteur, mixed $titulaireId): User
    {
        if ($titulaireId === null || $titulaireId === '' || (int) $titulaireId === $auteur->id) {
            if (!$auteur->aLeRole('demandeur')) {
                throw new ErreurMetier('POUR_LE_COMPTE_DE_OBLIGATOIRE', 'MSG-BC-001', [], 'RG-BC-29', 'demandeur_id');
            }

            return $auteur;
        }

        $delegation = Delegation::initiationActiveEntre((int) $titulaireId, $auteur->id);
        if (!$delegation) {
            $titulaire = User::find((int) $titulaireId);
            throw new ErreurMetier('DELEGATION_INACTIVE', 'MSG-BC-033', ['titulaire' => $titulaire?->nom_complet ?? 'ce collègue'], 'RG-BC-29', 'demandeur_id');
        }

        return $delegation->delegant;
    }

    public static function appliquer(BonCaisse $bon, array $saisie, ?User $auteur = null): BonCaisse
    {
        /* RG-M03-22 : champs d'un bon généré depuis un ODM verrouillés */
        if ($bon->genere_par_odm) {
            throw new ErreurMetier('BON_GENERE_PAR_ODM', 'MSG-APP-025', [], 'RG-M03-22', null, 409);
        }

        /* Changement de titulaire d'un brouillon par son initiateur */
        if ($auteur && $bon->exists && array_key_exists('demandeur_id', $saisie) && $bon->initiateur_id === $auteur->id) {
            $bon->demandeur_id = self::titulaire($auteur, $saisie['demandeur_id'])->id;
        }

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
            /* Un BD peut rester rattaché à son ODM (bon complémentaire d'hébergement, M12) */
            if ($bon->type_bon === 'BP') {
                $bon->odm_id = null;
            }
        }

        /* RG-BC-12 : caisse payeuse prévue (le paiement inscrit la caisse réellement débitée) */
        $bon->caisse_id = $bon->site && $bon->mode_paiement
            ? Caisse::payeusePour($bon->site, $bon->mode_paiement)?->id
            : null;

        $bon->save();

        return $bon;
    }
}
