<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordre de mission (ODM) — module M12, spec v2.2 §7.3.
 *
 * Un enregistrement est un segment de mission : l'ODM initial (rang 1) ou une prolongation (rang 2, 3…),
 * liée au segment précédent. La mission est la chaîne de segments, repérée par l'ODM initial (mission_id).
 *
 * L'ODM est créé et validé d'abord (chef d'atelier → DAF → DP) ; les bons de caisse sont ensuite générés
 * depuis l'ODM validé et suivent leur propre circuit complet.
 */
class OrdreMission extends Model
{
    use HasFactory;

    protected $table = 'ordres_mission';

    /** RG-M12-21 */
    public const STATUTS = [
        'BROUILLON' => 'Brouillon',
        'SOUMIS' => 'Soumis',
        'EN_VALIDATION' => 'En validation',
        'REJETE' => 'Rejeté',
        'VALIDE' => 'Validé',
        'BONS_GENERES' => 'Bons générés',
        'PAYE' => 'Payé',
        'CLOTURE' => 'Clôturé',
        'ANNULE' => 'Annulé',
    ];

    /** Statuts d'un ODM en cours de circuit */
    public const STATUTS_EN_CIRCUIT = ['SOUMIS', 'EN_VALIDATION'];

    /** Q25 : ODM retenus pour le contrôle de chevauchement (tous, sauf brouillons et annulés) */
    public const STATUTS_CHEVAUCHEMENT = ['SOUMIS', 'EN_VALIDATION', 'REJETE', 'VALIDE', 'BONS_GENERES', 'PAYE', 'CLOTURE'];

    public const TYPES = [
        'interieur' => 'Intérieur',
        'exterieur' => 'Extérieur',
    ];

    /** En-tête : défaut appliqué à toutes les lignes ; « mixte » quand les lignes diffèrent (Q49) */
    public const PRISES_EN_CHARGE = [
        'neemba' => 'Neemba',
        'client' => 'Client',
        'mixte' => 'Mixte (selon les lignes)',
    ];

    /** Lignes à la charge du client : avancées par Neemba puis refacturées, ou payées directement (Q49) */
    public const MODES_CLIENT = [
        'avance' => 'Avancé par Neemba, refacturé',
        'direct' => 'Payé directement par le client',
    ];

    /** RG-M12-26 */
    public const HEBERGEMENTS_EXTERIEURS = [
        'filiale' => "Pris en charge par la filiale d'accueil",
        'avant_depart' => 'Facture payée en GNF avant le départ',
        'au_retour' => 'Facture payée en GNF au retour',
    ];

    /**
     * RG-M12-11 : circuit de l'ODM. Rôles qui visent à chaque niveau (décision Q27 pour le DAF).
     * L'étape RH n'est insérée que si le paramètre « odm_etape_rh » est actif.
     */
    public const NIVEAUX = [
        'chef_atelier' => ['libelle' => "Chef d'atelier / chef d'équipe", 'roles' => ['chef_atelier']],
        'daf' => ['libelle' => 'DAF', 'roles' => ['daf', 'daf_adjoint', 'chef_comptable']],
        'rh' => ['libelle' => 'Ressources humaines', 'roles' => ['rh']],
        'directeur_pays' => ['libelle' => 'Directeur Pays', 'roles' => ['directeur_pays', 'dp_adjoint']],
    ];

    /** RG-M12-02 (PO-06) : nature technique pré-cochée pour ces services */
    public const SERVICES_TECHNIQUES = ['Technique', 'Aftermarket'];

    protected $fillable = [
        'numero', 'prefixe', 'sequence', 'annee',
        'type', 'technique',
        'entite', 'site', 'service', 'code_analytique', 'demandeur_id', 'initiateur_id',
        'but', 'clients', 'destinations', 'vehicule',
        'date_depart', 'date_retour_prevue', 'date_retour_reelle', 'motif_depart_passe',
        'prise_en_charge', 'mode_client', 'a_refacturer', 'montant_a_refacturer',
        'hebergement_exterieur', 'reference_billet',
        'mission_id', 'segment_precedent_id', 'rang',
        'statut', 'version', 'cle_soumission', 'total', 'parametres_figes',
        'derogation_statut', 'derogation_demande_motif', 'derogation_par_id', 'derogation_motif', 'derogation_le',
        'date_soumission', 'date_validation', 'date_cloture', 'date_annulation', 'annule_par_id', 'motif_annulation',
        'rappel_envoye_le',
    ];

    protected function casts(): array
    {
        return [
            'technique' => 'boolean',
            'a_refacturer' => 'boolean',
            'clients' => 'array',
            'destinations' => 'array',
            'parametres_figes' => 'array',
            'date_depart' => 'date',
            'date_retour_prevue' => 'date',
            'date_retour_reelle' => 'date',
            'total' => 'decimal:2',
            'montant_a_refacturer' => 'decimal:2',
            'derogation_le' => 'datetime',
            'date_soumission' => 'datetime',
            'date_validation' => 'datetime',
            'date_cloture' => 'datetime',
            'date_annulation' => 'datetime',
            'rappel_envoye_le' => 'datetime',
        ];
    }

    /** Prise en charge d'une ligne non encore choisie (nouveau participant) : celle de l'en-tête, Neemba si « mixte » */
    public function priseParDefaut(): string
    {
        return $this->prise_en_charge === 'client' ? 'client_' . ($this->mode_client === 'direct' ? 'direct' : 'avance') : 'neemba';
    }

    /* ----------------------------------------------------------------
     * RELATIONS
     * ---------------------------------------------------------------- */

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function initiateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiateur_id');
    }

    /** Tous les participants, y compris ceux retirés d'une prolongation */
    public function participants(): HasMany
    {
        return $this->hasMany(ParticipantOdm::class, 'ordre_mission_id')->orderBy('id');
    }

    /** Participants qui partent sur ce segment */
    public function participantsActifs(): HasMany
    {
        return $this->participants()->where('retire', false);
    }

    public function ordresReparation(): HasMany
    {
        return $this->hasMany(OrdreReparationOdm::class, 'ordre_mission_id')->orderBy('id');
    }

    public function etapes(): HasMany
    {
        return $this->hasMany(EtapeOdm::class, 'ordre_mission_id')->orderBy('version')->orderBy('niveau');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(HistoriqueOdm::class, 'ordre_mission_id')->orderBy('created_at')->orderBy('id');
    }

    /** Bons de caisse générés depuis cet ODM */
    public function bons(): HasMany
    {
        return $this->hasMany(BonCaisse::class, 'odm_id');
    }

    /** ODM initial de la mission (null pour l'ODM initial lui-même) */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mission_id');
    }

    public function segmentPrecedent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'segment_precedent_id');
    }

    /** Prolongations rattachées à cet ODM initial */
    public function prolongations(): HasMany
    {
        return $this->hasMany(self::class, 'mission_id')->orderBy('rang');
    }

    public function derogationPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'derogation_par_id');
    }

    /* ----------------------------------------------------------------
     * ACCESSEURS ET AIDES
     * ---------------------------------------------------------------- */

    public function getStatutLabelAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** Numéro, ou « Brouillon » tant que l'ODM n'est pas soumis */
    public function getLibelleAttribute(): string
    {
        return $this->numero ?? 'Brouillon';
    }

    public function estProlongation(): bool
    {
        return $this->segment_precedent_id !== null;
    }

    /** Q23 : « Prolongation 2 de N°285/AT/26 » */
    public function getLibelleProlongationAttribute(): ?string
    {
        if (!$this->estProlongation()) {
            return null;
        }
        $initial = $this->mission ?? $this->segmentPrecedent;

        return 'Prolongation ' . ($this->rang - 1) . ' de ' . ($initial?->numero ?? 'l\'ODM initial');
    }

    /** ODM initial de la mission (lui-même pour le segment 1) */
    public function initial(): self
    {
        return $this->mission_id ? ($this->mission ?? $this) : $this;
    }

    /** Segments de la mission, du premier au dernier */
    public function segmentsDeLaMission(): \Illuminate\Support\Collection
    {
        $initial = $this->initial();

        return collect([$initial])->merge($initial->prolongations()->get())->values();
    }

    /** Date de fin du segment : retour réel s'il est saisi, sinon retour prévu */
    public function dateFin(): ?\Carbon\CarbonInterface
    {
        return $this->date_retour_reelle ?? $this->date_retour_prevue;
    }

    public function getTotalFormatAttribute(): string
    {
        return $this->total === null ? '—' : Format::montant($this->total);
    }

    /** Niveaux du circuit, dans l'ordre, selon le paramètre de l'étape RH */
    public static function niveauxDuCircuit(): array
    {
        $niveaux = ['chef_atelier', 'daf'];
        if (Parametre::valeur('odm_etape_rh', false)) {
            $niveaux[] = 'rh';
        }
        $niveaux[] = 'directeur_pays';

        return $niveaux;
    }

    /* ----------------------------------------------------------------
     * DROITS
     * ---------------------------------------------------------------- */

    /** Rôles qui voient tous les ODM : circuit (DAF, DP), Trésorerie (taux, US-15), RH (retenues), administration */
    public const ROLES_VISION_GLOBALE = ['daf', 'daf_adjoint', 'chef_comptable', 'directeur_pays', 'dp_adjoint', 'tresorerie', 'rh', 'administrateur'];

    /** Rôles qui décident d'une dérogation au chevauchement (RG-M12-16) */
    public const ROLES_DEROGATION = ['daf', 'daf_adjoint'];

    public function estVisiblePar(User $utilisateur): bool
    {
        return static::visiblesPar($utilisateur)->whereKey($this->id)->exists();
    }

    /** Demandeur et initiateur, tant que l'ODM est en brouillon ou rejeté */
    public function estModifiablePar(User $utilisateur): bool
    {
        return in_array($this->statut, ['BROUILLON', 'REJETE'], true)
            && in_array($utilisateur->id, [$this->demandeur_id, $this->initiateur_id], true);
    }

    /* ----------------------------------------------------------------
     * SCOPES
     * ---------------------------------------------------------------- */

    /** Segments qui comptent pour le chevauchement (Q25) */
    public function scopePourChevauchement($query)
    {
        return $query->whereIn('statut', self::STATUTS_CHEVAUCHEMENT);
    }

    /**
     * ODM visibles : les siens (demandeur, initiateur, participant), ceux de son service pour un chef d'atelier,
     * ceux qu'on a visés, et tous pour les rôles de vision globale. Les brouillons ne sont visibles que de leurs auteurs.
     */
    public function scopeVisiblesPar($query, User $utilisateur)
    {
        $roles = $utilisateur->listeRoles();

        return $query->where(function ($q) use ($utilisateur, $roles) {
            $q->where('demandeur_id', $utilisateur->id)
                ->orWhere('initiateur_id', $utilisateur->id)
                /* Dérogation au chevauchement demandée sur un brouillon : le DAF doit pouvoir l'examiner */
                ->when(array_intersect($roles, self::ROLES_DEROGATION), fn ($d) => $d->orWhere('derogation_statut', 'demandee'))
                ->orWhere(fn ($soumis) => $soumis->where('statut', '!=', 'BROUILLON')->where(function ($s) use ($utilisateur, $roles) {
                    $s->whereHas('participants', fn ($p) => $p->where('user_id', $utilisateur->id))
                        ->orWhereHas('etapes', fn ($e) => $e->where('valideur_id', $utilisateur->id));
                    if (array_intersect($roles, self::ROLES_VISION_GLOBALE)) {
                        $s->orWhereRaw('1 = 1');
                    }
                    if (in_array('chef_atelier', $roles, true) && $utilisateur->service) {
                        $s->orWhere('service', $utilisateur->service);
                    }
                }));
        });
    }
}
