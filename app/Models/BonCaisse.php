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
    /* Spec v2.4, RG-M01-02, RG-M01-08 : rattaché à une entité */
    use \App\Models\Concerns\AppartientAUneEntite;

    /** Entité d'un nouveau bon ou ODM : celle du demandeur (RG-M03-11), sinon l'entité courante */
    public function entiteParDefaut(): ?int
    {
        return ($this->demandeur_id ? User::whereKey($this->demandeur_id)->value('entite_id') : null)
            ?? \App\Support\EntiteCourante::filtre() ?? Entite::idParDefaut();
    }

    /**
     * Nom de la table en base de données (en français)
     */
    protected $table = 'bons_caisse';

    /**
     * Champs remplissables en masse
     */
    protected $fillable = [
        'entite_id',
        'motif_visiteur',
        'commentaire_visiteur',
        'refacturation_inter_filiale',
        'filiale_refacturation',
        'bp_regularise_id',
        'suivi_rh',
        'reference_paiement',
        'types_or',
        'numero_om',
        'motif_numero_om',
        'caisse_emettrice_id',
        'tiers_id',
        'visiteur_id',
        'doublon_probable',
        'derogation_bp',
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
        'odm_participant_id',
        'genere_par_odm',
        'montant_fcfa',
        'montant_gnf_fixe',
        'taux_change_estime',
        'taux_change_applique',
        'montant_estime',
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
            'types_or' => 'array',
            'doublon_probable' => 'array',
            'derogation_bp' => 'array',
            'suivi_rh' => 'array',
            'refacturation_inter_filiale' => 'boolean',
            'lie_mission' => 'boolean',
            'genere_par_odm' => 'boolean',
            'montant_fcfa' => 'decimal:2',
            'montant_gnf_fixe' => 'decimal:2',
            'taux_change_estime' => 'decimal:4',
            'taux_change_applique' => 'decimal:4',
            'montant_estime' => 'decimal:2',
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
        /* RG-M06-01 : un bon approuvé est « Bon à payer » pour le caissier */
        'APPROUVE' => 'Bon à payer',
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
        'employe' => 'Salarié',
        'visiteur' => 'Visiteur du groupe',
        'tiers' => 'Tiers validé',
        /* Saisie libre de la SFD v1.3, conservée pour les anciens bons */
        'fournisseur' => 'Fournisseur / prestataire',
        'prestataire' => 'Prestataire',
        'autre' => 'Autre tiers',
    ];

    /** Spec v2.3, RG-M03-03 : bénéficiaire choisi dans un référentiel (salarié, visiteur du groupe, tiers validé) */
    const TYPES_BENEFICIAIRE_ASSISTANT = ['employe', 'visiteur', 'tiers'];

    /** Modes de paiement */
    const MODES_PAIEMENT = [
        'especes' => 'Espèces',
        'orange_money' => 'Orange Money',
        'cheque' => 'Chèque',
        'virement' => 'Virement',
        /* §6.2 : paramétré, inactif par défaut (paramètre « mode_mtn_actif ») */
        'mtn_money' => 'MTN Money',
        'autre' => 'Autre',
    ];

    /** Modes proposés dans l'assistant (US-BC-06) ; « autre » est conservé pour les anciens bons */
    const MODES_PAIEMENT_ASSISTANT = ['especes', 'orange_money', 'cheque', 'virement'];

    /** Modes de paiement qui sortent d'une caisse ; les autres sont payés hors caisse (RG-BC-12) */
    const MODES_PAIEMENT_CAISSE = ['especes', 'orange_money'];

    /** RG-M06-04 : modes qui exigent une référence au paiement (transaction OM ou MTN, n° de chèque, référence de virement) */
    const MODES_AVEC_REFERENCE = ['orange_money', 'mtn_money', 'cheque', 'virement'];

    /** RG-M06-07 : modes que le caissier peut retenir (MTN Money s'il est activé ; plus de mode « autre ») */
    public static function modesCaissier(): array
    {
        $modes = ['especes', 'orange_money', 'cheque', 'virement'];
        if (Parametre::valeur('mode_mtn_actif', false)) {
            $modes[] = 'mtn_money';
        }

        return array_intersect_key(self::MODES_PAIEMENT, array_flip($modes));
    }

    /** Niveaux d'urgence */
    const NIVEAUX_URGENCE = [
        'normale' => 'Normale',
        'urgente' => 'Urgente',
        'tres_urgente' => 'Très urgente',
    ];

    /** Motifs de rejet prédéfinis */

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
     * Ordre de mission dont le bon est issu, ou auquel un BP de mission est rattaché (M12)
     */
    /** M16 : imputation comptable du bon payé */
    public function imputation(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Imputation::class);
    }

    /** Lot E (RG-M07-03) : justification du BP (Annexe 3) */
    public function justification(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(JustificationBp::class, 'bon_caisse_id');
    }

    /** Lot E (RG-M07-08) : BP régularisé par ce BD de complément */
    public function bpRegularise(): BelongsTo
    {
        return $this->belongsTo(self::class, 'bp_regularise_id');
    }

    /** Spec v2.3, RG-M03-11, RG-M03-18 : caisse émettrice (rattachement du demandeur), qui numérote le bon */
    public function caisseEmettrice(): BelongsTo
    {
        return $this->belongsTo(Caisse::class, 'caisse_emettrice_id');
    }

    /** Caisse qui paierait le bon dans ce mode (RG-BC-12 ; la caisse émettrice paie en espèces, lot C) */
    public function caissePayeuse(?string $mode = null): ?Caisse
    {
        /* Spec v2.4, RG-M02-11, RG-M03-27 : la caisse dépend de la caisse émettrice (à défaut du site du demandeur), pas du site analytique */
        return Caisse::payeusePour((string) ($this->demandeur?->site ?: $this->site), $mode ?? $this->mode_paiement, $this->caisseEmettrice);
    }

    /** Spec v2.3 : tiers validé (RG-M03-03) */
    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class, 'tiers_id');
    }

    /** Visiteur du groupe : BD seulement, à titre exceptionnel (spec v2.4, RG-M03-04, RG-M03-28) */
    public function visiteur(): BelongsTo
    {
        return $this->belongsTo(Visiteur::class, 'visiteur_id');
    }

    public function ordreMission(): BelongsTo
    {
        return $this->belongsTo(OrdreMission::class, 'odm_id');
    }

    /** Participant de l'ODM payé par ce bon (bon généré « un par participant », RG-M12-14) */
    public function participantOdm(): BelongsTo
    {
        return $this->belongsTo(ParticipantOdm::class, 'odm_participant_id');
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

        /* RG-M04-09 : étape où personne ne peut viser (seul valideur demandeur ou bénéficiaire, ou aucun) → niveau supérieur */
        \App\Services\BonCaisse\CircuitBon::sauterEtapesSansValideur($this);

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
            /* Spec v2.3, RG-M07-03 : le BP attend sa justification (Annexe 3) et ses visas, même si des pièces ont été
             * jointes avant le paiement (elles sont reprises dans les lignes de la justification) */
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
        } else {
            /* BD : le bon reste « Payé » (SFD §1.4, Q14) ; l'archivage est une action à part */
            $this->statut = 'PAYE';
            $this->save();

            HistoriqueAction::enregistrer($this, HistoriqueAction::ACTION_PAIEMENT, $statutAvant, 'PAYE', $caissier->id,
                $textePaiement,
                [...$metaFrais, 'mode_paiement' => $modePaiement, 'date_paiement' => $this->date_paiement->toIso8601String()],
            );
        }

        /* M12 (RG-M12-21) : l'ODM passe « Payé » quand tous ses bons sont payés */
        \App\Services\Odm\PaiementOdm::apresPaiement($this, $caissier);

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
