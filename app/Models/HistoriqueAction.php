<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle HistoriqueAction - Journal d'audit des bons de caisse
 * 
 * Enregistre chaque événement du cycle de vie d'un bon de caisse
 * pour assurer la traçabilité complète et l'audit trail.
 * 
 * Actions possibles :
 * - creation, modification, soumission
 * - validation_chef_service, validation_cdg, validation_daf, validation_dp
 * - rejet, demande_complement
 * - paiement, regularisation, archivage
 * - ajout_piece_jointe, relance_regularisation
 */
class HistoriqueAction extends Model
{
    use HasFactory;

    protected $table = 'historique_actions';

    protected $fillable = [
        'bon_caisse_id',
        'action',
        'statut_avant',
        'statut_apres',
        'utilisateur_id',
        'commentaire',
        'metadata',
        'adresse_ip',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /* ----------------------------------------------------------------
     * CONSTANTES — TYPES D'ACTIONS
     * ---------------------------------------------------------------- */

    const ACTION_CREATION = 'creation';
    const ACTION_MODIFICATION = 'modification';
    const ACTION_SOUMISSION = 'soumission';
    const ACTION_VALIDATION_CHEF_SERVICE = 'validation_chef_service';
    const ACTION_VALIDATION_CDG = 'validation_cdg';
    const ACTION_VALIDATION_DAF = 'validation_daf';
    const ACTION_VALIDATION_DP = 'validation_dp';
    const ACTION_REJET = 'rejet';
    const ACTION_DEMANDE_COMPLEMENT = 'demande_complement';
    const ACTION_PAIEMENT = 'paiement';
    const ACTION_REGULARISATION = 'regularisation';
    const ACTION_ARCHIVAGE = 'archivage';
    const ACTION_AJOUT_PIECE_JOINTE = 'ajout_piece_jointe';
    const ACTION_RELANCE_REGULARISATION = 'relance_regularisation';
    const ACTION_MODIFICATION_CODE_ANALYTIQUE = 'modification_code_analytique';
    const ACTION_MODIFICATION_VENTILATION = 'modification_ventilation';
    const ACTION_MOUVEMENT_CAISSE = 'mouvement_caisse';
    const ACTION_DELEGATION = 'delegation';

    /** Labels lisibles pour chaque action */
    const ACTIONS_LABELS = [
        'creation' => 'Création du bon',
        'modification' => 'Modification du bon',
        'soumission' => 'Soumission pour validation',
        'validation_chef_service' => 'Validation Chef de Service',
        'validation_cdg' => 'Validation Contrôle de Gestion',
        'validation_daf' => 'Validation DAF',
        'validation_dp' => 'Validation Directeur Pays',
        'rejet' => 'Rejet du bon',
        'demande_complement' => 'Demande de complément',
        'paiement' => 'Paiement effectué',
        'regularisation' => 'Régularisation',
        'archivage' => 'Archivage',
        'ajout_piece_jointe' => 'Ajout de pièce jointe',
        'relance_regularisation' => 'Relance de régularisation',
        'modification_code_analytique' => 'Modification code analytique (CDG)',
        'modification_ventilation' => 'Modification ventilation analytique',
        'mouvement_caisse' => 'Mouvement de caisse',
        'delegation' => 'Délégation de pouvoirs',
    ];

    /** Icônes pour chaque action (noms Lucide) */
    const ACTIONS_ICONES = [
        'creation' => 'FilePlus',
        'modification' => 'Pencil',
        'soumission' => 'Send',
        'validation_chef_service' => 'CheckCircle2',
        'validation_cdg' => 'CheckCircle2',
        'validation_daf' => 'CheckCircle2',
        'validation_dp' => 'CheckCircle2',
        'rejet' => 'XCircle',
        'demande_complement' => 'MessageSquare',
        'paiement' => 'Banknote',
        'regularisation' => 'ClipboardCheck',
        'archivage' => 'Archive',
        'ajout_piece_jointe' => 'Paperclip',
        'relance_regularisation' => 'Bell',
        'modification_code_analytique' => 'Pencil',
        'modification_ventilation' => 'GitBranch',
        'mouvement_caisse' => 'Wallet',
        'delegation' => 'UserCheck',
    ];

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    /**
     * Bon de caisse concerné
     */
    public function bonCaisse(): BelongsTo
    {
        return $this->belongsTo(BonCaisse::class, 'bon_caisse_id');
    }

    /**
     * Utilisateur ayant effectué l'action
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'utilisateur_id');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS
     * ---------------------------------------------------------------- */

    /**
     * Label lisible de l'action
     */
    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS_LABELS[$this->action] ?? $this->action;
    }

    /**
     * Icône de l'action
     */
    public function getActionIconeAttribute(): string
    {
        return self::ACTIONS_ICONES[$this->action] ?? 'Activity';
    }

    /**
     * Libellés des champs d'un bon, pour le journal « ancienne → nouvelle valeur ».
     * Les champs absents de cette liste (statut, dates du cycle de vie, numéro, montant en lettres…)
     * sont tracés par l'action qui les change (soumission, validation, paiement…).
     */
    const LIBELLES_CHAMPS = [
        'type_bon' => 'Type de bon',
        'site' => 'Site',
        'service' => 'Service',
        'code_analytique' => 'Code analytique',
        'beneficiaire' => 'Bénéficiaire',
        'type_beneficiaire' => 'Type de bénéficiaire',
        'telephone_beneficiaire' => 'Téléphone du bénéficiaire',
        'mode_paiement' => 'Mode de paiement',
        'motif' => 'Motif',
        'categorie_depense' => 'Catégorie de dépense',
        'montant' => 'Montant',
        'devise' => 'Devise',
        'niveau_urgence' => "Niveau d'urgence",
        'motif_urgence' => "Motif d'urgence",
        'justification_urgence' => "Justification de l'urgence",
        'date_demande' => 'Date de la demande',
    ];

    /* ----------------------------------------------------------------
     * MÉTHODES STATIQUES — ENREGISTREMENT D'ÉVÉNEMENTS
     * ---------------------------------------------------------------- */

    /**
     * Changements d'un bon qui vient d'être enregistré, au format du journal :
     * [['champ' => 'montant', 'libelle' => 'Montant', 'avant' => '500000.00', 'apres' => '600000.00'], ...]
     * (à appeler dans l'événement « updated », quand getOriginal() contient encore les anciennes valeurs).
     */
    public static function changementsDe(BonCaisse $bonCaisse): array
    {
        $changements = [];
        foreach (array_keys($bonCaisse->getChanges()) as $champ) {
            if (!isset(self::LIBELLES_CHAMPS[$champ])) {
                continue;
            }
            $changements[] = [
                'champ' => $champ,
                'libelle' => self::LIBELLES_CHAMPS[$champ],
                'avant' => self::valeurJournal($bonCaisse, $champ, $bonCaisse->getRawOriginal($champ)),
                'apres' => self::valeurJournal($bonCaisse, $champ, $bonCaisse->getAttributes()[$champ] ?? null),
            ];
        }

        return $changements;
    }

    /**
     * Modification d'un bon (SFD §1.2 : chaque action qui modifie une donnée écrit une ligne
     * avec ancienne → nouvelle valeur). Rien n'est écrit si aucun champ suivi n'a changé.
     */
    public static function enregistrerChangements(BonCaisse $bonCaisse, string $action = self::ACTION_MODIFICATION, ?string $commentaire = null): ?self
    {
        $changements = self::changementsDe($bonCaisse);
        if (empty($changements)) {
            return null;
        }

        return self::enregistrer(
            $bonCaisse,
            $action,
            $bonCaisse->statut,
            $bonCaisse->statut,
            auth()->id(),
            $commentaire,
            ['changements' => $changements],
        );
    }

    /** Valeur brute en texte ; décimaux normalisés selon leur cast (500000 et « 500000.00 » → « 500000.00 ») */
    private static function valeurJournal(BonCaisse $bonCaisse, string $champ, mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $cast = $bonCaisse->getCasts()[$champ] ?? null;
        if (is_string($cast) && str_starts_with($cast, 'decimal:') && is_numeric($valeur)) {
            return number_format((float) $valeur, (int) substr($cast, 8), '.', '');
        }

        return (string) $valeur;
    }

    /**
     * Enregistrer un événement dans l'historique d'un bon de caisse
     */
    public static function enregistrer(
        BonCaisse $bonCaisse,
        string $action,
        ?string $statutAvant = null,
        ?string $statutApres = null,
        ?int $utilisateurId = null,
        ?string $commentaire = null,
        ?array $metadata = null,
    ): self {
        return self::create([
            'bon_caisse_id' => $bonCaisse->id,
            'action' => $action,
            'statut_avant' => $statutAvant,
            'statut_apres' => $statutApres,
            'utilisateur_id' => $utilisateurId,
            'commentaire' => $commentaire,
            'metadata' => $metadata,
            'adresse_ip' => request()?->ip(),
        ]);
    }
}
