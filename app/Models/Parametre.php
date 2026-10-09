<?php

namespace App\Models;

use App\Services\Paiement\FraisOrangeMoney;
use App\Support\JoursOuvres;
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
                'json' => json_decode($parametre->valeur, true) ?? $defaut,
                default => $parametre->valeur,
            };
        });
    }

    /**
     * Valeurs admises des paramètres de type « choix » (points ouverts de la spec v2.2, réglables sans toucher au code)
     */
    public const CHOIX = [
        /* RG-M12-14 (PO-03) */
        'odm_mode_generation' => [
            'par_participant' => 'Un bon par participant',
            'groupe' => "Un bon groupé, versé au n° OM d'un participant désigné",
        ],
        /* RG-M12-15 (PO-04) */
        'odm_prise_en_charge_client' => [
            'variante_a' => 'Avancée par Neemba, refacturée',
            'variante_b' => 'Payée directement par le client',
        ],
    ];

    /**
     * Contrôle d'une nouvelle valeur selon le type du paramètre : message d'erreur, ou null si elle est valable.
     */
    public function erreurValeur(string $valeur): ?string
    {
        $valeur = trim($valeur);

        return match ($this->type) {
            'number' => is_numeric($valeur) && (float) $valeur >= 0 ? null : 'Saisissez un nombre positif.',
            'boolean' => in_array(strtolower($valeur), ['true', 'false', '1', '0', 'oui', 'non'], true) ? null : 'Valeur attendue : oui ou non.',
            'choix' => array_key_exists($valeur, self::CHOIX[$this->cle] ?? [])
                ? null
                : 'Valeur attendue : ' . implode(', ', array_keys(self::CHOIX[$this->cle] ?? [])) . '.',
            'dates' => ($invalides = JoursOuvres::entreesInvalides($valeur)) === []
                ? null
                : 'Dates invalides : ' . implode(', ', $invalides) . ' (format MM-JJ ou AAAA-MM-JJ).',
            'json' => json_decode($valeur, true) === null
                ? "Le texte saisi n'est pas une liste JSON valable."
                : ($this->cle === 'frais_om_paliers' ? FraisOrangeMoney::erreurPaliers(json_decode($valeur, true)) : null),
            default => $valeur === '' ? 'La valeur est obligatoire.' : null,
        };
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

    /** RG-BC-23 : prix de référence du carburant (GNF/L), base du contrôle du prix au litre d'un ticket */
    public static function prixLitreReference(): int
    {
        return (int) static::valeur('prix_litre_reference', 12000);
    }

    /** RG-BC-26 : un brouillon non modifié depuis ce nombre de jours est annulé */
    public static function delaiAbandonBrouillon(): int
    {
        return (int) static::valeur('delai_abandon_brouillon', 30);
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
