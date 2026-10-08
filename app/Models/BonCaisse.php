<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\Paiement\FraisOrangeMoney;
use App\Support\Format;
use App\Support\JoursOuvres;
use App\Support\MontantEnLettres;

/**
 * Modèle BonCaisse - Bon de Caisse
 * 
 * Représente une demande de fonds dans le système de gestion de caisse.
 * Chaque bon suit un workflow de validation hiérarchique avant paiement.
 * 
 * Types : BD (Définitif) / BP (Provisoire)
 * Montant max : 20 000 000 GNF
 * Seuil validation DP : >= 5 000 000 GNF
 */
class BonCaisse extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Nom de la table en base de données (en français)
     */
    protected $table = 'bons_caisse';

    /**
     * Champs remplissables en masse
     */
    protected $fillable = [
        'numero',
        'type_bon',
        'site',
        'service',
        'code_analytique',
        'beneficiaire',
        'type_beneficiaire',
        'telephone_beneficiaire',
        'mode_paiement',
        'motif',
        'categorie_depense',
        'montant',
        'montant_lettres',
        'devise',
        'statut',
        'demandeur_id',
        'caissier_id',
        'mode_paiement_effectif',
        'frais_om',
        'frais_om_taux',
        'frais_om_saisis',
        'montant_verse',
        'caisse_id',
        'version',
        'initiateur_id',
        'beneficiaire_id',
        'vehicule',
        'references_or',
        'lie_mission',
        'odm_id',
        'date_retour_mission',
        'motif_annulation',
        'date_annulation',
        'cle_soumission',
        'date_demande',
        'date_soumission',
        'date_paiement',
        'date_regularisation',
        'date_limite_regularisation',
        'commentaire_rejet',
        'niveau_urgence',
        'motif_urgence',
        'justification_urgence',
        'motif_regularisation',
    ];

    /**
     * Attributs ajoutés automatiquement lors de la sérialisation
     */
    protected $appends = [
        'montant_format',
        'statut_label',
        'delai_traitement',
    ];

    /**
     * Conversions de types pour les attributs
     */
    protected function casts(): array
    {
        return [
            'references_or' => 'array',
            'lie_mission' => 'boolean',
            'date_retour_mission' => 'date',
            'date_annulation' => 'datetime',
            'montant' => 'decimal:2',
            'date_demande' => 'date',
            'date_soumission' => 'datetime',
            'date_paiement' => 'datetime',
            'frais_om' => 'decimal:2',
            'frais_om_taux' => 'decimal:2',
            'frais_om_saisis' => 'boolean',
            'montant_verse' => 'decimal:2',
            'date_regularisation' => 'datetime',
            'date_limite_regularisation' => 'date',
        ];
    }

    /* ----------------------------------------------------------------
     * CONSTANTES MÉTIER
     * ---------------------------------------------------------------- */

    /** Montant maximum autorisé pour un bon de caisse (20 millions GNF) */
    const MONTANT_MAX = 20000000;

    /** Seuil à partir duquel la validation du Directeur Pays est requise (5 millions GNF) */
    const SEUIL_VALIDATION_DP = 5000000;

    /** Liste des statuts possibles */
    const STATUTS = [
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
        'ANNULE',
    ];

    /** Labels lisibles pour chaque statut */
    const STATUTS_LABELS = [
        'BROUILLON' => 'Brouillon',
        'EN_ATTENTE_CHEF_SERVICE' => 'En attente Chef Service',
        'EN_ATTENTE_CDG' => 'En attente Contrôle de Gestion',
        'EN_ATTENTE_DAF' => 'En attente DAF',
        'EN_ATTENTE_DP' => 'En attente Directeur Pays',
        'APPROUVE' => 'Approuvé',
        'PAYE' => 'Payé',
        'REJETE' => 'Rejeté',
        'EN_ATTENTE_REGULARISATION' => 'En attente de régularisation',
        'REGULARISE' => 'Régularisé',
        'ARCHIVE' => 'Archivé',
        'ANNULE' => 'Annulé',
    ];

    /** Catégories de dépense */

    /** Types de bénéficiaire */
    const TYPES_BENEFICIAIRE = [
        'employe' => 'Employé',
        'fournisseur' => 'Fournisseur / prestataire',
        'prestataire' => 'Prestataire',
        'autre' => 'Autre tiers',
    ];

    /** Types proposés dans l'assistant (US-BC-04) ; « prestataire » est conservé pour les anciens bons */
    const TYPES_BENEFICIAIRE_ASSISTANT = ['employe', 'fournisseur', 'autre'];

    /** Modes de paiement */
    const MODES_PAIEMENT = [
        'especes' => 'Espèces',
        'orange_money' => 'Orange Money',
        'cheque' => 'Chèque',
        'virement' => 'Virement',
        'autre' => 'Autre',
    ];

    /** Modes proposés dans l'assistant (US-BC-06) ; « autre » est conservé pour les anciens bons */
    const MODES_PAIEMENT_ASSISTANT = ['especes', 'orange_money', 'cheque', 'virement'];

    /** Modes de paiement qui sortent d'une caisse ; les autres sont payés hors caisse (RG-BC-12) */
    const MODES_PAIEMENT_CAISSE = ['especes', 'orange_money'];

    /** Niveaux d'urgence */
    const NIVEAUX_URGENCE = [
        'normale' => 'Normale',
        'urgente' => 'Urgente',
        'tres_urgente' => 'Très urgente',
    ];

    /** Motifs de rejet prédéfinis */
    const MOTIFS_REJET = [
        'budget_insuffisant' => 'Budget insuffisant ou non alloué',
        'piece_manquante' => 'Pièce justificative manquante',
        'montant_incorrect' => 'Montant incorrect',
        'code_analytique_errone' => 'Code analytique erroné',
        'non_conforme_politique' => 'Non conforme à la politique de dépenses',
        'autre' => 'Autre (préciser en commentaire)',
    ];

    /** Délai de régularisation en jours pour une mission */
    const DELAI_REGULARISATION_MISSION = 3;

    /** Délai de régularisation en jours pour les autres dépenses */
    const DELAI_REGULARISATION_AUTRE = 2;

    /** Formats de fichiers autorisés pour les pièces jointes */
    const FORMATS_FICHIERS_AUTORISES = ['pdf', 'jpg', 'jpeg', 'png'];

    /** Taille maximale d'un fichier en octets (10 Mo) */
    const TAILLE_MAX_FICHIER = 10485760;

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Utilisateur qui a créé le bon de caisse
     */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    /**
     * Étapes de validation du bon
     */
    /** Personne qui a saisi le bon (elle-même ou un back-up délégué — US-BC-13) */
    public function initiateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiateur_id');
    }

    /** Bénéficiaire employé, choisi dans le référentiel (RG-BC-06) */
    public function beneficiaireUtilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiaire_id');
    }

    /** Caisse qui a payé le bon (lot 2) */
    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function validations(): HasMany
    {
        return $this->hasMany(Validation::class, 'bon_caisse_id')->orderBy('niveau');
    }

    /**
     * Pièces justificatives attachées au bon
     */
    /** Pièces en vigueur : une pièce remplacée par une nouvelle version reste dans l'historique (E-03.6) */
    public function piecesActives(): HasMany
    {
        return $this->hasMany(PieceJointe::class)->whereNull('remplacee_par_id');
    }

    public function piecesJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class, 'bon_caisse_id');
    }

    /**
     * Ordre de mission associé (le cas échéant)
     */
    public function ordreMission(): HasOne
    {
        return $this->hasOne(OrdreMission::class, 'bon_caisse_id');
    }

    /**
     * Caissier ayant effectué le paiement
     */
    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }

    /**
     * Historique complet des actions sur ce bon
     */
    public function historiqueActions(): HasMany
    {
        return $this->hasMany(HistoriqueAction::class, 'bon_caisse_id')->orderBy('created_at');
    }

    /**
     * Ventilations analytiques (multi-codes) du bon
     */
    public function ventilations(): HasMany
    {
        return $this->hasMany(VentilationAnalytique::class, 'bon_caisse_id');
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    /**
     * Filtrer par statut
     */
    public function scopeParStatut($query, string $statut)
    {
        return $query->where('statut', $statut);
    }

    /**
     * Bons en attente de validation (tous les statuts "EN_ATTENTE_*")
     */
    public function scopeEnAttenteValidation($query)
    {
        return $query->whereIn('statut', [
            'EN_ATTENTE_CHEF_SERVICE',
            'EN_ATTENTE_CDG',
            'EN_ATTENTE_DAF',
            'EN_ATTENTE_DP',
        ]);
    }

    /**
     * Bons d'un demandeur spécifique
     */
    public function scopeParDemandeur($query, int $demandeurId)
    {
        return $query->where('demandeur_id', $demandeurId);
    }

    /**
     * Filtrer par site
     */
    public function scopeParSite($query, string $site)
    {
        return $query->where('site', 'like', '%' . $site . '%');
    }

    /**
     * Bons créés aujourd'hui
     */
    public function scopeAujourdhui($query)
    {
        return $query->whereDate('date_demande', today());
    }

    /**
     * Scope : bons payés à une date donnée, quel que soit leur statut actuel
     * (un BP payé passe en attente de régularisation, puis régularisé ou archivé).
     */
    public function scopePayesLe($query, $date)
    {
        return $query->whereNotNull('date_paiement')->whereDate('date_paiement', $date);
    }

    /**
     * Bons provisoires en attente de régularisation
     */
    public function scopeEnAttenteRegularisation($query)
    {
        return $query->where('statut', 'EN_ATTENTE_REGULARISATION');
    }

    /**
     * Bons provisoires en retard de régularisation
     */
    public function scopeEnRetardRegularisation($query)
    {
        return $query->where('statut', 'EN_ATTENTE_REGULARISATION')
            ->whereNotNull('date_limite_regularisation')
            ->where('date_limite_regularisation', '<', today());
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /**
     * Label lisible du statut actuel
     */
    public function getStatutLabelAttribute(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut ?? 'Inconnu';
    }

    protected static function booted(): void
    {
        /* RG-BC-08 : le montant en lettres est toujours calculé par le serveur, jamais repris de la saisie */
        static::saving(function (BonCaisse $bon) {
            if ($bon->montant !== null && $bon->montant !== '') {
                $bon->montant_lettres = MontantEnLettres::convertir($bon->montant);
            }
        });

        /* SFD §1.2 : toute modification d'un champ du bon est journalisée (ancienne → nouvelle valeur) */
        static::updated(function (BonCaisse $bon) {
            HistoriqueAction::enregistrerChangements(
                $bon,
                $bon->contexteJournal['action'] ?? HistoriqueAction::ACTION_MODIFICATION,
                $bon->contexteJournal['commentaire'] ?? null,
            );
        });
    }

    /**
     * Action et commentaire de la prochaine ligne de journal automatique (voir modifierAvecJournal).
     * Propriété PHP, non enregistrée en base.
     */
    public ?array $contexteJournal = null;

    /**
     * Modifier le bon en précisant l'action inscrite au journal
     * (ex. correction du code analytique par le CDG) au lieu de « Modification du bon ».
     */
    public function modifierAvecJournal(array $valeurs, string $action, ?string $commentaire = null): bool
    {
        $this->contexteJournal = ['action' => $action, 'commentaire' => $commentaire];

        try {
            return $this->update($valeurs);
        } finally {
            $this->contexteJournal = null;
        }
    }

    /**
     * Montant formaté avec séparateur de milliers (ex: 1 500 000 GNF)
     */
    public function getMontantFormatAttribute(): string
    {
        return Format::montant($this->montant);
    }

    /**
     * Détermine si la validation du Directeur Pays est nécessaire
     */
    public function getNecessiteValidationDpAttribute(): bool
    {
        /* RG-BC-09 : visa du Directeur Pays au-delà du seuil (montant strictement supérieur) */
        return (float) $this->montant > Parametre::seuilDP();
    }

    /**
     * Détermine si le bon provisoire est en retard de régularisation
     */
    public function getEstEnRetardRegularisationAttribute(): bool
    {
        return $this->statut === 'EN_ATTENTE_REGULARISATION'
            && $this->date_limite_regularisation
            && $this->date_limite_regularisation->isPast();
    }

    /**
     * Nombre de jours restants avant la date limite de régularisation
     */
    public function getJoursRestantsRegularisationAttribute(): ?int
    {
        if (!$this->date_limite_regularisation || $this->statut !== 'EN_ATTENTE_REGULARISATION') {
            return null;
        }
        return (int) now()->startOfDay()->diffInDays($this->date_limite_regularisation, false);
    }

    /* ----------------------------------------------------------------
     * MÉTHODES MÉTIER
     * ---------------------------------------------------------------- */

    /**
     * Génère un numéro de bon unique au format BC-AAAA-NNNN
     * 
     * @return string Numéro généré (ex: BC-2026-0001)
     */
    public static function genererNumero(): string
    {
        $annee = now()->year;
        $prefixe = "BC-{$annee}-";

        /* Rechercher le dernier numéro de l'année en cours */
        $dernierBon = static::where('numero', 'like', $prefixe . '%')
            ->orderBy('numero', 'desc')
            ->first();

        if ($dernierBon) {
            /* Extraire le compteur et l'incrémenter */
            $dernierNumero = (int) substr($dernierBon->numero, -4);
            $nouveauNumero = $dernierNumero + 1;
        } else {
            $nouveauNumero = 1;
        }

        return $prefixe . str_pad($nouveauNumero, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Soumettre le bon pour validation (passage de BROUILLON à EN_ATTENTE_CHEF_SERVICE)
     * 
     * Contrôles automatiques avant soumission :
     * - Le montant ne doit pas dépasser MONTANT_MAX
     * - Pour un BD, au moins une pièce jointe est requise
     * - Tous les champs obligatoires doivent être remplis
     * 
     * @return array ['success' => bool, 'message' => string]
     */
    public function soumettre(): array
    {
        if ($this->statut !== 'BROUILLON') {
            return ['success' => false, 'message' => 'Ce bon ne peut pas être soumis (statut actuel : ' . $this->statut_label . ').'];
        }

        /* Contrôle : montant maximum */
        $montantMax = Parametre::montantMax();
        if ($this->montant > $montantMax) {
            return ['success' => false, 'message' => 'Le montant dépasse le maximum autorisé de ' . Format::montant($montantMax) . '.'];
        }

        /* Contrôle : pièces justificatives obligatoires pour un Bon Définitif */
        if ($this->type_bon === 'BD' && $this->piecesJointes()->count() === 0) {
            return ['success' => false, 'message' => 'Un Bon Définitif (BD) nécessite au moins une pièce justificative.'];
        }

        $statutAvant = $this->statut;
        $this->statut = 'EN_ATTENTE_CHEF_SERVICE';
        $this->date_soumission = now();
        $this->save();

        /* Créer les étapes de validation nécessaires */
        $this->creerEtapesValidation();

        /* Enregistrer dans l'historique */
        HistoriqueAction::enregistrer(
            $this,
            HistoriqueAction::ACTION_SOUMISSION,
            $statutAvant,
            $this->statut,
            $this->demandeur_id,
            'Bon soumis pour validation.',
        );

        return ['success' => true, 'message' => 'Bon soumis pour validation avec succès.'];
    }

    /**
     * Crée les étapes de validation selon le montant du bon
     * 
     * Workflow standard :
     * 1. Chef de Service
     * 2. Contrôle de Gestion
     * 3. DAF
     * 4. Directeur Pays (si montant >= 5 000 000 GNF)
     */
    public function creerEtapesValidation(): void
    {
        /* Resoumission après rejet (RG-BC-30) : les étapes traitées de la version précédente restent dans
         * l'historique ; seules celles qui n'avaient pas été atteintes sont retirées. */
        $this->validations()->where('statut', 'en_attente')->delete();

        $niveaux = [
            ['niveau' => 1, 'role' => 'responsable_service'],
            ['niveau' => 2, 'role' => 'controle_gestion'],
            ['niveau' => 3, 'role' => 'daf'],
        ];

        /* Ajouter le niveau Directeur Pays si le montant l'exige */
        if ($this->necessite_validation_dp) {
            $niveaux[] = ['niveau' => 4, 'role' => 'directeur_pays'];
        }

        foreach ($niveaux as $etape) {
            $this->validations()->create([
                'version' => $this->version ?? 1,
                'niveau' => $etape['niveau'],
                'role' => $etape['role'],
                'statut' => 'en_attente',
                'date_attribution' => $etape['niveau'] === 1 ? now() : null,
            ]);
        }
    }

    /**
     * Vérifie si le bon est en attente de validation par un rôle donné
     */
    public function estEnAttenteDe(string $role): bool
    {
        $mapping = [
            'responsable_service' => 'EN_ATTENTE_CHEF_SERVICE',
            'controle_gestion' => 'EN_ATTENTE_CDG',
            'daf' => 'EN_ATTENTE_DAF',
            'directeur_pays' => 'EN_ATTENTE_DP',
        ];

        return isset($mapping[$role]) && $this->statut === $mapping[$role];
    }

    /**
     * Vérifie si le bon est en attente de validation par l'un des rôles donnés
     */
    public function estEnAttenteDeUnDesRoles(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->estEnAttenteDe($role)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Passer au statut suivant après validation d'un niveau
     * Enregistre automatiquement l'action dans l'historique.
     * 
     * @param User $validateur L'utilisateur qui a validé
     * @param string|null $commentaire Commentaire du validateur
     */
    public function passerAuNiveauSuivant(User $validateur = null, ?string $commentaire = null): void
    {
        $transitions = [
            'EN_ATTENTE_CHEF_SERVICE' => 'EN_ATTENTE_CDG',
            'EN_ATTENTE_CDG' => 'EN_ATTENTE_DAF',
            'EN_ATTENTE_DAF' => $this->necessite_validation_dp ? 'EN_ATTENTE_DP' : 'APPROUVE',
            'EN_ATTENTE_DP' => 'APPROUVE',
        ];

        /* Map du statut actuel vers le type d'action historique */
        $actionsMap = [
            'EN_ATTENTE_CHEF_SERVICE' => HistoriqueAction::ACTION_VALIDATION_CHEF_SERVICE,
            'EN_ATTENTE_CDG' => HistoriqueAction::ACTION_VALIDATION_CDG,
            'EN_ATTENTE_DAF' => HistoriqueAction::ACTION_VALIDATION_DAF,
            'EN_ATTENTE_DP' => HistoriqueAction::ACTION_VALIDATION_DP,
        ];

        if (isset($transitions[$this->statut])) {
            $statutAvant = $this->statut;
            $this->statut = $transitions[$this->statut];
            $this->save();

            /* Activer le SLA du prochain niveau en attente */
            $prochainNiveau = $this->validations()
                ->where('statut', 'en_attente')
                ->whereNull('date_attribution')
                ->orderBy('niveau')
                ->first();
            if ($prochainNiveau) {
                $prochainNiveau->update(['date_attribution' => now()]);
            }

            /* Enregistrer dans l'historique */
            HistoriqueAction::enregistrer(
                $this,
                $actionsMap[$statutAvant] ?? 'validation',
                $statutAvant,
                $this->statut,
                $validateur?->id,
                $commentaire,
            );
        }
    }

    /**
     * Rejeter le bon de caisse
     * 
     * @param string|null $commentaire Motif du rejet
     * @param User|null $validateur L'utilisateur qui rejette
     */
    public function rejeter(?string $commentaire = null, ?User $validateur = null): void
    {
        $statutAvant = $this->statut;
        $this->statut = 'REJETE';
        $this->commentaire_rejet = $commentaire;
        $this->save();

        /* Enregistrer dans l'historique */
        HistoriqueAction::enregistrer(
            $this,
            HistoriqueAction::ACTION_REJET,
            $statutAvant,
            'REJETE',
            $validateur?->id,
            $commentaire,
        );
    }

    /**
     * Montant versé selon le mode retenu par le caissier (spec v2.2, §6.6 et RG-M06-07) : en Orange Money,
     * frais par paliers ajoutés au montant du bon ; hors paliers, frais saisis au paiement.
     * Le seuil du visa DP porte sur la dépense, hors frais : le circuit n'est pas relancé.
     *
     * @return array{frais: ?float, taux: ?float, saisis: bool, montant_verse: float}|null null : frais hors paliers non saisis
     */
    public function fraisPaiement(string $modePaiement, float|int|string|null $fraisSaisis = null): ?array
    {
        $montant = (float) $this->montant;
        if ($modePaiement !== 'orange_money') {
            return ['frais' => null, 'taux' => null, 'saisis' => false, 'montant_verse' => $montant];
        }

        if ($calcul = FraisOrangeMoney::calculer($montant)) {
            return ['frais' => (float) $calcul['frais'], 'taux' => $calcul['taux'], 'saisis' => false, 'montant_verse' => $calcul['montant_verse']];
        }
        if ($fraisSaisis === null || $fraisSaisis === '' || !is_numeric($fraisSaisis) || (float) $fraisSaisis < 0) {
            return null;
        }

        return ['frais' => (float) $fraisSaisis, 'taux' => null, 'saisis' => true, 'montant_verse' => $montant + (float) $fraisSaisis];
    }

    /**
     * Marquer le bon comme payé par le caissier
     *
     * @param User $caissier L'utilisateur caissier effectuant le paiement
     * @param string $modePaiement Mode de paiement effectif (especes, orange_money, virement, autre)
     * @param float|null $fraisSaisis Frais Orange Money saisis au paiement, pour un montant hors paliers
     */
    public function marquerCommePaye(User $caissier, string $modePaiement = 'especes', ?float $fraisSaisis = null): bool
    {
        if ($this->statut !== 'APPROUVE') {
            return false;
        }

        $frais = $this->fraisPaiement($modePaiement, $fraisSaisis);
        if ($frais === null) {
            return false;
        }

        $statutAvant = $this->statut;

        $this->date_paiement = now();
        $this->caissier_id = $caissier->id;
        $this->mode_paiement_effectif = $modePaiement;
        $this->frais_om = $frais['frais'];
        $this->frais_om_taux = $frais['taux'];
        $this->frais_om_saisis = $frais['saisis'];
        $this->montant_verse = $frais['montant_verse'];

        /* Frais OM tracés dans le journal : calculés (taux du palier) ou saisis au paiement (PO-01) */
        $textePaiement = 'Paiement effectué par ' . $caissier->nom_complet . ' en ' . self::MODES_PAIEMENT[$modePaiement]
            . ($frais['frais'] !== null
                ? ' : frais Orange Money ' . Format::montant($frais['frais'])
                    . ($frais['saisis'] ? ' (saisis au paiement, montant hors paliers)' : ' (' . str_replace('.', ',', (string) $frais['taux']) . ' %)')
                    . ', montant versé ' . Format::montant($frais['montant_verse'])
                : '')
            . '.';
        $metaFrais = $frais['frais'] !== null
            ? ['frais_om' => $frais['frais'], 'frais_om_taux' => $frais['taux'], 'frais_om_saisis' => $frais['saisis'], 'montant_verse' => $frais['montant_verse']]
            : [];

        if ($this->type_bon === 'BP') {
            /* BP : vérifier si des pièces de régularisation ont été pré-uploadées */
            if ($this->aDesPiecesRegularisation()) {
                /* Pré-régularisé → passer directement à REGULARISE (Q14 : pas d'archivage automatique) */
                $this->statut = 'PAYE';
                $this->date_regularisation = now();
                $this->save();

                HistoriqueAction::enregistrer($this, HistoriqueAction::ACTION_PAIEMENT, $statutAvant, 'PAYE', $caissier->id,
                    $textePaiement,
                    [...$metaFrais, 'mode_paiement' => $modePaiement, 'date_paiement' => $this->date_paiement->toIso8601String()],
                );

                /* Auto-régularisation */
                $this->statut = 'REGULARISE';
                $this->save();
                HistoriqueAction::enregistrer($this, HistoriqueAction::ACTION_REGULARISATION, 'PAYE', 'REGULARISE', $caissier->id,
                    'Bon provisoire auto-régularisé (pièces fournies avant paiement).',
                );

            } else {
                /* Pas de pièces → attente de régularisation (workflow standard) */
                $this->statut = 'EN_ATTENTE_REGULARISATION';
                $this->date_limite_regularisation = $this->dateLimiteRegularisation()->toDateString();
                $this->save();

                HistoriqueAction::enregistrer($this, HistoriqueAction::ACTION_PAIEMENT, $statutAvant, 'EN_ATTENTE_REGULARISATION', $caissier->id,
                    $textePaiement,
                    [
                        ...$metaFrais,
                        'mode_paiement' => $modePaiement,
                        'date_paiement' => $this->date_paiement->toIso8601String(),
                        'date_limite_regularisation' => $this->date_limite_regularisation?->format('Y-m-d'),
                    ],
                );
            }
        } else {
            /* BD : le bon reste « Payé » (SFD §1.4, Q14) ; l'archivage est une action à part */
            $this->statut = 'PAYE';
            $this->save();

            HistoriqueAction::enregistrer($this, HistoriqueAction::ACTION_PAIEMENT, $statutAvant, 'PAYE', $caissier->id,
                $textePaiement,
                [...$metaFrais, 'mode_paiement' => $modePaiement, 'date_paiement' => $this->date_paiement->toIso8601String()],
            );
        }

        return true;
    }

    /**
     * Régulariser un bon provisoire (BP)
     * 
     * @param int|null $utilisateurId ID de l'utilisateur effectuant la régularisation
     */
    public function regulariser(?int $utilisateurId = null, ?string $motifRegularisation = null): bool
    {
        if ($this->statut !== 'EN_ATTENTE_REGULARISATION') {
            return false;
        }

        $statutAvant = $this->statut;
        $this->statut = 'REGULARISE';
        $this->date_regularisation = now();
        $this->motif_regularisation = $motifRegularisation;
        $this->save();

        /* Enregistrer dans l'historique */
        HistoriqueAction::enregistrer(
            $this,
            HistoriqueAction::ACTION_REGULARISATION,
            $statutAvant,
            'REGULARISE',
            $utilisateurId,
            $motifRegularisation
                ? "Bon provisoire régularisé. Motif : {$motifRegularisation}"
                : 'Bon provisoire régularisé avec justificatifs.',
        );

        return true;
    }

    /**
     * Archiver le bon
     * 
     * @param int|null $utilisateurId ID de l'utilisateur effectuant l'archivage
     */
    public function archiver(?int $utilisateurId = null): bool
    {
        if (!in_array($this->statut, ['PAYE', 'REGULARISE', 'REJETE'])) {
            return false;
        }

        $statutAvant = $this->statut;
        $this->statut = 'ARCHIVE';
        $this->save();

        /* Enregistrer dans l'historique */
        HistoriqueAction::enregistrer(
            $this,
            HistoriqueAction::ACTION_ARCHIVAGE,
            $statutAvant,
            'ARCHIVE',
            $utilisateurId,
            'Bon archivé.',
        );

        return true;
    }

    /**
     * Enregistrer la création du bon dans l'historique
     */
    public function enregistrerCreation(): void
    {
        HistoriqueAction::enregistrer(
            $this,
            HistoriqueAction::ACTION_CREATION,
            null,
            'BROUILLON',
            $this->demandeur_id,
            'Bon de caisse ' . $this->numero . ' créé.',
            [
                'type_bon' => $this->type_bon,
                'montant' => $this->montant,
                'beneficiaire' => $this->beneficiaire,
            ],
        );
    }

    /**
     * Enregistrer une modification du bon dans l'historique
     */
    public function enregistrerModification(?int $utilisateurId = null, ?array $changementsMetadata = null): void
    {
        HistoriqueAction::enregistrer(
            $this,
            HistoriqueAction::ACTION_MODIFICATION,
            $this->statut,
            $this->statut,
            $utilisateurId,
            'Bon de caisse modifié.',
            $changementsMetadata,
        );
    }

    /**
     * Enregistrer l'ajout d'une pièce jointe dans l'historique
     */
    public function enregistrerAjoutPieceJointe(string $nomFichier, ?int $utilisateurId = null): void
    {
        HistoriqueAction::enregistrer(
            $this,
            HistoriqueAction::ACTION_AJOUT_PIECE_JOINTE,
            $this->statut,
            $this->statut,
            $utilisateurId,
            'Pièce jointe ajoutée : ' . $nomFichier,
            ['nom_fichier' => $nomFichier],
        );
    }

    /**
     * Date limite de régularisation d'un BP (RG-BC-14, RG-C3-01) :
     * lié à une mission → date de retour + délai « mission » ; sinon paiement + délai standard.
     * $paiement : date de paiement (aujourd'hui par défaut, pour une estimation avant paiement).
     */
    public function dateLimiteRegularisation(?\Carbon\CarbonInterface $paiement = null): \Carbon\CarbonInterface
    {
        $paiement = ($paiement ?? now())->copy()->startOfDay();

        /* RG-M07-02 (spec v2.2) : 3 jours ouvrés après le retour de mission, 2 jours ouvrés après le décaissement sinon */
        if ($this->lie_mission && $this->date_retour_mission) {
            $retour = $this->date_retour_mission->copy()->startOfDay();
            $depart = $retour->greaterThan($paiement) ? $retour : $paiement;

            return JoursOuvres::ajouter($depart, (int) Parametre::valeur('delai_regularisation_mission', self::DELAI_REGULARISATION_MISSION))->toMutable();
        }

        return JoursOuvres::ajouter($paiement, (int) Parametre::valeur('delai_regularisation_autre', self::DELAI_REGULARISATION_AUTRE))->toMutable();
    }

    /**
     * Vérifie si les pièces justificatives obligatoires sont présentes
     * Pour un BD, au moins une pièce est requise lors de la soumission
     */
    public function aPiecesObligatoires(): bool
    {
        if ($this->type_bon === 'BP') {
            return true; /* Les BP peuvent fournir les pièces après paiement */
        }
        return $this->piecesJointes()->count() > 0;
    }

    /**
     * Vérifie si le bon a des pièces de régularisation (justificatifs)
     */
    public function aDesPiecesRegularisation(): bool
    {
        return $this->piecesJointes()->where('type_document', 'justificatif')->count() > 0;
    }

    /**
     * Vérifie si le demandeur peut pré-régulariser (uploader des justificatifs avant paiement)
     * Possible pour les BP dès la soumission
     */
    public function peutPreRegulariser(): bool
    {
        return $this->type_bon === 'BP'
            && in_array($this->statut, [
                'EN_ATTENTE_CHEF_SERVICE',
                'EN_ATTENTE_CDG',
                'EN_ATTENTE_DAF',
                'EN_ATTENTE_DP',
                'APPROUVE',
            ]);
    }

    /**
     * Vérifie si le bon est modifiable (uniquement en brouillon)
     */
    public function estModifiable(): bool
    {
        return $this->statut === 'BROUILLON';
    }

    /**
     * Vérifie si le bon est soumissible
     */
    public function estSoumissible(): bool
    {
        return $this->statut === 'BROUILLON' && $this->montant <= Parametre::montantMax();
    }

    /**
     * Calcule le solde actuel de la caisse pour un site donné
     * Solde = approvisionnements validés - paiements effectués
     */
    public static function soldeCaisseActuel(?string $site = null): float
    {
        $siteModel = $site ? Site::where('nom', $site)->first() : null;
        if ($siteModel) {
            return (float) $siteModel->solde_caisse;
        }
        return 0;
    }

    /**
     * Calcule le délai total de traitement (soumission → paiement)
     */
    public function getDelaiTraitementAttribute(): ?string
    {
        if (!$this->date_soumission || !$this->date_paiement) {
            return null;
        }
        return Format::dureeEntre($this->date_soumission, $this->date_paiement);
    }
}
