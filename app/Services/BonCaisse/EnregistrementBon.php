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

        /*
         * RG-M03-03 (spec v2.3) : bénéficiaire repris d'un référentiel. Salarié : nom et téléphone du profil.
         * Visiteur du groupe ou tiers : nom du référentiel ; téléphone pré-rempli (n° OM, sinon téléphone) au choix,
         * modifiable ensuite. Les anciens types (saisie libre de la SFD v1.3) gardent leur texte.
         */
        $type = $bon->type_beneficiaire;
        if ($type !== 'visiteur') {
            $bon->visiteur_id = null;
        }
        if ($type !== 'tiers') {
            $bon->tiers_id = null;
        }
        if ($type === 'employe') {
            $employe = $bon->beneficiaire_id ? User::find($bon->beneficiaire_id) : null;
            $bon->beneficiaire = $employe?->nom_complet;
            $bon->telephone_beneficiaire = ReglesSaisie::normaliserTelephone($employe?->telephone);
        } elseif (in_array($type, ['visiteur', 'tiers'], true)) {
            $bon->beneficiaire_id = null;
            $choisi = $type === 'visiteur'
                ? ($bon->visiteur_id ? \App\Models\Visiteur::find($bon->visiteur_id) : null)
                : ($bon->tiers_id ? \App\Models\Tiers::find($bon->tiers_id) : null);
            $bon->beneficiaire = $choisi ? ($type === 'visiteur' ? $choisi->nom_complet : $choisi->nom) : null;
            $prerempli = $choisi ? ($choisi->numero_om ?? ($type === 'tiers' ? $choisi->telephone : null)) : null;
            if ($bon->isDirty(['visiteur_id', 'tiers_id', 'type_beneficiaire']) && !array_key_exists('telephone_beneficiaire', $saisie)) {
                $bon->telephone_beneficiaire = $prerempli;
            }
            $bon->telephone_beneficiaire = ReglesSaisie::normaliserTelephone($bon->telephone_beneficiaire ?: $prerempli);
        } else {
            $bon->beneficiaire_id = null;
            $bon->telephone_beneficiaire = ReglesSaisie::normaliserTelephone($bon->telephone_beneficiaire);
        }

        /* RG-M03-28 (v2.4) : un bon au profit d'un visiteur du groupe est marqué « refacturation inter-filiale », avec la
         * filiale d'origine du visiteur ; ailleurs, motif et commentaire du bon visiteur sont effacés */
        if ($type === 'visiteur') {
            $bon->refacturation_inter_filiale = true;
            $bon->filiale_refacturation = $bon->visiteur_id ? \App\Models\Visiteur::find($bon->visiteur_id)?->filiale : null;
        } else {
            $bon->refacturation_inter_filiale = false;
            $bon->filiale_refacturation = null;
            $bon->motif_visiteur = null;
            $bon->commentaire_visiteur = null;
        }

        /* RG-BC-13 : véhicule en majuscules, OR sans doublon */
        if ($bon->vehicule !== null) {
            $bon->vehicule = mb_strtoupper(trim($bon->vehicule)) ?: null;
        }
        /* RG-M03-10 : n° OM du référentiel du bénéficiaire, au format +224 ; effacé hors Orange Money */
        if ($bon->mode_paiement === 'orange_money') {
            $bon->numero_om = ReglesSaisie::normaliserTelephone($bon->numero_om) ?? ReglesSaisie::numeroOmReferentiel(ReglesSaisie::donneesDu($bon));
            if (!ReglesSaisie::numeroOmModifie(ReglesSaisie::donneesDu($bon))) {
                $bon->motif_numero_om = null;
            }
        } else {
            $bon->numero_om = null;
            $bon->motif_numero_om = null;
        }

        /* RG-M03-13 : un type par OR présent */
        if (is_array($bon->types_or)) {
            $bon->types_or = array_intersect_key($bon->types_or, array_flip((array) $bon->references_or)) ?: null;
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

        /* Lot C (RG-M03-11) : caisse émettrice = caisse de rattachement du titulaire, non modifiable */
        $titulaire = $bon->demandeur_id ? User::find($bon->demandeur_id) : null;
        $emettrice = Caisse::deRattachement($titulaire);
        $bon->caisse_emettrice_id = $emettrice?->id;
        $bon->setRelation('caisseEmettrice', $emettrice);
        $bon->setRelation('demandeur', $titulaire);
        /* Spec v2.4, RG-M03-27 : site analytique, par défaut celui du titulaire, modifiable à la saisie (un changement de
         * titulaire sans site choisi reprend le site du nouveau titulaire) */
        if (blank($bon->site) || ($bon->isDirty('demandeur_id') && !array_key_exists('site', $saisie))) {
            $bon->site = $titulaire?->site ?: ($emettrice?->sites->first()?->nom ?? $bon->site);
        }

        /* RG-BC-12 : caisse payeuse prévue (le paiement inscrit la caisse réellement débitée) */
        $bon->caisse_id = $bon->site && $bon->mode_paiement ? $bon->caissePayeuse()?->id : null;

        $bon->save();

        return $bon;
    }
}
