<?php

namespace App\Services\BonCaisse;

use App\Models\BonCaisse;
use App\Models\User;

/**
 * Qui peut ouvrir la fiche d'un bon de caisse (règle reprise de BonCaisseController::show, cohérente avec la liste).
 * Sert aussi à l'ordre de mission : qui peut ouvrir un bon généré depuis un ODM peut consulter cet ODM, son justificatif.
 */
final class VisibiliteBon
{
    public static function peutVoir(BonCaisse $bon, User $utilisateur): bool
    {
        /* Restriction de visibilité cohérente avec index() (strict + suivi) :
         * 1. Propriétaire (demandeur ou initiateur du bon) → toujours autorisé
         * 2. Administrateur → accès global sauf brouillons des autres
         * 3. Validateur → bon en attente à son niveau OU bon déjà validé par lui
         * 4. Caissier → bons à payer/régulariser de son site
         */
        $estProprietaire = $bon->demandeur_id === $utilisateur->id || $bon->initiateur_id === $utilisateur->id;

        if (!$estProprietaire) {
            $roles = array_unique(array_merge([$utilisateur->role], method_exists($utilisateur, 'rolesValidationEffectifs') ? $utilisateur->rolesValidationEffectifs() : []));
            $autorise = false;

            /* Administrateur : accès global sauf brouillons */
            if (in_array('administrateur', $roles) && $bon->statut !== 'BROUILLON') {
                $autorise = true;
            }

            /* Bénéficiaire (RG-BC-32) : consultation dès la soumission */
            if ($bon->beneficiaire_id === $utilisateur->id && $bon->statut !== 'BROUILLON') {
                $autorise = true;
            }

            /* M16 : la Comptabilité consulte les bons payés qu'elle impute */
            if ($utilisateur->aLeRole(\App\Models\Imputation::ROLES) && in_array($bon->statut, \App\Services\Odm\PaiementOdm::STATUTS_PAYES, true)) {
                $autorise = true;
            }

            /* Vérifier si le bon est déjà validé par cet utilisateur (suivi) */
            if (!$autorise) {
                $dejaValide = \App\Models\Validation::where('bon_caisse_id', $bon->id)
                    ->where('validateur_id', $utilisateur->id)
                    ->whereIn('statut', ['approuve', 'rejete'])
                    ->exists();
                if ($dejaValide) {
                    $autorise = true;
                }
            }

            /* Vérifier si le bon est en attente au niveau du validateur */
            if (!$autorise) {
                $roleStatutMap = [
                    'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
                    'controle_gestion' => 'EN_ATTENTE_CDG',
                    'daf' => 'EN_ATTENTE_DAF',
                    'directeur_pays' => 'EN_ATTENTE_DP',
                ];

                foreach ($roles as $r) {
                    if (!isset($roleStatutMap[$r])) continue;

                    if ($bon->statut === $roleStatutMap[$r]) {
                        /* Chef de service : restreindre aux services accessibles */
                        if ($r === 'responsable_service') {
                            if (in_array($bon->service, CircuitBon::servicesChef($utilisateur))) {
                                $autorise = true;
                                break;
                            }
                        } else {
                            $autorise = true;
                            break;
                        }
                    }
                }
            }

            /* Caissier : bons de son site à payer/régulariser */
            if (!$autorise && in_array('caissier', $roles)) {
                if (in_array($bon->statut, ['APPROUVE', 'PAYE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE', 'ARCHIVE'])
                    && (!$utilisateur->site || $bon->site === $utilisateur->site)) {
                    $autorise = true;
                }
            }

            return $autorise;
        }

        return true;
    }
}
