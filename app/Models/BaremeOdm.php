<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barème ODM daté (spec v2.4, RG-M02-10, RG-M02-01, §6.6) : tenu par le gestionnaire des référentiels, sans circuit de
 * validation. Chaque valeur porte une date d'effet ; l'historique est conservé (une valeur n'est jamais modifiée : on
 * en saisit une nouvelle). Un ODM est calculé avec les barèmes en vigueur à sa date de soumission (figés ensuite).
 *
 * Entité vide : barème commun à toutes les entités ; un barème d'une entité l'emporte sur le barème commun.
 */
class BaremeOdm extends Model
{
    use \App\Models\Concerns\AppartientAUneEntite;

    protected bool $entiteCommune = true;

    protected $table = 'baremes_odm';

    protected $fillable = ['entite_id', 'cle', 'valeur', 'date_effet', 'saisi_par', 'commentaire'];

    /** Barèmes tenus (§6.6) ; valeur par défaut de la spec si aucun barème n'est saisi */
    public const CLES = [
        'indemnite_journaliere' => ['libelle' => 'Indemnité journalière, ODM intérieur (GNF par jour)', 'type' => 'number', 'defaut' => 250000],
        'libelle_indemnite_1' => ['libelle' => "Libellé de la première ligne d'indemnité", 'type' => 'text', 'defaut' => 'Indemnité de repas'],
        'libelle_indemnite_2' => ['libelle' => "Libellé de la seconde ligne d'indemnité", 'type' => 'text', 'defaut' => 'Indemnité de déplacement'],
        'hebergement_nuit' => ['libelle' => 'Hébergement, ODM intérieur (GNF par nuit)', 'type' => 'number', 'defaut' => 500000],
        'bareme_fcfa_non_cadre' => ['libelle' => 'Indemnité extérieure, non-cadre (FCFA par jour)', 'type' => 'number', 'defaut' => 22000],
        'bareme_fcfa_cadre' => ['libelle' => 'Indemnité extérieure, cadre (FCFA par jour)', 'type' => 'number', 'defaut' => 34000],
    ];

    protected function casts(): array
    {
        return ['date_effet' => 'date'];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    /** Ligne en vigueur pour un barème à une date (aujourd'hui par défaut), pour une entité (à défaut : l'entité par défaut) */
    public static function ligneEnVigueur(string $cle, ?CarbonInterface $date = null, ?int $entite = null): ?self
    {
        /* Sans entité précisée (vue consolidée, calcul hors ODM) : l'entité par défaut */
        $entite ??= Entite::idParDefaut();

        return static::withoutGlobalScope('entite')
            ->where('cle', $cle)
            ->whereDate('date_effet', '<=', ($date ?? now())->toDateString())
            ->where(fn ($q) => $q->whereNull('entite_id')->when($entite, fn ($q) => $q->orWhere('entite_id', $entite)))
            ->orderByRaw('entite_id is null')
            ->orderByDesc('date_effet')
            ->orderByDesc('id')
            ->first();
    }

    /** Valeur typée d'un barème en vigueur */
    public static function enVigueur(string $cle, ?CarbonInterface $date = null, ?int $entite = null): int|string
    {
        $valeur = static::ligneEnVigueur($cle, $date, $entite)?->valeur ?? self::CLES[$cle]['defaut'];

        return self::CLES[$cle]['type'] === 'number' ? (int) $valeur : (string) $valeur;
    }

    /** Tous les barèmes en vigueur : clé => valeur */
    public static function tous(?CarbonInterface $date = null, ?int $entite = null): array
    {
        return collect(array_keys(self::CLES))->mapWithKeys(fn (string $cle) => [$cle => static::enVigueur($cle, $date, $entite)])->all();
    }
}
