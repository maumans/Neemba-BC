<?php

namespace App\Services\LectureTicket;

use App\Models\PieceJointe;

/**
 * Lecteur par défaut (décision Q8) : aucune image ne quitte le serveur. Le panneau s'ouvre avec des champs vides
 * et la mention « Lecture automatique indisponible » ; l'utilisateur saisit les valeurs du ticket.
 */
class LecteurManuel implements LecteurTicket
{
    public function nom(): string
    {
        return 'manuel';
    }

    public function lire(PieceJointe $piece): ?array
    {
        return null;
    }
}
