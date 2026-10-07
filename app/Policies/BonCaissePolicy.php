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
        return $this->estAuteur($utilisateur, $bon) && in_array($bon->statut, ['BROUILLON', 'REJETE'], true);
    }

    /** Le statut est vérifié par la soumission elle-même (idempotence d'un double clic) */
    public function soumettre(User $utilisateur, BonCaisse $bon): bool
    {
        return $this->estAuteur($utilisateur, $bon);
    }

    /** Le statut est vérifié par l'annulation (409 MSG-BC-034 si le bon est déjà en validation) */
    public function annuler(User $utilisateur, BonCaisse $bon): bool
    {
        return $this->estAuteur($utilisateur, $bon);
    }

    private function estAuteur(User $utilisateur, BonCaisse $bon): bool
    {
        return $bon->demandeur_id === $utilisateur->id || $bon->initiateur_id === $utilisateur->id;
    }
}
