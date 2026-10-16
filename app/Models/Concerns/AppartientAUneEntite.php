<?php

namespace App\Models\Concerns;

use App\Models\Entite;
use App\Support\EntiteCourante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Donnée rattachée à une entité (spec v2.4, RG-M01-02, RG-M01-08).
 *
 * - Création : l'entité est celle que donne `entiteParDefaut()` (par défaut : l'entité courante, sinon Neemba Guinée).
 * - Lecture : une portée globale ne montre à l'utilisateur connecté que les données de son entité courante ; un
 *   référentiel « commun » (entité vide) est visible de toutes. Pas de filtre en vue consolidée ni hors session
 *   (commandes) : `withoutGlobalScope('entite')` lève le filtre au besoin.
 *
 * Le modèle qui l'utilise peut déclarer `protected bool $entiteCommune = true` (référentiels communs possibles).
 */
trait AppartientAUneEntite
{
    public static function bootAppartientAUneEntite(): void
    {
        static::addGlobalScope('entite', function (Builder $requete) {
            $entite = EntiteCourante::filtre();
            if ($entite === null) {
                return;
            }
            $modele = $requete->getModel();
            $colonne = $modele->qualifyColumn('entite_id');
            $requete->where(fn ($q) => $q->where($colonne, $entite)
                ->when($modele->entiteCommunePossible(), fn ($q) => $q->orWhereNull($colonne)));
        });

        /* Entité non précisée : celle par défaut. Une entité vide donnée explicitement (référentiel commun) est gardée */
        static::creating(function ($modele) {
            $donnee = array_key_exists('entite_id', $modele->getAttributes());
            if (!$donnee || ($modele->entite_id === null && !$modele->entiteCommunePossible())) {
                $modele->entite_id = $modele->entiteParDefaut();
            }
        });
    }

    public function entite(): BelongsTo
    {
        return $this->belongsTo(Entite::class, 'entite_id');
    }

    /** Entité d'une nouvelle donnée : l'entité courante de l'utilisateur, sinon Neemba Guinée */
    public function entiteParDefaut(): ?int
    {
        return EntiteCourante::filtre() ?? Entite::idParDefaut();
    }

    /** Données d'une entité donnée, sans la portée de l'utilisateur connecté (null : toutes) */
    public function scopeDeLEntite(Builder $requete, ?int $entite): Builder
    {
        return $requete->withoutGlobalScope('entite')
            ->when($entite !== null, fn ($q) => $q->where(fn ($w) => $w->where($this->qualifyColumn('entite_id'), $entite)
                ->when($this->entiteCommunePossible(), fn ($c) => $c->orWhereNull($this->qualifyColumn('entite_id')))));
    }

    /** Un référentiel peut être commun à toutes les entités (entité vide) */
    public function entiteCommunePossible(): bool
    {
        return property_exists($this, 'entiteCommune') && $this->entiteCommune;
    }
}
