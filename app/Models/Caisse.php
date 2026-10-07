<?php

namespace App\Models;

use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Caisse (SFD v1.3) : caisse espèces d'un site, caisse Orange Money (unique, à Conakry),
 * caisse Atelier en avance fixe.
 *
 * Le solde ne se modifie jamais directement : chaque mouvement d'argent passe par
 * crediter() / debiter(), qui l'inscrit au registre (EcritureCaisse) avec le solde avant / après.
 */
class Caisse extends Model
{
    use HasFactory;

    const TYPES = [
        'especes' => 'Espèces',
        'orange_money' => 'Orange Money',
    ];

    const MODES = [
        'standard' => 'Standard',
        'avance_fixe' => 'Avance fixe',
    ];

    protected $fillable = [
        'code', 'libelle', 'site_id', 'type', 'mode', 'montant_avance',
        'solde', 'plafond_retrait', 'seuil_alerte', 'actif',
    ];

    protected $casts = [
        'montant_avance' => 'decimal:2',
        'solde' => 'decimal:2',
        'plafond_retrait' => 'decimal:2',
        'seuil_alerte' => 'decimal:2',
        'actif' => 'boolean',
    ];

    /* ----------------------------------------------------------------
     * RELATIONS ET SCOPES
     * ---------------------------------------------------------------- */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function ecritures(): HasMany
    {
        return $this->hasMany(EcritureCaisse::class);
    }

    public function scopeActives($query)
    {
        return $query->where('actif', true);
    }

    public function scopeDuSite($query, string $nomSite)
    {
        return $query->whereHas('site', fn ($q) => $q->where('nom', $nomSite));
    }

    /* ----------------------------------------------------------------
     * CAISSE PAYEUSE (RG-BC-12)
     * ---------------------------------------------------------------- */

    /**
     * Caisse qui paie un bon selon son site et son mode de paiement (RG-BC-12) :
     * espèces → caisse espèces active du site (à défaut : caisse principale de Conakry) ;
     * Orange Money → caisse OM de Conakry ; chèque, virement, autre → aucune (paiement hors caisse).
     */
    public static function payeusePour(string $nomSite, ?string $modePaiement): ?self
    {
        return match ($modePaiement) {
            'especes' => static::actives()->where('type', 'especes')->where('mode', 'standard')
                ->duSite($nomSite)->orderBy('id')->first()
                ?? static::principaleConakry(),
            'orange_money' => static::actives()->where('type', 'orange_money')->orderBy('id')->first(),
            default => null,
        };
    }

    /** Caisse principale (espèces) de Conakry */
    public static function principaleConakry(): ?self
    {
        return static::actives()->where('type', 'especes')->where('mode', 'standard')
            ->whereHas('site', fn ($q) => $q->where('code', '01')->orWhere('nom', 'Conakry'))
            ->orderBy('id')
            ->first();
    }

    /**
     * Caisse espèces d'un nouveau site (solde 0) : « Caisse principale <site> », code <3 lettres>-ESP.
     */
    public static function creerCaissePrincipale(Site $site): self
    {
        $prefixe = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', \Illuminate\Support\Str::ascii($site->nom)), 0, 3)) ?: 'SIT';
        $code = "{$prefixe}-ESP";
        if (static::where('code', $code)->exists()) {
            $code = "{$prefixe}{$site->id}-ESP";
        }

        return static::create([
            'code' => $code,
            'libelle' => "Caisse principale {$site->nom}",
            'site_id' => $site->id,
            'type' => 'especes',
            'mode' => 'standard',
            'solde' => 0,
            'actif' => true,
        ]);
    }

    /* ----------------------------------------------------------------
     * RÈGLES
     * ---------------------------------------------------------------- */

    /** Le solde couvre-t-il ce montant ? */
    public function peutPayer(float $montant): bool
    {
        return (float) $this->solde >= $montant;
    }

    /** RG-BC-11 : au-delà du plafond de retrait, le paiement en espèces sur cette caisse est interdit */
    public function depassePlafondRetrait(float $montant): bool
    {
        return $this->plafond_retrait !== null && $montant > (float) $this->plafond_retrait;
    }

    /** Seuil d'alerte de la caisse, à défaut le paramètre général */
    public function seuilAlerteEffectif(): float
    {
        return (float) ($this->seuil_alerte ?? Parametre::valeur('seuil_minimum_caisse', 500000));
    }

    public function sousSeuil(): bool
    {
        return (float) $this->solde <= $this->seuilAlerteEffectif();
    }

    /* ----------------------------------------------------------------
     * REGISTRE
     * ---------------------------------------------------------------- */

    /**
     * Entrée d'argent inscrite au registre. $contexte : bon_caisse_id, mouvement_caisse_id, utilisateur_id, libelle, date_ecriture.
     */
    public function crediter(float $montant, string $nature, array $contexte = []): EcritureCaisse
    {
        return $this->ecrire('entree', $montant, $nature, $contexte);
    }

    /**
     * Sortie d'argent inscrite au registre.
     */
    public function debiter(float $montant, string $nature, array $contexte = []): EcritureCaisse
    {
        return $this->ecrire('sortie', $montant, $nature, $contexte);
    }

    private function ecrire(string $sens, float $montant, string $nature, array $contexte): EcritureCaisse
    {
        if ($montant <= 0) {
            throw new InvalidArgumentException('Le montant d\'une écriture de caisse doit être positif.');
        }

        return DB::transaction(function () use ($sens, $montant, $nature, $contexte) {
            /* Verrou : deux mouvements simultanés sur la même caisse ne se marchent pas dessus */
            $soldeAvant = (float) static::whereKey($this->id)->lockForUpdate()->value('solde');
            $soldeApres = $sens === 'entree' ? $soldeAvant + $montant : $soldeAvant - $montant;

            static::whereKey($this->id)->update(['solde' => $soldeApres, 'updated_at' => now()]);
            $this->setRawAttributes(array_merge($this->getAttributes(), ['solde' => $soldeApres]), true);

            return $this->ecritures()->create([
                'date_ecriture' => $contexte['date_ecriture'] ?? now(),
                'sens' => $sens,
                'nature' => $nature,
                'montant' => $montant,
                'solde_avant' => $soldeAvant,
                'solde_apres' => $soldeApres,
                'libelle' => $contexte['libelle'] ?? null,
                'bon_caisse_id' => $contexte['bon_caisse_id'] ?? null,
                'mouvement_caisse_id' => $contexte['mouvement_caisse_id'] ?? null,
                'utilisateur_id' => $contexte['utilisateur_id'] ?? auth()->id(),
            ]);
        });
    }

    /**
     * Solde à un instant donné, lu dans le registre (null si la caisse n'a aucune écriture avant cet instant).
     */
    public function soldeAu(CarbonInterface $instant): ?float
    {
        $derniere = $this->ecritures()
            ->where('date_ecriture', '<', $instant)
            ->orderByDesc('date_ecriture')
            ->orderByDesc('id')
            ->first();

        return $derniere ? (float) $derniere->solde_apres : null;
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    public function getSoldeFormatAttribute(): string
    {
        return Format::montant($this->solde);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
