<?php

namespace App\Services\LectureTicket;

use App\Models\PieceJointe;

/**
 * Lecteur de ticket carburant (US-BC-09). Le fournisseur de lecture n'est pas encore choisi (OP-BC-5,
 * décision Q8) : le lecteur se choisit par configuration (services.lecture_tickets.lecteur).
 */
interface LecteurTicket
{
    /** Nom enregistré avec chaque lecture */
    public function nom(): string;

    /**
     * Valeurs lues et confiance (0 à 100) par champ de LectureTicket::CHAMPS ;
     * null si la lecture automatique est indisponible (le panneau s'ouvre alors avec des champs vides).
     *
     * @return array{valeurs: array<string, mixed>, confiances: array<string, int>}|null
     */
    public function lire(PieceJointe $piece): ?array;
}
