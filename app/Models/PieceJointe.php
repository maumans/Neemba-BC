<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Modèle PieceJointe - Document justificatif
 * 
 * Gère les fichiers attachés aux bons de caisse (factures, reçus, devis, etc.)
 * Les fichiers sont stockés via Laravel Storage dans le disque configuré.
 */
class PieceJointe extends Model
{
    use HasFactory;

    protected $table = 'pieces_jointes';

    protected $fillable = [
        'bon_caisse_id',
        'type_document',
        'classification_ia',
        'confiance_classification',
        'nom_fichier',
        'chemin_fichier',
        'taille',
        'mime_type',
        'dpi_detecte',
        'qualite_ok',
        'version',
        'identifiant_unique',
        'date_archivage',
        'date_expiration_retention',
        'archived_by',
        'checksum',
        'ocr_statut',
        'ocr_data',
        'ocr_texte_brut',
        'texte_indexable',
    ];

    protected function casts(): array
    {
        return [
            'ocr_data' => 'array',
            'qualite_ok' => 'boolean',
            'date_archivage' => 'datetime',
            'date_expiration_retention' => 'date',
        ];
    }

    /* ----------------------------------------------------------------
     * CONSTANTES
     * ---------------------------------------------------------------- */

    /** Statuts possibles de l'analyse OCR */
    const OCR_EN_ATTENTE = 'en_attente';
    const OCR_EN_COURS = 'en_cours';
    const OCR_TERMINE = 'termine';
    const OCR_ERREUR = 'erreur';
    const OCR_NON_APPLICABLE = 'non_applicable';

    const TYPES_DOCUMENTS = [
        'facture'          => 'Facture',
        'recu'             => 'Reçu',
        'devis'            => 'Devis',
        'ordre_mission'    => 'Ordre de mission',
        'proforma'         => 'Proforma',
        'email'            => 'Email justificatif',
        'recu_carburant'   => 'Reçu carburant',
        'bon_commande'     => 'Bon de commande',
        'rapport_journalier' => 'Rapport journalier',
        'justificatif'     => 'Justificatif de régularisation',
        'autre'            => 'Autre',
    ];

    /** Types de classification IA */
    const CLASSIFICATIONS_IA = [
        'bon_caisse' => 'Bon de caisse',
        'facture' => 'Facture',
        'proforma' => 'Proforma',
        'ordre_mission' => 'Ordre de mission',
        'recu_carburant' => 'Justificatif carburant',
        'rapport_journalier' => 'Rapport journalier',
        'recu' => 'Reçu de paiement',
        'autre' => 'Autre',
    ];

    /** Seuil DPI minimum pour un scan de qualité */
    const DPI_MINIMUM = 300;

    /** Durée de rétention en années (procédure Neemba Nov. 2025) */
    const RETENTION_ANNEES = 5;

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Bon de caisse auquel ce document est rattaché
     */
    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /**
     * Taille formatée du fichier (Ko, Mo)
     */
    public function getTailleFormatAttribute(): string
    {
        if (!$this->taille) return '0 Ko';

        if ($this->taille < 1024) {
            return $this->taille . ' o';
        } elseif ($this->taille < 1048576) {
            return round($this->taille / 1024, 1) . ' Ko';
        } else {
            return round($this->taille / 1048576, 1) . ' Mo';
        }
    }

    /**
     * URL de téléchargement du fichier
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->chemin_fichier);
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Supprimer le fichier physique du stockage
     */
    public function supprimerFichier(): bool
    {
        return Storage::delete($this->chemin_fichier);
    }

    /* ----------------------------------------------------------------
     * ARCHIVAGE & CLASSIFICATION
     * ---------------------------------------------------------------- */

    /**
     * Générer un identifiant unique lors de la création
     */
    protected static function booted(): void
    {
        static::creating(function (PieceJointe $piece) {
            if (!$piece->identifiant_unique) {
                $piece->identifiant_unique = (string) Str::uuid();
            }
        });
    }

    /**
     * Appliquer la classification IA sur le document
     */
    public function appliquerClassification(string $type, int $confiance = 100): void
    {
        $this->update([
            'classification_ia' => $type,
            'confiance_classification' => min(100, max(0, $confiance)),
        ]);
    }

    /**
     * Construire le texte indexable à partir de l'OCR et des métadonnées du bon
     */
    public function construireTexteIndexable(): void
    {
        $parties = [];

        /* Texte OCR brut */
        if ($this->ocr_texte_brut) {
            $parties[] = $this->ocr_texte_brut;
        }

        /* Métadonnées du bon associé */
        if ($this->bonCaisse) {
            $bon = $this->bonCaisse;
            $parties[] = $bon->numero;
            $parties[] = $bon->beneficiaire;
            $parties[] = $bon->motif;
            $parties[] = $bon->site;
            $parties[] = number_format((float) $bon->montant, 0, '', '');
        }

        /* Nom du fichier */
        $parties[] = $this->nom_fichier;

        $this->update([
            'texte_indexable' => implode(' ', array_filter($parties)),
        ]);
    }

    /**
     * Vérifier la qualité DPI d'un scan (images uniquement)
     */
    public function verifierQualiteDpi(?int $dpi = null): void
    {
        if (!$this->estImage()) {
            $this->update(['qualite_ok' => true, 'dpi_detecte' => null]);
            return;
        }

        if ($dpi !== null) {
            $this->update([
                'dpi_detecte' => $dpi,
                'qualite_ok' => $dpi >= self::DPI_MINIMUM,
            ]);
            return;
        }

        /* Essayer de détecter le DPI via getimagesize */
        $chemin = Storage::path($this->chemin_fichier);
        if (file_exists($chemin) && function_exists('getimagesize')) {
            $info = @getimagesize($chemin);
            if ($info && isset($info['channels'])) {
                /* Pas de DPI fiable via getimagesize, marquer comme inconnu */
                $this->update(['qualite_ok' => true]);
            }
        }
    }

    /**
     * Archiver le document avec rétention de 5 ans
     */
    public function archiver(?int $userId = null): void
    {
        $this->update([
            'date_archivage' => now(),
            'date_expiration_retention' => now()->addYears(self::RETENTION_ANNEES),
            'archived_by' => $userId,
            'checksum' => $this->calculerChecksum(),
        ]);
    }

    /**
     * Calculer le checksum SHA-256 du fichier
     */
    public function calculerChecksum(): ?string
    {
        $chemin = Storage::path($this->chemin_fichier);
        if (file_exists($chemin)) {
            return hash_file('sha256', $chemin);
        }
        return null;
    }

    /**
     * Incrémenter la version du document (lors d'un remplacement)
     */
    public function incrementerVersion(): void
    {
        $this->update([
            'version' => $this->version + 1,
            'checksum' => $this->calculerChecksum(),
        ]);
    }

    /**
     * Vérifier si le fichier est une image
     */
    public function estImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    /**
     * Vérifier si le document est archivé
     */
    public function estArchive(): bool
    {
        return $this->date_archivage !== null;
    }

    /**
     * Vérifier si la rétention est expirée
     */
    public function retentionExpiree(): bool
    {
        return $this->date_expiration_retention && $this->date_expiration_retention->isPast();
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    /**
     * Documents archivés
     */
    public function scopeArchives($query)
    {
        return $query->whereNotNull('date_archivage');
    }

    /**
     * Documents avec qualité insuffisante
     */
    public function scopeQualiteInsuffisante($query)
    {
        return $query->where('qualite_ok', false);
    }

    /**
     * Recherche full-text sur le texte indexable
     */
    public function scopeRecherche($query, string $terme)
    {
        if (config('database.default') === 'mysql') {
            return $query->whereRaw(
                'MATCH(texte_indexable) AGAINST(? IN BOOLEAN MODE)',
                [$terme . '*']
            );
        }

        /* Fallback LIKE pour SQLite et autres */
        return $query->where('texte_indexable', 'LIKE', '%' . $terme . '%');
    }

    /**
     * Rétention expirée
     */
    public function scopeRetentionExpiree($query)
    {
        return $query->whereNotNull('date_expiration_retention')
            ->where('date_expiration_retention', '<', now());
    }
}
