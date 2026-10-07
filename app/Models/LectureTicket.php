<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lecture d'un ticket carburant (US-BC-09) : valeurs lues et confiance par champ, puis valeurs validées
 * par l'utilisateur. Aucune valeur lue n'est retenue sans sa confirmation (RG-BC-21) ; les champs corrigés
 * sont conservés pour mesurer la qualité de lecture (TC-BC-019).
 */
class LectureTicket extends Model
{
    protected $table = 'lectures_tickets';

    public const EN_COURS = 'en_cours';
    public const TERMINEE = 'terminee';
    public const INDISPONIBLE = 'indisponible';
    public const VALIDEE = 'validee';

    /** Champs du panneau « Lecture du ticket » (E-03.6) */
    public const CHAMPS = ['station', 'date', 'litres', 'montant', 'montant_lettres', 'immatriculation'];

    protected $fillable = [
        'piece_jointe_id',
        'statut',
        'lecteur',
        'valeurs_lues',
        'confiances',
        'valeurs_validees',
        'champs_corriges',
        'demarree_le',
        'terminee_le',
        'validee_par',
        'validee_le',
    ];

    protected function casts(): array
    {
        return [
            'valeurs_lues' => 'array',
            'confiances' => 'array',
            'valeurs_validees' => 'array',
            'champs_corriges' => 'array',
            'demarree_le' => 'datetime',
            'terminee_le' => 'datetime',
            'validee_le' => 'datetime',
        ];
    }

    public function pieceJointe(): BelongsTo
    {
        return $this->belongsTo(PieceJointe::class);
    }

    public function estValidee(): bool
    {
        return $this->statut === self::VALIDEE;
    }
}
