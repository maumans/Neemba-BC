<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Entité juridique du groupe (spec v2.4, RG-M01-02, RG-M01-08) : Neemba Guinée, seule active ; Neemba Mining prévue,
 * inactive (mise en service : proposition distincte). Elle porte les données d'impression (raison sociale, adresse,
 * logo) et cloisonne utilisateurs, caisses, bons, ODM et référentiels.
 *
 * À ne pas confondre avec `modifications_en_attente.entite_id`, qui désigne l'objet modifié.
 */
class Entite extends Model
{
    protected $table = 'entites';

    protected $fillable = ['code', 'nom', 'raison_sociale', 'adresse', 'logo', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    /** Cache de la requête : l'entité par défaut est demandée à chaque création */
    private static ?int $parDefautId = null;

    public function scopeActives($query)
    {
        return $query->where('actif', true);
    }

    /** Entité par défaut : Neemba Guinée (la première entité active) */
    public static function parDefaut(): ?self
    {
        if (static::$parDefautId && ($entite = static::find(static::$parDefautId))) {
            return $entite;
        }
        $entite = static::where('code', 'NGU')->first() ?? static::actives()->orderBy('id')->first();
        static::$parDefautId = $entite?->id;

        return $entite;
    }

    public static function idParDefaut(): ?int
    {
        return static::$parDefautId ??= static::parDefaut()?->id;
    }

    /** À appeler quand les entités changent (tests, paramétrage) */
    public static function oublierCache(): void
    {
        static::$parDefautId = null;
    }

    /** Lignes de l'adresse imprimée (PDF du bon et de l'ODM) */
    public function lignesAdresse(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->adresse))));
    }

    /** Chemin du logo dans `public/`, à défaut le logo Neemba */
    public function cheminLogo(): string
    {
        $logo = $this->logo ?: 'logo.png';

        return file_exists(public_path($logo)) ? public_path($logo) : public_path('logo.png');
    }
}
