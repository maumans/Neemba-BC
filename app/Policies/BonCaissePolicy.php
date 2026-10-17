<?php

namespace App\Policies;

use App\Models\BonCaisse;
use App\Models\User;

/**
 * Droits sur un bon de caisse côté demandeur (M03). Contrôlés par le serveur : un appel direct
 * qui contourne l'écran est refusé de la même façon (SFD §1.2).
 */
class BonCaissePolicy
{
    /** US-BC-01 : rôle DEMANDEUR (TC-BC-032) */
    public function create(User $utilisateur): bool
    {
        return $utilisateur->peutInitierBon();
    }

    /** Saisie dans l'assistant : demandeur ou initiateur, bon en brouillon ou rejeté (RG-BC-30) */
    public function modifier(User $utilisateur, BonCaisse $bon): bool
    {
        /* RG-M03-22 : un bon généré depuis un ODM reprend ses champs de l'ODM, verrouillés */
        return $this->estAuteur($utilisateur, $bon) && in_array($bon->statut, ['BROUILLON', 'REJETE'], true) && !$bon->genere_par_odm;
    }

    /** Le statut est vérifié par la soumission elle-même (idempotence d'un double clic) */
    public function soumettre(User $utilisateur, BonCaisse $bon): bool
    {
        return $this->estAuteur($utilisateur, $bon);
    }

    /**
     * RG-M04-07 (spec v2.3) : le demandeur tant qu'aucun visa n'est donné, puis le DAF seulement ; le statut est
     * vérifié par l'annulation elle-même (409 MSG-BC-034).
     */
    public function annuler(User $utilisateur, BonCaisse $bon): bool
    {
        return $this->estAuteur($utilisateur, $bon) || $utilisateur->aLeRole(\App\Services\BonCaisse\AnnulerBon::ROLES_DAF);
    }

    private function estAuteur(User $utilisateur, BonCaisse $bon): bool
    {
        return $bon->demandeur_id === $utilisateur->id || $bon->initiateur_id === $utilisateur->id;
    }
}
