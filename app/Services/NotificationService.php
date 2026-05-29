<?php

namespace App\Services;

use App\Events\NouvelleNotification;
use App\Models\BonCaisse;
use App\Models\Notification;
use App\Models\User;

/**
 * Service de Notification Intelligente NEEMBA
 * 
 * Centralise la logique d'envoi des notifications push en temps réel.
 * Détermine automatiquement les destinataires selon le type d'action
 * et le contexte métier (rôle, workflow de validation, etc.).
 */
class NotificationService
{
    /**
     * Notifier lors d'une soumission de bon
     * → Destinataires : les responsables de service actifs
     * → Si urgence : email et/ou SMS progressif
     */
    public static function notifierSoumission(BonCaisse $bon, User $demandeur): void
    {
        /* Seuls les chefs de service du MÊME service que le demandeur (propres et délégués) */
        $destinataires = self::destinatairesParRole('responsable_service', $bon);

        $urgenceLabel = self::labelUrgence($bon->niveau_urgence);

        foreach ($destinataires as $destinataire) {
            $titre = $bon->niveau_urgence !== 'normale'
                ? "[{$urgenceLabel}] Nouveau bon à valider"
                : 'Nouveau bon à valider';

            self::creerEtDiffuser(
                destinataire: $destinataire,
                bon: $bon,
                expediteur: $demandeur,
                type: Notification::TYPE_SOUMISSION,
                titre: $titre,
                message: "{$demandeur->nom_complet} a soumis le bon {$bon->numero} ({$bon->montant_format}) pour validation."
                    . ($bon->niveau_urgence !== 'normale' ? " ⚠ Niveau d'urgence : {$urgenceLabel}." : ''),
                metadata: [
                    'montant' => $bon->montant,
                    'type_bon' => $bon->type_bon,
                    'niveau_urgence' => $bon->niveau_urgence,
                ],
            );

            /* Notifications progressives selon l'urgence */
            self::notifierParUrgence($bon, $destinataire, $demandeur);
        }
    }

    /**
     * Notifier lors d'une validation (approbation d'un niveau)
     * → Destinataires : le demandeur + le prochain validateur dans la chaîne
     */
    public static function notifierValidation(BonCaisse $bon, User $validateur): void
    {
        $roleLabel = self::labelRole($validateur->role);

        /* Notifier le demandeur */
        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: $validateur,
            type: Notification::TYPE_VALIDATION,
            titre: 'Bon validé',
            message: "Votre bon {$bon->numero} a été validé par {$roleLabel} ({$validateur->nom_complet}).",
            metadata: ['role_validateur' => $validateur->role],
        );

        /* Si le bon n'est pas encore approuvé, notifier le prochain validateur */
        $prochainRole = self::prochainRoleValidateur($bon->statut);
        if ($prochainRole) {
            $urgenceLabel = self::labelUrgence($bon->niveau_urgence);
            $prochainsValidateurs = self::destinatairesParRole($prochainRole, $bon);
            foreach ($prochainsValidateurs as $prochain) {
                $titre = $bon->niveau_urgence !== 'normale'
                    ? "[{$urgenceLabel}] Bon en attente de votre validation"
                    : 'Bon en attente de votre validation';

                self::creerEtDiffuser(
                    destinataire: $prochain,
                    bon: $bon,
                    expediteur: $validateur,
                    type: Notification::TYPE_SOUMISSION,
                    titre: $titre,
                    message: "Le bon {$bon->numero} de {$bon->demandeur->nom_complet} ({$bon->montant_format}) attend votre validation."
                        . ($bon->niveau_urgence !== 'normale' ? " ⚠ Niveau d'urgence : {$urgenceLabel}." : ''),
                    metadata: [
                        'montant' => $bon->montant,
                        'type_bon' => $bon->type_bon,
                        'valideur_precedent' => $validateur->nom_complet,
                        'niveau_urgence' => $bon->niveau_urgence,
                    ],
                );

                /* Notifications progressives selon l'urgence */
                self::notifierParUrgence($bon, $prochain, $validateur);
            }
        }
    }

    /**
     * Notifier lors de l'approbation finale
     * → Destinataires : le demandeur + les caissiers
     */
    public static function notifierApprobationFinale(BonCaisse $bon, User $validateur): void
    {
        /* Notifier le demandeur */
        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: $validateur,
            type: Notification::TYPE_APPROBATION_FINALE,
            titre: 'Bon approuvé — prêt au paiement',
            message: "Votre bon {$bon->numero} a été approuvé. Il est maintenant en attente de paiement.",
        );

        /* Notifier les caissiers */
        $caissiers = User::actifs()->parRole('caissier')->get();
        foreach ($caissiers as $caissier) {
            self::creerEtDiffuser(
                destinataire: $caissier,
                bon: $bon,
                expediteur: $validateur,
                type: Notification::TYPE_APPROBATION_FINALE,
                titre: 'Nouveau bon à payer',
                message: "Le bon {$bon->numero} de {$bon->demandeur->nom_complet} ({$bon->montant_format}) est approuvé et prêt au paiement.",
                metadata: [
                    'montant' => $bon->montant,
                    'mode_paiement' => $bon->mode_paiement,
                ],
            );
        }
    }

    /**
     * Notifier lors d'un rejet
     * → Destinataire : le demandeur
     */
    public static function notifierRejet(BonCaisse $bon, User $validateur, ?string $motif = null): void
    {
        $roleLabel = self::labelRole($validateur->role);

        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: $validateur,
            type: Notification::TYPE_REJET,
            titre: 'Bon rejeté',
            message: "Votre bon {$bon->numero} a été rejeté par {$roleLabel} ({$validateur->nom_complet})."
                . ($motif ? " Motif : {$motif}" : ''),
            metadata: ['motif' => $motif, 'role_validateur' => $validateur->role],
        );
    }

    /**
     * Notifier lors d'une demande de complément
     * → Destinataire : le demandeur
     */
    public static function notifierDemandeComplement(BonCaisse $bon, User $validateur, string $commentaire): void
    {
        $roleLabel = self::labelRole($validateur->role);

        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: $validateur,
            type: Notification::TYPE_DEMANDE_COMPLEMENT,
            titre: 'Complément d\'information demandé',
            message: "{$roleLabel} ({$validateur->nom_complet}) demande un complément sur votre bon {$bon->numero} : \"{$commentaire}\"",
            metadata: ['commentaire' => $commentaire, 'role_validateur' => $validateur->role],
        );
    }

    /**
     * Notifier lors d'un paiement
     * → Destinataire : le demandeur
     */
    public static function notifierPaiement(BonCaisse $bon, User $caissier): void
    {
        $modePaiementLabel = BonCaisse::MODES_PAIEMENT[$bon->mode_paiement_effectif] ?? $bon->mode_paiement_effectif;

        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: $caissier,
            type: Notification::TYPE_PAIEMENT,
            titre: 'Paiement effectué',
            message: "Votre bon {$bon->numero} a été payé par {$caissier->nom_complet} ({$modePaiementLabel})."
                . ($bon->type_bon === 'BP' ? ' N\'oubliez pas de régulariser avant le ' . $bon->date_limite_regularisation?->format('d/m/Y') . '.' : ''),
            metadata: [
                'mode_paiement' => $bon->mode_paiement_effectif,
                'date_limite_regularisation' => $bon->date_limite_regularisation?->format('Y-m-d'),
            ],
        );
    }

    /**
     * Notifier lors d'une régularisation
     * → Destinataires : le caissier qui a payé + DAF
     */
    public static function notifierRegularisation(BonCaisse $bon, User $utilisateur): void
    {
        /* Notifier le caissier */
        if ($bon->caissier_id) {
            self::creerEtDiffuser(
                destinataire: $bon->caissier,
                bon: $bon,
                expediteur: $utilisateur,
                type: Notification::TYPE_REGULARISATION,
                titre: 'Bon régularisé',
                message: "Le bon provisoire {$bon->numero} de {$bon->demandeur->nom_complet} a été régularisé.",
            );
        }

        /* Notifier le DAF */
        $dafs = User::actifs()->parRole('daf')->get();
        foreach ($dafs as $daf) {
            self::creerEtDiffuser(
                destinataire: $daf,
                bon: $bon,
                expediteur: $utilisateur,
                type: Notification::TYPE_REGULARISATION,
                titre: 'Bon provisoire régularisé',
                message: "Le BP {$bon->numero} de {$bon->demandeur->nom_complet} ({$bon->montant_format}) a été régularisé avec justificatifs.",
            );
        }
    }

    /**
     * Notifier la relance de régularisation (commande Artisan)
     * → Destinataire : le demandeur
     */
    public static function notifierRelanceRegularisation(BonCaisse $bon): void
    {
        $joursRetard = $bon->jours_restants_regularisation;
        $retardLabel = $joursRetard !== null && $joursRetard < 0
            ? abs($joursRetard) . ' jour(s) de retard'
            : 'en retard';

        $metadata = [
            'jours_retard' => $joursRetard ? abs($joursRetard) : 0,
            'date_limite' => $bon->date_limite_regularisation?->format('Y-m-d'),
        ];

        /* Notifier le demandeur */
        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: null,
            type: Notification::TYPE_RELANCE_REGULARISATION,
            titre: 'Régularisation en retard',
            message: "Votre bon provisoire {$bon->numero} ({$bon->montant_format}) est en attente de régularisation ({$retardLabel}). Veuillez fournir les justificatifs au plus vite.",
            metadata: $metadata,
        );

        /* Notifier le(s) responsable(s) de service du demandeur */
        $responsables = self::destinatairesParRole('responsable_service', $bon);
        foreach ($responsables as $responsable) {
            self::creerEtDiffuser(
                destinataire: $responsable,
                bon: $bon,
                expediteur: null,
                type: Notification::TYPE_RELANCE_REGULARISATION,
                titre: 'BP en retard de régularisation — Action requise',
                message: "{$bon->demandeur?->nom_complet} n'a pas encore régularisé le bon provisoire {$bon->numero} ({$bon->montant_format}). Retard : {$retardLabel}. Veuillez relancer votre collaborateur.",
                metadata: $metadata,
            );
        }
    }

    /**
     * Notifier l'archivage
     * → Destinataire : le demandeur
     */
    public static function notifierArchivage(BonCaisse $bon, User $utilisateur): void
    {
        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: $utilisateur,
            type: Notification::TYPE_ARCHIVAGE,
            titre: 'Bon archivé',
            message: "Votre bon {$bon->numero} a été archivé par {$utilisateur->nom_complet}.",
        );
    }

    /**
     * Notifier la relance SLA (validation en retard)
     * → Destinataires : les validateurs du rôle concerné
     */
    public static function notifierRelanceSla(BonCaisse $bon, string $role, float $heuresRetard): void
    {
        $roleLabel = self::labelRole($role);
        $destinataires = self::destinatairesParRole($role, $bon);

        foreach ($destinataires as $destinataire) {
            self::creerEtDiffuser(
                destinataire: $destinataire,
                bon: $bon,
                expediteur: null,
                type: Notification::TYPE_RELANCE_SLA,
                titre: 'Relance : validation en attente',
                message: "Le bon {$bon->numero} de {$bon->demandeur->nom_complet} ({$bon->montant_format}) attend votre validation depuis " . round($heuresRetard) . "h. Merci d'agir rapidement.",
                metadata: [
                    'role_cible' => $role,
                    'heures_retard' => round($heuresRetard, 1),
                    'montant' => $bon->montant,
                ],
            );
        }
    }

    /**
     * Notifier l'escalade automatique (SLA × multiplicateur dépassé)
     * → Destinataires : les validateurs du niveau N+1 + le demandeur
     */
    public static function notifierEscaladeSla(BonCaisse $bon, string $roleOrigine, string $roleEscalade): void
    {
        $roleLabelOrigine = self::labelRole($roleOrigine);
        $roleLabelEscalade = self::labelRole($roleEscalade);

        /* Notifier le niveau N+1 */
        $destinataires = self::destinatairesParRole($roleEscalade, $bon);
        foreach ($destinataires as $destinataire) {
            self::creerEtDiffuser(
                destinataire: $destinataire,
                bon: $bon,
                expediteur: null,
                type: Notification::TYPE_ESCALADE_SLA,
                titre: 'Escalade : bon en attente prolongée',
                message: "Le bon {$bon->numero} ({$bon->montant_format}) de {$bon->demandeur->nom_complet} n'a pas été traité par {$roleLabelOrigine} dans les délais. Il a été escaladé pour votre attention.",
                metadata: [
                    'role_origine' => $roleOrigine,
                    'role_escalade' => $roleEscalade,
                    'montant' => $bon->montant,
                ],
            );
        }

        /* Notifier le demandeur */
        self::creerEtDiffuser(
            destinataire: $bon->demandeur,
            bon: $bon,
            expediteur: null,
            type: Notification::TYPE_ESCALADE_SLA,
            titre: 'Votre bon a été escaladé',
            message: "Votre bon {$bon->numero} n'a pas été traité par {$roleLabelOrigine} dans les délais. Il a été automatiquement escaladé à {$roleLabelEscalade}.",
            metadata: [
                'role_origine' => $roleOrigine,
                'role_escalade' => $roleEscalade,
            ],
        );
    }

    /**
     * Notifier l'alerte de solde minimum de caisse
     * → Destinataires : DAF + Directeur Pays + caissiers du site
     * → SMS envoyé aux caissiers du site pour action rapide (réapprovisionnement)
     */
    public static function notifierAlerteSolde(\App\Models\Site $site, ?User $declencheur = null): void
    {
        $destinataires = User::actifs()
            ->where(function ($q) use ($site) {
                $q->whereIn('role', ['daf', 'directeur_pays'])
                    ->orWhere(function ($q2) use ($site) {
                        $q2->where('role', 'caissier')
                            ->where('site', $site->nom);
                    });
            })
            ->get();

        $seuilFormat = number_format($site->seuil_minimum_caisse, 0, ',', ' ') . ' GNF';

        foreach ($destinataires as $destinataire) {
            if ($declencheur && $destinataire->id === $declencheur->id) continue;

            $notification = \App\Models\Notification::create([
                'destinataire_id' => $destinataire->id,
                'bon_caisse_id' => null,
                'expediteur_id' => $declencheur?->id,
                'type' => 'alerte_solde',
                'titre' => '⚠ Alerte : solde de caisse bas',
                'message' => "Le solde de la caisse du site {$site->nom} est passé sous le seuil minimum. "
                    . "Solde actuel : {$site->solde_caisse_format}. "
                    . "Seuil minimum : {$seuilFormat}. "
                    . "Un réapprovisionnement est recommandé.",
                'metadata' => [
                    'site' => $site->nom,
                    'solde' => $site->solde_caisse,
                    'seuil' => $site->seuil_minimum_caisse,
                ],
            ]);

            try {
                broadcast(new \App\Events\NouvelleNotification($notification));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Broadcast alerte solde échoué : ' . $e->getMessage());
            }

            /* Envoyer un SMS aux caissiers du site pour action rapide */
            if ($destinataire->role === 'caissier' && $destinataire->telephone) {
                try {
                    $smsService = new NimbaSmsService();
                    $smsService->envoyerAlerteSeuil(
                        $destinataire->telephone,
                        $site->nom,
                        $site->solde_caisse_format,
                        $seuilFormat
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("SMS alerte seuil échoué pour {$destinataire->telephone} : " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Vérification proactive des seuils de caisse pour tous les sites
     * Appelée par la commande Artisan caisse:verifier-seuils
     * 
     * → Dédoublonnage : max 1 alerte par jour par site (via cache)
     * → Envoie push + SMS aux caissiers du site
     * 
     * @return array Résumé des alertes envoyées ['site' => 'message']
     */
    public static function verifierSeuilsCaisse(): array
    {
        $resultats = [];
        $sites = \App\Models\Site::actifs()->get();

        foreach ($sites as $site) {
            if (!$site->soldeSousSeuil()) {
                continue;
            }

            /* Dédoublonnage : max 1 alerte par jour par site */
            $cacheKey = "alerte_seuil_caisse_{$site->id}_" . now()->format('Y-m-d');
            if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                $resultats[$site->nom] = 'Alerte déjà envoyée aujourd\'hui — ignorée';
                continue;
            }

            /* Envoyer les alertes */
            self::notifierAlerteSolde($site);

            /* Marquer l'alerte comme envoyée pour aujourd'hui */
            \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->endOfDay());

            $resultats[$site->nom] = "Solde : {$site->solde_caisse_format} — Seuil : "
                . number_format($site->seuil_minimum_caisse, 0, ',', ' ') . ' GNF — Alertes envoyées';
        }

        return $resultats;
    }

    /* ----------------------------------------------------------------
     * MOUVEMENTS DE CAISSE
     * ---------------------------------------------------------------- */

    /**
     * Notifier la création d'un mouvement de caisse
     * → Destinataires : tous les DAF et Directeurs Pays
     */
    public static function notifierMouvementCaisseCreee(\App\Models\MouvementCaisse $mouvement, User $createur): void
    {
        $typeLabel = match ($mouvement->type) {
            'approvisionnement' => 'Approvisionnement',
            'retrait'           => 'Retrait',
            'ajustement'        => 'Ajustement',
            default             => ucfirst($mouvement->type),
        };

        $montantFormat = number_format($mouvement->montant, 0, ',', ' ') . ' GNF';

        $destinataires = User::actifs()
            ->whereIn('role', ['daf', 'directeur_pays'])
            ->get();

        foreach ($destinataires as $dest) {
            if ($dest->id === $createur->id) continue;
            self::creerEtDiffuserSimple(
                destinataire: $dest,
                expediteur: $createur,
                type: Notification::TYPE_MOUVEMENT_CAISSE,
                titre: "Nouveau mouvement de caisse à valider",
                message: "{$createur->nom_complet} a créé un {$typeLabel} de {$montantFormat} sur le site {$mouvement->site} ({$mouvement->reference}). En attente de votre validation.",
                metadata: [
                    'reference'   => $mouvement->reference,
                    'type'        => $mouvement->type,
                    'montant'     => $mouvement->montant,
                    'site'        => $mouvement->site,
                    'mouvement_id' => $mouvement->id,
                ],
            );
        }
    }

    /**
     * Notifier la validation d'un mouvement de caisse
     * → Destinataire : le créateur du mouvement
     */
    public static function notifierMouvementCaisseValidee(\App\Models\MouvementCaisse $mouvement, User $validateur): void
    {
        $createur = $mouvement->effectuePar;
        if (!$createur || $createur->id === $validateur->id) return;

        $montantFormat = number_format($mouvement->montant, 0, ',', ' ') . ' GNF';

        self::creerEtDiffuserSimple(
            destinataire: $createur,
            expediteur: $validateur,
            type: Notification::TYPE_MOUVEMENT_CAISSE,
            titre: 'Mouvement de caisse validé',
            message: "Votre mouvement {$mouvement->reference} ({$montantFormat} — {$mouvement->site}) a été validé par {$validateur->nom_complet}. Le solde du site a été mis à jour.",
            metadata: [
                'reference'   => $mouvement->reference,
                'montant'     => $mouvement->montant,
                'site'        => $mouvement->site,
                'mouvement_id' => $mouvement->id,
                'action'      => 'validee',
            ],
        );
    }

    /**
     * Notifier le rejet d'un mouvement de caisse
     * → Destinataire : le créateur du mouvement
     */
    public static function notifierMouvementCaisseRejetee(\App\Models\MouvementCaisse $mouvement, User $validateur, ?string $commentaire = null): void
    {
        $createur = $mouvement->effectuePar;
        if (!$createur || $createur->id === $validateur->id) return;

        $montantFormat = number_format($mouvement->montant, 0, ',', ' ') . ' GNF';

        self::creerEtDiffuserSimple(
            destinataire: $createur,
            expediteur: $validateur,
            type: Notification::TYPE_MOUVEMENT_CAISSE,
            titre: 'Mouvement de caisse rejeté',
            message: "Votre mouvement {$mouvement->reference} ({$montantFormat} — {$mouvement->site}) a été rejeté par {$validateur->nom_complet}."
                . ($commentaire ? " Motif : {$commentaire}" : ''),
            metadata: [
                'reference'    => $mouvement->reference,
                'montant'      => $mouvement->montant,
                'site'         => $mouvement->site,
                'mouvement_id' => $mouvement->id,
                'action'       => 'rejetee',
                'commentaire'  => $commentaire,
            ],
        );
    }

    /* ----------------------------------------------------------------
     * DÉLÉGATIONS
     * ---------------------------------------------------------------- */

    /**
     * Notifier la création d'une délégation
     * → Destinataire : le délégataire (doit accepter ou refuser)
     */
    public static function notifierDelegationCreee(\App\Models\Delegation $delegation, User $delegant): void
    {
        $delegue = $delegation->delegue;
        if (!$delegue) return;

        $roleLabel  = self::labelRole($delegant->role);
        $dateDebut  = \Carbon\Carbon::parse($delegation->date_debut)->format('d/m/Y');
        $dateFin    = \Carbon\Carbon::parse($delegation->date_fin)->format('d/m/Y');

        self::creerEtDiffuserSimple(
            destinataire: $delegue,
            expediteur: $delegant,
            type: Notification::TYPE_DELEGATION,
            titre: 'Délégation de pouvoirs reçue',
            message: "{$delegant->nom_complet} ({$roleLabel}) vous a délégué ses droits de validation du {$dateDebut} au {$dateFin}."
                . ($delegation->motif ? " Motif : {$delegation->motif}." : '')
                . " Veuillez accepter ou refuser cette délégation.",
            metadata: [
                'delegation_id' => $delegation->id,
                'delegant'      => $delegant->nom_complet,
                'role_delegant'  => $delegant->role,
                'date_debut'    => $delegation->date_debut,
                'date_fin'      => $delegation->date_fin,
                'action'        => 'creee',
            ],
        );
    }

    /**
     * Notifier l'acceptation d'une délégation
     * → Destinataire : le délégant
     */
    public static function notifierDelegationAcceptee(\App\Models\Delegation $delegation, User $delegue): void
    {
        $delegant = $delegation->delegant;
        if (!$delegant || $delegant->id === $delegue->id) return;

        $dateFin = \Carbon\Carbon::parse($delegation->date_fin)->format('d/m/Y');

        self::creerEtDiffuserSimple(
            destinataire: $delegant,
            expediteur: $delegue,
            type: Notification::TYPE_DELEGATION,
            titre: 'Délégation acceptée',
            message: "{$delegue->nom_complet} a accepté votre délégation de pouvoirs jusqu'au {$dateFin}. Il/Elle peut désormais valider en votre nom.",
            metadata: [
                'delegation_id' => $delegation->id,
                'delegue'       => $delegue->nom_complet,
                'date_fin'      => $delegation->date_fin,
                'action'        => 'acceptee',
            ],
        );
    }

    /**
     * Notifier le refus d'une délégation
     * → Destinataire : le délégant
     */
    public static function notifierDelegationRefusee(\App\Models\Delegation $delegation, User $delegue): void
    {
        $delegant = $delegation->delegant;
        if (!$delegant || $delegant->id === $delegue->id) return;

        self::creerEtDiffuserSimple(
            destinataire: $delegant,
            expediteur: $delegue,
            type: Notification::TYPE_DELEGATION,
            titre: 'Délégation refusée',
            message: "{$delegue->nom_complet} a refusé votre délégation de pouvoirs. Veuillez désigner un autre délégataire si nécessaire.",
            metadata: [
                'delegation_id' => $delegation->id,
                'delegue'       => $delegue->nom_complet,
                'action'        => 'refusee',
            ],
        );
    }

    /**
     * Notifier la fin prématurée d'une délégation
     * → Destinataire : le délégataire
     */
    public static function notifierDelegationTerminee(\App\Models\Delegation $delegation, User $delegant): void
    {
        $delegue = $delegation->delegue;
        if (!$delegue || $delegue->id === $delegant->id) return;

        self::creerEtDiffuserSimple(
            destinataire: $delegue,
            expediteur: $delegant,
            type: Notification::TYPE_DELEGATION,
            titre: 'Délégation terminée',
            message: "{$delegant->nom_complet} a mis fin à votre délégation de pouvoirs. Vous n'avez plus les droits de validation délégués.",
            metadata: [
                'delegation_id' => $delegation->id,
                'delegant'      => $delegant->nom_complet,
                'action'        => 'terminee',
            ],
        );
    }

    /* ----------------------------------------------------------------
     * MÉTHODES PRIVÉES
     * ---------------------------------------------------------------- */

    /**
     * Récupérer les destinataires pour un rôle donné, filtrés par service si c'est un chef de service
     */
    private static function destinatairesParRole(string $role, BonCaisse $bon)
    {
        $serviceRequis = null;
        if ($role === 'responsable_service') {
            $bon->loadMissing('demandeur');
            $serviceRequis = $bon->demandeur->service ?? $bon->service;
        }

        /* 1. Utilisateurs ayant ce rôle en propre */
        $queryPropres = User::actifs()->parRole($role);
        if ($serviceRequis) {
            $queryPropres->where('service', $serviceRequis);
        }
        $propres = $queryPropres->get();

        /* 2. Utilisateurs agissant en tant que ce rôle par délégation */
        $delegues = User::actifs()
            ->whereHas('delegationsRecues', function ($q) use ($role, $serviceRequis) {
                $q->actives()
                  ->whereHas('delegant', function ($qDelegant) use ($role, $serviceRequis) {
                      $qDelegant->where('role', $role);
                      if ($serviceRequis) {
                          $qDelegant->where('service', $serviceRequis);
                      }
                  });
            })
            ->get();

        /* Fusionner et dédupliquer */
        return $propres->merge($delegues)->unique('id');
    }

    /**
     * Créer une notification en BDD et la diffuser via Reverb
     */
    private static function creerEtDiffuser(
        User $destinataire,
        BonCaisse $bon,
        ?User $expediteur,
        string $type,
        string $titre,
        string $message,
        ?array $metadata = null,
    ): void {
        /* Ne pas notifier l'expéditeur lui-même */
        if ($expediteur && $destinataire->id === $expediteur->id) {
            return;
        }

        $notification = Notification::create([
            'destinataire_id' => $destinataire->id,
            'bon_caisse_id' => $bon->id,
            'expediteur_id' => $expediteur?->id,
            'type' => $type,
            'titre' => $titre,
            'message' => $message,
            'metadata' => $metadata,
        ]);

        /* Charger les relations pour le broadcast */
        $notification->load('expediteur');

        /* Diffuser l'événement en temps réel (silencieux si Reverb indisponible) */
        try {
            broadcast(new NouvelleNotification($notification));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Broadcast notification échoué (Reverb indisponible) : ' . $e->getMessage());
        }
    }

    /**
     * Déterminer le prochain rôle validateur selon le statut actuel
     */
    private static function prochainRoleValidateur(string $statut): ?string
    {
        return match ($statut) {
            'EN_ATTENTE_CDG' => 'controle_gestion',
            'EN_ATTENTE_DAF' => 'daf',
            'EN_ATTENTE_DP' => 'directeur_pays',
            default => null,
        };
    }

    /**
     * Label lisible pour un rôle
     */
    private static function labelRole(string $role): string
    {
        return match ($role) {
            'responsable_service' => 'le Chef de Service',
            'controle_gestion' => 'le Contrôle de Gestion',
            'daf' => 'le DAF',
            'directeur_pays' => 'le Directeur Pays',
            'caissier' => 'le Caissier',
            default => $role,
        };
    }

    /**
     * Label lisible pour un niveau d'urgence
     */
    private static function labelUrgence(?string $niveau): string
    {
        return match ($niveau) {
            'urgente' => 'URGENT',
            'tres_urgente' => 'TRÈS URGENT',
            default => 'Normal',
        };
    }

    /**
     * Envoyer des notifications progressives selon le niveau d'urgence du bon
     * 
     * - normale : push uniquement (déjà géré par creerEtDiffuser)
     * - urgente : push + email au validateur suivant
     * - tres_urgente : push + email + SMS au validateur suivant
     */
    private static function notifierParUrgence(BonCaisse $bon, User $destinataire, ?User $expediteur): void
    {
        if (!$bon->niveau_urgence || $bon->niveau_urgence === 'normale') {
            return;
        }

        $urgenceLabel = self::labelUrgence($bon->niveau_urgence);
        $expediteurNom = $expediteur ? $expediteur->nom_complet : 'le système';

        /* Urgente et Très urgente → Email */
        if (in_array($bon->niveau_urgence, ['urgente', 'tres_urgente'])) {
            try {
                \Illuminate\Support\Facades\Mail::raw(
                    "[{$urgenceLabel}] Le bon de caisse {$bon->numero} ({$bon->montant_format}) "
                    . "de {$bon->demandeur->nom_complet} attend votre validation.\n\n"
                    . "Motif : {$bon->motif}\n"
                    . "Expéditeur : {$expediteurNom}\n\n"
                    . "Merci d'agir rapidement.",
                    function ($message) use ($destinataire, $urgenceLabel, $bon) {
                        $message->to($destinataire->email)
                            ->subject("[{$urgenceLabel}] Bon {$bon->numero} en attente de votre validation - NEEMBA");
                    }
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Email urgence échoué pour {$destinataire->email} : " . $e->getMessage());
            }
        }

        /* Très urgente → SMS en plus */
        if ($bon->niveau_urgence === 'tres_urgente' && $destinataire->telephone) {
            try {
                $smsService = new NimbaSmsService();
                $smsService->envoyerSms(
                    $destinataire->telephone,
                    "NEEMBA [{$urgenceLabel}] - Le bon {$bon->numero} ({$bon->montant_format}) de {$bon->demandeur->nom_complet} attend votre validation. Merci d'agir immédiatement."
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("SMS urgence échoué pour {$destinataire->telephone} : " . $e->getMessage());
            }
        }
    }

    /**
     * Créer une notification simple (sans bon de caisse) et la diffuser via Reverb
     * Utilisée pour les mouvements de caisse, délégations, etc.
     */
    private static function creerEtDiffuserSimple(
        User $destinataire,
        ?User $expediteur,
        string $type,
        string $titre,
        string $message,
        array $metadata = [],
    ): void {
        /* Ne pas notifier l'expéditeur lui-même */
        if ($expediteur && $destinataire->id === $expediteur->id) {
            return;
        }

        $notification = Notification::create([
            'destinataire_id' => $destinataire->id,
            'bon_caisse_id'   => null,
            'expediteur_id'   => $expediteur?->id,
            'type'            => $type,
            'titre'           => $titre,
            'message'         => $message,
            'metadata'        => $metadata,
        ]);

        $notification->load('expediteur');

        try {
            broadcast(new NouvelleNotification($notification));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Broadcast notification simple échoué : ' . $e->getMessage());
        }
    }
}
