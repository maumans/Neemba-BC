<?php

namespace App\Models;

use App\Support\Format;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Caisse (SFD v1.3) : caisse espèces, caisse Orange Money (unique), caisse Atelier en avance fixe.
 *
 * Spec v2.4, RG-M02-11, §6.1 : la caisse est le point de décaissement, distinct du site. Elle sert un ou plusieurs sites
 * (table `caisse_site`) ; un site peut avoir plusieurs caisses ; la caisse Orange Money n'a pas de site.
 *
 * Le solde ne se modifie jamais directement : chaque mouvement d'argent passe par
 * crediter() / debiter(), qui l'inscrit au registre (EcritureCaisse) avec le solde avant / après.
 */
class Caisse extends Model
{
    use HasFactory;
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    const TYPES = [
        'especes' => 'Espèces',
        'orange_money' => 'Orange Money',
    ];

    const MODES = [
        'standard' => 'Standard',
        'avance_fixe' => 'Avance fixe',
    ];

    protected $fillable = [
        'entite_id',
        /* `site_id` : compatibilité (fabriques, import, nouveau site) — ajoute ce site aux sites servis */
        'code', 'libelle', 'site_id', 'type', 'mode', 'montant_avance',
        /* Lot C (RG-M03-18, RG-M08-11) : code de numérotation, reprise du carnet papier, nombre de chiffres */
        'code_numerotation', 'numero_depart', 'chiffres_numero',
        'solde', 'plafond_retrait', 'seuil_alerte', 'actif',
        /* Matrice de paramétrage par caisse (référentiel Neemba, point 14) */
        'plafond_caisse', 'gestionnaire_id', 'suppleant_id', 'encaissements_clients',
        'reapprovisionnement', 'destinataires_rapport',
        /* Spec v2.4, RG-M08-08 : réapprovisionnement possible par transfert depuis une autre caisse (caisse Atelier) */
        'reappro_par_transfert',
    ];

    protected $casts = [
        'montant_avance' => 'decimal:2',
        'solde' => 'decimal:2',
        'plafond_retrait' => 'decimal:2',
        'seuil_alerte' => 'decimal:2',
        'plafond_caisse' => 'decimal:2',
        'actif' => 'boolean',
        'encaissements_clients' => 'boolean',
        'reappro_par_transfert' => 'boolean',
        'destinataires_rapport' => 'array',
        /* Spec v2.4, RG-M08-03 : alerte de seuil envoyée, dernier rappel */
        'alerte_seuil_le' => 'datetime',
        'alerte_seuil_rappel_le' => 'date',
    ];

    /** Site à ajouter aux sites servis après l'enregistrement (attribut de compatibilité `site_id`) */
    private ?int $siteAAjouter = null;

    protected static function booted(): void
    {
        static::saved(function (Caisse $caisse) {
            if ($caisse->siteAAjouter) {
                $caisse->sites()->syncWithoutDetaching([$caisse->siteAAjouter]);
                $caisse->siteAAjouter = null;
                $caisse->unsetRelation('sites');
            }
        });
    }

    /** `site_id` n'est plus une colonne : la valeur donnée devient un site servi (RG-M02-11) */
    public function setSiteIdAttribute(mixed $valeur): void
    {
        $this->siteAAjouter = is_numeric($valeur) ? (int) $valeur : null;
    }

    /** Champs réglés par double validation (ModificationEnAttente) */
    public const CHAMPS_SENSIBLES = ['montant_avance', 'plafond_retrait', 'seuil_alerte', 'plafond_caisse'];

    public function gestionnaire(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'gestionnaire_id');
    }

    public function suppleant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'suppleant_id');
    }

    /* ----------------------------------------------------------------
     * RELATIONS ET SCOPES
     * ---------------------------------------------------------------- */

    /** Spec v2.4, RG-M02-11 : sites servis (aucun pour la caisse Orange Money) */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'caisse_site')->orderBy('nom');
    }

    /** « Conakry », « Boke, Sangaredi » ou « Aucun site » */
    public function getSitesLibelleAttribute(): string
    {
        return $this->sites->pluck('nom')->implode(', ') ?: 'Aucun site';
    }

    public function sertLeSite(?string $nomSite): bool
    {
        return $nomSite !== null && $this->sites->contains('nom', $nomSite);
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
        return $query->whereHas('sites', fn ($q) => $q->where('nom', $nomSite));
    }

    /* ----------------------------------------------------------------
     * CAISSE PAYEUSE (RG-BC-12)
     * ---------------------------------------------------------------- */

    /**
     * Caisse qui paie un bon selon son mode de paiement (RG-BC-12) :
     * espèces → caisse émettrice active, sinon une caisse espèces qui sert le site donné (celui du demandeur, spec v2.4
     * RG-M02-11 : pas le site analytique du bon), à défaut la caisse principale de Conakry ;
     * Orange Money → caisse OM de Conakry ; chèque, virement, autre → aucune (paiement hors caisse).
     */
    public static function payeusePour(string $nomSite, ?string $modePaiement, ?self $emettrice = null): ?self
    {
        return match ($modePaiement) {
            /* Lot C (RG-M03-11, RG-M08-01) : une caisse espèces émettrice active paie ses bons (caisse Atelier comprise) */
            'especes' => ($emettrice && $emettrice->actif && $emettrice->type === 'especes' ? $emettrice : null)
                ?? static::actives()->where('type', 'especes')->where('mode', 'standard')
                    ->duSite($nomSite)->orderBy('id')->first()
                ?? static::principaleConakry(),
            'orange_money' => static::actives()->where('type', 'orange_money')->orderBy('id')->first(),
            default => null,
        };
    }

    /**
     * Spec v2.3, RG-M02-03, RG-M03-11 : caisse de rattachement d'un utilisateur, sur laquelle il émet ses bons. À défaut
     * de rattachement (ou caisse désactivée) : caisse principale espèces de son site, sinon celle de Conakry.
     */
    public static function deRattachement(?User $utilisateur): ?self
    {
        $caisse = $utilisateur?->caisse_id ? static::find($utilisateur->caisse_id) : null;

        return ($caisse?->actif ? $caisse : null)
            ?? ($utilisateur?->site ? static::actives()->where('type', 'especes')->where('mode', 'standard')->duSite($utilisateur->site)->orderBy('id')->first() : null)
            ?? static::principaleConakry()
            ?? ($utilisateur?->site ? static::actives()->duSite($utilisateur->site)->orderBy('id')->first() : null)
            ?? static::actives()->orderBy('id')->first();
    }

    /** RG-M03-18 : code de la caisse dans les numéros de bons (à défaut, le début de son code : CKY-ESP → CKY) */
    public function codeNumerotation(): string
    {
        return $this->code_numerotation ?: strtoupper(explode('-', (string) $this->code)[0]);
    }

    /** Caisse principale (espèces) de Conakry */
    public static function principaleConakry(): ?self
    {
        return static::actives()->where('type', 'especes')->where('mode', 'standard')
            ->whereHas('sites', fn ($q) => $q->where('code', '01')->orWhere('nom', 'Conakry'))
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

            /* Spec v2.4, RG-M08-03 : alerte au franchissement du seuil vers le bas, quel que soit le mouvement */
            DB::afterCommit(fn () => \App\Services\Caisse\AlerteSeuil::apresEcriture($this->fresh(), $soldeAvant, $soldeApres));

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
