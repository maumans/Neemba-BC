<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal d'un ODM : chaque événement (création, soumission, visa, rejet, prolongation, génération des bons,
 * clôture, annulation, dérogation) est tracé et daté. Il remplace le fil d'e-mails « Neemba Service_Mission ».
 */
class HistoriqueOdm extends Model
{
    protected $table = 'odm_historique';

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'creation' => 'Création',
        'modification' => 'Modification',
        'soumission' => 'Soumission',
        'visa' => 'Visa',
        'rejet' => 'Rejet',
        'validation' => 'Validation finale',
        'derogation_demandee' => 'Dérogation demandée',
        'derogation_accordee' => 'Dérogation accordée',
        'derogation_refusee' => 'Dérogation refusée',
        'generation_bons' => 'Génération des bons',
        'paiement' => 'Paiement',
        'prolongation' => 'Prolongation',
        'cloture' => 'Clôture',
        'annulation' => 'Annulation',
        'rappel' => 'Rappel',
    ];

    protected $fillable = ['ordre_mission_id', 'action', 'statut_avant', 'statut_apres', 'utilisateur_id', 'commentaire', 'metadonnees'];

    protected function casts(): array
    {
        return [
            'metadonnees' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ordreMission(): BelongsTo
    {
        return $this->belongsTo(OrdreMission::class, 'ordre_mission_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    public static function enregistrer(
        OrdreMission $odm,
        string $action,
        ?string $statutAvant = null,
        ?string $statutApres = null,
        ?int $utilisateurId = null,
        ?string $commentaire = null,
        array $metadonnees = [],
    ): self {
        return static::create([
            'ordre_mission_id' => $odm->id,
            'action' => $action,
            'statut_avant' => $statutAvant,
            'statut_apres' => $statutApres,
            'utilisateur_id' => $utilisateurId,
            'commentaire' => $commentaire,
            'metadonnees' => $metadonnees ?: null,
        ]);
    }
}
