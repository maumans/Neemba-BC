<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Statut ANNULE (RG-BC-31) : un bon au statut Brouillon ou Rejeté peut être annulé ;
 * il n'est plus modifiable et son numéro n'est jamais réutilisé.
 */
return new class extends Migration
{
    private const STATUTS = [
        'BROUILLON',
        'EN_ATTENTE_CHEF_SERVICE',
        'EN_ATTENTE_CDG',
        'EN_ATTENTE_DAF',
        'EN_ATTENTE_DP',
        'APPROUVE',
        'PAYE',
        'REJETE',
        'EN_ATTENTE_REGULARISATION',
        'REGULARISE',
        'ARCHIVE',
    ];

    public function up(): void
    {
        $this->definirStatuts([...self::STATUTS, 'ANNULE']);
    }

    public function down(): void
    {
        /* Un bon annulé redevient rejeté : c'est le seul autre statut d'où l'on peut l'annuler qui garde son numéro */
        DB::table('bons_caisse')->where('statut', 'ANNULE')->update(['statut' => 'REJETE']);
        $this->definirStatuts(self::STATUTS);
    }

    private function definirStatuts(array $statuts): void
    {
        $valeurs = implode(', ', array_map(fn ($s) => "'{$s}'", $statuts));
        DB::statement("ALTER TABLE bons_caisse MODIFY COLUMN statut ENUM({$valeurs}) NOT NULL DEFAULT 'BROUILLON'");
    }
};
