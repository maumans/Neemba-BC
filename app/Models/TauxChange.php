<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Taux de change FCFA → GNF (spec v2.2, §6.6 et RG-M12-10) : nombre de GNF pour 1 FCFA,
 * saisi chaque jour par la Trésorerie d'après le taux communiqué par la banque (PO-08).
 *
 * - duJour() : taux du jour, exigé pour payer un ODM extérieur (sans lui, MSG-M12-05) ;
 * - dernier() : dernier taux saisi, qui sert à estimer le montant d'un ODM extérieur pour son circuit.
 */
class TauxChange extends Model
{
    protected $table = 'taux_change';

    public const DEVISE_FCFA = 'XOF';

    /** Rôles qui saisissent le taux du jour : la Trésorerie, et le DAF ou son adjoint en secours */
    public const ROLES_SAISIE = ['tresorerie', 'daf', 'daf_adjoint'];

    /** Rôles qui consultent l'historique des taux */
    public const ROLES_CONSULTATION = ['tresorerie', 'daf', 'daf_adjoint', 'chef_comptable', 'directeur_pays', 'caissier', 'administrateur'];

    protected $fillable = [
        'date_taux',
        'devise',
        'taux',
        'saisi_par_id',
        'commentaire',
    ];

    protected function casts(): array
    {
        return [
            'date_taux' => 'date',
            'taux' => 'decimal:4',
        ];
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par_id');
    }

    public static function duJour(string $devise = self::DEVISE_FCFA): ?self
    {
        return static::where('devise', $devise)->whereDate('date_taux', today())->first();
    }

    public static function dernier(string $devise = self::DEVISE_FCFA): ?self
    {
        return static::where('devise', $devise)->whereDate('date_taux', '<=', today())->orderByDesc('date_taux')->first();
    }

    /** Conversion d'un montant en FCFA, arrondie au franc guinéen le plus proche */
    public function convertir(float $montantFcfa): int
    {
        return (int) round($montantFcfa * (float) $this->taux);
    }
}
