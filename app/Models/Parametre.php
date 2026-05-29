<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Modèle Parametre - Paramètres système configurables
 * 
 * Stocke les seuils, délais et autres valeurs métier modifiables
 * par les administrateurs (DAF, Directeur Pays) sans toucher au code.
 * 
 * Les valeurs sont mises en cache pour éviter des requêtes répétées.
 */
class Parametre extends Model
{
    protected $table = 'parametres';

    protected $fillable = [
        'cle',
        'valeur',
        'libelle',
        'description',
        'type',
        'groupe',
    ];

    /**
     * Récupérer la valeur d'un paramètre par sa clé (avec cache)
     */
    public static function valeur(string $cle, mixed $defaut = null): mixed
    {
        return Cache::remember("parametre.{$cle}", 3600, function () use ($cle, $defaut) {
            $parametre = static::where('cle', $cle)->first();
            if (!$parametre) {
                return $defaut;
            }

            return match ($parametre->type) {
                'number' => is_numeric($parametre->valeur) ? (float) $parametre->valeur : $defaut,
                'boolean' => in_array(strtolower($parametre->valeur), ['true', '1', 'oui']),
                default => $parametre->valeur,
            };
        });
    }

    /**
     * Mettre à jour un paramètre et vider le cache
     */
    public static function majValeur(string $cle, string $valeur): bool
    {
        $parametre = static::where('cle', $cle)->first();
        if (!$parametre) {
            return false;
        }

        $parametre->update(['valeur' => $valeur]);
        Cache::forget("parametre.{$cle}");

        return true;
    }

    /**
     * Raccourcis pour les seuils les plus utilisés
     */
    public static function montantMax(): float
    {
        return (float) static::valeur('montant_max_bon', 20000000);
    }

    public static function seuilDP(): float
    {
        return (float) static::valeur('seuil_validation_dp', 5000000);
    }

    public static function delaiRegularisationMission(): int
    {
        return (int) static::valeur('delai_regularisation_mission', 3);
    }

    public static function delaiRegularisationAutre(): int
    {
        return (int) static::valeur('delai_regularisation_autre', 2);
    }

    public static function tailleMaxFichier(): int
    {
        return (int) static::valeur('taille_max_fichier', 10485760);
    }
}
