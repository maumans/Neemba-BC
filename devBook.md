# NEEMBA - Gestion de Caisse (devBook)

> **Application interne de gestion des bons de caisse pour l'entreprise NEEMBA**
> Dernière mise à jour : 19 Octobre 2026 (v55 — lot F0 : PV d'arrêté de caisse)
>
> **Où en est le projet** : voir le chapitre 0 ci-dessous. Les chapitres 1, 4 et 5 sont à jour au 15/10/2026. Les chapitres 3 et 6 à 18 décrivent les versions de mars à juin 2026 : ils sont gardés pour l'historique, et les versions suivantes (§19 à §55) indiquent ce qui a changé.

---

## 0. Où en est le projet (19/10/2026)

### 0.1 Référence

- **Spécification en vigueur** : `docs/Spec_Fonctionnelle_v2.4_Neemba_P2.docx` (Thierno DIALLO, Addvalis, 14/10/2026). Elle remplace la v2.3 du 09/10, qui remplaçait elle-même la SFD v1.3 (M03) et la v2.2. Ces fichiers ne sont pas commités (documents de l'utilisateur).
- **Suivi de conformité** : [docs/CONFORMITE.md](docs/CONFORMITE.md). Il donne toutes les règles de la spec v2.4, avec pour chacune l'état dans le code, ce qui manque, pourquoi, et le lot qui la traite. Ses cases et son journal sont tenus à chaque lot. Il s'appelait `CONFORMITE_V23.md` jusqu'au 16/10.
- **Suivi du module M12 (ODM)** : [docs/ORDRE_DE_MISSIONS.md](docs/ORDRE_DE_MISSIONS.md).
- **Décisions de développement** : [docs/questions.md](docs/questions.md), Q1 à Q69. Q70 à Q75 sont proposées au §46, Q76 à Q81 au §47, Q82 au §51, Q83 et Q84 au §52, Q85 et Q86 au §53, Q87 au §54, Q88 à Q90 au §55 ; Q73, Q76 à Q88 et Q90 sont appliquées (§48 à §55), Q89 le sera au lot F1.

### 0.2 Branches

Toutes locales, chacune partie de la précédente ; rien n'est poussé ni déployé depuis la v18 (prod = `main`) :

`main` → `lot0-stabilisation` → `lot1-socle` → `lot2-caisses` → `lot3-assistant` → `lot4-pieces` → `lot4-referentiels` → `lot5-suivi` → `m12-odm` → `m12-prise-en-charge` → `m12-v23` → `m16-imputation` → `odm-pdf` → `odm-generation-selective` → **`v23-conformite`** (branche de travail).

### 0.3 État des modules (audit du 15/10/2026, v2.4 intégrée le 16/10)

| Module | État | Détail |
|---|---|---|
| M01 Comptes, rôles, sécurité | Partiel | Rôles multiples, incompatibilités, entité (lot E1), double authentification (lot E2), gestionnaire des référentiels (lot E3) faits ; reste le domaine @neemba.com, l'assistant trésorier, la double validation complète (lot G) |
| M02 Référentiels et paramétrage | Partiel | Caisses, tiers, visiteurs, clients, motifs, types d'OR, barèmes ODM datés (lot E3), caisses servant plusieurs sites (lot E4) faits ; reste la date d'effet des autres paramètres, les signataires habilités (lot G) |
| M03 Saisie du bon | Fait (27/28) | Lots 3 à 5, A à E, V, E1 à E4 (site analytique) ; reste la décision sur les brouillons abandonnés (lot G) |
| M04 Circuit de validation | Partiel | Correctifs du lot R (visa Finance jamais sauté, alertes sur l'écran de visa), signataires (lot G) |
| M05 Délégations | Partiel | Montants, information du caissier, absence du DP et de son adjoint (lot G) |
| M06 Paiement | Partiel | OTP au bénéficiaire seulement (lot R), reçu signé (lot I) |
| M07 Bons provisoires | Fait (5/8), 3 partiels | Lot E ; emails et statut « En retard » (lot I) |
| M08 Caisses, réapprovisionnement | À faire (2/13) | Lot F1 |
| M09, M10 Clôture, rapport, inventaire | Partiel | PV d'arrêté par caisse et ajustement d'inventaire faits (lot F0) ; clôture, rapport par caisse, inventaires obligatoires : lot F2 |
| M11 Notifications | Partiel | Dans l'application : oui ; emails : lot I |
| M12 Ordres de mission | Fait (36/37) | §26 à §40 ; vue de la Comptabilité (lot R) |
| M13 Carburant | À faire | Lot H |
| M14 Tableaux de bord, exports | Partiel | Exports faits ; tableau de bord du DAF à compléter (lot I) |
| M15 Archivage, audit | Partiel | Journal protégé, PDF archivés, purge (lot G) |
| M16 Imputation comptable | Fait | §38 |

**Règles RG de la v2.4** : 165, dont 114 faites, 32 partielles et 19 à faire au 19/10, après les lots R, V, E1 à E4, O et F0 (88, 45 et 32 à l'intégration de la v2.4, §47). La v2.4 ajoute ou modifie, entre autres :
- l'entité transverse ;
- la double authentification ;
- le gestionnaire des référentiels ;
- les barèmes ODM datés ;
- les types d'OR et l'« OR à régulariser » ;
- les visiteurs du groupe, qui ne reçoivent plus qu'un BD ;
- un seul bon par participant ;
- le modèle caisse ≠ site.

**Prochain lot** : F1 (caisses, réapprovisionnement avec le PV d'arrêté du jour, encaissement client, rôle « Membre du CODIR », M08), puis F2 → G → I → H → P3 (plan validé le 16/10, §47 ; F0 sorti de F2 le 19/10, Q88). R, V, le bloc E (E1 à E4), O et F0 sont faits (§48 à §55).

**Tests** : 412 tests PHP (`php artisan test`, base MySQL `neemba_test`) et 75 tests JavaScript (`npm test`).

**Connexion en dev** : depuis le lot E2, la connexion demande un code à 6 chiffres envoyé par email. Avec `MAIL_MAILER=log`, il se lit dans `storage/logs/laravel.log` (« Code : NNNNNN »). L'administrateur active ou désactive la double authentification dans Paramétrage > Sécurité (§51.7).

---

## 1. Présentation du Projet

### 1.1 Objectif
NEEMBA Cash Management est une application web interne permettant de gérer le cycle de vie complet des **bons de caisse** au sein de l'entreprise NEEMBA. L'application couvre la création, la validation hiérarchique, le paiement, la régularisation et l'archivage des demandes de fonds.

### 1.2 Contexte Métier
L'entreprise NEEMBA opère en **Guinée (GNF)** avec plusieurs sites et services. Chaque demande de fonds suit un workflow de validation hiérarchique strict avant décaissement par la caisse.

### 1.3 Règles Métier Clés

Valeurs au 15/10/2026 (spec v2.3). Toutes sont des paramètres (écran Paramétrage), aucune n'est en dur.

| Règle | Valeur |
|-------|--------|
| Plafond de retrait en espèces | **Par caisse** (ex. Conakry principale 20 000 000 GNF, Atelier et sites 1 000 000 à 4 000 000) ; au-delà : Orange Money, chèque ou virement |
| Visa du Directeur Pays | Dépense **> 1 500 000 GNF**, hors frais Orange Money (DP ou DP adjoint) |
| Circuit d'un bon | Chef de service → Contrôle de gestion → Finance (DAF, DAF adjoint ou Chef comptable) → DP au-delà du seuil → caissier |
| Format du numéro de bon | **BC-[code caisse]-[AAAA]-[00001]**, attribué à la soumission, une séquence par caisse émettrice et par année (lot C) ; les anciens BC-AAAA-NNNN restent tels quels |
| Types de bons | **BD** (définitif), **BP** (provisoire, salariés et visiteurs du groupe seulement) |
| Régularisation d'un BP | 3 jours ouvrés après le retour de mission, 2 jours ouvrés après le décaissement sinon ; justification Annexe 3 visée (lot E) |
| Mode de paiement | Souhaité par le demandeur, **décidé par le caissier** au paiement ; frais Orange Money par paliers, ajoutés au montant versé |
| Paiement | Débloqué par un code OTP à 6 chiffres (5 min) |
| Devise | **GNF** (Franc Guinéen) ; ODM extérieur en FCFA, converti au taux du jour |

---

## 2. Stack Technique

| Couche | Technologie | Version |
|--------|-------------|---------|
| Backend | Laravel | 12.x |
| Auth | Laravel Breeze | React SPA |
| Frontend | React | 18.x |
| Routage SPA | Inertia.js | 2.x |
| Bundler | Vite | 7.x |
| CSS | TailwindCSS | 3.x |
| Composants UI | shadcn/ui (Radix UI) | custom |
| Animations | Framer Motion | 12.x |
| Icônes | Lucide React | 0.577+ |
| Base de données | Mysql (dev) | — |
| Routes nommées JS | Ziggy | — |
| Formatage nombres | Utilitaire `nombreEnLettres.js` | custom |

### 2.1 Couleur Principale
- **Or NEEMBA** : `#fdc911`
- Palette complète : neemba-50 (`#fffbeb`) → neemba-950 (`#432001`)
- Couleur secondaire Marine : marine-50 (`#f0f4ff`) → marine-950 (`#0f172a`)

### 2.2 Conventions
- **Langue** : Français pour tout le nommage (tables, colonnes, variables, fonctions métier, commentaires, UI)
- **Composants** : shadcn/ui personnalisés dans `resources/js/Components/ui/`
- **Pages** : `resources/js/Pages/[Module]/[Action].jsx`

---

## 3. Architecture du Projet

```
Neemba/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── BonCaisseController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── DelegationController.php      ← Délégations de pouvoirs
│   │   │   ├── MouvementCaisseController.php ← Mouvements caisse
│   │   │   ├── RapportCaisseController.php
│   │   │   ├── UtilisateurController.php
│   │   │   ├── ValidationController.php
│   │   │   ├── NotificationController.php   ← API notifications
│   │   │   └── ProfileController.php        ← Breeze (à adapter)
│   │   └── Middleware/
│   │       ├── HandleInertiaRequests.php
│   │       └── VerifierRole.php             ← Middleware rôle (alias: role)
│   ├── Console/
│   │   └── Commands/
│   │       ├── RelancerRegularisationBP.php   ← Relance auto BP
│   │       ├── RelancerValidationsSLA.php     ← Relance SLA validations
│   │       └── VerifierSeuilCaisse.php        ← Alertes seuil caisse (SMS + push)
│   ├── Events/
│   │   └── NouvelleNotification.php      ← Broadcast Reverb
│   ├── Services/
│   │   └── NotificationService.php       ← Routage intelligent
│   └── Models/
│       ├── User.php
│       ├── BonCaisse.php
│       ├── HistoriqueAction.php                ← Traçabilité complète
│       ├── Validation.php
│       ├── PieceJointe.php
│       ├── OrdreMission.php
│       ├── RapportCaisse.php
│       ├── Notification.php               ← Notifications push
│       ├── Parametre.php                  ← Seuils paramétrables (cache)
│       ├── MouvementCaisse.php            ← Mouvements caisse
│       ├── Delegation.php                 ← Délégations de pouvoirs
│       ├── VentilationAnalytique.php      ← Ventilation multi-codes
│       ├── CodeAnalytique.php             ← Codes analytiques enrichis
│       ├── ModificationEnAttente.php      ← Double validation admin
│       └── Site.php                       ← Sites avec caisse
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php      ← Breeze (ne pas toucher)
│   │   ├── 2026_03_05_000001_ajouter_champs_metier_users.php
│   │   ├── 2026_03_05_000002_creer_table_bons_caisse.php
│   │   ├── 2026_03_05_000003_creer_table_validations.php
│   │   ├── 2026_03_05_000004_creer_table_pieces_jointes.php
│   │   ├── 2026_03_05_000005_creer_table_ordres_mission.php
│   │   ├── 2026_03_05_000006_creer_table_rapports_caisse.php
│   │   ├── 2026_03_05_000007_creer_tables_parametrage.php
│   │   ├── 2026_03_05_000008_enrichir_table_bons_caisse.php    ← Champs métier enrichis
│   │   ├── 2026_03_05_000009_creer_table_historique_actions.php
│   │   └── 2026_03_05_000010_creer_table_notifications.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── NeembaSeeder.php
├── resources/
│   ├── css/
│   │   └── app.css                       ← Variables thème NEEMBA
│   └── js/
│       ├── Components/
│       │   ├── ui/                       ← shadcn/ui personnalisés
│       │   │   ├── badge.jsx
│       │   │   ├── button.jsx
│       │   │   ├── card.jsx
│       │   │   ├── dialog.jsx
│       │   │   ├── dropdown-menu.jsx
│       │   │   ├── input.jsx
│       │   │   ├── label.jsx
│       │   │   ├── scroll-area.jsx
│       │   │   ├── select.jsx
│       │   │   ├── separator.jsx
│       │   │   ├── table.jsx
│       │   │   ├── tabs.jsx
│       │   │   ├── textarea.jsx
│       │   │   ├── tooltip.jsx
│       │   │   ├── combobox.jsx              ← Sélecteur avec recherche
│       │   │   └── sonner.jsx                ← Notifications toast
│       │   NotificationBell.jsx        ← Cloche + dropdown temps réel
│       │   └── (Breeze components)       ← À adapter
│       ├── Layouts/
│       │   ├── AuthenticatedLayout.jsx   ← Refait NEEMBA (sidebar)
│       │   └── GuestLayout.jsx           ← Breeze (À ADAPTER)
│       ├── Pages/
│       │   ├── Auth/                     ← Breeze (À ADAPTER)
│       │   ├── Profile/                  ← Breeze (À ADAPTER)
│       │   ├── BonsCaisse/
│       │   ├── Validations/
│       │   ├── Rapports/
│       │   ├── Utilisateurs/
│       │   ├── MouvementsCaisse/         ← Index + Create
│       │   ├── Delegations/              ← Index + Create
│       │   └── Parametrage/              ← Index (enrichi)
│       ├── utils/
│       │   └── nombreEnLettres.js       ← Conversion nombres → lettres FR + formatage
│       └── lib/
│           └── utils.js                  ← cn() pour shadcn/ui
├── routes/
│   ├── web.php                           ← Routes NEEMBA
│   ├── channels.php                      ← Canaux broadcast Reverb
│   └── auth.php                          ← Breeze auth routes
└── tailwind.config.js                    ← Thème NEEMBA
```

---

## 4. Rôles et Permissions

### 4.1 Les rôles du système (au 15/10/2026)

Un utilisateur a un **rôle principal** (`users.role`) et peut cumuler des **rôles complémentaires** (table `roles_utilisateurs`, §20.7). Source : `User::ROLES`.

| Rôle | Code | Droits principaux |
|------|------|-------------------|
| Demandeur | `demandeur` | Crée ses bons et ses ODM, propose des tiers, justifie ses BP |
| Chef de service | `responsable_service` | 1er visa des bons de son service ; visa N+1 des justifications de BP |
| Contrôle de gestion | `controle_gestion` | Visa des bons, modifie le code analytique, valide les tiers, vise les justifications de BP |
| DAF | `daf` | Visa Finance des bons, visa DAF des ODM, dérogations, annulation après visa |
| DAF adjoint | `daf_adjoint` | Comme le DAF (Finance) |
| Chef comptable | `chef_comptable` | Visa Finance des bons, visa Comptabilité des justifications de BP, imputation |
| Directeur Pays | `directeur_pays` | Visa des bons au-delà de 1 500 000 GNF et de tous les ODM |
| DP adjoint | `dp_adjoint` | Signe à la place du DP |
| Chef d'atelier / chef d'équipe | `chef_atelier` | 1er visa des ODM |
| Caissier | `caissier` | Décide du mode, paie les bons de sa caisse, encaisse les reliquats de BP |
| Trésorerie | `tresorerie` | Taux du jour ; réapprovisionnements (lot F1) |
| Comptabilité | `comptable` | Imputation comptable (M16), visa Comptabilité des justifications de BP |
| Logistique | `logistique` | Avances carburant (M13, lot H) |
| Ressources humaines | `rh` | Visiteurs du groupe, suivi des BP en retard (information de la DRH, retenue) |
| Administrateur | `administrateur` | Paramétrage et comptes, avec double validation des changements sensibles |

Incompatibilités (RG-M01-04, `CircuitBon`) : personne ne vise un bon ou un ODM dont il est demandeur, initiateur ou bénéficiaire ; le caissier ne paie pas un bon dont il est bénéficiaire. Une étape sans valideur possible passe au suppléant, sinon au niveau supérieur (Q48).

### 4.2 Workflow de validation d'un bon

```
BROUILLON (sans numéro)
    │ soumettre (numéro attribué : BC-[caisse]-[AAAA]-[n])
    ▼
EN_ATTENTE_CHEF_SERVICE ──► EN_ATTENTE_CDG ──► EN_ATTENTE_DAF (Finance) ──┬─► APPROUVE (≤ 1,5 M)
                                                                          └─► EN_ATTENTE_DP (> 1,5 M) ──► APPROUVE
   (à chaque étape : REJETE → corrigé et resoumis, même numéro, nouvelle version)

APPROUVE ─(caissier, OTP)─► BD : PAYE ──► ARCHIVE
                          └► BP : EN_ATTENTE_REGULARISATION ─(justification visée : N+1 → CDG → Comptabilité)─► REGULARISE ──► ARCHIVE

Annulation (ANNULE) : par le demandeur avant le 1er visa, puis par le DAF jusqu'à l'approbation ; jamais après paiement.
```

Le bon généré depuis un ODM suit le même circuit (§30).

### 4.3 Statuts complets

| Code | Label FR |
|--------|----------|
| `BROUILLON` | Brouillon |
| `EN_ATTENTE_CHEF_SERVICE` | En attente Chef Service |
| `EN_ATTENTE_CDG` | En attente Contrôle de Gestion |
| `EN_ATTENTE_DAF` | En attente DAF |
| `EN_ATTENTE_DP` | En attente Directeur Pays |
| `APPROUVE` | Approuvé (« Bon à payer » prévu au lot R) |
| `PAYE` | Payé |
| `REJETE` | Rejeté |
| `EN_ATTENTE_REGULARISATION` | En attente de régularisation |
| `REGULARISE` | Régularisé |
| `ARCHIVE` | Archivé |
| `ANNULE` | Annulé |

---

## 5. Données Référentielles NEEMBA

### 5.1 Sites et caisses (spec v2.3 §6.1)

Le site n'est plus la maille de gestion : chaque **caisse** a son solde, ses trois montants (plafond de caisse, seuil de réapprovisionnement, plafond de retrait par bon), son gestionnaire et son carnet. Valeurs de la spec, posées par l'import des référentiels (double validation) et modifiables au Paramétrage.

| Code | Caisse | Site | Plafond caisse | Seuil | Retrait / bon |
|------|--------|------|---:|---:|---:|
| CKY | Conakry principale | Conakry | 250 000 000 | 50 000 000 | 20 000 000 |
| ATL | Atelier (avance fixe, rattachée à Conakry) | Conakry | 15 000 000 | 3 000 000 | 1 000 000 |
| SAG | Sangarédi | Sangarédi | 100 000 000 | 20 000 000 | 4 000 000 |
| BOK | Boké | Boké | 20 000 000 | 5 000 000 | 1 000 000 |
| SGL | Siguiri Location | Siguiri | 5 000 000 | 1 000 000 | 1 000 000 |
| SGT | Siguiri Technique (Mandiana) | Mandiana | 5 000 000 | 1 000 000 | 1 000 000 |
| COR | CORICA (Kouroussa) | Kouroussa | 5 000 000 | 1 000 000 | 1 000 000 |
| OM | Orange Money | Conakry | 500 000 000 | 100 000 000 | — |

Chaque utilisateur est rattaché à une caisse (`users.caisse_id`), qui émet et numérote ses bons (lot C, §43). La caisse Atelier reste inactive tant que son solde réel n'est pas saisi.

### 5.2 Services (spec v2.3 §6.3)

Liste unique du Contrôle de gestion, pour les bons et les ODM : Direction, DAF, RH, Aftermarket, Technique, PSSR, Location, Logistique, Moyens Généraux, Informatique. Les codes analytiques (radical de 6 caractères, ex. ADAZZZ) sont filtrés par service.

### 5.3 Types de documents (pièces jointes)

Source : `PieceJointe::TYPES_DOCUMENTS`.

| Code | Label |
|------|-------|
| `facture` | Facture |
| `proforma` | Proforma |
| `recu` | Reçu |
| `devis` | Devis |
| `ordre_mission` | Ordre de mission |
| `email` | Email justificatif |
| `recu_carburant` | Ticket carburant |
| `demande_achat` | Demande d'achat |
| `bon_travail` | Bon de travail (OR) |
| `bon_commande` | Bon de commande |
| `rapport_journalier` | Rapport journalier |
| `justificatif` | Justificatif de régularisation |
| `justificatif_bp` | Justificatif de BP (Annexe 3) |
| `autre` | Autre |

Un BD n'est justifié que par une facture ou une proforma (RG-M03-15, lot D), sauf un BD issu d'un ODM.

### 5.4 Types de Bons

| Code | Nom | Description |
|------|-----|-------------|
| `BD` | Bon Définitif | Demande de fonds pour un montant connu et final |
| `BP` | Bon Provisoire | Avance de fonds à régulariser ultérieurement |

---

## 6. Base de Données

### 6.1 Table `users` (extension métier)
Champs ajoutés par la migration `2026_03_05_000001` :

| Colonne | Type | Description |
|---------|------|-------------|
| prenom | string | Prénom de l'utilisateur |
| matricule | string (unique) | Matricule interne (ex: NMB-001) |
| telephone | string (nullable) | Numéro de téléphone |
| role | enum | Rôle métier (voir §4.1) |
| service | string | Service d'appartenance |
| site | string | Site d'affectation |
| poste | string (nullable) | Intitulé du poste |
| actif | boolean (default: true) | Compte activé/désactivé |

### 6.2 Table `bons_caisse`

| Colonne | Type | Description |
|---------|------|-------------|
| id | bigint (PK) | Identifiant |
| numero | string (unique) | Numéro formaté BC-AAAA-NNNN |
| type_bon | enum (BD, BP) | Type de bon |
| site | string | Site de la demande |
| service | string | Service demandeur |
| code_analytique | string (nullable) | Code analytique comptable |
| beneficiaire | string | Nom du bénéficiaire |
| **type_beneficiaire** | string | employe, fournisseur, prestataire, autre |
| **telephone_beneficiaire** | string (nullable) | Téléphone du bénéficiaire |
| **mode_paiement** | string | especes, virement, cheque, mobile_money |
| motif | text | Motif détaillé de la demande |
| **categorie_depense** | string | Catégorie (carburant, mission, etc.) |
| montant | decimal(15,2) | Montant en GNF |
| montant_lettres | string (nullable) | Montant en toutes lettres |
| **devise** | string (default: GNF) | Devise du montant |
| statut | enum | Statut courant (voir §4.3) |
| demandeur_id | FK → users | Utilisateur demandeur |
| **caissier_id** | FK → users (nullable) | Caissier ayant payé |
| **mode_paiement_effectif** | string (nullable) | Mode de paiement réel au décaissement |
| date_demande | date | Date de la demande |
| **date_soumission** | datetime (nullable) | Date/heure de soumission |
| **date_paiement** | datetime (nullable) | Date/heure de paiement |
| **date_regularisation** | datetime (nullable) | Date/heure de régularisation |
| **date_limite_regularisation** | date (nullable) | Limite pour régulariser un BP |
| commentaire_rejet | text (nullable) | Motif du rejet |
| deleted_at | timestamp (nullable) | Soft delete |
| timestamps | — | created_at, updated_at |

### 6.3 Table `validations`

| Colonne | Type | Description |
|---------|------|-------------|
| id | bigint (PK) | Identifiant |
| bon_caisse_id | FK → bons_caisse | Bon concerné |
| niveau | integer | Ordre de validation (1, 2, 3, 4) |
| role | string | Rôle attendu pour cette étape |
| statut | enum | en_attente, approuve, rejete |
| validateur_id | FK → users (nullable) | Utilisateur qui a validé |
| commentaire | text (nullable) | Commentaire du validateur |
| date_validation | timestamp (nullable) | Date de l'action |
| timestamps | — | created_at, updated_at |

### 6.4 Table `pieces_jointes`

| Colonne | Type | Description |
|---------|------|-------------|
| id | bigint (PK) | Identifiant |
| bon_caisse_id | FK → bons_caisse | Bon concerné |
| type_document | enum | Type de document (voir §5.3) |
| nom_fichier | string | Nom original du fichier |
| chemin_fichier | string | Chemin de stockage |
| taille | integer | Taille en octets |
| mime_type | string | Type MIME |
| timestamps | — | created_at, updated_at |

### 6.5 Table `ordres_mission`

| Colonne | Type | Description |
|---------|------|-------------|
| id | bigint (PK) | Identifiant |
| bon_caisse_id | FK → bons_caisse | Bon associé |
| reference | string (unique) | Référence OM-AAAA-NNNN |
| objet | text | Objet de la mission |
| destination | string | Lieu de destination |
| date_depart | date | Date de départ |
| date_retour | date | Date de retour |
| moyen_transport | string (nullable) | Moyen de transport |
| timestamps | — | created_at, updated_at |

### 6.7 Table `historique_actions`

| Colonne | Type | Description |
|---------|------|-------------|
| id | bigint (PK) | Identifiant |
| bon_caisse_id | FK → bons_caisse | Bon concerné |
| action | string | Type d'action (creation, soumission, validation_*, rejet, paiement, etc.) |
| statut_avant | string (nullable) | Statut avant l'action |
| statut_apres | string (nullable) | Statut après l'action |
| utilisateur_id | FK → users (nullable) | Utilisateur ayant effectué l'action |
| commentaire | text (nullable) | Commentaire/détail de l'action |
| metadata | json (nullable) | Données supplémentaires |
| timestamps | — | created_at, updated_at |

### 6.8 Table `rapports_caisse`

| Colonne | Type | Description |
|---------|------|-------------|
| id | bigint (PK) | Identifiant |
| date_rapport | date | Date du rapport |
| site | string | Site concerné |
| solde_ouverture | decimal(15,2) | Solde en début de journée |
| total_entrees | decimal(15,2) | Total des entrées |
| total_sorties | decimal(15,2) | Total des sorties |
| solde_cloture | decimal(15,2) | Solde en fin de journée |
| observations | text (nullable) | Observations du caissier |
| caissier_id | FK → users | Caissier ayant créé le rapport |
| cloture | boolean (default: false) | Déprécié (non utilisé) |
| timestamps | — | created_at, updated_at |

---

## 7. Modules et Évolution des Tâches

### 7.1 Infrastructure & Configuration

| # | Tâche | Statut |
|---|-------|--------|
| 1 | Initialisation projet Laravel 12 + Breeze React | ✅ Fait |
| 2 | Installation des dépendances npm (Radix UI, Framer Motion, Lucide, etc.) | ✅ Fait |
| 3 | Configuration thème Tailwind (`tailwind.config.js`) avec couleurs NEEMBA | ✅ Fait |
| 4 | Configuration CSS variables shadcn/ui (`app.css`) | ✅ Fait |
| 5 | Création utilitaire `cn()` (`lib/utils.js`) | ✅ Fait |
| 6 | Configuration `HandleInertiaRequests` (flash messages) | ✅ Fait |

### 7.2 Composants UI (shadcn/ui)

| # | Composant | Fichier | Statut |
|---|-----------|---------|--------|
| 1 | Button | `Components/ui/button.jsx` | ✅ Fait |
| 2 | Card | `Components/ui/card.jsx` | ✅ Fait |
| 3 | Badge | `Components/ui/badge.jsx` | ✅ Fait |
| 4 | Dialog | `Components/ui/dialog.jsx` | ✅ Fait |
| 5 | Input | `Components/ui/input.jsx` | ✅ Fait |
| 6 | Label | `Components/ui/label.jsx` | ✅ Fait |
| 7 | Select | `Components/ui/select.jsx` | ✅ Fait |
| 8 | Tabs | `Components/ui/tabs.jsx` | ✅ Fait |
| 9 | Textarea | `Components/ui/textarea.jsx` | ✅ Fait |
| 10 | Separator | `Components/ui/separator.jsx` | ✅ Fait |
| 11 | Tooltip | `Components/ui/tooltip.jsx` | ✅ Fait |
| 12 | ScrollArea | `Components/ui/scroll-area.jsx` | ✅ Fait |
| 13 | Table | `Components/ui/table.jsx` | ✅ Fait |
| 14 | DropdownMenu | `Components/ui/dropdown-menu.jsx` | ✅ Fait |
| 15 | Combobox | `Components/ui/combobox.jsx` | ✅ Fait |
| 16 | Sonner (Toast) | `Components/ui/sonner.jsx` | ✅ Fait |

### 7.3 Migrations Base de Données

| # | Migration | Table | Statut |
|---|-----------|-------|--------|
| 1 | `0001_01_01_000000_create_users_table` | users, password_reset_tokens, sessions | ✅ Fait (Breeze) |
| 2 | `2026_03_05_000001_ajouter_champs_metier_users` | users (extension) | ✅ Fait |
| 3 | `2026_03_05_000002_creer_table_bons_caisse` | bons_caisse | ✅ Fait |
| 4 | `2026_03_05_000003_creer_table_validations` | validations | ✅ Fait |
| 5 | `2026_03_05_000004_creer_table_pieces_jointes` | pieces_jointes | ✅ Fait |
| 6 | `2026_03_05_000005_creer_table_ordres_mission` | ordres_mission | ✅ Fait |
| 7 | `2026_03_05_000006_creer_table_rapports_caisse` | rapports_caisse | ✅ Fait |
| 8 | `2026_03_05_000007_creer_tables_parametrage` | sites, services, codes_analytiques, types_documents | ✅ Fait |
| 9 | `2026_03_05_000008_enrichir_table_bons_caisse` | bons_caisse (enrichissement) | ✅ Fait |
| 10 | `2026_03_05_000009_creer_table_historique_actions` | historique_actions | ✅ Fait |
| 11 | `2026_03_05_000010_creer_table_notifications` | notifications | ✅ Fait |
| 12 | `2026_03_06_create_parametres_table` | parametres (seuils paramétrables) | ✅ Fait |
| 13 | `2026_03_09_000001_ajouter_role_administrateur_users` | users (ajout rôle administrateur) | ✅ Fait |
| 14 | `2026_03_09_000002_enrichir_rapports_caisse` | rapports_caisse (enrichissement stats) | ✅ Fait |
| 15 | `2026_03_09_000003_archivage_centralise_pieces_jointes` | pieces_jointes (archivage IA) | ✅ Fait |
| 16 | `2026_03_09_000004_optimiser_index_notifications` | notifications (index composites) | ✅ Fait |

### 7.4 Modèles Eloquent

| # | Modèle | Fichier | Relations | Statut |
|---|--------|---------|-----------|--------|
| 1 | User | `Models/User.php` | bonsCaisse, validations, rapportsCaisse | ✅ Fait |
| 2 | BonCaisse | `Models/BonCaisse.php` | demandeur, validations, piecesJointes, ordreMission | ✅ Fait |
| 3 | Validation | `Models/Validation.php` | bonCaisse, validateur | ✅ Fait |
| 4 | PieceJointe | `Models/PieceJointe.php` | bonCaisse | ✅ Fait |
| 5 | OrdreMission | `Models/OrdreMission.php` | bonCaisse | ✅ Fait |
| 6 | RapportCaisse | `Models/RapportCaisse.php` | caissier | ✅ Fait |
| 7 | HistoriqueAction | `Models/HistoriqueAction.php` | bonCaisse, utilisateur | ✅ Fait |
| 8 | Notification | `Models/Notification.php` | destinataire, bonCaisse, expediteur | ✅ Fait |
| 9 | Parametre | `Models/Parametre.php` | — (cache, raccourcis statiques) | ✅ Fait |
| 10 | MouvementCaisse | `Models/MouvementCaisse.php` | effectuePar, validePar | ✅ Fait |
| 11 | Delegation | `Models/Delegation.php` | delegant, delegue | ✅ Fait |
| 12 | VentilationAnalytique | `Models/VentilationAnalytique.php` | bonCaisse | ✅ Fait |
| 13 | CodeAnalytique | `Models/CodeAnalytique.php` | — (business_unit, label_complet) | ✅ Fait |
| 14 | ModificationEnAttente | `Models/ModificationEnAttente.php` | demandeur, valideur | ✅ Fait |
| 15 | Site | `Models/Site.php` | mouvementsCaisse ($appends solde/plafond format) | ✅ Fait |

### 7.5 Contrôleurs

| # | Contrôleur | Routes | Statut |
|---|------------|--------|--------|
| 1 | DashboardController | GET `/dashboard` (KPIs, stats mois, catégories, activité) | ✅ Fait |
| 2 | BonCaisseController | CRUD `/bons-caisse` + soumettre, payer, régulariser, archiver | ✅ Fait |
| 3 | ValidationController | GET/POST `/validations` + approuver, rejeter, demander-complement | ✅ Fait |
| 4 | RapportCaisseController | CRUD `/rapports` + exports + visa DAF | ✅ Fait |
| 5 | UtilisateurController | CRUD `/utilisateurs` + toggle-actif | ✅ Fait |
| 6 | ProfileController | GET/PATCH/DELETE `/profile` | ✅ Fait (Breeze) |
| 7 | NotificationController | API `/api/notifications` (index, non-lues, lue, tout-lire) | ✅ Fait |
| 8 | ParametrageController | CRUD paramétrage + PUT `/parametrage/parametres/{parametre}` | ✅ Fait |
| 9 | **ArchivageController** | **GET/POST `/archivage` (recherche full-text, classification IA, archivage)** | **✅ Fait** |
| 10 | **MouvementCaisseController** | **CRUD `/mouvements-caisse` + valider, rejeter** | **✅ Fait** |
| 11 | **DelegationController** | **CRUD `/delegations` + accepter, refuser, terminer** | **✅ Fait** |

### 7.6 Layouts

| # | Layout | Fichier | Statut |
|---|--------|---------|--------|
| 1 | AuthenticatedLayout (Sidebar NEEMBA + Toaster + NotificationBell) | `Layouts/AuthenticatedLayout.jsx` | ✅ Fait |
| 2 | GuestLayout (Split-screen NEEMBA) | `Layouts/GuestLayout.jsx` | ✅ Fait |

### 7.7 Pages Frontend - Modules NEEMBA

| # | Module | Page | Fichier | Statut |
|---|--------|------|---------|--------|
| 1 | Dashboard | Tableau de bord enrichi (KPIs, stats mois, BP retard, catégories, activité) | `Pages/Dashboard.jsx` | ✅ Fait |
| 2 | Bons de Caisse | Liste | `Pages/BonsCaisse/Index.jsx` | ✅ Fait |
| 3 | Bons de Caisse | Création | `Pages/BonsCaisse/Create.jsx` | ✅ Fait |
| 4 | Bons de Caisse | Détail | `Pages/BonsCaisse/Show.jsx` | ✅ Fait |
| 5 | Bons de Caisse | Édition | `Pages/BonsCaisse/Edit.jsx` | ✅ Fait |
| 6 | Validations | Liste | `Pages/Validations/Index.jsx` | ✅ Fait |
| 7 | Validations | Détail + Action | `Pages/Validations/Show.jsx` | ✅ Fait |
| 8 | Rapports | Liste | `Pages/Rapports/Index.jsx` | ✅ Fait |
| 9 | Rapports | Création | `Pages/Rapports/Create.jsx` | ✅ Fait |
| 10 | Rapports | Détail | `Pages/Rapports/Show.jsx` | ✅ Fait |
| 11 | Utilisateurs | Liste | `Pages/Utilisateurs/Index.jsx` | ✅ Fait |
| 12 | Utilisateurs | Création | `Pages/Utilisateurs/Create.jsx` | ✅ Fait |
| 13 | Utilisateurs | Édition | `Pages/Utilisateurs/Edit.jsx` | ✅ Fait |
| 14 | **Archivage** | **Recherche & Liste** | **`Pages/Archivage/Index.jsx`** | **✅ Fait** |
| 15 | **Archivage** | **Détail Document** | **`Pages/Archivage/Show.jsx`** | **✅ Fait** |
| 16 | **Mouvements Caisse** | **Liste + filtres + soldes sites** | **`Pages/MouvementsCaisse/Index.jsx`** | **✅ Fait (v13)** |
| 17 | **Mouvements Caisse** | **Création mouvement** | **`Pages/MouvementsCaisse/Create.jsx`** | **✅ Fait (v13)** |
| 18 | **Délégations** | **Liste données/reçues + actions** | **`Pages/Delegations/Index.jsx`** | **✅ Fait (v13)** |
| 19 | **Délégations** | **Création délégation** | **`Pages/Delegations/Create.jsx`** | **✅ Fait (v13)** |

### 7.8 Pages Breeze à Adapter au Design NEEMBA

Ces pages ont été générées par Laravel Breeze et ont été **adaptées** au design NEEMBA :
- Textes en **français**
- Design **NEEMBA** (couleur `#fdc911`, logo Wallet, animations Framer Motion)
- Composants **shadcn/ui** (Input, Button, Label, Card) au lieu des composants Breeze
- Page **Register** désactivée (les comptes sont créés par l'admin via Utilisateurs)
- Page **DeleteUser** retirée de la vue Profile (les comptes sont désactivés par l'admin)

| # | Page | Fichier | Changements effectués | Statut |
|---|------|---------|-----------------------|--------|
| 1 | **GuestLayout** | `Layouts/GuestLayout.jsx` | Split-screen : panneau gauche branding marine/or + panneau droit formulaire. Logo Wallet, animations Framer Motion, responsive mobile | ✅ Fait |
| 2 | **Login** | `Pages/Auth/Login.jsx` | Traduit FR, shadcn/ui (Input, Button, Label), checkbox stylisé neemba, lien mot de passe oublié | ✅ Fait |
| 3 | **Register** | `Pages/Auth/Register.jsx` | Désactivé — affiche une page informative « Inscription désactivée » avec redirection vers login | ✅ Fait |
| 4 | **ForgotPassword** | `Pages/Auth/ForgotPassword.jsx` | Traduit FR, shadcn/ui, icône Mail, lien retour connexion | ✅ Fait |
| 5 | **ResetPassword** | `Pages/Auth/ResetPassword.jsx` | Traduit FR, shadcn/ui, icône KeyRound | ✅ Fait |
| 6 | **ConfirmPassword** | `Pages/Auth/ConfirmPassword.jsx` | Traduit FR, shadcn/ui, icône ShieldCheck, zone sécurisée | ✅ Fait |
| 7 | **VerifyEmail** | `Pages/Auth/VerifyEmail.jsx` | Traduit FR, shadcn/ui, icône Mail/LogOut | ✅ Fait |
| 8 | **Profile/Edit** | `Pages/Profile/Edit.jsx` | Layout sidebar NEEMBA, Cards shadcn/ui, suppression DeleteUserForm | ✅ Fait |
| 9 | **UpdateProfileInfo** | `Pages/Profile/Partials/UpdateProfileInformationForm.jsx` | Traduit FR, shadcn/ui, affichage champs métier NEEMBA en lecture seule (matricule, rôle, service, site) | ✅ Fait |
| 10 | **UpdatePassword** | `Pages/Profile/Partials/UpdatePasswordForm.jsx` | Traduit FR, shadcn/ui, icônes Save/CheckCircle | ✅ Fait |
| 11 | **DeleteUser** | `Pages/Profile/Partials/DeleteUserForm.jsx` | **Retiré de la vue** — fichier conservé mais non affiché (désactivation gérée par admin) | ✅ Fait |
| 12 | **Welcome** | `Pages/Welcome.jsx` | **Non utilisé** — la route `/` redirige déjà vers login | ✅ Fait |

### 7.9 Seeders & Données de Test

| # | Tâche | Statut |
|---|-------|--------|
| 1 | NeembaSeeder (7 utilisateurs, 7 bons, 2 rapports, historique) | ✅ Fait |
| 2 | DatabaseSeeder mis à jour | ✅ Fait |
| 3 | Exécution `migrate:fresh --seed` | ✅ Fait |

### 7.10 Build & Déploiement

| # | Tâche | Statut |
|---|-------|--------|
| 1 | `npm run build` sans erreurs | ✅ Fait |
| 2 | Serveurs Laravel + Vite démarrés | ✅ Fait |
| 3 | Déploiement production | ✅ Fait — `neemba.wedrive.africa` (correction bug 403 `storage:link`) |

---

## 8. Comptes de Test

Mot de passe commun : **`password`**

| Rôle | Email | Nom | Matricule |
|------|-------|-----|-----------|
| Demandeur | demandeur@neemba.com | Mamadou DIALLO | NMB-001 |
| Responsable Service | chef@neemba.com | Thierno BAH | NMB-002 |
| Contrôle de Gestion | cdg@neemba.com | Fatoumata CAMARA | NMB-003 |
| DAF | daf@neemba.com | Mohamed SOUMAH | NMB-004 |
| Directeur Pays | dp@neemba.com | Alpha CONDE | NMB-005 |
| Caissier | caissier@neemba.com | Aissatou SYLLA | NMB-006 |
| Demandeur 2 | demandeur2@neemba.com | Ousmane BARRY | NMB-007 |

---

## 9. Routes de l'Application

### 9.1 Routes Publiques
| Méthode | URI | Action |
|---------|-----|--------|
| GET | `/` | Redirection vers login |
| GET | `/login` | Page de connexion |
| POST | `/login` | Authentification |
| POST | `/logout` | Déconnexion |
| GET | `/forgot-password` | Mot de passe oublié |
| POST | `/forgot-password` | Envoi email reset |
| GET | `/reset-password/{token}` | Réinitialisation |
| POST | `/reset-password` | Enregistrer nouveau MDP |

### 9.2 Routes Authentifiées
| Méthode | URI | Nom | Contrôleur |
|---------|-----|-----|------------|
| GET | `/dashboard` | dashboard | DashboardController@index |
| GET | `/bons-caisse` | bons-caisse.index | BonCaisseController@index |
| GET | `/bons-caisse/create` | bons-caisse.create | BonCaisseController@create |
| POST | `/bons-caisse` | bons-caisse.store | BonCaisseController@store |
| GET | `/bons-caisse/{bonCaisse}` | bons-caisse.show | BonCaisseController@show |
| GET | `/bons-caisse/{bonCaisse}/edit` | bons-caisse.edit | BonCaisseController@edit |
| PUT | `/bons-caisse/{bonCaisse}` | bons-caisse.update | BonCaisseController@update |
| POST | `/bons-caisse/{bonCaisse}/soumettre` | bons-caisse.soumettre | BonCaisseController@soumettre |
| POST | `/bons-caisse/{bonCaisse}/payer` | bons-caisse.payer | BonCaisseController@payer | `role:caissier` |
| POST | `/bons-caisse/{bonCaisse}/regulariser` | bons-caisse.regulariser | BonCaisseController@regulariser |
| POST | `/bons-caisse/{bonCaisse}/archiver` | bons-caisse.archiver | BonCaisseController@archiver | `role:daf,dp,caissier` |
| GET | `/validations` | validations.index | ValidationController@index | `role:validateurs` |
| GET | `/validations/{bonCaisse}` | validations.show | ValidationController@show |
| POST | `/validations/{bonCaisse}/approuver` | validations.approuver | ValidationController@approuver |
| POST | `/validations/{bonCaisse}/rejeter` | validations.rejeter | ValidationController@rejeter |
| POST | `/validations/{bonCaisse}/demander-complement` | validations.demander-complement | ValidationController@demanderComplement |
| GET | `/rapports` | rapports.index | RapportCaisseController@index | `role:caissier,daf,dp` |
| GET | `/rapports/create` | rapports.create | RapportCaisseController@create |
| POST | `/rapports` | rapports.store | RapportCaisseController@store |
| GET | `/rapports/{rapport}` | rapports.show | RapportCaisseController@show |
| GET | `/utilisateurs` | utilisateurs.index | UtilisateurController@index | `role:daf,dp` |
| GET | `/utilisateurs/create` | utilisateurs.create | UtilisateurController@create |
| POST | `/utilisateurs` | utilisateurs.store | UtilisateurController@store |
| GET | `/utilisateurs/{utilisateur}/edit` | utilisateurs.edit | UtilisateurController@edit |
| PUT | `/utilisateurs/{utilisateur}` | utilisateurs.update | UtilisateurController@update |
| POST | `/utilisateurs/{utilisateur}/toggle-actif` | utilisateurs.toggle-actif | UtilisateurController@toggleActif |
| GET | `/profile` | profile.edit | ProfileController@edit |
| PATCH | `/profile` | profile.update | ProfileController@update |
| DELETE | `/profile` | profile.destroy | ProfileController@destroy |
| GET | `/api/notifications` | notifications.index | NotificationController@index |
| GET | `/api/notifications/non-lues` | notifications.non-lues | NotificationController@compterNonLues |
| POST | `/api/notifications/{notification}/lue` | notifications.lue | NotificationController@marquerLue |
| POST | `/api/notifications/tout-lire` | notifications.tout-lire | NotificationController@marquerToutesLues |
| PUT | `/parametrage/parametres/{parametre}` | parametrage.parametres.update | ParametrageController@updateParametre | `role:daf,dp` |

---

## 10. Fonctionnalités Avancées (v6 — Mars 2026)

### 10.1 Rapport Journalier de Caisse Automatisé

#### 10.1.1 Objectif
Automatiser la génération et l'envoi quotidien des rapports de caisse avec consolidation des flux, calcul automatique des soldes, export Excel/PDF et envoi programmé aux destinataires.

#### 10.1.2 Fonctionnalités Implémentées

| Fonctionnalité | Description | Statut |
|----------------|-------------|--------|
| **Consolidation automatique** | Calcul auto des soldes (ouverture, entrées, sorties, clôture) | ✅ Fait |
| **Statistiques détaillées** | Nombre de bons, ventilation par catégorie et mode de paiement | ✅ Fait |
| **Export Excel** | Génération Excel structuré avec PHPSpreadsheet (maatwebsite/excel) | ✅ Fait |
| **Export PDF** | Génération PDF avec DomPDF (barryvdh/laravel-dompdf) | ✅ Fait |
| **Envoi programmé** | Commande artisan `rapports:envoyer-quotidien` planifiée à 07:30 (calcul temps réel, sans création en base) | ✅ Fait |
| **Destinataires** | DAF, Contrôle de Gestion, Caissier du site, Administrateurs | ✅ Fait |
| **Alertes données incomplètes** | Notification si rapport non générable (aucun mouvement) | ✅ Fait |
| **Visa DAF** | Le DAF peut apposer son visa sur un rapport (sans prérequis de clôture) | ✅ Fait |

#### 10.1.3 Fichiers Créés/Modifiés

**Backend :**
- `app/Exports/RapportCaisseExport.php` — Export Excel structuré
- `app/Mail/RapportCaisseQuotidien.php` — Mailable avec pièces jointes Excel + PDF
- `app/Console/Commands/EnvoyerRapportCaisseQuotidien.php` — Commande planifiée
- `resources/views/exports/rapport-caisse-pdf.blade.php` — Template PDF
- `resources/views/emails/rapport-caisse-quotidien.blade.php` — Email HTML
- `database/migrations/2026_03_09_000002_enrichir_rapports_caisse.php` — Enrichissement table
- `routes/console.php` — Planification quotidienne à 07:30

**Frontend :**
- `resources/js/Pages/Rapports/Show.jsx` — Ajout boutons export Excel/PDF + visa DAF

**Modèles :**
- `app/Models/RapportCaisse.php` — Méthodes `calculerStatistiques()`, `viserParDaf()`
- `app/Http/Controllers/RapportCaisseController.php` — Méthodes `exportExcel()`, `exportPdf()`, `viserDaf()`

#### 10.1.4 Commande Planifiée

```bash
# Génération et envoi automatique du rapport de la veille
php artisan rapports:envoyer-quotidien

# Planification (routes/console.php)
Schedule::command('rapports:envoyer-quotidien')->dailyAt('07:30');
```

### 10.2 Archivage Centralisé & Classification IA

#### 10.2.1 Objectif
Module d'archivage numérique avec classification automatique par IA, indexation full-text, contrôle qualité des scans, versionnement et rétention légale de 5 ans.

#### 10.2.2 Fonctionnalités Implémentées

| Fonctionnalité | Description | Statut |
|----------------|-------------|--------|
| **Classification IA** | Détection automatique du type de document (facture, proforma, OM, reçu carburant, etc.) | ✅ Fait |
| **Score de confiance** | Confiance de classification 0-100% basée sur analyse texte OCR + nom fichier | ✅ Fait |
| **Indexation full-text** | Index MySQL FULLTEXT sur `texte_indexable` pour recherche rapide | ✅ Fait |
| **Recherche avancée** | Recherche par N° BC, bénéficiaire, montant, site, classification, qualité DPI | ✅ Fait |
| **Contrôle qualité DPI** | Détection résolution < 300 DPI avec alerte qualité insuffisante | ✅ Fait |
| **Versionnement** | Numéro de version incrémenté à chaque remplacement de document | ✅ Fait |
| **Identifiant unique** | UUID généré automatiquement pour traçabilité unique | ✅ Fait |
| **Rétention 5 ans** | Date d'archivage + date d'expiration calculée automatiquement | ✅ Fait |
| **Checksum SHA-256** | Hash du fichier pour contrôle d'intégrité | ✅ Fait |
| **Liens traçables** | BC ↔ Ordre de Mission ↔ Justificatifs (relations existantes exploitées) | ✅ Fait |

#### 10.2.3 Fichiers Créés/Modifiés

**Backend :**
- `app/Services/ClassificationDocumentService.php` — Service de classification IA
- `app/Http/Controllers/ArchivageController.php` — Contrôleur archivage
- `database/migrations/2026_03_09_000003_archivage_centralise_pieces_jointes.php` — Enrichissement table
- `app/Jobs/ProcessPieceJointeOcrJob.php` — Intégration classification après OCR

**Frontend :**
- `resources/js/Pages/Archivage/Index.jsx` — Recherche et liste des documents
- `resources/js/Pages/Archivage/Show.jsx` — Détail document avec liens traçables
- `resources/js/Layouts/AuthenticatedLayout.jsx` — Ajout menu Archivage

**Modèles :**
- `app/Models/PieceJointe.php` — Méthodes archivage : `appliquerClassification()`, `construireTexteIndexable()`, `verifierQualiteDpi()`, `archiver()`, `incrementerVersion()`, scopes `archives()`, `qualiteInsuffisante()`, `recherche()`

#### 10.2.4 Classifications IA Disponibles

| Type | Description | Mots-clés de détection |
|------|-------------|------------------------|
| `bon_caisse` | Bon de caisse | BC-, bon de caisse, décaissement |
| `facture` | Facture fournisseur | facture, invoice, total ttc, tva |
| `proforma` | Proforma/Devis | proforma, devis, estimation, cotation |
| `ordre_mission` | Ordre de mission | ordre de mission, déplacement, per diem |
| `recu_carburant` | Reçu carburant | carburant, gasoil, essence, station, litre |
| `rapport_journalier` | Rapport journalier | rapport journalier, solde ouverture |
| `recu` | Reçu de paiement | reçu, quittance, acquitté |
| `autre` | Autre document | — (fallback) |

#### 10.2.5 Routes Archivage

```php
GET  /archivage                              — Liste et recherche
GET  /archivage/{piece}                      — Détail document
POST /archivage/{piece}/archiver             — Archiver (rétention 5 ans)
POST /archivage/archiver-bon                 — Archiver tous les docs d'un bon
POST /archivage/{piece}/reclassifier         — Reclassifier manuellement
POST /archivage/{piece}/relancer-classification — Relancer classification IA
```

### 10.3 Optimisations Performances

#### 10.3.1 Notifications (Problème Résolu)

**Problème :** Lenteur d'affichage du dropdown notifications (requêtes N+1, chargement à chaque clic).

**Solutions Implémentées :**

| Optimisation | Description | Impact |
|--------------|-------------|--------|
| **Lazy loading** | Chargement des notifications uniquement au premier clic (pas au montage) | ✅ Délai initial éliminé |
| **Cache frontend** | Notifications chargées une seule fois, réutilisées ensuite | ✅ Pas de rechargement inutile |
| **Eager loading** | `with(['expediteur', 'bonCaisse'])` pour éviter N+1 queries | ✅ Requêtes optimisées |
| **Select minimal** | Sélection uniquement des colonnes nécessaires | ✅ Payload réduit |
| **Index composites** | `idx_destinataire_lue`, `idx_destinataire_created` sur table notifications | ✅ Requêtes SQL accélérées |
| **Limit au lieu de paginate** | `limit(20)->get()` au lieu de `paginate()` pour API simple | ✅ Moins de métadonnées |

**Fichiers Modifiés :**
- `resources/js/Components/NotificationBell.jsx` — Lazy loading + cache
- `app/Http/Controllers/NotificationController.php` — Eager loading + select minimal
- `database/migrations/2026_03_09_000004_optimiser_index_notifications.php` — Index composites

#### 10.3.2 Résultat

- **Avant :** 2-3 secondes de délai à chaque ouverture du dropdown
- **Après :** Ouverture instantanée, chargement en arrière-plan imperceptible

### 10.4 Corrections de Bugs (v6)

| Bug | Description | Solution | Fichiers |
|-----|-------------|----------|----------|
| **Seuils hardcodés** | Seuil DP hardcodé à 5M GNF dans Create/Edit/Show au lieu d'utiliser `seuilDP` dynamique | Remplacé tous les `5000000` par `seuilDP` prop | `Create.jsx`, `Edit.jsx`, `Show.jsx`, `BonCaisseController.php` |
| **Double loading state** | Clic sur "Soumettre" affichait "Enregistrement..." ET "Soumission en cours..." simultanément | Ajout état `actionEnCours` pour tracker le bouton cliqué | `Create.jsx`, `Edit.jsx` |
| **Soumission échoue silencieusement** | Après soumission, le bon restait en BROUILLON car `broadcast()` crashait quand Reverb n'était pas démarré (erreur cURL port 8080) | Ajout `try/catch` autour de `broadcast()` dans `NotificationService` — la notification est créée en BDD même si Reverb est indisponible | `NotificationService.php` |
| **Notifications : "Aucune notification" flash** | Le dropdown affichait "Aucune notification" pendant le chargement alors que le badge indiquait des notifs non lues | Ajout d'un état `chargement` avec spinner animé avant d'afficher la liste vide | `NotificationBell.jsx` |
| **Notifications rechargent à chaque page** | Le cache notifications était perdu à chaque navigation Inertia (remontage du composant React) | Utilisation d'un cache global `window.__notifCache` persistant entre navigations | `NotificationBell.jsx` |
| **403 sur pièces jointes** | Accès aux fichiers `/storage/pieces_jointes/` renvoyait 403 FORBIDDEN | Le symlink `public/storage` était manquant — exécuté `php artisan storage:link` | Configuration serveur |
| **403 sur détail bon de caisse** | Certains rôles (ex: responsable_service) recevaient 403 sur des bons qu'ils devaient pouvoir voir | Refactorisation complète de la visibilité dans `show()` avec `match` par rôle, cohérent avec `index()` | `BonCaisseController.php` |
| **Bouton Soumettre enregistre en brouillon** | Le bouton "Soumettre pour validation" ne transmettait pas `soumettre=true` — le `post()` d'Inertia ignore le paramètre `data` en option | Construction explicite d'un `FormData` avec `router.post()` au lieu de `useForm.post()` | `Create.jsx`, `Edit.jsx` |
| **Bouton Archiver visible pour tous** | Le bouton Archiver était visible pour les demandeurs qui n'ont pas le droit d'archiver | Ajout vérification `peutArchiver` basée sur le rôle (daf, dp, caissier, admin) | `Show.jsx` |
| **Rôle administrateur manquant dans index()** | L'administrateur n'avait pas de règle de visibilité dans le switch `index()`, donc voyait tous les bons y compris brouillons | Ajouté `case 'administrateur'` dans le switch, même visibilité que DAF/DP | `BonCaisseController.php` |

### 10.5 Améliorations UX (v7)

| Amélioration | Description | Implémentation | Fichiers |
|-------------|-------------|----------------|----------|
| **MontantInput formaté** | Le champ montant affiche désormais `5 000 000` directement dans l'input (plus de nombre brut). Basculement automatique entre mode édition (chiffres bruts) et affichage (formaté). Suffixe `GNF` intégré. | Nouveau composant `MontantInput` avec `inputMode="numeric"`, gestion focus/blur | `MontantInput.jsx`, `Create.jsx`, `Edit.jsx` |
| **TelephoneInput formaté** | Formatage automatique des numéros guinéens : `622 12 34 56` ou `+224 622 12 34 56`. Accepte aussi les formats internationaux. | Nouveau composant `TelephoneInput` avec formatage au vol | `TelephoneInput.jsx`, `Create.jsx` |
| **Dashboard graphiques** | Ajout de 4 graphiques interactifs : (1) AreaChart évolution 6 mois (créés/payés/rejetés), (2) PieChart donut répartition par statut avec légende, (3) BarChart horizontal top 5 bénéficiaires, (4) BarChart montants payés par mois. Plus 3 nouveaux KPIs : taux d'approbation, délai moyen de traitement, montant approuvé en attente. | `recharts` (AreaChart, PieChart, BarChart), tooltip personnalisé FR, gradients | `DashboardController.php`, `Dashboard.jsx` |
| **Rapports Caisse enrichis** | 5 KPIs résumé en haut de page (rapports, clôturés, total entrées, total sorties, dernier solde) + graphique AreaChart évolution des 10 derniers rapports (solde, entrées, sorties). | Nouvelles données backend `statsResume` et `evolutionSolde` | `RapportCaisseController.php`, `Rapports/Index.jsx` |

### 10.6 Dépendances ajoutées (v7)

| Package | Version | Usage |
|---------|---------|-------|
| `recharts` | ^2.x | Graphiques interactifs (AreaChart, PieChart, BarChart) pour Dashboard et Rapports |

### 10.7 Sécurisation paiements par OTP SMS (v8)

| Fonctionnalité | Description | Implémentation | Fichiers |
|----------------|-------------|----------------|----------|
| **Table otp_validations** | Stockage des codes OTP générés : `code` (6 chiffres), `telephone`, `expires_at`, `verified_at`, `is_used`. Relation avec `bons_caisse`. | Migration + modèle Eloquent avec scopes `valide()`, `nonVerifie()` | `2026_03_10_151855_create_otp_validations_table.php`, `OtpValidation.php` |
| **Service NimbaSMS** | Intégration API Nimba pour envoi SMS et récupération solde. Méthodes : `envoyerSms()`, `obtenirSolde()`, `envoyerCodeOtp()`. Gestion erreurs avec logs. | Service dédié utilisant Guzzle HTTP client | `NimbaSmsService.php` |
| **Paramètre durée OTP** | Durée de validité du code OTP configurable (défaut 5 minutes). Paramètre `duree_validite_otp` dans table `parametres`. | Migration de données | `2026_03_10_152941_add_duree_validite_otp_parametre.php` |
| **Routes OTP** | 2 routes POST : `/bons-caisse/{bon}/otp/generer` (génère et envoie SMS) et `/bons-caisse/{bon}/otp/verifier` (vérifie code saisi). Middleware `role:caissier`. | Routes web avec middleware | `web.php` |
| **Workflow paiement sécurisé** | Le caissier doit : (1) Générer un code OTP → SMS envoyé au demandeur, (2) Saisir le code reçu par le demandeur, (3) Vérifier le code, (4) Sélectionner mode de paiement et confirmer. Le paiement échoue si aucun OTP vérifié dans les 10 dernières minutes. | 3 méthodes backend : `genererOtp()`, `verifierOtp()`, `payer()` modifié | `BonCaisseController.php` |
| **UI formulaire OTP** | Interface en 3 étapes dans le formulaire de paiement : bouton "Envoyer code OTP", champ de saisie 6 chiffres avec bouton "Renvoyer", validation avec feedback visuel (✓ Code vérifié). | États React + formulaire progressif | `Show.jsx` |
| **Configuration Nimba** | Variables d'environnement : `NIMBA_API_URL`, `NIMBA_AUTH_TOKEN`, `NIMBA_SENDER_NAME`. Configuration dans `config/services.php`. | Config + .env.example | `services.php`, `.env.example` |

**Flux de sécurisation :**
1. Caissier clique "Effectuer le paiement" sur un bon APPROUVÉ
2. Étape 1 : Caissier clique "Envoyer le code OTP" → Backend génère code 6 chiffres, enregistre en BDD avec expiration (5 min), envoie SMS via Nimba au téléphone du demandeur
3. Étape 2 : Caissier saisit le code communiqué par le demandeur → Backend vérifie le code, marque comme `verified_at`
4. Étape 3 : Caissier sélectionne le mode de paiement effectif et confirme → Backend vérifie qu'un OTP a été validé récemment, marque l'OTP comme `is_used`, enregistre le paiement

### 10.8 Exports par période & Prévisualisation fichiers (v9)

#### 10.8.1 Exports Rapports par Période

| Fonctionnalité | Description | Implémentation | Fichiers |
|----------------|-------------|----------------|----------|
| **Export Excel période** | Génération d'un fichier Excel synthétisant tous les rapports de caisse d'une période donnée (date début → date fin). Résumé global + détail par rapport. | Nouvelle classe `RapportsPeriodeExport` avec collection + mapping | `RapportsPeriodeExport.php` |
| **Export PDF période** | Génération d'un PDF paysage (A4) avec synthèse des rapports de la période. Tableau récapitulatif + totaux. | Template Blade dédié avec mise en page paysage | `rapports-periode-pdf.blade.php` |
| **Routes dédiées** | 2 nouvelles routes GET : `/rapports-periode/export-excel` et `/rapports-periode/export-pdf` avec paramètres `date_debut`, `date_fin`, `site` (optionnel) | Routes nommées `rapports.periode.export-excel/pdf` | `web.php` |
| **Boutons toujours visibles** | Les boutons d'export Excel/PDF sont affichés en permanence dans `Rapports/Index.jsx`. Si aucune période n'est sélectionnée, export de l'année en cours par défaut. | Dates par défaut : 1er janvier année en cours → aujourd'hui | `Rapports/Index.jsx` |
| **Ouverture nouvel onglet** | Tous les exports (Excel, PDF) s'ouvrent dans un nouvel onglet au lieu de télécharger directement. Les PDF s'affichent inline dans le navigateur. | `target="_blank"` sur liens + `$pdf->stream()` au lieu de `download()` | `RapportCaisseController.php`, `Rapports/Show.jsx`, `Rapports/Index.jsx` |

**Méthodes Backend :**
- `RapportCaisseController::exportPeriodeExcel()` — Validation dates, filtrage site optionnel, génération Excel
- `RapportCaisseController::exportPeriodePdf()` — Validation dates, filtrage site optionnel, génération PDF stream
- `RapportCaisseController::exportPdf()` — Modifié pour utiliser `stream()` au lieu de `download()`

#### 10.8.2 Prévisualisation Fichiers & Navigation Arborescente Archivage

| Fonctionnalité | Description | Implémentation | Fichiers |
|----------------|-------------|----------------|----------|
| **Navigation arborescente** | Refonte complète de la page Archivage avec arborescence hiérarchique : **Année > Service > Code Analytique > Référence BC > Fichiers**. Panneau gauche (arbre) + panneau droit (prévisualisation). | Composant récursif `NoeudArbre` avec expand/collapse animé, compteurs de fichiers par niveau | `Archivage/Index.jsx` |
| **Prévisualisation PDF inline** | Les PDF s'affichent directement dans la page via `<embed>` au lieu de `<iframe>` pour un meilleur rendu. Paramètres `#toolbar=1&navpanes=0` pour contrôle natif. | Remplacement iframe → embed avec détection MIME type | `Archivage/Index.jsx` |
| **Prévisualisation images** | Les images (jpg, png, gif, etc.) s'affichent en grand format avec bordure et ombre. | Détection `mime_type.startsWith('image/')` | `Archivage/Index.jsx` |
| **Fallback fichiers non prévisualisables** | Pour les fichiers Word, Excel, etc., affichage d'un message avec bouton "Ouvrir dans un nouvel onglet" au lieu d'un message d'erreur. | Condition else avec bouton Eye + lien `target="_blank"` | `Archivage/Index.jsx` |
| **Boutons Ouvrir + Télécharger** | Chaque fichier dispose de 2 boutons : **Ouvrir** (nouvel onglet) et **Télécharger**. Idem pour les pièces jointes dans BonsCaisse/Show, Validations/Show, Archivage/Show. | Icônes Eye (ouvrir) + Download (télécharger) avec `target="_blank"` et `download` | `Archivage/Index.jsx`, `Archivage/Show.jsx`, `BonsCaisse/Show.jsx`, `Validations/Show.jsx` |
| **Fil d'ariane plan de classement** | Affichage du plan de classement en haut de page : Année > Service > Code Analytique > Référence. | Composant visuel avec icônes ChevronRight | `Archivage/Index.jsx` |
| **Backend arborescence** | Construction de l'arborescence hiérarchique côté serveur avec méthodes `construireArborescence()` et `convertirEnTableau()`. Groupement par année, service, code analytique, référence BC. | Algorithme de groupement récursif avec compteurs | `ArchivageController.php` |

**Corrections UX :**
- **Bouton "Confirmer le paiement" qui dépasse** : Ajout de `flex-col sm:flex-row` pour responsive mobile, `text-sm` et `truncate` sur le texte du bouton
- **PDF téléchargés au lieu de prévisualisés** : Tous les exports PDF utilisent maintenant `stream()` pour affichage inline dans le navigateur

**Fichiers Modifiés :**
- `app/Http/Controllers/RapportCaisseController.php` — Ajout méthodes export période + stream PDF
- `app/Exports/RapportsPeriodeExport.php` — Nouvelle classe export Excel période
- `resources/views/exports/rapports-periode-pdf.blade.php` — Template PDF période
- `resources/js/Pages/Rapports/Index.jsx` — Boutons export toujours visibles + target blank
- `resources/js/Pages/Rapports/Show.jsx` — Liens export avec target blank
- `app/Http/Controllers/ArchivageController.php` — Construction arborescence hiérarchique
- `resources/js/Pages/Archivage/Index.jsx` — Refonte complète avec arbre + prévisualisation
- `resources/js/Pages/Archivage/Show.jsx` — Boutons Ouvrir + Télécharger
- `resources/js/Pages/BonsCaisse/Show.jsx` — Pièces jointes avec Eye + Download, fix bouton paiement
- `resources/js/Pages/Validations/Show.jsx` — Pièces jointes avec Eye + Download
- `routes/web.php` — Routes export période

### 10.9 Prévisualisation inline universelle & Corrections UX (v10)

#### 10.9.1 Prévisualisation inline des pièces jointes

| Fonctionnalité | Description | Implémentation | Fichiers |
|----------------|-------------|----------------|----------|
| **Preview inline BonsCaisse** | Cliquer sur l'icône Eye d'une pièce jointe déploie un aperçu animé sous le fichier (PDF embed, image, fallback nouvel onglet). Plus besoin d'ouvrir un onglet pour voir le document. | État `previewPieceId` + `AnimatePresence` Framer Motion | `BonsCaisse/Show.jsx` |
| **Preview inline Validations** | Même mécanisme de prévisualisation dépliable pour les pièces jointes dans la page de validation. Le validateur peut examiner les justificatifs sans quitter la page. | État `previewPieceId` + `AnimatePresence` | `Validations/Show.jsx` |
| **Preview inline Archivage/Show** | Ajout d'une section « Prévisualisation » dans la page détail d'un document archivé avec embed PDF (600px), affichage image ou fallback avec bouton ouvrir. | Détection MIME type + embed/img conditionnel | `Archivage/Show.jsx` |
| **Types supportés** | **PDF** : `<embed>` avec toolbar natif navigateur. **Images** (jpg, png, gif, etc.) : `<img>` responsive. **Autres** : message + bouton « Ouvrir dans un nouvel onglet ». | Détection `mime_type` (startsWith image/, includes pdf) | Tous les fichiers concernés |

#### 10.9.2 Corrections UX

| Correction | Description | Solution | Fichiers |
|-----------|-------------|----------|----------|
| **Bouton "Confirmer le paiement" qui dépasse** | En mobile/sidebar étroite, les boutons Annuler et Confirmer côte à côte dépassaient du conteneur (visible sur l'image) | Boutons empilés verticalement (`flex-col`) en pleine largeur (`w-full`), action principale en premier | `BonsCaisse/Show.jsx` |
| **Exports Rapports : UX déroutante** | L'utilisateur devait sélectionner une période pour voir les boutons d'export, ce qui était confus. Le texte « Exporter tous les rapports » n'était pas clair. | Boutons toujours visibles avec texte contextuel : « Période : Année 2026 (par défaut) » ou dates sélectionnées. Labels « Exporter Excel » / « Exporter PDF » plus explicites. | `Rapports/Index.jsx` |

#### 10.9.3 Fichiers Modifiés

- `resources/js/Pages/BonsCaisse/Show.jsx` — Prévisualisation inline pièces jointes + fix bouton paiement
- `resources/js/Pages/Validations/Show.jsx` — Prévisualisation inline pièces jointes
- `resources/js/Pages/Archivage/Show.jsx` — Section prévisualisation document
- `resources/js/Pages/Rapports/Index.jsx` — UX exports améliorée

### 10.10 SLA configurable, Filtres avancés archivage, PDF bon de caisse (v11)

#### 10.10.1 Système SLA configurable par niveau de validation

| Paramètre | Clé | Défaut | Description |
|-----------|-----|--------|-------------|
| SLA Chef de Service | `sla_responsable_service` | 4h | Délai max avant relance automatique |
| SLA Contrôle de Gestion | `sla_controle_gestion` | 8h | Délai max avant relance automatique |
| SLA DAF | `sla_daf` | 4h | Délai max avant relance automatique |
| SLA Directeur Pays | `sla_directeur_pays` | 8h | Délai max avant relance automatique |
| Multiplicateur escalade | `sla_multiplicateur_escalade` | 2× | Escalade au N+1 après SLA × multiplicateur |
| Relance SMS | `sla_relance_sms` | true | Activer/désactiver l'envoi SMS de relance |

**Mécanisme :**
1. À la soumission d'un bon, `date_attribution` est positionné sur le premier niveau de validation.
2. À chaque approbation, `date_attribution` est positionné sur le niveau suivant.
3. La commande `validations:relancer-sla` (planifiée toutes les heures) :
   - Détecte les validations en attente dont le SLA est dépassé → Envoie relance push + SMS
   - Détecte les validations dont le SLA × multiplicateur est dépassé → Escalade automatique au N+1
   - Évite les relances trop fréquentes (max 1 par heure par validation)
4. Chaque relance et escalade est tracée dans `historique_actions` et les notifications push/SMS.

**Colonnes ajoutées à `validations` :** `date_attribution`, `date_relance`, `nb_relances`, `escalade`, `date_escalade`

**Fichiers :**
- `database/migrations/2026_03_11_160000_creer_sla_et_relances_validation.php`
- `app/Models/Validation.php` — Méthodes SLA : `slaHeures()`, `slaDepasse()`, `doitEscalader()`, `enregistrerRelance()`, `marquerEscalade()`
- `app/Console/Commands/RelancerValidationsSLA.php` — Commande Artisan avec escalade map
- `app/Services/NotificationService.php` — `notifierRelanceSla()`, `notifierEscaladeSla()`
- `app/Services/NimbaSmsService.php` — `envoyerRelanceSla()`, `envoyerEscaladeSla()`
- `app/Models/Notification.php` — Types `relance_sla`, `escalade_sla`
- `routes/console.php` — Planification horaire

#### 10.10.2 Filtres avancés archivage

| Filtre | Type | Description |
|--------|------|-------------|
| Classification IA | Select | Filtrer par classification automatique (facture, bon de caisse, etc.) |
| Type de document | Select | Filtrer par type de document métier |
| Site | Select | Filtrer par site du bon associé |
| Date début / fin | Date | Plage de dates de création |
| Statut archivage | Select | Archivés / Non archivés / Tous |
| Qualité scan | Select | OK / Faible / Tous |

Le panneau de filtres est dépliable via un bouton « Filtres » animé avec Framer Motion. Tous les filtres sont combinables et transmis en query string.

**Fichiers :**
- `app/Http/Controllers/ArchivageController.php` — Filtrage avancé dans `index()`
- `resources/js/Pages/Archivage/Index.jsx` — Panneau filtres animé, état local par filtre

#### 10.10.3 Export PDF Bon de Caisse (formulaire officiel NEEMBA)

Le formulaire officiel « Autorisation de Dépenses de Caisse » est généré dynamiquement avec toutes les données du bon :
- En-tête procédure avec logo NEEMBA CAT
- Type (BP/BD) avec cases cochées dynamiquement
- Numéro, date, sites et services avec cases cochées
- Code analytique, bénéficiaire, motif
- Montant en chiffres et en lettres
- Tableau des visas (Demandeur, Chef de Service, RCDG, DAF, DP) avec statut
- Champ réservé à la comptabilité
- Notes de bas de page réglementaires
- Signatures (bénéficiaire + caissier)
- Informations de paiement si payé

**Fichiers :**
- `resources/views/exports/bon-caisse-pdf.blade.php` — Template Blade PDF
- `app/Http/Controllers/BonCaisseController.php` — Méthode `exportPdf()`
- `routes/web.php` — Route `bons-caisse.export-pdf`
- `resources/js/Pages/BonsCaisse/Show.jsx` — Bouton « Exporter PDF »

---

## 11. Prochaines Étapes (Backlog)

| Priorité | Tâche | Description | Statut |
|----------|-------|-------------|--------|
| ✅ | Dashboard enrichi | KPIs par rôle, stats mois, BP retard, répartition catégories, activité | Fait |
| ✅ | Notifications toast | Sonner intégré au layout, flash messages (success/error/warning/info) | Fait |
| ✅ | Middleware rôle | `VerifierRole` enregistré comme alias `role`, routes protégées | Fait |
| ✅ | Notifications push temps réel | Laravel Reverb + Echo, NotificationBell, routage intelligent par rôle | Fait |
| ✅ | Sidebar menu actif | Extraction du préfixe de ressource pour highlight correct sur toutes les sous-routes | Fait |
| ✅ | Montant en lettres (FR) | Conversion automatique du montant en toutes lettres français dans Create/Edit, affichage dans Show/Validations | Fait |
| ✅ | Formatage nombres | Utilitaire `formaterMontant`/`formaterNombre` centralisé dans `nombreEnLettres.js`, remplacé dans 7 fichiers | Fait |
| ✅ | Visibilité bons par rôle | Brouillons masqués pour les autres, chaque rôle ne voit que les bons à son niveau+ | Fait |
| ✅ | Seuils paramétrables | Table `parametres`, modèle `Parametre` avec cache, UI inline éditable dans Paramétrage > Seuils | Fait |
| ✅ | Harmonisation validation UI | Même UI (Approuver/Rejeter/Complément) dans Validations/Show et BonsCaisse/Show | Fait |
| ✅ | Notifications instant | Compteur non-lues partagé via Inertia SSR props (`notificationsNonLues`), affichage immédiat | Fait |
| ✅ | **Rapport journalier automatisé** | **Consolidation, export Excel/PDF, envoi programmé, alertes** | **Fait (v6)** |
| ✅ | **Archivage centralisé IA** | **Classification auto, full-text, DPI, versionnement, rétention 5 ans** | **Fait (v6)** |
| ✅ | **Optimisation notifications** | **Lazy loading, cache, eager loading, index composites** | **Fait (v6)** |
| ✅ | **SLA configurable + relances/escalade** | **SLA par niveau (RS 4h, CDG 8h, DAF 4h, DP 8h), relances auto email+SMS, escalade N+1 après 2×SLA** | **Fait (v11)** |
| ✅ | **Filtres avancés archivage** | **Filtrage par classification IA, type document, site, dates, statut archivage, qualité scan** | **Fait (v11)** |
| ✅ | **Export PDF bon de caisse** | **Formulaire officiel NEEMBA dynamique avec visas, comptabilité, notes** | **Fait (v11)** |
| ✅ | **Cohérence service notifications/validations** | **Un chef de service ne voit/valide/reçoit que les bons de son propre service** | **Fait (v12)** |
| ✅ | **Localisation FR complète** | **Jours de la semaine en français (Carbon + frontend), dates FR partout** | **Fait (v12)** |
| ✅ | **Responsiveness mobile-first** | **Toutes les pages sans scroll horizontal, tables adaptatives** | **Fait (v12)** |
| ✅ | **Urgence visuelle bons** | **Indicateurs colorés (rouge/orange) + badges clignotants sur bons urgents** | **Fait (v12)** |
| ✅ | **Fix notifications vides** | **Badge affichait un compteur mais liste vide — refactoring fetch/cache** | **Fait (v12)** |
| ✅ | **Frontend post-réunion (v13)** | **MouvementsCaisse, Délégations, Dashboard enrichi, Rapports détaillés, Paramétrage caisse/BU** | **Fait (v13)** |
| ✅ | **Module OCR documenté** | **Analyse auto pièces jointes (pdftotext + Tesseract + OpenAI/regex fallback)** | **Fait (v18 — audit Juin 2026)** |
| ✅ | **Seeder pilote Conakry** | **7 utilisateurs réels, 10 bons pré-positionnés, seuil DP 1 500 000 GNF** | **Fait (v18)** |
| ✅ | **Déploiement production** | **neemba.wedrive.africa — bug 403 storage:link corrigé** | **Fait (v18)** |
| 🟡 | Notifications email | Emails transactionnels (validation, paiement, relance) | À faire |
| 🟢 | Tests unitaires | PHPUnit pour les modèles et contrôleurs | À faire |
| ⏳ | OpenAI API key production | Activer l'analyse IA OCR (sans clé = mode regex uniquement) | En attente client |
| ⏳ | poppler-utils + tesseract serveur | Installer sur le serveur de prod pour activer l'OCR PDF/image | En attente serveur |

---

### 10.11 Cohérence service, localisation FR, responsiveness, correctifs UX (v12)

#### 10.11.1 Cohérence service pour les chefs de service (BUG CRITIQUE)

**Problème :** Un chef de service (ex: Thierno BAH, service Informatique) recevait des notifications et voyait dans ses validations les bons de **tous les services** (ex: bons d'Ousmane BARRY du service Logistique). Les notifications étaient envoyées à **tous** les `responsable_service` sans filtre de service.

**Règle métier corrigée :** Un `responsable_service` ne doit voir, valider et recevoir des notifications que pour les bons de **son propre service**. Les rôles supérieurs (CDG, DAF, DP) valident tous les services.

**Corrections appliquées :**

| Fichier | Correction | Impact |
|---------|-----------|--------|
| `NotificationService.php` | `notifierSoumission()` filtre par `$demandeur->service` | Notifications soumission envoyées uniquement au chef du bon service |
| `NotificationService.php` | Nouvelle méthode `destinatairesParRole()` centralise le filtrage service | `notifierValidation()`, `notifierRelanceSla()`, `notifierEscaladeSla()` utilisent ce filtre |
| `ValidationController.php` | `index()` ajoute `->where('service', $utilisateur->service)` pour `responsable_service` | Liste de validation filtrée par service |
| `ValidationController.php` | `show()`, `approuver()`, `rejeter()`, `demanderComplement()` vérifient `$bonCaisse->service !== $utilisateur->service` | Protection contre validation cross-service |
| `DashboardController.php` | `bonsEnAttenteValidation` filtré par service pour `responsable_service` | Dashboard cohérent |
| `BonCaisseController.php` | `index()` : responsable_service voit ses bons + ceux de son service (hors brouillons) | Liste bons filtrée par service |
| `BonCaisseController.php` | `show()` : ajout vérification `$bonCaisse->service === $utilisateur->service` | Accès détail filtré par service |
| `RelancerValidationsSLA.php` | SMS relance/escalade filtrés par service si `responsable_service` | SMS envoyés au bon chef de service |

**Méthode centralisée ajoutée :**
```php
// NotificationService.php
private static function destinatairesParRole(string $role, BonCaisse $bon)
{
    $query = User::actifs()->parRole($role);
    if ($role === 'responsable_service') {
        $bon->loadMissing('demandeur');
        $query->where('service', $bon->demandeur->service ?? $bon->service);
    }
    return $query->get();
}
```

#### 10.11.2 Localisation française complète

| Correction | Description | Fichiers |
|-----------|-------------|----------|
| **Carbon locale FR** | `Carbon::setLocale('fr')` + `setlocale(LC_TIME, 'fr_FR.UTF-8')` dans `AppServiceProvider::boot()` pour que `translatedFormat('l')` retourne des jours en français | `AppServiceProvider.php` |
| **Jours FR frontend** | Map `JOURS_FR` + helper `jourFr()` pour traduire les noms de jours anglais résiduels côté frontend | `Rapports/Index.jsx` |
| **metadata notifications** | Ajout `metadata` dans le `select()` de `NotificationController::index()` pour que l'urgence s'affiche côté frontend | `NotificationController.php` |

#### 10.11.3 Rapports de Caisse — UX

| Correction | Description | Fichiers |
|-----------|-------------|----------|
| **Tri du plus récent au plus ancien** | `[...lignesRapport].reverse().map(...)` pour afficher la date la plus récente en premier | `Rapports/Index.jsx` |
| **Tableau avant graphique** | Le tableau détaillé s'affiche désormais avant le graphique « Évolution de la caisse » | `Rapports/Index.jsx` |
| **Suppression bouton « Nouveau Rapport »** | Le bouton n'a pas de sens sur la page de rapports temps réel — retiré avec nettoyage des imports `Plus` et `Link` | `Rapports/Index.jsx` |

#### 10.11.4 Urgence visuelle sur les bons de caisse

| Élément | `tres_urgente` | `urgente` |
|---------|---------------|-----------|
| **Fond de ligne** | `bg-red-50/60` | `bg-orange-50/40` |
| **Badge** | Rouge + `animate-urgence-blink` | Orange |
| **Pastille** | Rouge + `animate-pulse` | Orange |
| **Label** | `TRÈS URGENT` | `URGENT` |

Configuration via constante `URGENCE_CONFIG` dans `BonsCaisse/Index.jsx`.

#### 10.11.5 Fix notification dropdown vide (BUG)

**Problème :** Le badge affichait « 4 nouvelles notifications » mais la liste déroulante était vide.

**Cause :** Le cache global `window.__notifCache` pouvait rester bloqué en état `enCours: true` empêchant tout chargement, et `metadata` manquait dans la réponse API.

**Solution :**
- Suppression du cache global `window.__notifCache`
- Remplacement par état React (`dejaCharge`, `fetchEnCours` ref)
- `chargerNotifications()` appelé à chaque ouverture du dropdown
- `fetchEnCours` ref empêche les requêtes concurrentes
- Parsing robuste de la réponse : `notifRes.data?.data || notifRes.data || []`

#### 10.11.6 Fix Dashboard.jsx — Erreurs de syntaxe JSX

**Problème :** Erreur Vite `Unexpected token, expected ","` à cause de commentaires JSX non fermés.

**Cause :** `{/* commentaire */` au lieu de `{/* commentaire */}` (accolade fermante manquante) aux lignes 397 et 460.

**Solution :** Ajout de `}` fermant sur les deux commentaires JSX concernés.

#### 10.11.7 Adaptation des exports Excel/PDF au mode temps réel

**Problème :** Les boutons Excel/PDF sur la page Rapports appelaient les anciennes routes `rapports.periode.export-excel/pdf` qui exportaient à partir de la table `rapports_caisse`. Or la page affiche désormais des données calculées en **temps réel** à partir de `bons_caisse`. L'export ne correspondait pas du tout aux données affichées à l'écran.

**Solution :** Création de nouveaux exports dédiés au mode temps réel.

| Élément | Description |
|---------|-------------|
| **Méthode partagée** | `calculerDonneesTempsReel()` — logique de calcul extraite de `tableauTempsReel()` en méthode privée réutilisable |
| **Export Excel** | `exportTempsReelExcel()` + classe `RapportTempsReelExport` — même données que l'écran |
| **Export PDF** | `exportTempsReelPdf()` + template `rapports-temps-reel-pdf.blade.php` — même données que l'écran |
| **Routes** | `rapports.temps-reel.export-excel` et `rapports.temps-reel.export-pdf` |
| **Filtres transmis** | `date_debut`, `date_fin`, `granularite`, `site`, `categorie`, `type_bon` — tous les filtres de la page |

**Contenu des exports :**
- En-tête avec période, granularité, site
- Résumé global (jours, bons, entrées, sorties, moyenne, solde)
- Top 5 catégories de dépenses
- Détail par période (jour/mois/année) avec entrées, sorties, solde cumulé
- Ligne total

**Note :** Les anciens exports (`rapports.periode.export-excel/pdf` et `rapports.export-excel/pdf`) sont conservés pour l'historique des rapports manuels (`Rapports/Show.jsx`). L'export quotidien par email (`EnvoyerRapportCaisseQuotidien`) utilise toujours les rapports sauvegardés, ce qui est correct.

#### 10.11.8 Fichiers modifiés (v12)

**Backend :**
- `app/Providers/AppServiceProvider.php` — Carbon locale FR
- `app/Services/NotificationService.php` — Filtrage service + méthode `destinatairesParRole()`
- `app/Http/Controllers/NotificationController.php` — Ajout `metadata` au select
- `app/Http/Controllers/ValidationController.php` — Filtrage service dans index/show/approuver/rejeter/demanderComplement
- `app/Http/Controllers/DashboardController.php` — Filtrage service dans bonsEnAttenteValidation
- `app/Http/Controllers/BonCaisseController.php` — Filtrage service dans index/show pour responsable_service
- `app/Http/Controllers/RapportCaisseController.php` — Refactoring `calculerDonneesTempsReel()` + `exportTempsReelExcel()` + `exportTempsReelPdf()`
- `app/Console/Commands/RelancerValidationsSLA.php` — Filtrage service dans SMS relance/escalade

**Nouveaux fichiers :**
- `app/Exports/RapportTempsReelExport.php` — Classe export Excel temps réel
- `resources/views/exports/rapports-temps-reel-pdf.blade.php` — Template PDF temps réel

**Frontend :**
- `resources/js/Pages/Rapports/Index.jsx` — Jours FR, tri récent→ancien, tableau avant graphique, suppression bouton Nouveau Rapport, liens export vers nouvelles routes temps réel avec tous les filtres
- `resources/js/Pages/BonsCaisse/Index.jsx` — Indicateurs visuels d'urgence (URGENCE_CONFIG)
- `resources/js/Components/NotificationBell.jsx` — Refactoring complet du chargement notifications
- `resources/js/Pages/Dashboard.jsx` — Fix commentaires JSX non fermés

**Routes ajoutées :**
- `GET /rapports-temps-reel/export-excel` → `rapports.temps-reel.export-excel`
- `GET /rapports-temps-reel/export-pdf` → `rapports.temps-reel.export-pdf`

#### 10.11.9 Nettoyage des exports rapport temps réel

**Problème :** Les exports PDF/Excel contenaient des informations inutiles pour un rapport de caisse professionnel (Top Catégories de dépenses, nombre de jours, moyenne par jour, granularité).

**Solution :** Épuration des exports pour ne conserver que les données essentielles :
- En-tête : titre, période, site, date d'édition
- Résumé : bons payés, total entrées (approvisionnements), total sorties (décaissements), solde de clôture
- Détail par période : tableau jour/mois/année avec bons, entrées, sorties, solde cumulé
- Ligne total
- PDF passé en orientation portrait (plus adapté au contenu allégé)

#### 10.11.10 Bon de caisse PDF — Données dynamiques

**Problème :** Le template `bon-caisse-pdf.blade.php` contenait des données en dur :
- Liste des sites codée : `['CKY', 'BOKE', 'SANGA', ...]`
- Liste des services codée : `['ADMIN', 'LOG', 'TECH', ...]`
- Seuil DP codé : `≥ 5.000.000`

**Solution :** Le contrôleur `BonCaisseController@exportPdf` transmet désormais :
- `$sitesListe` — `Site::actifs()` depuis la table `sites`
- `$servicesListe` — `Service::actifs()` depuis la table `services`
- `$seuilDP` — `Parametre::seuilDP()` depuis la table `parametres`

Le template affiche dynamiquement `DP (≥ {{ number_format($seuilDP, 0, ',', '.') }})`.

#### 10.11.11 Dashboard — Exclusion des brouillons des KPIs

**Problème :** Le KPI « Total Bons » comptait les bons au statut BROUILLON, ce qui rendait le total incohérent avec la somme des autres KPIs (En cours + En attente paiement + Terminés + Rejetés ≠ Total).

**Solution :**
- `total_bons` exclut désormais les `BROUILLON`
- `bons_crees` (mois en cours) exclut aussi les `BROUILLON`
- Le KPI « Terminés » utilise `bons_termines` qui inclut les statuts `PAYE`, `EN_ATTENTE_REGULARISATION`, `REGULARISE`, `ARCHIVE` (au lieu de seulement `PAYE`)
- `montant_total_paye` inclut aussi les montants des bons régularisés/archivés
- Résultat : **Total Bons = En cours + En attente paiement + Terminés + Rejetés**

#### 10.11.12 Fichiers modifiés (corrections exports & dashboard)

**Backend :**
- `app/Http/Controllers/RapportCaisseController.php` — Suppression `topCategories` des exports temps réel, PDF en portrait
- `app/Http/Controllers/BonCaisseController.php` — Passage données dynamiques (sites, services, seuilDP) au template PDF
- `app/Http/Controllers/DashboardController.php` — Exclusion BROUILLON du total, ajout `bons_termines`, `montant_total_paye` élargi
- `app/Exports/RapportTempsReelExport.php` — Suppression topCategories, nettoyage résumé
- `resources/views/exports/rapports-temps-reel-pdf.blade.php` — Suppression top catégories, résumé épuré, portrait
- `resources/views/exports/bon-caisse-pdf.blade.php` — Sites/services/seuil DP dynamiques

**Frontend :**
- `resources/js/Pages/Dashboard.jsx` — KPI Terminés utilise `bons_termines`

---

## 11. Commandes Utiles

```bash
# Installer les dépendances
composer install
npm install

# Lancer les migrations et seeder
php artisan migrate:fresh --seed

# Démarrer les serveurs de développement
php artisan serve
npm run dev
php artisan reverb:start --debug    # WebSocket Reverb (port 8080)

# Build de production
npm run build

# Vider les caches Laravel
php artisan optimize:clear

# Relance manuelle des BP non régularisés
php artisan bons:relancer-regularisation

# Génération et envoi automatique du rapport journalier de caisse
php artisan rapports:envoyer-quotidien

# Relance SLA validations (relances + escalade automatique)
php artisan validations:relancer-sla

# Vérification proactive des seuils de caisse (alertes SMS + push)
php artisan caisse:verifier-seuils

# Voir les tâches planifiées
php artisan schedule:list
```

---

## 12. Implémentation Post-Réunion 31/03/2026

Suite à la réunion client du 31 mars 2026, les fonctionnalités suivantes ont été implémentées en backend (modèles, migrations, contrôleurs, routes). Le frontend (pages React/Inertia) devra être mis à jour pour exploiter les nouvelles données transmises par les contrôleurs.

### 12.1 Phase 1 — Corrections Critiques

#### 12.1.1 Justification obligatoire urgence (Phase 1.1)

**Objectif :** Quand un bon est marqué `urgente` ou `tres_urgente`, le demandeur doit obligatoirement fournir un motif d'urgence (liste déroulante) et une justification textuelle.

**Migration :** `2026_04_01_142337_ajouter_motif_urgence_bons_caisse.php`
- Ajoute `motif_urgence` (string, nullable) et `justification_urgence` (text, nullable) à `bons_caisse`
- Insère le paramètre `motifs_urgence_predefinis` (JSON) dans `parametres` avec les motifs prédéfinis

**Modèle :** `BonCaisse.php`
- `motif_urgence` et `justification_urgence` ajoutés au `$fillable`

**Contrôleur :** `BonCaisseController.php`
- Règles `required_if:niveau_urgence,urgente,tres_urgente` dans `store()` et `update()`
- Les motifs prédéfinis sont passés au frontend via `motifsUrgence` dans `create()` et `edit()`

**Données frontend disponibles :**
- `motifsUrgence` — tableau des motifs prédéfinis (depuis Parametre)

#### 12.1.2 Code analytique éditable par CDG (Phase 1.2)

**Objectif :** Le Contrôle de Gestion peut modifier le code analytique et les ventilations analytiques lors de l'approbation d'un bon.

**Contrôleur :** `ValidationController.php` → méthode `approuver()`
- Si `$utilisateur->role === 'controle_gestion'`, les champs `code_analytique` et `ventilations[]` sont acceptés
- Modification enregistrée dans `HistoriqueAction` avec type `modification_code_analytique` ou `modification_ventilation`

**Données frontend disponibles (page Show) :**
- `codesAnalytiques` avec `business_unit` — pour afficher le sélecteur de code analytique au CDG

#### 12.1.3 Délai de traitement par étape (Phase 1.3)

**Objectif :** Afficher le délai de traitement à chaque étape de validation dans la vue détail d'un bon.

**Contrôleur :** `BonCaisseController.php` → méthode `show()`
- Calcul du délai par validation : `date_attribution → date_validation` (terminé) ou `date_attribution → now()` (en cours)
- Format : `2j 3h 15min` ou `5h 30min (en cours)`

**Modèle :** `BonCaisse.php`
- Nouvel accesseur `delai_traitement` : délai total soumission → paiement

**Données frontend disponibles :**
- `delaisValidation` — objet `{validation_id: "délai formaté"}`

### 12.2 Phase 2 — Gestion Solde de Caisse

#### 12.2.1 Caisse par site + Blocage solde négatif (Phase 2.1)

**Objectif :** Chaque site a sa propre caisse avec solde, plafond et seuil d'alerte. Le paiement est bloqué si le solde est insuffisant.

**Migration :** `2026_04_01_145809_add_caisse_limits_to_sites_table.php`
- Ajoute `solde_caisse` (decimal 15,2), `plafond_caisse` (decimal, nullable), `seuil_minimum_caisse` (decimal, défaut 500 000) à `sites`
- Insère le paramètre `seuil_minimum_caisse` (défaut 500 000 GNF) dans `parametres`

**Modèle :** `Site.php` (enrichi)
- Nouveaux champs dans `$fillable` et `$casts`
- Méthodes : `peutPayer($montant)`, `soldeSousSeuil()`, `debiter($montant)`, `crediter($montant)`
- Accesseurs : `solde_caisse_format`, `plafond_caisse_format`
- Relation : `mouvementsCaisse()`

**Contrôleur :** `BonCaisseController.php` → méthode `payer()`
- Vérifie `$siteModel->peutPayer($bonCaisse->montant)` avant le paiement
- Débite la caisse du site après paiement réussi
- Déclenche `NotificationService::notifierAlerteSolde()` si solde sous seuil

**Données frontend disponibles (page Show) :**
- `soldeCaisseSite` — `{solde, solde_format, plafond, seuil_minimum, sous_seuil, peut_payer}`

#### 12.2.2 Alerte seuil minimum (Phase 2.2)

**Objectif :** Notification automatique aux DAF, DP et caissiers du site quand le solde passe sous le seuil.

**Service :** `NotificationService.php`
- Nouvelle méthode `notifierAlerteSolde(Site $site, User $caissier)`
- Destinataires : DAF + DP + caissiers du même site
- Type notification : `alerte_solde`

**Modèle :** `Notification.php`
- Nouveau type `TYPE_ALERTE_SOLDE = 'alerte_solde'` avec config icône/couleur

#### 12.2.3 Réapprovisionnement caisse (Phase 2.3)

**Objectif :** Module complet de mouvements de caisse (approvisionnement, retrait, ajustement).

**Migration :** `2026_04_02_000001_creer_table_mouvements_caisse.php`
- Table `mouvements_caisse` : reference, type, montant, motif, site, statut, effectue_par, valide_par, dates, metadata

**Modèle :** `MouvementCaisse.php`
- Types : `approvisionnement`, `retrait`, `ajustement`
- Statuts : `en_attente`, `valide`, `rejete`
- Référence auto : `MVC-AAAA-NNNN`
- Méthodes : `valider()` (met à jour solde site), `rejeter()`
- Scopes : `parSite()`, `valides()`, `enAttente()`

**Contrôleur :** `MouvementCaisseController.php`
- `index()` — Liste avec filtres (site, statut, type) + soldes par site
- `create()` — Formulaire
- `store()` — Création mouvement (caissier)
- `valider()` — Validation DAF/DP, mise à jour solde site
- `rejeter()` — Rejet DAF/DP

**Routes :**
- `GET /mouvements-caisse` → `mouvements-caisse.index`
- `GET /mouvements-caisse/create` → `mouvements-caisse.create`
- `POST /mouvements-caisse` → `mouvements-caisse.store`
- `POST /mouvements-caisse/{mouvement}/valider` → `mouvements-caisse.valider` (DAF/DP)
- `POST /mouvements-caisse/{mouvement}/rejeter` → `mouvements-caisse.rejeter` (DAF/DP)

### 12.3 Phase 3 — Délégation de Pouvoirs

**Objectif :** Un validateur peut déléguer ses droits de validation à un autre utilisateur pendant une période définie (absence, congé).

**Migration :** `2026_04_02_000002_creer_table_delegations.php`
- Table `delegations` : delegant_id, delegue_id, date_debut, date_fin, motif, statut, acceptee_le

**Modèle :** `Delegation.php`
- Statuts : `en_attente`, `acceptee`, `refusee`, `terminee`
- Scope `actives()` : acceptées et dans la période courante
- Méthodes statiques : `delegationActivePour()`, `delegantsActifsPour()`
- Méthodes : `accepter()`, `refuser()`, `terminer()`

**Modèle :** `User.php` (enrichi)
- `peutValider()` vérifie désormais aussi les délégations actives
- `rolesValidationEffectifs()` retourne les rôles propres + délégués
- `aDelegationPour($role)` vérifie si délégation active pour un rôle donné
- Relations : `delegationsDonnees()`, `delegationsRecues()`

**Contrôleur :** `DelegationController.php`
- `index()` — Délégations données + reçues + en attente
- `create()` — Formulaire (validateurs uniquement)
- `store()` — Création avec contrôle de chevauchement
- `accepter()` / `refuser()` / `terminer()`

**Routes :**
- `GET /delegations` → `delegations.index`
- `GET /delegations/create` → `delegations.create`
- `POST /delegations` → `delegations.store`
- `POST /delegations/{delegation}/accepter` → `delegations.accepter`
- `POST /delegations/{delegation}/refuser` → `delegations.refuser`
- `POST /delegations/{delegation}/terminer` → `delegations.terminer`

### 12.4 Phase 4 — Codes Analytiques Enrichis + Ventilation

#### 12.4.1 Business Unit sur codes analytiques (Phase 4.1)

**Migration :** `2026_04_02_000003_enrichir_codes_analytiques.php`
- Ajoute `business_unit`, `description`, `categorie_depense_defaut` à `codes_analytiques`

**Modèle :** `CodeAnalytique.php`
- Nouveaux champs dans `$fillable`
- Scope `parBusinessUnit()`
- Accesseur `label_complet` : `ANA-001 — Fournitures (Administration)`

**Contrôleur :** `ParametrageController.php`
- `storeCodeAnalytique()` et `updateCodeAnalytique()` acceptent les nouveaux champs

#### 12.4.2 Ventilation analytique multi-codes (Phase 4.2)

**Migration :** `2026_04_02_000003_enrichir_codes_analytiques.php`
- Table `ventilations_analytiques` : bon_caisse_id, code_analytique, libelle, montant, pourcentage

**Modèle :** `VentilationAnalytique.php`
- Relation `bonCaisse()`
- Accesseur `montant_format`

**Modèle :** `BonCaisse.php`
- Relation `ventilations()`

**Contrôleurs :**
- `BonCaisseController.php` — `store()`/`update()` gèrent les ventilations (création, remplacement)
- `ValidationController.php` — Le CDG peut modifier les ventilations lors de l'approbation

### 12.5 Phase 5 — Rapports Détaillés

**Objectif :** Le rapport de caisse inclut désormais les détails complets de chaque bon : bénéficiaire, motif, numéro, site, code analytique, délai de traitement.

**Contrôleur :** `RapportCaisseController.php`
- `show()` — Enrichi avec `bonsPayeDetailles` contenant tous les champs détaillés + `delai_traitement`
- `exportPdf()` — Enrichi avec `categoriesLabels` et `modesPaiementLabels`, format paysage
- Données passées : `soldeCaisseSite` (solde + alerte seuil)

### 12.6 Phase 6 — Sécurité & Archivage

#### 12.6.1 Double validation modifications admin (Phase 6.1)

**Migration :** `2026_04_02_000004_creer_table_modifications_en_attente.php`
- Table `modifications_en_attente` : type_entite, entite_id, champ, ancienne_valeur, nouvelle_valeur, demandeur_id, valideur_id, statut, commentaire

**Modèle :** `ModificationEnAttente.php`
- Types critiques : `parametre`, `utilisateur_role`, `site_caisse`, `code_analytique`
- Méthodes : `approuver()`, `refuser()`

#### 12.6.2 Archivage durée conservation paramétrable (Phase 6.2)

**Migration :** `2026_04_02_000004_creer_table_modifications_en_attente.php`
- Ajoute `duree_conservation_mois` (défaut 120 = 10 ans) à `types_document`

**Modèle :** `TypeDocument.php`
- `duree_conservation_mois` ajouté au `$fillable`

### 12.7 Phase 7 — Identité Visuelle (Préparation)

La couleur principale `#fdc911` et le cadre visuel sont prêts. Les pages React devront être mises à jour pour exploiter les nouvelles données transmises (soldes site, délais, ventilations, motifs urgence, délégations, mouvements).

### 12.8 Impact Dashboard

**Contrôleur :** `DashboardController.php`
- Nouvelles données transmises au frontend :
  - `soldesSites` — solde/plafond/seuil par site (caissier, DAF, DP, admin)
  - `mouvementsEnAttente` — mouvements à valider (DAF/DP)
  - `delegationsActives` — délégations actives pour l'utilisateur courant

### 12.9 Nouvelles tables de base de données

| Table | Description |
|---|---|
| `mouvements_caisse` | Approvisionnements, retraits et ajustements de caisse |
| `delegations` | Délégations de pouvoirs entre validateurs |
| `ventilations_analytiques` | Ventilation multi-codes sur un bon |
| `modifications_en_attente` | Double validation pour modifications admin critiques |

### 12.10 Colonnes ajoutées aux tables existantes

| Table | Colonne | Type | Description |
|---|---|---|---|
| `bons_caisse` | `motif_urgence` | string, nullable | Motif prédéfini d'urgence |
| `bons_caisse` | `justification_urgence` | text, nullable | Justification détaillée urgence |
| `sites` | `solde_caisse` | decimal(15,2) | Solde courant de la caisse |
| `sites` | `plafond_caisse` | decimal(15,2), nullable | Plafond maximum caisse |
| `sites` | `seuil_minimum_caisse` | decimal(15,2) | Seuil d'alerte minimum |
| `codes_analytiques` | `business_unit` | string, nullable | Business unit associée |
| `codes_analytiques` | `description` | text, nullable | Description détaillée |
| `codes_analytiques` | `categorie_depense_defaut` | string, nullable | Catégorie de dépense par défaut |
| `types_document` | `duree_conservation_mois` | integer | Durée de conservation (défaut 120) |

### 12.11 Nouveaux modèles

| Modèle | Table | Description |
|---|---|---|
| `MouvementCaisse` | `mouvements_caisse` | Mouvements d'approvisionnement/retrait caisse |
| `Delegation` | `delegations` | Délégation de pouvoirs validateur |
| `VentilationAnalytique` | `ventilations_analytiques` | Ventilation multi-codes analytiques |
| `ModificationEnAttente` | `modifications_en_attente` | Double validation admin |

### 12.12 Nouveaux contrôleurs

| Contrôleur | Description |
|---|---|
| `MouvementCaisseController` | CRUD + validation mouvements caisse |
| `DelegationController` | CRUD + acceptation/refus/terminaison délégations |

### 12.13 Fichiers modifiés (récapitulatif)

**Migrations (remplies ou créées) :**
- `2026_04_01_142337_ajouter_motif_urgence_bons_caisse.php` — motif + justification urgence
- `2026_04_01_145809_add_caisse_limits_to_sites_table.php` — caisse par site
- `2026_04_02_000001_creer_table_mouvements_caisse.php` — *(nouveau)*
- `2026_04_02_000002_creer_table_delegations.php` — *(nouveau)*
- `2026_04_02_000003_enrichir_codes_analytiques.php` — *(nouveau)*
- `2026_04_02_000004_creer_table_modifications_en_attente.php` — *(nouveau)*

**Modèles modifiés :**
- `BonCaisse.php` — fillable, ventilations relation, soldeCaisseActuel, delai_traitement
- `User.php` — peutValider avec délégations, rolesValidationEffectifs, aDelegationPour, delegations relations
- `Site.php` — caisse fields, peutPayer, soldeSousSeuil, debiter, crediter
- `CodeAnalytique.php` — business_unit, description, categorie_depense_defaut
- `TypeDocument.php` — duree_conservation_mois
- `Notification.php` — TYPE_ALERTE_SOLDE
- `HistoriqueAction.php` — nouvelles constantes d'actions

**Contrôleurs modifiés :**
- `BonCaisseController.php` — urgence validation, ventilations, blocage solde, motifs urgence
- `ValidationController.php` — CDG édition code analytique/ventilations
- `DashboardController.php` — soldesSites, mouvementsEnAttente, delegationsActives
- `ParametrageController.php` — nouveaux champs sites et codes analytiques
- `RapportCaisseController.php` — rapport détaillé enrichi

**Services modifiés :**
- `NotificationService.php` — notifierAlerteSolde

**Routes ajoutées :** `routes/web.php`
- Bloc délégations (6 routes)
- Bloc mouvements-caisse (5 routes, middleware rôle)

**Seeder modifié :** `NeembaSeeder.php`
- Sites avec solde/plafond/seuil
- Codes analytiques avec business_unit
- Délégation exemple
- Mouvements de caisse exemples

### 12.14 Frontend post-réunion (v13 — TERMINÉ)

Toutes les pages React/Inertia ont été créées ou mises à jour pour exploiter les données backend post-réunion :

| # | Page | Statut | Détails |
|---|------|--------|----------|
| 1 | **`BonsCaisse/Create.jsx`** | ✅ Fait | Champs motif_urgence + justification_urgence conditionnels, section ventilations analytiques (synchronisation montant/%) |
| 2 | **`BonsCaisse/Edit.jsx`** | ✅ Fait | Mêmes ajouts urgence + ventilations que Create |
| 3 | **`BonsCaisse/Show.jsx`** | ✅ Fait | Délais par étape + délai total, solde caisse site (alerte seuil), ventilations, justification urgence |
| 4 | **`Dashboard.jsx`** | ✅ Fait | Widget soldes caisse par site (jauges + alertes), mouvements en attente, délégations actives |
| 5 | **`MouvementsCaisse/Index.jsx`** | ✅ Fait | Liste avec filtres (site/statut/type), KPIs soldes par site, actions valider/rejeter, pagination |
| 6 | **`MouvementsCaisse/Create.jsx`** | ✅ Fait | Formulaire avec cartes type, sélection site avec solde, montant avec alerte dépassement |
| 7 | **`Delegations/Index.jsx`** | ✅ Fait | Onglets délégations données/reçues/en attente, actions accepter/refuser/terminer |
| 8 | **`Delegations/Create.jsx`** | ✅ Fait | Formulaire sélection délégué, période, motif |
| 9 | **`Rapports/Show.jsx`** | ✅ Fait | Tableau bons payés enrichi (bénéficiaire, motif, numéro, délai traitement, site), widget solde caisse site |
| 10 | **`Parametrage/Index.jsx`** | ✅ Fait | Champs caisse site (solde, plafond, seuil), business_unit codes analytiques, colonnes tableau enrichies |

**Corrections backend associées (v13) :**
- `DashboardController.php` — Props `solde_caisse`/`plafond_caisse` renommées pour cohérence frontend, chargement relation `delegue` dans délégations
- `RapportCaisseController.php` — Prop `bonsPayeDetailles` renommée en `detailsBons`, ajout `solde` numérique dans `soldeCaisseSite`
- `MouvementCaisseController.php` — Ajout props `peutCreer`/`peutValider`, enrichissement `soldesSites` avec `sous_seuil`
- `Site.php` — Ajout `$appends = ['solde_caisse_format', 'plafond_caisse_format']` pour sérialisation automatique

---

## 13. Version v14 — Complétion des points post-réunion (03 Avril 2026)

### 13.1 Fonctionnalités ajoutées

#### 13.1.1 Notification responsable hiérarchique pour BP en retard

**Fichier :** `app/Services/NotificationService.php` — méthode `notifierRelanceRegularisation()`

La commande `bons:relancer-regularisation` envoyait uniquement une notification au demandeur. Désormais, le(s) responsable(s) de service du demandeur reçoivent également une notification d'alerte avec le message :
> "[Prénom Nom] n'a pas encore régularisé le bon provisoire [N°] ([Montant]). Retard : X jour(s). Veuillez relancer votre collaborateur."

Les responsables sont identifiés via `destinatairesParRole('responsable_service', $bon)` qui prend en compte les délégations actives.

#### 13.1.2 Commande Artisan — Alertes expiration archives

**Fichier :** `app/Console/Commands/AlerterExpirationArchives.php`

Nouvelle commande planifiée quotidiennement à 08h30 via `routes/console.php`.

Seuils de préavis : **J-30, J-7 et J-1** avant la date d'expiration.

Pour chaque document archivé expirant à ces seuils :
- Notification in-app + broadcast Reverb aux **DAF et administrateurs**
- Dédoublonnage via cache Laravel (une alerte par jour par document par seuil)
- Log d'erreur silencieux si Reverb indisponible

**Planification :** `Schedule::command('archives:alerter-expiration')->dailyAt('08:30')`

#### 13.1.3 Page "BP en Retard" — Tableau de suivi chef comptable

**Backend :** `app/Http/Controllers/BonCaisseController.php` — méthode `bpEnRetard()`
**Frontend :** `resources/js/Pages/BonsCaisse/BPEnRetard.jsx`
**Route :** `GET /bons-caisse/bp-en-retard` | Rôles : `daf`, `directeur_pays`, `administrateur`

Fonctionnalités :
- **3 KPIs** : nombre total de BP en retard, montant total, retard moyen en jours
- **Filtres** par site et par service
- **Tableau** avec numéro (lien cliquable), demandeur, site/service, montant, date limite, badge retard coloré (rouge >14j, orange >7j)
- **Pagination** sur 25 éléments par page
- **Bandeau d'alerte** informatif en haut de page

#### 13.1.4 Pré-remplissage automatique du code analytique (Create.jsx)

**Fichier :** `resources/js/Pages/BonsCaisse/Create.jsx`

Un `useEffect` écoute le changement de `data.categorie_depense`. Si le champ `code_analytique` est vide, il cherche automatiquement le premier code analytique dont `categorie_depense_defaut` correspond à la catégorie sélectionnée et le pré-remplit.

**Règle :** Le pré-remplissage n'écrase **jamais** un code déjà saisi par l'utilisateur.

**Dépendance :** Les codes analytiques doivent avoir leur champ `categorie_depense_defaut` renseigné lors du paramétrage initial (à faire avec le client).

#### 13.1.5 Menu Sidebar — Lien "BP en Retard"

**Fichier :** `resources/js/Layouts/AuthenticatedLayout.jsx`

Ajout d'une entrée "BP en Retard" (icône `ClipboardList`) dans la navigation latérale, visible uniquement pour les rôles : `daf`, `directeur_pays`, `administrateur`.

### 13.2 Récapitulatif des fichiers modifiés v14

| Fichier | Type | Description |
|---|---|---|
| `app/Services/NotificationService.php` | Modifié | Notif. responsable service pour BP en retard |
| `app/Console/Commands/AlerterExpirationArchives.php` | Nouveau | Alertes expiration archives légales |
| `routes/console.php` | Modifié | Planification commande expiration archives |
| `app/Http/Controllers/BonCaisseController.php` | Modifié | Méthode `bpEnRetard()` |
| `resources/js/Pages/BonsCaisse/BPEnRetard.jsx` | Nouveau | Page tableau suivi BP en retard |
| `routes/web.php` | Modifié | Route `bons-caisse.bp-en-retard` |
| `resources/js/Pages/BonsCaisse/Create.jsx` | Modifié | Pré-remplissage code analytique par catégorie |
| `resources/js/Layouts/AuthenticatedLayout.jsx` | Modifié | Lien "BP en Retard" dans la sidebar |

### 13.3 Fichiers supplémentaires v14 — UI Double Validation Admin

| Fichier | Type | Description |
|---|---|---|
| `app/Http/Controllers/ParametrageController.php` | Modifié | Méthodes `modificationsEnAttente()`, `approuverModification()`, `refuserModification()` |
| `resources/js/Pages/Admin/ModificationsEnAttente.jsx` | Nouveau | Page admin double validation avec KPIs, filtre, panel inline approuv/refus |
| `routes/web.php` | Modifié | 3 routes admin `/admin/modifications-en-attente/*` |
| `resources/js/Layouts/AuthenticatedLayout.jsx` | Modifié | Lien "Modifications Admin" dans sidebar (admin uniquement) |

### 13.4 Corrections de bugs v14

| Bug | Cause | Correction |
|---|---|---|
| 404 sur "BP en Retard" | Route statique déclarée APRÈS `Route::resource()` → wildcard `{bonCaisse}` capte "bp-en-retard" | Route déplacée AVANT `Route::resource()` + `php artisan route:clear` |
| Délai "2j" avec 0 validations | `delai_traitement` calculé depuis `created_at` même sans validation | Condition JSX : s'affiche seulement si `validations.length > 0` |
| Délai absent avec validations en cours | `delai_traitement` null pour bons non finalisés + condition `.some()` trop stricte | Calcul client-side fallback depuis `created_at` si backend retourne null |
| `formaterMontant` introuvable | Fonction inexistante dans `@/utils/nombreEnLettres` | Remplacée par `formaterNombre` (export existant) |
| `React.Fragment` non résolu | Import `React` manquant (nécessaire en React 17 pour `React.Fragment`) | `import React, { useState } from 'react'` |
| `window.route` résiduel | Appel incorrect au helper Ziggy | Remplacé par `route()` (global Ziggy) |

### 13.5 Points restants pour une livraison complète

| Priorité | Fonctionnalité | Statut |
|---|---|---|
| ✅ Terminé | UI double validation admin (`ModificationEnAttente`) | ✅ Fait |
| 🟡 Moyenne | Emails transactionnels (validation, paiement, relance) | ❌ Backlog |
| 🟡 Moyenne | Tests unitaires PHPUnit | ❌ Backlog |
| ⏳ Client | Paramétrage `categorie_depense_defaut` sur codes analytiques | ⏳ Données client |
| ⏳ Client | Liste codes analytiques, sites, utilisateurs définitifs | ⏳ Données client |

### 13.6 Règle d'ordre des routes Laravel (à respecter)

> **⚠ RÈGLE CRITIQUE :** En Laravel, toutes les routes statiques sans paramètre (`/ressource/action-statique`)
> doivent être déclarées **AVANT** `Route::resource()` ou tout wildcard `{param}` sur le même préfixe.
> Sinon, `{param}` intercepte le segment statique et retourne 404.

```php
// ✅ CORRECT
Route::get('/bons-caisse/bp-en-retard', ...); // statique en PREMIER
Route::resource('bons-caisse', ...);           // wildcard APRÈS

// ❌ INCORRECT (404 garanti sur /bp-en-retard)
Route::resource('bons-caisse', ...);
Route::get('/bons-caisse/bp-en-retard', ...);
```

---

## 14. Version v15 — Motif de régularisation + Timer BP (15 Avril 2026)

### 14.1 Motif obligatoire lors de la régularisation

**Objectif :** Obliger le demandeur à fournir un motif textuel lors de la régularisation d'un bon provisoire (BP), en plus des pièces justificatives. Le motif est exigé aussi bien dans la régularisation classique (`regulariser`) que dans la régularisation anticipée (`preRegulariser`).

**Migration :** `2026_04_15_000001_ajouter_motif_regularisation_bons_caisse.php`
- Ajoute `motif_regularisation` (text, nullable) à `bons_caisse`

**Modèle :** `BonCaisse.php`
- `motif_regularisation` ajouté au `$fillable`
- Méthode `regulariser()` accepte un 2ème paramètre `$motifRegularisation` et le sauvegarde
- Le motif est enregistré dans `historique_actions` pour traçabilité

**Contrôleur :** `BonCaisseController.php`
- Validation `motif_regularisation` obligatoire (`required|string|min:5|max:1000`) dans `regulariser()` **et** `preRegulariser()`
- Le motif est sauvegardé en base dans les deux cas
- Le formulaire de pré-régularisation renommé « Régulariser » (titre + bouton)

**Frontend :** `BonsCaisse/Show.jsx`
- Champ `Textarea` « Motif de régularisation » obligatoire dans les **deux** formulaires (régularisation classique + régularisation anticipée)
- Le motif est affiché dans le dialog de confirmation avant soumission
- Bouton « Régulariser » désactivé si motif < 5 caractères
- Titre et bouton de la pré-régularisation renommés : « Pré-régulariser » → « Régulariser »
- Affichage du motif sauvegardé dans la section Paiement/Régularisation pour les bons déjà régularisés

### 14.2 Timer temps écoulé pour les Bons Provisoires

**Objectif :** Afficher un compteur temps réel indiquant le temps écoulé depuis la création d'un BP, afin de mettre le demandeur en alerte sur le temps de traitement.

**Page Détail (`BonsCaisse/Show.jsx`) :**
- Timer live avec `useEffect` + `setInterval` (rafraîchissement chaque seconde)
- Format : `Xj Xh Xmin Xs` (ex: `2j 5h 30min 12s`)
- Code couleur progressif :
  - **Bleu** : < 3 jours (normal)
  - **Orange** : 3–7 jours (attention)
  - **Rouge + pulse** : > 7 jours (alerte)
- S'affiche pour tous les BP non régularisés/archivés

**Page Liste (`BonsCaisse/Index.jsx`) :**
- Temps écoulé affiché sous la date dans la colonne Date pour chaque BP en cours
- Format compact : `Xj Xh Xmin` (sans secondes)
- Rafraîchissement toutes les 60 secondes
- Même code couleur (bleu/orange/rouge)

### 14.3 Visibilité des bons — Strict + suivi

**Objectif :** Chaque validateur ne voit que les bons pertinents à son niveau, pas l'ensemble du pipeline.

**Règle de visibilité (appliquée dans `index()` et `show()`) :**
1. **Ses propres bons** (comme demandeur, tous statuts)
2. **Bons en attente à son niveau** de validation (rôle natif + rôles délégués)
   - `responsable_service` → `EN_ATTENTE_CHEF_SERVICE` (restreint aux services accessibles)
   - `controle_gestion` → `EN_ATTENTE_CDG`
   - `daf` → `EN_ATTENTE_DAF`
   - `directeur_pays` → `EN_ATTENTE_DP`
3. **Bons déjà validés** par l'utilisateur (via table `validations`) pour suivi
4. **Caissier** : bons de son site à payer/régulariser
5. **Administrateur** : accès global (tout sauf brouillons des autres)

**Impact :** Un validateur CDG (natif ou délégué) ne verra pas un bon en `EN_ATTENTE_CHEF_SERVICE` — il ne le verra que quand le bon arrivera à `EN_ATTENTE_CDG`.

### 14.4 Colonne ajoutée

| Table | Colonne | Type | Description |
|---|---|---|---|
| `bons_caisse` | `motif_regularisation` | text, nullable | Motif fourni lors de la régularisation d'un BP |

### 14.5 Fichiers modifiés/créés (v15)

| Fichier | Type | Description |
|---|---|---|
| `database/migrations/2026_04_15_000001_ajouter_motif_regularisation_bons_caisse.php` | Nouveau | Migration motif_regularisation |
| `app/Models/BonCaisse.php` | Modifié | fillable + méthode regulariser() avec motif |
| `app/Http/Controllers/BonCaisseController.php` | Modifié | Validation motif dans regulariser() et preRegulariser(), visibilité strict+suivi dans index() et show() |
| `resources/js/Pages/BonsCaisse/Show.jsx` | Modifié | Textarea motif dans les 2 formulaires, renommage « Régulariser », timer live BP |
| `resources/js/Pages/BonsCaisse/Index.jsx` | Modifié | Timer compact BP dans la colonne Date |

---

## 15. Version v16 — Suppression clôture, envoi email manuel, détail bons journalier (15 Avril 2026)

### 15.1 Suppression de la notion de clôture

**Objectif :** Éliminer la dépendance à la « clôture » pour la génération et l'envoi des rapports journaliers. Les rapports sont désormais calculés en temps réel à partir des bons de caisse payés, sans nécessiter un acte de clôture préalable.

**Changements backend :**

| Fichier | Modification |
|---------|-------------|
| `app/Models/RapportCaisse.php` | Suppression méthode `cloturer()` et scope `scopeClotures()`. `soldePrecedent()` ne filtre plus sur `cloture=true`. |
| `app/Http/Controllers/RapportCaisseController.php` | Suppression méthode `cloturer()`. `viserDaf()` n'exige plus la clôture. Suppression des filtres clôture dans `index()`. |
| `app/Console/Commands/EnvoyerRapportCaisseQuotidien.php` | Refactorisé : calcule les données en temps réel via `calculerDonneesJournalieres()` sans créer de `RapportCaisse` en base. Envoie directement l'email avec les données calculées en mémoire. |
| `routes/web.php` | Suppression routes `rapports.cloturer` et `rapports.historique`. |

**Changements frontend :**

| Fichier | Modification |
|---------|-------------|
| `resources/js/Pages/Rapports/Index.jsx` | Suppression onglet « Historique et Clôtures », bouton « Clôturer aujourd'hui ». Page simplifiée en vue unique temps réel. |
| `resources/js/Pages/Rapports/Show.jsx` | Suppression bouton/dialog clôture, badge clôture. « Solde clôture » renommé « Solde fin de journée ». Visa DAF indépendant de la clôture. |
| `resources/js/Pages/Rapports/Create.jsx` | « Solde clôture » renommé « Solde fin de journée ». |
| `resources/js/Pages/Dashboard.jsx` | Badge `cloture` remplacé par badge « Visé DAF » / « En attente visa ». |

**Note :** La colonne `cloture` dans `rapports_caisse` est dépréciée mais conservée pour compatibilité.

### 15.2 Envoi manuel du rapport journalier par email

**Objectif :** Permettre l'envoi du rapport de caisse (Excel + PDF en pièces jointes) par email aux destinataires (DAF, Contrôle de Gestion, Caissier du site, Administrateurs) directement depuis l'interface.

**Route :** `POST /rapports/envoyer-email` → `rapports.envoyer-email`

**Paramètres :**
- `date` (requis) — Date du rapport (YYYY-MM-DD)
- `site` (optionnel) — Si vide, envoie pour **tous les sites actifs**

**Backend :** `RapportCaisseController.php`
- Méthode publique `envoyerRapportEmail()` — Orchestre l'envoi pour un ou tous les sites
- Méthode privée `envoyerRapportPourSite()` — Calcule les données en mémoire (sans persister), construit un `RapportCaisse` en mémoire, envoie via `RapportCaisseQuotidien` Mailable
- Message flash : nombre de rapports envoyés, sites ignorés (sans mouvement), erreurs éventuelles

**Destinataires par site :**
- Rôles `daf`, `controle_gestion`, `administrateur` (globaux)
- Rôle `caissier` du site concerné

### 15.3 Détail des bons payés par jour (expansion)

**Objectif :** En vue journalière, chaque ligne du tableau peut être dépliée pour afficher les bons de caisse payés ce jour-là avec tous leurs détails.

**Backend :** `RapportCaisseController.php` → `calculerDonneesTempsReel()`
- Nouvelle requête `bonsParJour` : récupère les bons payés avec `demandeur`, groupés par date
- Chaque ligne de `lignesRapport` contient un tableau `bons` (uniquement en granularité `jour`) avec :
  - `id`, `numero`, `beneficiaire`, `demandeur`, `motif`, `montant`, `categorie`, `mode_paiement`, `type_bon`

**Frontend :** `Rapports/Index.jsx`
- État `lignesExpansees` pour tracker les lignes ouvertes
- Chevron `›` / `▾` sur les lignes ayant des bons
- Clic sur une ligne → déplie un sous-tableau animé (Framer Motion) avec :
  - N° bon (mono), bénéficiaire (+demandeur si différent), motif, catégorie (badge), type BD/BP (badge), montant
- Fond ambre pour distinguer la zone d'expansion

### 15.4 Actions par ligne de jour (email, Excel, PDF)

**Objectif :** Chaque ligne de jour avec des bons dispose de 3 boutons d'action pour ce jour précis.

| Bouton | Icône | Action |
|--------|-------|--------|
| **Email** | `Send` (jaune) | POST `/rapports/envoyer-email` avec `date` + `site` du jour |
| **Excel** | `FileSpreadsheet` (vert) | Ouvre `/rapports-temps-reel/export-excel?date_debut=JOUR&date_fin=JOUR&site=...` |
| **PDF** | `Download` (rouge) | Ouvre `/rapports-temps-reel/export-pdf?date_debut=JOUR&date_fin=JOUR&site=...` |

- Spinner de chargement pendant l'envoi email (par ligne, indépendant)
- `stopPropagation()` sur chaque bouton pour ne pas déclencher l'expansion de la ligne
- Bouton d'envoi global (barre d'exports) conservé pour envoyer la période entière

### 15.5 Fichiers modifiés/créés (v16)

| Fichier | Type | Description |
|---------|------|-------------|
| `app/Models/RapportCaisse.php` | Modifié | Suppression `cloturer()`, `scopeClotures()`, `soldePrecedent()` sans filtre clôture |
| `app/Http/Controllers/RapportCaisseController.php` | Modifié | Suppression `cloturer()`, ajout `envoyerRapportEmail()` + `envoyerRapportPourSite()`, `calculerDonneesTempsReel()` enrichi avec `bonsParJour`, `viserDaf()` sans prérequis clôture |
| `app/Console/Commands/EnvoyerRapportCaisseQuotidien.php` | Modifié | Refactorisé : calcul temps réel sans persistance en base |
| `routes/web.php` | Modifié | Suppression `rapports.cloturer` et `rapports.historique`, ajout `rapports.envoyer-email` |
| `resources/js/Pages/Rapports/Index.jsx` | Modifié | Suppression onglet historique/clôture, ajout expansion bons, actions par ligne (email/Excel/PDF), flash messages, import React |
| `resources/js/Pages/Rapports/Show.jsx` | Modifié | Suppression clôture UI, renommage solde, visa DAF indépendant |
| `resources/js/Pages/Rapports/Create.jsx` | Modifié | Renommage « Solde clôture » → « Solde fin de journée » |
| `resources/js/Pages/Dashboard.jsx` | Modifié | Badge clôture → badge visa DAF |

---

## 16. Version v17 — Alertes proactives seuil de caisse (15 Avril 2026)

### 16.1 Objectif

Alerter automatiquement les caissiers d'un site par **SMS + notification push** lorsque le solde de caisse de leur site atteint ou passe sous le seuil minimum configuré, afin qu'ils procèdent au réapprovisionnement. Le seuil est fixé individuellement pour chaque site (colonne `seuil_minimum_caisse` de la table `sites`).

### 16.2 Mécanisme

Les alertes sont déclenchées dans **3 situations** :

| Déclencheur | Moment | Description |
|------------|--------|-------------|
| **Paiement d'un bon** | Après `BonCaisseController::payer()` | Déjà existant (v13), enrichi avec envoi SMS |
| **Validation d'un retrait** | Après `MouvementCaisseController::valider()` | Nouveau — vérifie le seuil après débit du retrait |
| **Vérification planifiée** | Commande `caisse:verifier-seuils` (2× par jour) | Nouveau — scan proactif de tous les sites |

### 16.3 Destinataires des alertes

| Destinataire | Notification push | SMS |
|-------------|:-:|:-:|
| **Caissiers du site** | ✅ | ✅ (si téléphone renseigné) |
| **DAF** | ✅ | ❌ |
| **Directeur Pays** | ✅ | ❌ |

### 16.4 Commande Artisan `caisse:verifier-seuils`

```bash
# Vérification manuelle
php artisan caisse:verifier-seuils

# Planification (routes/console.php) — 2× par jour
Schedule::command('caisse:verifier-seuils')->dailyAt('08:00');
Schedule::command('caisse:verifier-seuils')->dailyAt('14:00');
```

**Fonctionnement :**
1. Parcourt tous les sites actifs
2. Pour chaque site dont `soldeSousSeuil()` est vrai :
   - Vérifie le dédoublonnage (max 1 alerte par jour par site via cache Laravel)
   - Envoie notifications push (DAF + DP + caissiers du site)
   - Envoie SMS aux caissiers du site (via Nimba)
3. Affiche un tableau récapitulatif en console

### 16.5 Dédoublonnage

- Clé cache : `alerte_seuil_caisse_{site_id}_{date_jour}`
- Durée : jusqu'à fin de journée (`now()->endOfDay()`)
- Effet : même si la commande tourne 2× par jour, un site ne reçoit qu'**une seule alerte par jour**

### 16.6 Modifications `NotificationService`

| Méthode | Modification |
|---------|-------------|
| `notifierAlerteSolde(Site, ?User)` | Signature modifiée (`$caissier` → `$declencheur` nullable). Ajout envoi SMS Nimba aux caissiers du site |
| `verifierSeuilsCaisse()` | **Nouvelle** — Logique de scan proactif + dédoublonnage |

### 16.7 Fichiers modifiés/créés (v17)

| Fichier | Type | Description |
|---------|------|-------------|
| `app/Console/Commands/VerifierSeuilCaisse.php` | Nouveau | Commande Artisan `caisse:verifier-seuils` |
| `app/Services/NotificationService.php` | Modifié | `notifierAlerteSolde()` enrichi avec SMS + `verifierSeuilsCaisse()` nouvelle méthode |
| `app/Http/Controllers/MouvementCaisseController.php` | Modifié | Alerte seuil après validation d'un retrait |
| `routes/console.php` | Modifié | Planification `caisse:verifier-seuils` à 08:00 et 14:00 |

### 16.8 Configuration par site

Le seuil est propre à chaque site et configurable dans **Paramétrage > Sites** :

| Colonne | Description | Défaut |
|---------|-------------|--------|
| `seuil_minimum_caisse` | Seuil en GNF sous lequel l'alerte est déclenchée | 500 000 GNF |

Si aucun `seuil_minimum_caisse` n'est défini pour un site, le paramètre global `seuil_minimum_caisse` de la table `parametres` est utilisé (défaut : 500 000 GNF).

---

## 17. Module OCR — Analyse automatique des pièces jointes (Non daté — découvert audit Juin 2026)

> Ce module était entièrement implémenté mais non documenté. Documenté rétroactivement après audit du code.

### 17.1 Objectif

Analyser automatiquement les pièces jointes (factures, reçus, tickets carburant) lors de leur upload pour **pré-remplir le formulaire de bon de caisse** : fournisseur, montant, date, référence de facture. Contexte guinéen pris en compte (devise GNF par défaut, stations-service africaines, langues fr+en).

### 17.2 Architecture — Deux modes

| Mode | Déclencheur | Contrôleur/Job | Usage |
|------|------------|----------------|-------|
| **Synchrone** | Upload d'un fichier pendant la saisie | `OcrAnalyseController` | Pré-remplissage immédiat du formulaire |
| **Asynchrone** | Pièce jointe déjà attachée à un bon | `ProcessPieceJointeOcrJob` | Traitement en arrière-plan (queue) |

**Philosophie explicite dans le code :** *"OCR = assistance, jamais obligation"*

### 17.3 Fichiers

| Fichier | Rôle |
|---------|------|
| `app/Http/Controllers/OcrAnalyseController.php` | POST `/api/ocr/analyser` — reçoit un fichier, retourne les données extraites |
| `app/Http/Controllers/OcrController.php` | GET `/api/bons-caisse/{bon}/ocr` — statut et données OCR de toutes les pièces d'un bon |
| `app/Services/OcrService.php` | Extraction du texte brut depuis PDF (pdftotext + regex fallback) et images (Tesseract) |
| `app/Services/DocumentAnalyseService.php` | Analyse intelligente du texte extrait → champs structurés |
| `app/Jobs/ProcessPieceJointeOcrJob.php` | Job de queue : OCR + classification IA + indexation full-text + contrôle DPI |

### 17.4 Moteur d'extraction — `OcrService`

| Support | Outil | Fallback |
|---------|-------|---------|
| **PDF** | `pdftotext` (poppler-utils, option `-layout`) | Extraction regex PHP sur streams textuels |
| **Images** | Tesseract OCR (langues `fra+eng`) | Retry sans langue si échec |

Détection automatique des exécutables via `where` (Windows) / `which` (Linux/Mac). Retourne une chaîne vide si aucun outil disponible (dégradation gracieuse).

> **⚠ Windows :** La commande Tesseract utilise `2>/dev/null` qui ne fonctionne pas sur Windows PowerShell. Sur le serveur Linux de production ce n'est pas un problème.

### 17.5 Analyse intelligente — `DocumentAnalyseService`

**Niveau 1 (si `OPENAI_API_KEY` configurée) :**
- Appel API OpenAI `chat/completions` (modèle configurable `OPENAI_MODEL`, défaut `gpt-4o-mini`)
- Temperature 0.1, max_tokens 500
- Prompt extrait : fournisseur, date_document, montant, devise, reference_document, description, et pour carburant : station, litrage, prix_unitaire, immatriculation

**Niveau 2 — Fallback regex (sans clé OpenAI) :**
- Montant : patterns total/net à payer/TTC
- Devise : GNF par défaut si contexte guinéen
- Date : formats DD/MM/YYYY, YYYY-MM-DD, texte français
- Référence : patterns FAC-, INV-, numéro de facture
- Fournisseur : heuristique première ligne
- Carburant : stations TOTAL/SHELL/STAR OIL/ORYX/VIVO ENERGY/ENGEN détectées

### 17.6 Routes OCR

```php
POST /api/ocr/analyser                          — Analyse instantanée d'un fichier uploadé
GET  /api/bons-caisse/{bonCaisse}/ocr           — Statut OCR de toutes les pièces du bon
```

### 17.7 Configuration requise

```env
# Optionnel — active l'analyse IA. Sans clé, mode regex uniquement.
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini   # défaut

# Outils système (installer sur le serveur)
# PDF  : sudo apt install poppler-utils
# Image: sudo apt install tesseract-ocr tesseract-ocr-fra
```

### 17.8 Champs retournés

| Champ | Type | Description |
|-------|------|-------------|
| `fournisseur` | string | Nom du fournisseur/émetteur |
| `date_document` | string | Date de la facture/reçu |
| `montant` | float | Montant total |
| `devise` | string | Devise (GNF par défaut) |
| `reference_document` | string | Numéro de facture/référence |
| `description` | string | Description de la dépense |
| `station` | string | *(carburant)* Nom de la station |
| `litrage` | float | *(carburant)* Volume en litres |
| `prix_unitaire` | float | *(carburant)* Prix par litre |
| `immatriculation` | string | *(carburant)* Immatriculation du véhicule |

---

## 18. Version v18 — Pilote Conakry + Déploiement Production (2 Juin 2026)

### 18.1 Déploiement Production

| Élément | Valeur |
|---------|--------|
| URL production | `https://neemba.wedrive.africa` |
| Référent Addvalis | Thierno DIALLO — Lead consultant |

**Bug production identifié et corrigé :**

| Bug | Cause | Correction |
|-----|-------|-----------|
| **403 FORBIDDEN sur pièces jointes** | Le symlink `public/storage → storage/app/public` était absent sur le serveur de production | `php artisan storage:link` sur le serveur |

### 18.2 Paramétrage client NEEMBA Caterpillar Conakry

Le client a défini ses propres seuils de validation, différents des valeurs par défaut du devBook :

| Paramètre | Valeur par défaut | **Valeur client** | Raison |
|-----------|------------------|-------------------|--------|
| `seuil_validation_dp` | 5 000 000 GNF | **1 500 000 GNF** | Scénario S08 plan de test client |
| `seuil_minimum_caisse` | 500 000 GNF | **1 000 000 GNF** | Seuil d'alerte caisse Conakry |

> **⚠ IMPORTANT :** Toute référence au seuil DP dans le code doit utiliser `Parametre::seuilDP()` (dynamique) et non une valeur en dur. Cette règle est déjà appliquée depuis la v6.

### 18.3 Utilisateurs pilote réels (Conakry)

Mot de passe commun : **`Neemba@2026`**

| Rôle | Email | Téléphone | Matricule |
|------|-------|-----------|-----------|
| Demandeur | saoudou.barry@neemba.com | 622 461 261 | NMB-P001 |
| Responsable Service | thierry.gomis@neemba.com | 627 471 735 | NMB-P002 |
| Contrôle de Gestion | maimouna.barry@neemba.com | 627 261 871 | NMB-P003 |
| DAF | mohamed.diakite@neemba.com | 629 000 769 | NMB-P004 |
| Directeur Pays | mamadou.lo@neemba.com | 612 007 272 | NMB-P005 |
| Caissier | youssouf.toure@neemba.com | 623 072 484 | NMB-P006 |
| Administrateur (Addvalis) | thierno.diallo@addvalis.com | — | ADV-001 |

Tous les utilisateurs pilote sont dans le service **Aftermarket** (demandeur + chef) et le site **Conakry**.

### 18.4 Seeder pilote

**Fichier :** `database/seeders/NeembaSeeder.php` — entièrement reécrit pour le pilote.

```bash
php artisan migrate:fresh --seed
```

**Configuration caisse Conakry :**
- Solde initial : **15 000 000 GNF**
- Plafond : 50 000 000 GNF
- Seuil alerte : 1 000 000 GNF

**Bons pré-positionnés pour les 11 scénarios du plan de test :**

| Bon | Scénario | Statut | Montant | Objectif |
|-----|---------|--------|---------|----------|
| BC-2026-0001 | S03 | EN_ATTENTE_CDG | 450 000 | CDG corrige le code analytique (DAFZZZ → ADAZZZ) |
| BC-2026-0002 | S04 | EN_ATTENTE_CHEF_SERVICE | 320 000 | Chef de service rejette avec motif |
| BC-2026-0003 | S05 | PAYE (BP) | 1 200 000 | Demandeur régularise avant J+2 |
| BC-2026-0004 | S06 | APPROUVE | 750 000 | Caissier génère OTP et paie |
| BC-2026-0005 | S07 | EN_ATTENTE_DAF | 6 000 000 | Workflow 4 niveaux (DAF → DP) |
| BC-2026-0006 | S08 | EN_ATTENTE_DP | 2 000 000 | Validation DG Pays (seuil 1,5M) |
| BC-2026-0007/8/9 | S09 | PAYE (aujourd'hui) | ~450 000 chacun | Rapport journalier temps réel |
| BC-2026-0010 | S11 | APPROUVE | 16 000 000 | Blocage solde (16M > 15M caisse) |

> S01, S02 et S10 sont créés directement par les utilisateurs pendant le test (pas de pré-seeding).

### 18.5 Plan de test pilote

**Fichier :** `test.md` à la racine du projet.

| Réf | Scénario | Rôles impliqués | KPI mesuré |
|-----|---------|-----------------|------------|
| S01 | Bon standard bout-en-bout | Tous | Délai validation < 2j |
| S02 | Bon urgent (motif obligatoire) | Demandeur | Notification < 2h |
| S03 | Correction code analytique CDG | Demandeur + CDG | Traçabilité modification |
| S04 | Rejet avec notification | CDG/DAF | Délai notification rejet < 2h |
| S05 | Bon provisoire + régularisation | Demandeur + Caissier | Taux régularisation dans délai |
| S06 | Paiement OTP sécurisé | Caissier | Sécurité + solde mis à jour |
| S07 | Workflow 4 niveaux (≥ 5M GNF) | DAF + DG Pays | Workflow complet |
| S08 | Seuil DG Pays (≥ 1,5M GNF) | DG Pays | Seuil paramétrable |
| S09 | Rapport journalier automatique | Caissier/Finance | Rapport J+0 avant 8h |
| S10 | Délégation de signature | Admin/CDG | Délégation opérationnelle |
| S11 | Blocage solde négatif | Caissier | Blocage + alerte automatique |

**Critère de clôture :** Fiche GO / NO GO signée à J+10 maximum.

### 18.6 Fichiers modifiés (v18)

| Fichier | Type | Description |
|---------|------|-------------|
| `database/seeders/NeembaSeeder.php` | Modifié | Réécriture complète — utilisateurs pilote réels, bons pré-positionnés, seuil DP 1,5M |
| `test.md` | Nouveau | Plan de test pilote Conakry — 11 scénarios, grille KPI, journal quotidien |
| `devBook.md` | Modifié | Documentation module OCR (§17), pilote Conakry (§18) |

---

## 19. Version v19 — Lot 0 : sécurité et stabilisation avant M03 (6 Octobre 2026)

### 19.1 Contexte

La SFD v1.3 (livraison 1, 05/10/2026) détaille le module M03 « Saisie du bon de caisse » et liste 14 anomalies du socle (ANO-01 à ANO-14). Le travail est découpé en lots :

| Lot | Contenu | Statut |
|-----|---------|--------|
| 0 | Sécurité, stabilisation du travail non commité (juin/août), bugs de production | ✅ Fait (branche `lot0-stabilisation`) |
| 1 | Socle transverse : messages MSG-xx (`lang/fr.json`), formatage §1.4, statuts, montant en lettres, audit, multi-rôles | ✅ Fait (branche `lot1-socle`, §20) |
| 2 | Table `caisses` (caisse payeuse, plafond de retrait, OM Conakry, Atelier) | ✅ Fait (branche `lot2-caisses`, §21) |
| 3 | M03-A : assistant, brouillon, soumission (US-BC-01 à 07, 10, 11, 12, 15) | ⏳ À faire |
| 4 | M03-B : pièces justificatives, lecture des tickets carburant (US-BC-08, 09) | ⏳ À faire |
| 5 | M03-C : délégation d'initiation, resoumission, liste et fiche (US-BC-13, 14, 16) | ⏳ À faire |

Les questions en attente de réponse sont dans `docs/questions.md` (Q1 à Q13).

### 19.2 Sécurité — inscription libre supprimée

`POST /register` était encore actif : n'importe qui pouvait se créer un compte actif avec le rôle `demandeur` (le middleware `verified` ne bloque rien, `User` n'implémente pas `MustVerifyEmail`).
Supprimés : routes `register` (`routes/auth.php`), `RegisteredUserController`, pages `Auth/Register.jsx` et `Welcome.jsx` (non utilisée). Les comptes sont créés uniquement par l'administrateur.

> **⚠ Production :** lister les comptes sans matricule (créés par l'inscription libre) et les désactiver.

### 19.3 Évolutions DAF intégrées (travail de juin et août — Périmètre 2)

| Évolution | Détail | Fichiers principaux |
|-----------|--------|---------------------|
| **Double solde Espèces / OM** | `sites.solde_caisse` remplacé par `solde_especes` + `solde_om` (`solde_caisse` devient un accesseur = total). Mouvements de caisse qualifiés par `type_caisse` (`especes` / `om`), pièce justificative facultative, caissier limité à son site | `2026_06_02_000001/000002`, `Site.php`, `MouvementCaisse.php`, `MouvementCaisseController.php`, Dashboard, Paramétrage |
| **Billetage et réconciliation** | Rapport manuel : comptage par coupure (20 000 → 50 GNF), solde OM lu sur le téléphone, écarts compté − comptable, justification obligatoire en cas d'écart | `2026_06_02_000003`, `RapportCaisse.php`, `Rapports/Create.jsx` |
| **Motifs de rejet prédéfinis** | `BonCaisse::MOTIFS_REJET` (6 motifs) ; commentaire obligatoire si « Autre ». Champs partagés par les deux écrans de rejet (`Components/ChampsRejet.jsx`) | `ValidationController@rejeter`, `Validations/Show.jsx`, `BonsCaisse/Show.jsx` |

### 19.4 Correctifs

| Problème | Cause | Correction |
|----------|-------|------------|
| Un paiement Orange Money débitait la caisse espèces | `payer()` testait `'mobile_money'`, la valeur réelle est `'orange_money'` | `BonCaisseController@payer` |
| Un virement (ou « autre ») débitait la caisse espèces | Tout mode non OM était traité comme espèces | Paiement hors caisse (RG-BC-12) : `BonCaisse::MODES_PAIEMENT_CAISSE = ['especes', 'orange_money']` |
| L'OTP était consommé même si le solde était insuffisant | Consommé avant le contrôle du solde | Consommé dans la même transaction que le paiement |
| Double paiement possible (double clic, deux caissiers) | Ni transaction ni verrou | `DB::transaction` + `lockForUpdate` sur le bon |
| **Le rapport automatique de 07:30 était vide en usage réel** | Il ne retenait que les bons au statut `PAYE`, alors qu'un BD payé passe aussitôt en `ARCHIVE` et un BP en `EN_ATTENTE_REGULARISATION` ; il ignorait aussi les entrées | Nouveau `App\Services\RapportJournalierService`, commun à la commande et à l'envoi manuel ; scope `BonCaisse::payesLe($date)` |
| Création, fiche et exports du rapport manuel vides ou faux | Même filtre `PAYE` + date `updated_at` au lieu de `date_paiement` | `payesLe()` + modes de caisse uniquement |
| Le rapport du jour servait d'ouverture à lui-même | `soldePrecedent()` prenait le dernier rapport sans borne de date | `RapportCaisse::soldePrecedent($site, $avant)` |
| Rapports antérieurs au double solde : ouverture Espèces/OM à 0 | Colonnes ajoutées avec la valeur 0 | Migration de reprise `2026_10_06_000002` (totaux reportés en espèces) |
| L'enregistrement du rapport reprenait les totaux et écarts envoyés par l'écran | Aucun recalcul serveur | Totaux, clôtures, comptage espèces (depuis le billetage) et écarts recalculés ; motif exigé si écart |
| `Rapports/Create.jsx` ne compilait pas | `import` dans la fonction, `soldeCloture` déclaré deux fois ; écarts calculés avant tout comptage | Valeurs dérivées, écart affiché seulement une fois le comptage saisi |
| Rejet impossible depuis la fiche du bon | `motif_rejet` non envoyé, erreur non affichée | `ChampsRejet` partagé, `motifsRejet` transmis par `BonCaisseController@show` |
| Cartes de la liste à 0 dès la page 2 | Statistiques calculées sur un clone pris après `paginate()` (LIMIT/OFFSET) | Clone pris avant `paginate()` |
| `archives:alerter-expiration` plantait | Colonne `date_expiration_archive` inexistante | `date_expiration_retention` |
| `DELETE /bons-caisse/{id}` pointait vers une méthode inexistante | `Route::resource` complet | `->except(['destroy'])` (un bon s'annulera, RG-BC-31) |
| Seeder : tous les soldes à 0 (scénario S11 cassé) | `solde_caisse` n'est plus remplissable | `solde_especes` / `solde_om` |
| Paramètre `duree_validite_otp` mal typé | Type `integer` inconnu, groupe perdu | Migration `2026_10_06_000001` (type `number`, groupe `securite`) |

### 19.5 Tests

- `phpunit.xml` : base **MySQL `neemba_test`** (les migrations `ALTER ... MODIFY ENUM` et l'index FULLTEXT ne passent pas sous SQLite). Créer la base une fois : `CREATE DATABASE neemba_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
- Aucun SMS ni appel IA pendant les tests (Nimba et OpenAI neutralisés) ; `NimbaSmsService` n'appelle plus l'API quand aucun jeton n'est configuré.
- `tests/Feature/Lot0/StabilisationTest.php` (10 tests de non-régression), `RegistrationTest` (inscription fermée), `ExampleTest` (redirection vers la connexion).
- `php artisan test` : **35 tests verts**.

### 19.6 Déploiement

```bash
php artisan migrate        # 2026_06_02_000001/2/3 + 2026_10_06_000001/2
php artisan optimize:clear
```

Le front est déjà compilé (`public/build`).

### 19.7 Limites connues (traitées dans les lots suivants)

- L'ouverture du rapport journalier reprend la clôture du dernier rapport enregistré (Q13). Le registre par caisse du lot 2 la recalculera exactement.
- Les données Espèces / OM sont calculées, mais les rapports e-mail, PDF et Excel n'affichent encore que les totaux.
- Le tableau temps réel ne compte plus que les paiements en caisse (espèces et OM) ; sa ventilation Espèces / OM viendra avec le lot 2.

---

## 20. Version v20 — Lot 1 : socle transverse (7 Octobre 2026)

Prérequis communs à toutes les user stories de M03. Branche `lot1-socle`, partie de `lot0-stabilisation`.

### 20.1 Outillage de tests

| Élément | Détail |
|---------|--------|
| Factories | `UserFactory` enrichie (prénom, matricule, rôle, site, service ; états `role()`, `inactif()`), `SiteFactory` (état `conakry()`), `ServiceFactory`, `CodeAnalytiqueFactory`, `BonCaisseFactory` (états `statut()`, `approuve()`, `provisoire()`) |
| Utilitaire | `TestCase::utilisateurAvecRoles(['caissier'])` : utilisateur ayant exactement ces rôles |
| Tests JavaScript | **Vitest** (`npm test`, `vitest.config.js`, dossier `tests/js/`) |
| Cas partagés | `tests/fixtures/montants_lettres.json`, lu par les tests PHP et JS |

### 20.2 Formatage commun (SFD §1.4)

- `resources/js/utils/format.js` et `App\Support\Format` appliquent les mêmes règles :
  - montant `1 500 000 GNF` (espace insécable) ;
  - date `05/10/2026` ;
  - date-heure `05/10/2026 14:30` ;
  - durée `2 h 15 min` sous 24 h, `3 j 4 h` au-delà.
- Côté serveur, ces règles s'appliquent :
  - aux accesseurs `*_format` (bon, site, mouvement, rapport, ventilation, ordre de mission) ;
  - aux PDF et à l'e-mail du rapport ;
  - aux notifications ;
  - aux délais de validation. Leur ancien calcul ignorait les mois : un délai de 35 jours s'affichait « 4j ».
- Côté écran, `utils/nombreEnLettres.js` réexporte `formaterNombre` et `formaterMontant` depuis `format.js`. La fiche du bon, la liste et les mouvements de caisse utilisent ces fonctions communes.
- **SMS** : `NimbaSmsService` remplace les espaces insécables par des espaces simples (`Format::pourSms`). Sinon le SMS passe en Unicode : 70 caractères au lieu de 160, donc un coût doublé.
- Fuseau de l'application : `Africa/Conakry` (`config/app.php`). C'est UTC+0 sans heure d'été : aucune date stockée ne change.

### 20.3 Montant en lettres (ANO-01, RG-BC-08)

- `App\Support\MontantEnLettres` fait foi ; `utils/nombreEnLettres.js` suit le même algorithme pour l'aperçu à la frappe.
- Règles :
  - « deux cent mille », mais « deux cents » et « deux cents millions » ;
  - « quatre-vingt mille », mais « quatre-vingts » ;
  - minuscules ;
  - « un million **de** francs guinéens » quand le montant se termine par million(s) (Q15) ;
  - « un franc guinéen » au singulier.
- **Recalcul serveur à chaque enregistrement** (événement `saving` de `BonCaisse`) : la valeur envoyée par l'écran est ignorée.
- La migration `2026_10_07_000001` recalcule les montants en lettres déjà enregistrés. Ceux-ci, faux, étaient imprimés sur le PDF officiel.

### 20.4 Catalogue des messages (SFD §5.6, §5.7)

- `lang/fr.json` contient les messages MSG-BC-001 à 042, au texte exact de la SFD. Les variables `[plafond]` deviennent `:plafond`.
- Écran : `utils/messages.js` fournit :
  - `msg('MSG-BC-012', { plafond, caisse })`, qui formate les nombres et la `date` ;
  - `msgErreur(reponse)` pour une erreur du serveur.
- Serveur : `App\Exceptions\ErreurMetier` (code, règle, clé de message, valeurs, champ).
  - En **API**, elle renvoie une 422 au format §5.7 : `{ code, regle, message_cle, valeurs, message }`.
  - À l'**écran**, elle provoque un retour à la page, avec le message sous le champ.
  - Elle n'est pas journalisée comme une erreur.

### 20.5 Statuts

- Le statut **`ANNULE`** est ajouté à l'enum (migration `2026_10_07_000002`, RG-BC-31) et à `BonCaisse::STATUTS`, libellé « Annulé ».
- `utils/statuts.js` et le composant `BadgeStatut` appliquent les couleurs de la SFD :

  | Statut | Couleur |
  |--------|---------|
  | Brouillon | gris |
  | En validation · étape | bleu |
  | Approuvé | vert clair |
  | Payé | vert |
  | Rejeté | rouge |
  | En attente de régularisation | orange |
  | Annulé | gris barré |

- Les trois copies de `badgeVariantParStatut` (Index, Show, Dashboard) sont supprimées. `Validations/Show` utilise aussi `BadgeStatut`.

### 20.6 Journal d'audit (SFD §1.2)

- Toute modification d'un champ métier d'un bon écrit une ligne « Modification du bon » avec, pour chaque champ, l'ancienne et la nouvelle valeur (`metadata.changements`).
  - Mécanisme : événement `updated` de `BonCaisse` → `HistoriqueAction::enregistrerChangements()`.
  - Les champs suivis sont ceux de `HistoriqueAction::LIBELLES_CHAMPS`. Le statut, les dates du cycle de vie et le numéro restent tracés par leur propre action (soumission, validation, paiement…).
- `BonCaisse::modifierAvecJournal()` permet de préciser l'action inscrite au journal. La correction du code analytique par le CDG l'utilise : une seule ligne, avec avant → après.
- L'onglet Historique de la fiche affiche ces changements.

### 20.7 Plusieurs rôles par utilisateur

- **Table `roles_utilisateurs`** (`user_id`, `role`), créée par la migration `2026_10_07_000003`.
  - Reprise des comptes existants : rôle actuel + `demandeur`. Aujourd'hui tout le monde peut créer un bon, donc rien ne change pour les utilisateurs.
  - `users.role` reste le **rôle principal**.
- **Synchronisation automatique** :
  - un nouveau compte reçoit son rôle et `demandeur` ;
  - un changement de rôle principal remplace l'ancien rôle et conserve `demandeur`.
- **Méthodes du modèle `User`** :
  - `listeRoles()` ;
  - `aLeRole(string|array)` ;
  - `peutInitierBon()` ;
  - `definirRoles()` / `ajouterRoles()` ;
  - `peutValider()`, `peutPayer()`, `peutEffectuer()`, `estAdministrateur()`, `rolesValidationEffectifs()`, qui lisent désormais tous les rôles.
- **Middleware `role:`** : il accepte un rôle secondaire.
- **Propriétés partagées** : `auth.user.roles`, `auth.user.roles_effectifs`, `auth.user.peut_initier_bon`.
- **Création d'un bon réservée au rôle demandeur** (US-BC-01 scénario 2, TC-BC-032) :
  - 403 sur `create` et `store`, y compris pour un appel direct ;
  - boutons « Nouveau Bon » masqués.

> **Reste pour M02 (administration des rôles)** : environ 45 contrôles lisent encore le rôle principal (`->role ===`, `where('role', …)`, `parRole()`), dans les contrôleurs, `NotificationService` et les commandes. Ils restent justes tant qu'aucun rôle secondaire autre que `demandeur` n'est attribué. Il faudra les convertir avec l'écran d'attribution des rôles. Pour les retrouver : `grep -rnE "->role ===|where\('role'|parRole\(" app`.

### 20.8 Déploiement

```bash
php artisan migrate        # 2026_10_07_000001 (montants en lettres), 000002 (statut ANNULE), 000003 (rôles)
php artisan optimize:clear
npm run build
```

### 20.9 Tests

- JavaScript : `npm test` (43 tests).
- PHP : `php artisan test` (MySQL `neemba_test` ; Laragon doit être démarré).
  - Nouveaux tests unitaires : `MontantEnLettresTest`, `FormatTest`.
  - Nouveaux tests fonctionnels : `tests/Feature/Socle/` (messages, rôles, montant en lettres, statut Annulé, journal).

### 20.10 Décisions du 07/10/2026

Les arbitrages ont été délégués au développeur. Le registre, avec la possibilité de revenir sur chaque décision, est `docs/questions.md`. Deux décisions changent déjà le comportement :

- **Q14** : il n'y a plus d'archivage automatique. Un BD payé reste « Payé » et un BP régularisé reste « Régularisé », comme dans la SFD. L'archivage est une action manuelle (DAF, DP, caissier, administrateur). Les bons déjà archivés le restent.
- **Q11** : la demandeuse pilote s'appelle « Souadou BARRY ». Le seeder est corrigé ; en production, il faut corriger le prénom dans Utilisateurs.

---

## 21. Version v21 — Lot 2 : caisses et registre de caisse (7 Octobre 2026)

L'argent n'est plus porté par les sites mais par des **caisses**, et chaque mouvement d'argent est inscrit à un **registre**. Branche `lot2-caisses`, partie de `lot1-socle`.

### 21.1 Modèle

| Table | Rôle |
|-------|------|
| `caisses` | `code`, `libelle`, `site_id`, `type` (`especes` / `orange_money`), `mode` (`standard` / `avance_fixe`), `montant_avance`, `solde`, `plafond_retrait`, `seuil_alerte`, `actif` |
| `ecritures_caisse` | Registre : `caisse_id`, `date_ecriture`, `sens` (entrée / sortie), `nature` (solde initial, paiement d'un bon, approvisionnement, retrait, ajustement, correction de solde), `montant`, `solde_avant`, `solde_apres`, liens vers le bon, le mouvement et l'utilisateur |
| `bons_caisse.caisse_id` | Caisse qui a payé le bon |
| `mouvements_caisse.caisse_id` | Caisse concernée par le mouvement |

- **Écritures** : le solde ne se modifie jamais directement. `Caisse::crediter()` et `Caisse::debiter()` verrouillent la caisse, mettent le solde à jour et écrivent la ligne du registre, le tout dans une transaction.
- **Solde à une date** : `Caisse::soldeAu($instant)` le lit dans le registre.
- **Site** : `solde_especes`, `solde_om` et `solde_caisse` restent disponibles, mais calculés sur les caisses actives du site. Les colonnes correspondantes de `sites` sont supprimées.

### 21.2 Reprise des données (migration `2026_10_07_000004`)

- **Une caisse espèces par site** (`CKY-ESP` pour Conakry, sinon les 3 premières lettres du site), libellée « Caisse principale <site> » :
  - solde : l'ancien solde espèces du site ;
  - seuil : l'ancien seuil d'alerte ;
  - plafond de retrait : Conakry 20 000 000, Boké 1 000 000 (Q2), aucun pour les autres sites.
- **`CKY-OM` « Caisse Orange Money Conakry »** : la somme des anciens soldes OM. C'est la caisse OM unique (RG-BC-12).
- **`CKY-ATL` « Caisse Atelier »** : avance fixe de 15 000 000, inactive et à 0 jusqu'à sa mise en service (Q3, M07).
- Une écriture « solde initial » par caisse non vide.
- Les bons payés et les mouvements existants sont rattachés à leur caisse.
- Les modifications de site en attente de double validation sont annulées, car elles portaient sur des champs supprimés.
- **Vérifié sur une copie de la base de développement** : 38 500 000 GNF avant et après, 4 bons payés rattachés. Le retour arrière remet les soldes exacts sur les sites.

### 21.3 Flux d'argent

| Flux | Avant | Après |
|------|-------|-------|
| Paiement d'un bon | Débit du solde du site | **Caisse payeuse** (RG-BC-12) : espèces → caisse espèces du site, ou à défaut la caisse principale de Conakry ; Orange Money → caisse OM ; virement / autre → hors caisse. **Plafond de retrait contrôlé** (RG-BC-11, message MSG-BC-012). Débit inscrit au registre, `bons_caisse.caisse_id` renseigné |
| Mouvement de caisse | Choix d'un site + Espèces / OM, puis incrément du site | Choix d'une **caisse** (un caissier ne voit que celles de son site). À la validation (DAF / DP), écriture au registre |
| Alerte de seuil | Par site, sur le total | **Par caisse** (ANO-10). Notification aux DAF / DP, SMS aux caissiers du site. Commande `caisse:verifier-seuils` : au plus une alerte par jour et par caisse |
| Rapport journalier | Ouverture = dernier rapport enregistré | **Lu dans le registre** (Q13) : ouverture exacte, entrées / sorties du jour, ventilation espèces / OM. Les jours antérieurs à l'ouverture du registre gardent l'ancien calcul |
| Rapport manuel (arrêté) | Entrées / sorties saisies | Totaux **repris du registre** (lecture seule). Le caissier saisit le comptage : billetage et solde OM lu |

### 21.4 Écrans

- **Tableau de bord** et **Mouvements de caisse** : soldes **par caisse** (ANO-09), avec seuil d'alerte et plafond de retrait.
- **Fiche du bon** : panneau « Caisses payeuses » (caisse espèces et caisse OM, retrait espèces maximal).
- **Paramétrage** :
  - **Sites** : sans soldes. Un nouveau site reçoit sa caisse principale.
  - **Caisses** (nouvel onglet) : une caisse est créée avec un solde de 0. Code et libellé se modifient tout de suite.
  - **Double validation** (`ModificationEnAttente` de type `caisse`) : plafond de retrait, seuil, avance et correction de solde. Une correction approuvée inscrit l'écart au registre (`correction_solde`) ; le solde n'est jamais écrasé.

### 21.5 Tests

`tests/Feature/Lot2/CaissesTest.php` couvre :
- le registre et le solde à une date ;
- la caisse payeuse et son repli sur Conakry ;
- le paiement en espèces et le plafond de retrait ;
- les mouvements et la restriction par site ;
- les alertes ;
- le rapport lu dans le registre ;
- la création d'un site, la double validation et la correction de solde ;
- les soldes du tableau de bord et l'ouverture des écrans.

Au total : **100 tests PHP** et 43 tests JavaScript passent.

### 21.6 Déploiement

```bash
php artisan migrate        # crée caisses + registre, reprend les soldes, supprime les soldes de sites
php artisan optimize:clear
```

Après la mise en production, le DAF doit vérifier dans Paramétrage › Caisses les soldes repris et les plafonds de retrait des sites autres que Conakry et Boké.

### 21.7 Reste à faire (lots suivants)

- **M03 (lot 3)** : afficher la caisse payeuse et son plafond pendant la saisie du bon (`Caisse::payeusePour`, `depassePlafondRetrait`).
- **M07** :
  - mettre la caisse Atelier en service, avec son réapprovisionnement à avance fixe ;
  - transferts entre caisses.
- **M08** : arrêté **par caisse** (aujourd'hui, le rapport manuel agrège les caisses du site).
- **M10** : afficher la ventilation espèces / OM dans l'e-mail, le PDF et l'Excel.

---

## 22. Version v22 — Lot 3 : assistant de saisie du bon, M03-A (7 Octobre 2026)

Le formulaire de création et la page de modification sont remplacés par **un seul assistant en 5 étapes**, conforme à la SFD v1.3 (E-03.2 à E-03.7). Il sert à la création, à la reprise d'un brouillon et à la correction après rejet. Branche `lot3-assistant`, partie de `lot2-caisses`.

US couvertes : US-BC-01 à 08, 10, 11, 12, 14 et 15. La lecture des tickets (US-BC-09) et la délégation d'initiation (US-BC-13) viennent aux lots 4 et 5.

### 22.1 Parcours

| Action | Comportement |
|--------|--------------|
| Ouvrir « Nouveau bon » | Aucun bon n'est créé (RG-BC-01, TC-BC-001). Valeurs par défaut (RG-BC-02) : site et service du demandeur, urgence Normale, bénéficiaire = le demandeur, espèces |
| Premier « Suivant » ou « Brouillon » | Création du brouillon, **sans numéro** (ANO-03). L'adresse devient `/bons-caisse/{id}/edit` : un rechargement le rouvre |
| « Suivant » | Le serveur contrôle l'étape (formats + champs obligatoires, messages exacts de la SFD) puis enregistre. En cas d'erreur, l'étape passe en rouge et le curseur va sur le premier champ en erreur |
| « Précédent », clic sur une étape visitée | Aucun contrôle ; la saisie est enregistrée |
| « Brouillon » | Formats seulement (RG-BC-25), message MSG-BC-030 pendant 3 secondes |
| Étape 5 | Les **12 contrôles** du serveur (§5.4.7), avec un lien « Corriger » vers le champ, et le circuit de validation prévu avec le nom des valideurs |
| « Soumettre » | Actif seulement sans contrôle rouge. Confirmation MSG-BC-031, puis le serveur refait tous les contrôles. Le numéro est attribué à ce moment, et l'écran affiche la fiche avec MSG-BC-032 |
| Quitter avec des modifications | Fenêtre MSG-BC-035 : Enregistrer / Quitter sans enregistrer / Annuler |
| Reprise d'un brouillon | L'assistant s'ouvre sur la première étape incomplète (TC-BC-024) |

### 22.2 Modèle (migration `2026_10_08_000001_saisie_bon_m03`)

- **`bons_caisse`** :
  - `numero` facultatif : il est attribué à la soumission ;
  - les champs de saisie deviennent facultatifs, pour qu'un brouillon puisse être incomplet ;
  - nouvelles colonnes : `version`, `initiateur_id`, `beneficiaire_id` (bénéficiaire employé), `vehicule`, `references_or` (JSON), `lie_mission`, `odm_id`, `date_retour_mission`, `motif_annulation`, `date_annulation`, `cle_soumission`.
- **Mode de paiement « chèque »** ajouté.
- **Types de pièce** : « Demande d'achat » et « Bon de travail (OR) » ajoutés. Le type est vide tant que l'utilisateur ne l'a pas choisi.
- **`categories_depense`** : les 10 catégories du §5.4.3, paramétrables. Chacune indique si elle affiche le véhicule (obligatoire ou non) et l'OR, et si elle est proposée dans l'assistant (Mission ne l'est pas : elle passera par les ordres de mission, M12).
  - Les anciennes catégories sont converties (décision Q1).
  - La constante `BonCaisse::CATEGORIES_DEPENSE` est remplacée par `CategorieDepense::libelles()`, mis en cache.
- **`compteurs_numerotation`** : un compteur par année, verrouillé à chaque attribution. Il démarre au plus grand numéro existant : aucun numéro n'est réutilisé, et un bon annulé garde le sien.
  - Les brouillons existants perdent leur numéro (décision Q9).
- **`validations.version`** : quand un bon est resoumis, les étapes déjà traitées restent dans l'historique.
- **Paramètre `delai_abandon_brouillon`** : 30 jours (RG-BC-26).
- **Reprise** :
  - `initiateur_id` reçoit la valeur de `demandeur_id` ;
  - `beneficiaire_id` est renseigné quand le nom correspond exactement à un utilisateur.
- **Vérifié sur une copie de la base de développement**, aller et retour :
  - 10 bons ;
  - catégories converties puis restaurées ;
  - compteur 2026 initialisé à 10.

### 22.3 Règles (`app/Services/BonCaisse/`)

| Classe | Rôle |
|--------|------|
| `ReglesSaisie` | Champs de chaque étape, règles complètes et règles de format, messages exacts de la SFD. Code analytique rattaché au service (RG-BC-04), plafond de retrait (RG-BC-11), téléphone guinéen stocké `+224XXXXXXXXX`, pièces (RG-BC-15, RG-BC-18), première étape incomplète |
| `EnregistrementBon` | Création du brouillon avec les valeurs par défaut, puis enregistrement. Un bénéficiaire employé reprend le nom et le téléphone du référentiel (RG-BC-06). Revenir à « Normale » efface le motif et la justification d'urgence. Le véhicule est mis en majuscules. La caisse payeuse est recalculée (RG-BC-12) |
| `ControlesBon` | Les 12 contrôles de l'étape 5. Chacun renvoie `{numero, code, niveau, message, regle, message_cle, valeurs, etape, champ}` |
| `CircuitPrevisionnel` | Niveaux de validation prévus et nom des valideurs disponibles. Un niveau sans valideur déclenche MSG-BC-042 |
| `NumeroteurBon` | Prochain numéro `BC-AAAA-NNNN`, tiré du compteur verrouillé |
| `SoumettreBon` | Une transaction qui fait, dans l'ordre : verrou sur le bon ; contrôles refaits ; attribution du numéro ; circuit ; journal. Les notifications partent après la validation de la transaction. **Clé d'idempotence** : un double clic ne consomme qu'un numéro (TC-BC-025). Un contrôle en échec renvoie 422 sans consommer de numéro (TC-BC-026). Un bon rejeté est resoumis avec le même numéro, `version` + 1, et le circuit repart du chef de service |
| `AnnulerBon` | Brouillon ou rejeté seulement, avec un motif d'au moins 10 caractères ; sinon 409 MSG-BC-034 (RG-BC-31, TC-BC-030) |
| `BonCaissePolicy` | Créer : rôle demandeur (TC-BC-032). Modifier : demandeur ou initiateur, bon en brouillon ou rejeté |

D'autres règles changent hors de ces classes :

- **Seuil du Directeur Pays** : il est franchi strictement au-delà du seuil (RG-BC-09).
- **Bon très urgent** : les délais de validation sont divisés par 2 (RG-BC-05, `Validation::slaHeures()`).
- **Date limite de régularisation** : elle tient compte de la date de retour de mission (RG-BC-14).

### 22.4 API JSON `/api/v1` (authentification par session)

| Route | Rôle |
|-------|------|
| `POST /bons` | Création du brouillon. `etape` : vérifier aussi cette étape |
| `PATCH /bons/{id}` | Enregistrement d'un brouillon ou d'un bon rejeté |
| `GET /bons/{id}/controles` | Les 12 contrôles, si la soumission est possible, et le circuit |
| `POST /bons/{id}/soumettre` | En-tête `Idempotency-Key` ; réponse au format §5.7 |
| `POST /bons/{id}/annuler` | Motif obligatoire |
| `POST /bons/{id}/pieces` | Un fichier, envoyé dès son dépôt. PDF, JPG ou PNG, contrôlés sur le contenu ; 10 Mo, 20 fichiers et 50 Mo par bon ; empreinte SHA-256 calculée à l'envoi |
| `PATCH` et `DELETE /bons/{id}/pieces/{piece}` | Type de la pièce ; suppression réelle avant soumission seulement |
| `GET /referentiels/beneficiaires?q=` | Employés actifs, dès 2 caractères : « NOM Prénom — matricule — service » |
| `GET /referentiels/caisse-payeuse?site=&mode=` | Caisse payeuse et son plafond de retrait |

Un refus métier renvoie `{code, regle, message_cle, valeurs, message}`, plus `champ` quand il vise un champ. À la soumission, `regle` est celle du contrôle en échec, comme dans l'exemple du §5.7 (RG-BC-11 pour le plafond).

### 22.5 Écrans

- **`Pages/BonsCaisse/Assistant.jsx`** et **`Components/Assistant/`** : un composant par étape, la barre d'étapes et le résumé.
  - **Barre d'étapes** : jaune, vert ou rouge selon l'état. Sur mobile, réduite aux icônes.
  - **Résumé** : mis à jour en direct, replié en bas d'écran sur mobile.
  - **Étape 3** :
    - ligne d'information caisse payeuse, plafond et seuil DP (ANO-05) ;
    - « Espèces » grisé au-delà du plafond, avec l'info-bulle MSG-BC-012 ;
    - montant en lettres en lecture seule ;
    - bandeau MSG-BC-020 au-delà du seuil DP ;
    - véhicule et OR affichés selon la catégorie ;
    - OR sous forme d'étiquettes ;
    - mission pour un BP.
  - **Étape 4** :
    - glisser-déposer, ou appareil photo sur mobile ;
    - barre de progression ;
    - type par ligne : « Ticket carburant » proposé pour la catégorie Carburant.
- **`utils/assistant.js`** : règles d'affichage. Le serveur reste juge.
- **Supprimés** : `Create.jsx`, `Edit.jsx`, `BonCaisseController@store/update/soumettre` et la route web de soumission. La ventilation multi-codes n'est plus proposée à la saisie (décision Q16) ; le CDG peut toujours ventiler à la validation.
- **Fiche du bon** :
  - brouillon : « Reprendre » ;
  - bon rejeté : « Corriger et resoumettre » ;
  - dans les deux cas, « Annuler le bon » avec un motif ;
  - l'initiateur a les mêmes droits que le demandeur.
- **Liste** : « — » pour un brouillon, crayon pour reprendre un brouillon ou corriger un bon rejeté.
- **Tableau de bord** : « Brouillon » s'affiche à la place du numéro.

### 22.6 Tâche de nuit

`bons:annuler-brouillons-abandonnes`, planifiée à 02:00 (RG-BC-26) :

- elle annule un brouillon non modifié depuis `delai_abandon_brouillon` jours ;
- une pièce ajoutée récemment compte comme une modification ;
- le demandeur reçoit une notification in-app (type `annulation`).

### 22.7 Tests

- **`tests/Feature/M03/SaisieBonTest.php`** : 33 tests couvrant :
  - TC-BC-001 à 006, 008 à 015, 024 à 026, 030 et 032 ;
  - la numérotation sans retour arrière ;
  - la resoumission ;
  - l'annulation ;
  - la tâche de nuit.
- **`tests/js/assistant.test.js`** : règles d'affichage (plafond, seuil DP, catégories, OR, fichiers).
- **Total** : **133 tests PHP** et 56 tests JavaScript passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base, avec SMS et IA désactivés : 32 vérifications. On y passe successivement par :
  - la création ;
  - les erreurs de l'étape 1 ;
  - le plafond, puis le virement ;
  - le véhicule et l'OR ;
  - la fenêtre MSG-BC-035 ;
  - le dépôt d'une pièce ;
  - les contrôles ;
  - la soumission, qui attribue BC-2026-0011 ;
  - l'affichage mobile.

### 22.8 Déploiement

```bash
php artisan migrate        # numéro à la soumission, catégories de la SFD, compteur, colonnes M03
php artisan optimize:clear
npm run build
```

Le planificateur (`schedule:run`) doit tourner pour l'annulation des brouillons abandonnés.

### 22.9 Reste à faire

- **Lot 4** :
  - qualité réelle des pièces (RG-BC-16) ;
  - doublons à confirmer et justifier (RG-BC-19). Aujourd'hui, c'est un simple avertissement ;
  - panneau de lecture des tickets et contrôles des tickets (US-BC-09, RG-BC-20 à 23) ;
  - nouvelle version d'une pièce après soumission.
- **Lot 5** :
  - « Pour le compte de » : délégation d'initiation (US-BC-13, contrôle 12) ;
  - liste E-03.1 : filtres, colonne Âge, 20 lignes ;
  - fiche E-03.8 : bandeau de rejet, historique ancienne → nouvelle valeur ;
  - le chef de service est notifié.
- **M12** : choix de l'ordre de mission dans l'étape 3. Pour l'instant, seule la date de retour est demandée.

---

## 23. Version v23 — Lot 4 : pièces et lecture des tickets carburant, M03-B (7 Octobre 2026)

L'étape 4 de l'assistant contrôle maintenant la **qualité réelle** des pièces et les **pièces déjà utilisées**. Elle lit aussi les **tickets carburant**, avec validation par l'utilisateur. Branche `lot4-pieces`, partie de `lot3-assistant`.

US couvertes : US-BC-08 et US-BC-09 (RG-BC-15 à RG-BC-23, TC-BC-015 à TC-BC-023).

### 23.1 Qualité d'une pièce (RG-BC-16) — `App\Services\BonCaisse\QualitePiece`

| Pièce | Règle |
|-------|-------|
| PDF produit par un logiciel (police déclarée, ou texte extrait par `pdftotext`) | **Conforme**, sans contrôle de résolution (TC-BC-017) |
| Image, ou PDF scanné (plus grande image, mesurée par `pdfimages -list` ou lue dans le fichier) | Résolution effective = grand côté en pixels ÷ 11,69 pouces (A4). **Moins de 150 dpi : illisible** (bloquant, MSG-BC-018) ; 150 à 299 : qualité moyenne (avertissement) ; 300 et plus : conforme. TC-BC-016 : 1 100 px → 94 dpi ; 3 000 px → 257 dpi ; 4 000 px → 342 dpi |
| Résolution impossible à mesurer | Qualité non renseignée : aucun blocage (décision Q17) |

- La qualité est calculée **dès le dépôt** et enregistrée dans `pieces_jointes.qualite` et `dpi_detecte`. `qualite_ok` reste utilisé par l'archivage : il vaut faux seulement pour une pièce illisible.
- **Une pièce illisible ne compte pas comme justificatif d'un BD** (US-BC-08).

### 23.2 Pièce déjà utilisée (RG-BC-19)

- L'empreinte SHA-256 est calculée au dépôt, puis comparée aux pièces des **autres bons non annulés**, brouillons compris. La pièce garde le lien vers la première pièce identique (`doublon_de_id`).
- Sur la ligne, MSG-BC-019 avec le numéro et la date de l'autre bon. Le demandeur doit cocher « Je confirme que cette pièce concerne une autre dépense » (sinon MSG-APP-009) et justifier en 10 caractères au moins (MSG-BC-005).
- **Contrôle 6** : rouge tant que la pièce n'est pas confirmée ; orange une fois confirmée et justifiée.
- **Bandeau** « Pièce(s) déjà présentée(s) sur un autre bon », avec la justification du demandeur. Il est visible sur la fiche du bon et sur l'écran de validation (contrôle de gestion), via le composant `BandeauPiecesDejaUtilisees`.
- La migration repère les doublons déjà présents en base.

### 23.3 Lecture des tickets carburant (US-BC-09, RG-BC-20 à RG-BC-23)

| Élément | Comportement |
|---------|--------------|
| Démarrage | Dès qu'une pièce est de type « Ticket carburant » (au dépôt ou au changement de type). C'est le type proposé pour la catégorie Carburant |
| Lecteur | Interface `LecteurTicket`, choisie par `LECTEUR_TICKETS`. **`manuel` par défaut (décision Q8)** : aucune image ne quitte le serveur, et le panneau s'ouvre avec des champs vides et la mention « Lecture automatique indisponible ». **`local`** : Tesseract sur le serveur, puis repérage des champs (`LecteurLocal::analyser`) ; rien ne sort du serveur. Un lecteur « vision » viendra après OP-BC-5 |
| Asynchrone | `LireTicketJob` (file d'attente). L'écran interroge le serveur toutes les 2 secondes. Après **60 secondes**, la lecture est abandonnée au profit de la saisie manuelle |
| Panneau | Ticket agrandissable. Pour chaque champ : la valeur, la pastille de confiance (vert ≥ 85 %, orange 60–84 %, rouge < 60 %) et une case « Vérifié ». Corriger un champ coche sa case. « Valider la lecture » n'est actif qu'une fois les 6 cases cochées. Le prix au litre est calculé pendant la saisie |
| Enregistrement (RG-BC-21) | Table `lectures_tickets` : valeurs lues, confiances, valeurs validées, **champs corrigés** (pour mesurer la qualité de lecture, TC-BC-019), auteur et date de validation |
| Badges | « Lecture en cours… », « À vérifier », « Lecture validée » |

Règles du panneau :

- station : 2 à 80 caractères ;
- date : obligatoire et pas dans le futur (MSG-APP-006) ;
- litres : supérieurs à 0 et au plus 500 (MSG-APP-007) ;
- montant : entier supérieur à 0 ;
- montant en lettres : lu, en lecture seule.

**Contrôles d'un ticket validé (RG-BC-22, en avertissement)** :

| Contrôle | Message |
|----------|---------|
| Prix au litre à plus de 10 % du paramètre `prix_litre_reference` (12 000 GNF/L, groupe « Carburant » du paramétrage) | MSG-BC-022 (TC-BC-020 : 597 000 / 49,75 L = 12 000, rien ; TC-BC-021 : 600 000 / 40 L = 15 000, avertissement) |
| Montant en lettres relu (`MontantEnLettres::lire`) différent du montant en chiffres | MSG-BC-023 |
| Immatriculation lue différente du véhicule du bon | MSG-BC-026 |
| Date du ticket plus d'un jour après le retour de mission (le départ viendra avec M12) | MSG-BC-025 |

**Contrôle 7 de l'étape 5** :

- **rouge** si une lecture n'est pas validée (MSG-APP-005, TC-BC-023 : Soumettre grisé) ;
- **orange** si le total des tickets s'écarte de plus de 2 % du montant du bon (MSG-BC-024, TC-BC-022 : 2 376 000 pour 2 500 000, écart 124 000), ou si un ticket porte un avertissement ;
- vert sinon.

### 23.4 Nouvelle version d'une pièce (E-03.6)

- Une pièce **jamais soumise** se supprime : bon en brouillon, ou pièce ajoutée à un bon rejeté depuis sa dernière soumission.
- Une pièce **déjà soumise** ne se supprime pas (MSG-APP-004) : on la **remplace**.
  - La nouvelle pièce reprend le type et passe à `version` + 1.
  - L'ancienne reste dans l'historique (`remplacee_par_id`).
  - Seules les pièces en vigueur (`BonCaisse::piecesActives()`) comptent pour les règles, les contrôles et les limites (20 fichiers, 50 Mo).

### 23.5 API (`/api/v1/bons/{id}/pieces/...`)

| Route | Rôle |
|-------|------|
| `POST /pieces` | Dépôt. La réponse donne qualité, dpi, doublon, lecture, `supprimable` et une adresse **relative** (indépendante d'`APP_URL`) |
| `POST /pieces/{piece}/remplacer` | Nouvelle version |
| `PATCH /pieces/{piece}` | Type de la pièce. « Ticket carburant » lance la lecture |
| `PATCH /pieces/{piece}/doublon` | Confirmation et justification |
| `GET` et `POST /pieces/{piece}/lecture` | Suivi de la lecture ; « Valider la lecture » |
| `DELETE /pieces/{piece}` | Pièce jamais soumise seulement |

### 23.6 Messages ajoutés au catalogue (hors SFD)

La SFD ne prévoit pas de texte pour ces cas : MSG-APP-005 (lecture à vérifier), 006 (date future), 007 (litres), 008 (cases à cocher), 009 (confirmation d'un doublon), 010 (station). Ils sont à faire valider avec le catalogue.

### 23.7 Tests

- **`tests/Feature/M03/PiecesEtTicketsTest.php`** (15 tests) :
  - TC-BC-016 à 018 et TC-BC-020 à 023 ;
  - RG-BC-20 (abandon au-delà de 60 s) et RG-BC-21 (corrections conservées, avec un lecteur d'essai) ;
  - remplacement ;
  - relecture des montants en lettres sur tous les cas de `tests/fixtures/montants_lettres.json` ;
  - lecteur local.
- **`tests/js/assistant.test.js`** : confiance, prix au litre, validation possible.
- **Total** : **148 tests PHP** et 59 tests JavaScript passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base, avec SMS et IA coupés : 18 vérifications. On y passe successivement par :
  - une photo illisible, supprimée ensuite ;
  - un ticket saisi puis validé ;
  - une facture déjà présente sur BC-2026-0003, confirmée et justifiée ;
  - les contrôles 6 et 7.
- **Défaut trouvé et corrigé** pendant ce parcours : les aperçus d'images ne s'affichaient pas quand `APP_URL` ne correspondait pas à l'adresse du serveur. Les adresses des pièces sont désormais relatives.
- **Migration vérifiée sur une copie de la base**, aller et retour, et sur des pièces fabriquées : copie liée à l'original ; bon annulé ignoré ; pièce de faible qualité marquée illisible.

### 23.8 Déploiement

```bash
php artisan migrate        # qualité, doublons, versions, lectures_tickets, prix_litre_reference
php artisan optimize:clear
npm run build
```

- Pour une lecture locale : `LECTEUR_TICKETS=local`, avec `apt install tesseract-ocr tesseract-ocr-fra poppler-utils` et un worker de file (`queue:work` sous supervisor).
- Sans worker, une lecture locale tombe en saisie manuelle au bout de 60 secondes. Le lecteur `manuel` n'a pas besoin de worker.
- `poppler-utils` (`pdftotext`, `pdfimages`) améliore la mesure des PDF ; sans lui, la lecture du fichier sert de repli.

### 23.9 Reste à faire

- **Lot 5** :
  - délégation d'initiation (US-BC-13) ;
  - liste E-03.1 et fiche E-03.8 : onglet Pièces avec miniatures, qualité, lecture et versions.
- **OP-BC-5** : choisir le lecteur « vision » des tickets manuscrits, avec l'accord écrit de l'IT Neemba. Puis mesurer le taux de champs corrigés sur les 5 tickets de septembre 2026 (TC-BC-019).
- **M12** : dates de départ et de retour de la mission pour MSG-BC-025.

---

## 24. Version v24 — Référentiels de paramétrage de Neemba (7 Octobre 2026)

Les données réelles du classeur « Référentiels de paramétrage — tous sites » (points 11 à 14, version du 06/10/2026, après le comité) entrent dans l'application par un **import réexécutable**. Branche `lot4-referentiels`, partie de `lot4-pieces`.

### 24.1 Le classeur

Le classeur est un modèle pré-rempli :
- les **cellules grises** viennent d'Addvalis ;
- les **cellules jaunes** sont à compléter par Neemba, avant le 09/10 pour les onglets 1 à 3 et avant le 13/10 pour les onglets 4 et 5 ;
- beaucoup de valeurs sont encore marquées « (à confirmer) ».

D'où le choix : un import qu'on relance à chaque nouvelle version du classeur, plutôt qu'une saisie unique.

| Onglet | Contenu | Où il va dans l'application |
|--------|---------|------------------------------|
| 1-Utilisateurs | Nom, prénom, matricule, e-mail, téléphone, entité, site, service, fonction, statut cadre, N+1, rôles, actif | `users` (+ `entite`, `statut_cadre`, `responsable_id`), rôles |
| 2-Codes analytiques | Radical, statut (liste de référence ou hors liste), code service du CDG, libellé, usage en caisse 2024, commentaires | `codes_analytiques` (+ `code_service_comptable`, `valide_cdg`) |
| 3-Services | Code comptable, équivalent sur la fiche ODM, chef de service à Conakry | `services` (+ `equivalent_odm`) |
| 4-Caisses | Plafond de caisse, seuil de réapprovisionnement, plafond de retrait, gestionnaire, suppléant, réapprovisionnement, destinataires du rapport, encaissements clients | `caisses` (+ `plafond_caisse`, `gestionnaire_id`, `suppleant_id`, `encaissements_clients`, `reapprovisionnement`, `destinataires_rapport`) |
| 5-Valideurs | CDG, Finance, Directeur Pays, Trésorerie, RH, chefs de service par site et par service | Rôles |

### 24.2 L'import — `php artisan referentiels:importer <classeur.xlsx>`

- **Simulation par défaut**. Tout se fait dans une transaction annulée à la fin : le compte rendu est identique à celui de l'import réel. `--appliquer` enregistre.
- **Compte rendu** en Markdown, à renvoyer à Neemba :
  - ce qui est appliqué ;
  - ce qui attend la double validation ;
  - les actions de l'administrateur ;
  - les anomalies ;
  - les commentaires et points à trancher ;
  - les valeurs « à confirmer », groupées par onglet avec le responsable et l'échéance du « Mode d'emploi ».
- **Lecture souple** : la ligne d'en-tête est repérée, et les colonnes sont retrouvées par le début de leur libellé, sans tenir compte des accents ni de la casse. Le classeur peut gagner des lignes ou des colonnes sans casser l'import.
- **Prudence**. L'import :
  - n'applique pas une valeur marquée « (à confirmer) » : il la liste ;
  - ne change rien pour une cellule vide ;
  - ne supprime rien ;
  - ne crée pas les codes hors liste de référence (la décision revient au CDG) ;
  - n'importe pas une caisse dont le site ou l'entité est à confirmer (Simandou / SIMFER, Neemba Mining).
- **Double validation**. Les plafonds, seuils et avances de caisse donnent lieu à des demandes en attente (Paramétrage › Modifications en attente), avec `--auteur` comme demandeur. Une relance ne double pas une demande déjà en attente.
  - `--sans-double-validation` : application directe, pour une installation initiale. Cette option est **refusée en production**.
- **Personnes**. Un compte est retrouvé par son e-mail, puis par son matricule, puis par son nom exact. À défaut, un nom proche est accepté : même nom de famille, prénom à 2 lettres près, une seule possibilité (« Saoudou » → « Souadou », décision Q11).
  - Un compte nouveau reçoit ses rôles, sans mot de passe connu : la personne le définit par « Mot de passe oublié ».
  - Sur un compte existant, un **rôle à privilèges** n'est pas ajouté par l'import. C'est une action de l'administrateur à l'écran, comme tout changement de rôle.
  - Une personne sans e-mail n'a pas de compte : c'est une anomalie, l'e-mail étant indispensable pour se connecter.
- **Rôles du classeur** :
  - « Finance (visa DAF) » → DAF ;
  - « Finance (visa) » → **DAF adjoint** ou **Chef comptable**, selon la fonction ;
  - « CDG » → contrôle de gestion ;
  - « Trésorerie » → **trésorerie** ;
  - « RH » → **rh** ;
  - « Administrateur technique » → administrateur.
  - Les rôles en gras sont des **rôles déclarés** (`User::ROLES_DECLARES`, décision Q5) : ils sont attribués dès maintenant, mais leurs droits arriveront avec leur module (Finance élargie M04, Trésorerie M07-M08, RH M09).
  - Un **chef de service** se désigne dans l'onglet 5, par site et par service, et seulement si le compte est rattaché à ce site et ce service.
  - « Caissier — caisse Atelier » : la personne devient **gestionnaire** de la caisse, sans le rôle caissier. Ce rôle ouvre aujourd'hui la caisse principale du site (décision Q19).
- **Sites** : les libellés du classeur sont rattachés par le code site (« CORICA » = Kouroussa, code 49 ; « Mandiana (Siguiri Technique) », code 39). Les noms des sites de l'application ne changent pas.

### 24.3 Résultat sur la version v01 (06/10/2026)

**Appliqué** :
- 12 comptes créés : BOIRO Saliou (CDG), BAH Mamadou Alpha (chef comptable), CAMARA Mariamagbè (trésorerie), TOUNKARA Raby, BANGOURA Thomas, DIAKITE Baba (administrateur technique)… ;
- BARRY « Saoudou » devient **Souadou**, avec son adresse de connexion `souadou.barry@neemba.com` ;
- entité, fonction et N+1 renseignés ;
- code service du CDG sur les 22 codes analytiques de référence ;
- libellés des caisses repris du classeur (« Caisse Boké », « Caisse CORICA », « Caisse Siguiri Location »…) ;
- caisse principale Conakry : encaissements clients, réapprovisionnement par virement bancaire, 4 destinataires du rapport ;
- caisse Atelier : gestionnaire TOUNKARA Raby.

**Double validation** (21 demandes) :

| Caisse | Plafond de caisse | Seuil | Plafond de retrait |
|--------|-------------------|-------|--------------------|
| Caisse principale Conakry | 250 M | 50 M | 20 M (inchangé) |
| Caisse Orange Money | 500 M | 100 M | sans plafond |
| Caisse Atelier | 15 M | 3 M | 1 M |
| Caisse Sangarédi | 100 M | 20 M | **4 M (à confirmer)** |
| Caisse Boké | 20 M | 5 M | 1 M (inchangé) |
| Siguiri Location, Siguiri Technique, CORICA | 5 M | 1 M | 1 M |

Cela tranche la décision Q2.

**Anomalies** : 5 personnes sans e-mail n'ont pas de compte (CISS Mor, DAF adjoint ; KEITA Abdoulaye ; SYLLA Mama Aissata ; BAH Thierno Sounounou ; DIALLO Amadou Oury). CISS Mor manque donc aussi au visa Finance et aux destinataires du rapport.

**Reste à confirmer** (25 valeurs) : équivalents ODM des services, chef d'atelier, fonctions, services de Souadou BARRY et d'Astou CAMARA, rôles de Kadiatou SOUMAH et d'Astou CAMARA, origine du réapprovisionnement de l'Atelier, etc.

**Points à trancher par le CDG** :
- codes imputés à un autre service que leur référence (MACZZZ, SMIZZZ, MKTZZZ, LABZZZ) ;
- codes sans usage (CGEZZZ, DEPZZZ) ;
- MMICBS (Neemba Mining) ;
- codes hors liste (ZZZZZZ, CIDZZZ, KAMZZZ, LOCOPE) ;
- codes de l'Atelier.

**Comptes absents du classeur** : GOMIS Thierry, BARRY Maïmouna (CDG du pilote), DIALLO Thierno. Ils sont à ajouter au classeur ou à désactiver.

Compte rendu complet : `docs/referentiels/Compte_rendu_import_referentiels_v01.md`.

**Base de développement** : import appliqué avec `--sans-double-validation`. Une relance de la simulation n'applique plus rien (import idempotent).

### 24.4 Écrans

- **Paramétrage › Caisses** :
  - colonnes « Plafond de caisse » et « Gestionnaire » ;
  - plafond de caisse en double validation, comme le plafond de retrait et le seuil ;
  - gestionnaire, suppléant, réapprovisionnement et encaissements clients modifiables tout de suite ;
  - destinataires du rapport affichés.
- **Paramétrage › Services** : équivalent sur la fiche ODM.
- **Paramétrage › Codes analytiques** :
  - le « Code service » est celui de la liste du CDG (à défaut, celui du service) ;
  - colonne « Libellé CDG » : Validé / À valider. Les libellés actuels (« Signalisation SGN »…) n'ont jamais été validés.
- Libellés des rôles déclarés dans le menu et la liste des utilisateurs.

### 24.5 Tests

- **`tests/Feature/Referentiels/ImportReferentielsTest.php`** (11 tests), sur un classeur au même format fabriqué dans le test, sans données réelles. Ils couvrent :
  - la simulation ;
  - la création des comptes et des rôles ;
  - le rapprochement Saoudou / Souadou ;
  - les valeurs « à confirmer » ;
  - les rôles à privilèges sur un compte existant ;
  - l'absence d'e-mail ;
  - la double validation et son idempotence ;
  - l'approbation du plafond de caisse ;
  - le gestionnaire de l'Atelier ;
  - CORICA = Kouroussa ;
  - les codes hors liste ;
  - l'écran Paramétrage.
- **Total** : **159 tests PHP** et 59 tests JavaScript passent.

### 24.6 Déploiement (production)

```bash
php artisan migrate
php artisan referentiels:importer docs/Referentiels_Parametrage_Neemba_tous_sites_v01.xlsx --auteur=<admin>      # simulation : lire le compte rendu
php artisan referentiels:importer docs/Referentiels_Parametrage_Neemba_tous_sites_v01.xlsx --auteur=<admin> --appliquer
```

Ensuite :
- un **second administrateur** approuve les plafonds et seuils dans Modifications en attente ;
- relancer l'import à chaque nouvelle version du classeur.

---

## 25. Version v25 — Lot 5 : délégation d'initiation, liste et fiche du bon, M03-C (7 Octobre 2026)

Dernier lot du module M03. Branche `lot5-suivi`, partie de `lot4-referentiels`.

US couvertes :
- US-BC-13 : délégation d'initiation ;
- US-BC-14 : bandeau de rejet (la resoumission date du lot 3) ;
- US-BC-16 : suivi des bons.

### 25.1 Délégation d'initiation (US-BC-13, RG-BC-29)

- **Nouvelle fonctionnalité de délégation « Initiation de bons (pour le compte de) »** (`Delegation::FONCTIONNALITES_LABELS`) :
  - tout utilisateur qui a le rôle demandeur peut la déléguer ;
  - le parcours ne change pas : création, acceptation par le back-up, période (décision Q6) ;
  - sur l'écran de création d'une délégation, elle n'est **pas cochée par défaut** ;
  - une délégation enregistrée sans liste de fonctionnalités ne la donne pas.
- **Qui peut créer une délégation** : tout utilisateur qui a une fonctionnalité à déléguer (`Delegation::fonctionnalitesDelegablesPar`). Avant, un simple demandeur ne pouvait pas déléguer.
- **Assistant, étape 1** : le champ « Pour le compte de » apparaît en tête si l'utilisateur est le back-up d'au moins une délégation d'initiation active.
  - Il propose « Moi-même », si l'utilisateur a le rôle demandeur, et les titulaires, avec la date de fin de leur délégation.
  - Choisir un titulaire reprend son site, son service et le bénéficiaire par défaut.
  - Le résumé indique « Pour le compte de ».
- **Serveur** (`EnregistrementBon::titulaire`) :
  - le titulaire devient `demandeur_id`, l'auteur réel reste `initiateur_id` ;
  - sans délégation active, la saisie est refusée (MSG-BC-033) ;
  - sans le rôle demandeur, un back-up doit choisir un titulaire ;
  - `User::peutInitierBon()` tient compte des délégations d'initiation, pour le bouton « Nouveau bon » et la policy.
- **Contrôle 12 de l'étape 5** : il est rouge (MSG-BC-033) si la délégation n'est plus active au moment où l'initiateur soumet. Le bon reste en brouillon (TC-BC-028). Le titulaire peut toujours soumettre lui-même.
- **Notifications** :
  - le chef de service du service du bon (celui du titulaire) reçoit « … a soumis le bon … pour le compte de … » ;
  - le titulaire reçoit « Bon soumis pour votre compte ».
- **Mention « Initié par X pour le compte de Y »** sur la fiche et dans le PDF.
- **Correction de sécurité** : `Delegation::delegantsActifsPour()` ne retient plus que les délégations qui couvrent la validation. Elle sert aux services visibles et validables d'un chef de service par délégation. Sans ce filtre, une délégation d'initiation aurait donné au back-up les droits de validation du titulaire.

### 25.2 Liste « Bons de Caisse » (E-03.1, US-BC-16)

- **Périmètre** (RG-BC-32) : les bons dont on est demandeur ou initiateur, et ceux dont on est **bénéficiaire** à partir de la soumission. S'y ajoutent les règles de rôle de la v15 (décision Q4). Le bénéficiaire peut aussi ouvrir la fiche.
- **Filtres** : statut, type, **urgence**, **période** (du / au, sur la date de la demande). La **recherche se lance à la frappe** (300 ms), sans tenir compte de la casse ni des accents. Les filtres restent dans l'adresse de la page, avec un bouton « Réinitialiser les filtres ».
- **20 lignes par page**, triées par date de création, du plus récent au plus ancien.
- **Colonne Âge** : durée depuis la soumission pour un bon en cours (en validation, approuvé, en attente de régularisation). Elle passe **en rouge** au-delà de 2 × le délai de l'étape de validation en cours, ce délai étant divisé par 2 pour un bon très urgent.
- **Cartes** (RG-BC-33, ANO-08) : Total, À valider, Payés, Rejetés. Chacune donne un nombre et un montant (« Montant total rejeté : … GNF »), calculés **sur la même requête filtrée que la liste**.
  - Un bon rejeté puis archivé compte parmi les rejetés, et non plus parmi les payés.
  - TC-BC-031 : avec le filtre « Rejeté », la carte vaut le nombre de lignes affichées.
- **Mise en page** : la colonne principale du gabarit commun a reçu `min-w-0`. Un tableau large défile désormais dans son cadre au lieu d'élargir toute la page ; le tableau des bons tient à 1366 px.

### 25.3 Fiche du bon (E-03.8)

- **Bandeau rouge d'un bon rejeté** (US-BC-14) : niveau, valideur, date, motif et commentaire, avec le bouton « Corriger et resoumettre ».
- **En-tête** : âge du bon depuis la soumission, et mention « Initié par … pour le compte de … ».
- **Onglet Validations** : une ligne par niveau, regroupée par version pour un bon resoumis.
  - **Fait** : valideur, « **au titre de** » s'il s'agit d'un suppléant, date et durée de traitement.
  - **En cours** : valideurs possibles, durée d'attente et échéance (en rouge si dépassée).
  - **À venir**.
  - **Non atteint**, pour un bon rejeté ou annulé.
  - Nouvelle colonne `validations.au_titre_de_id`, renseignée quand un suppléant valide par délégation.
- **Onglet Pièces** : qualité, résultat de la lecture des tickets (montant et litres validés), version, et mention « remplacée ».
- **Chronologie** : création, soumission, chaque validation ou rejet (avec la version), paiement, régularisation, annulation.
- **Correction** : `Validation::slaDepasse()` et `doitEscalader()` décalaient la date d'attribution du modèle en mémoire, car `addHours()` modifie l'objet Carbon lui-même. Elles calculent désormais sur une copie.

### 25.4 Tests

- **`tests/Feature/M03/SuiviEtDelegationTest.php`** (14 tests), qui couvrent :
  - TC-BC-027 et TC-BC-028 ;
  - les notifications ;
  - le back-up sans rôle demandeur ;
  - l'absence de droits de validation par une délégation d'initiation ;
  - « au titre de » ;
  - le périmètre de la liste ;
  - TC-BC-031 et les cartes ;
  - les filtres et les 20 lignes ;
  - l'âge en alerte ;
  - le bandeau de rejet ;
  - l'onglet Validations.
- **Total** : **173 tests PHP** et 59 tests JavaScript passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 14 vérifications. On y passe successivement par :
  - la connexion avec la nouvelle adresse de Souadou ;
  - les cartes, filtres et colonne Âge ;
  - la recherche à la frappe ;
  - le bandeau de rejet et l'onglet Validations ;
  - « Pour le compte de » avec une délégation de Mohamed DIAKITE.

### 25.5 Déploiement

```bash
php artisan migrate        # validations.au_titre_de_id
php artisan optimize:clear
npm run build
```

### 25.6 Bilan du module M03

Les 16 US de la SFD v1.3 sont couvertes (lots 3, 4 et 5). Restent hors de ces lots :

| Point | Où |
|-------|-----|
| Lecteur « vision » des tickets manuscrits, et mesure du taux de corrections sur les tickets de septembre 2026 (TC-BC-019) | OP-BC-5, décision Q8 |
| Choix de l'ordre de mission dans l'étape 3 ; dates de départ pour MSG-BC-025 | M12 |
| Matrice Excel de traçabilité à cocher (hash des commits) | Fichier non présent dans le dépôt |
| Recette sur l'environnement séparé avec les comptes pilote ; comparaison visuelle aux maquettes HTML | À planifier avec Neemba |

---

*Ce document est maintenu à jour au fur et à mesure du développement de l'application NEEMBA Cash Management.*

## 26. Version v26 — Module M12, lot M12-0 : prérequis des ordres de mission (8 Octobre 2026)

Premier lot du module M12 « Ordres de mission », d'après la spec v2.2 du 07/10/2026 et le comité du 06/10/2026. Branche `m12-odm`, partie de `lot5-suivi`. Le suivi détaillé du module est dans `docs/ORDRE_DE_MISSIONS.md`.

Ce lot pose ce dont les ODM ont besoin sans être propre aux ODM : frais Orange Money, taux de change, jours ouvrés, paramètres, rôles.

### 26.1 Frais Orange Money (spec v2.2, §6.6)

- **`App\Services\Paiement\FraisOrangeMoney`** : frais = arrondi au franc supérieur de (total × taux du palier).
  - Le palier se lit sur le **total du bon**, pas par tranche : 1 % de 100 001 à 5 000 000 GNF, 0,8 % de 5 000 001 à 15 000 000 GNF.
  - La grille est le paramètre `frais_om_paliers` (JSON), contrôlé à la saisie : paliers complets, cohérents, sans chevauchement.
  - Hors paliers (≤ 100 000 ou > 15 000 000 GNF, PO-01), pas de calcul.
- **Paiement** (`BonCaisseController::payer`, `BonCaisse::marquerCommePaye`) :
  - si le caissier retient Orange Money, les frais s'ajoutent : **montant versé = montant du bon + frais** ;
  - hors paliers, le caissier saisit les frais (champ « Frais Orange Money ») ; sans saisie, le paiement est refusé et l'OTP n'est pas consommé ;
  - la caisse OM décaisse le montant versé ; le contrôle du solde porte sur ce montant ;
  - le montant du bon ne change pas : le seuil du visa DP porte sur la dépense, hors frais (RG-M06-07) ;
  - nouvelles colonnes `bons_caisse.frais_om`, `frais_om_taux`, `frais_om_saisis`, `montant_verse` ; le journal du bon note les frais, leur taux ou leur saisie manuelle.
- **Fiche du bon** : à l'étape du paiement, choisir Orange Money affiche MSG-M03-06 (« Si le caissier retient Orange Money : frais estimés 7 500 GNF (1 %), montant versé 757 500 GNF. »). Une fois payé, la fiche montre les frais et le montant versé.
- **Rapport journalier** : pour les jours antérieurs au registre, les sorties OM comptent le montant versé.

### 26.2 Taux de change FCFA → GNF

- Table `taux_change` (date, devise XOF, taux en GNF pour 1 FCFA, saisi par, commentaire), un taux par jour.
- **Écran « Taux du jour »** (menu, `/tresorerie/taux-du-jour`) :
  - saisie par la Trésorerie, et par le DAF ou le DAF adjoint en secours ;
  - consultation par le chef comptable, le DP, le caissier et l'administrateur ;
  - sans taux du jour, une alerte rappelle que le paiement des ODM à l'étranger est bloqué ;
  - une correction le même jour exige un motif, conservé avec l'ancien taux.
- `TauxChange::duJour()` (exigé au paiement d'un ODM extérieur, M12-4) et `TauxChange::dernier()` (estimation du montant pour le circuit).

### 26.3 Jours ouvrés

- **`App\Support\JoursOuvres`** : du lundi au vendredi, hors jours fériés du paramètre `jours_feries`.
  - Le paramètre accepte des dates fixes `MM-JJ` (chaque année) et des dates mobiles `AAAA-MM-JJ` ; une date invalide est refusée à la saisie.
  - Valeur initiale : 01-01, 05-01, 08-15, 10-02, 12-25 (décision Q29 : les fêtes mobiles sont à saisir chaque année).
- **Échéance des BP** (RG-M07-02, confirmée par la v2.2) : 3 jours ouvrés après le retour de mission, 2 jours ouvrés après le paiement sinon. Le contrôle 11 de l'assistant parle désormais de « jours ouvrés ».

### 26.4 Paramètres « Ordres de mission »

Nouveau groupe dans Paramétrage, chaque modification passant par la double validation :

| Paramètre | Valeur | Référence |
|-----------|--------|-----------|
| Indemnité journalière (intérieur) | 250 000 GNF, en 2 lignes égales | §6.6 |
| Libellés des 2 lignes | Indemnité de repas / Indemnité de déplacement | RG-M12-08, PO-02 |
| Hébergement par nuit (intérieur) | 500 000 GNF ; 0 sur base vie | §6.6 |
| Indemnité extérieure | 22 000 FCFA (non-cadre) / 34 000 FCFA (cadre) par jour | §6.6 |
| Participants par ODM | 10 au plus | RG-M12-04 |
| ODM générant un BP | Oui | RG-M12-13 |
| Mode de génération des bons | Un bon par participant | RG-M12-14, PO-03 |
| ODM à la charge du client | Variante A | RG-M12-15, PO-04 |
| Visa RH sur les ODM | Non | Comité du 06/10 |
| Rappel avant la fin d'un segment | 2 jours ouvrés | RG-M12-28 |

- Nouveau type de paramètre **« choix »** (`Parametre::CHOIX`) : liste déroulante à l'écran, valeur contrôlée par le serveur. Les types `number`, `boolean`, `dates` et `json` sont aussi contrôlés (`Parametre::erreurValeur`).
- La colonne `parametres.valeur` passe en texte (listes longues).

### 26.5 Rôles, n° Orange Money, fiche utilisateur

- **Rôles** : `chef_atelier` (chef d'atelier ou chef d'équipe, premier visa de l'ODM), `dp_adjoint` (visa DP de l'ODM), `logistique` (déclaré pour M13).
- **Fiche utilisateur** :
  - **n° Orange Money** au format guinéen, 9 chiffres commençant par 6 (RG-M02-05). Il est saisi avec ou sans espaces ni indicatif (+224) et enregistré sur 9 chiffres ;
  - **statut cadre / non-cadre**, exigé pour un ODM à l'étranger ;
  - **rôles complémentaires** (cases à cocher), en plus du rôle principal. Toute modification passe par la double validation (type `utilisateur_roles`) ; à l'approbation, le rôle principal est conservé (décision Q31) ;
  - le **matricule** devient facultatif à la modification (unique s'il est saisi) : les comptes créés par l'import des référentiels n'en ont souvent pas, et le formulaire ne pouvait plus être enregistré.
- **Import des référentiels** :
  - onglet 5, niveau « Chef d'atelier / chef d'équipe (ODM) » : le titulaire reçoit `chef_atelier` si son compte est rattaché au site et au service indiqués ; sinon, une anomalie est signalée. Un suppléant passe par une délégation ;
  - onglet 1 : « DP adjoint », « Chef d'atelier » (à désigner dans l'onglet 5) et « Logistique » sont reconnus ;
  - `chef_atelier` et `dp_adjoint` sont des rôles à privilèges : sur un compte existant, ils s'attribuent à l'écran, avec la double validation.

### 26.6 Corrections

- **Génération de l'OTP** : quand le service SMS n'est pas configuré, la réponse ne contient qu'une clé `error`, et l'écran tombait en erreur 500. Le message d'erreur est maintenant affiché (« Erreur SMS : Service SMS non configuré »).
- Catalogue des messages : MSG-M03-06 et MSG-M12-01 à MSG-M12-09, au texte exact de la spec v2.2.

### 26.7 Tests

- **`tests/Feature/M12/PrerequisTest.php`** (19 tests) :
  - frais OM des exemples de la spec (3 250 000 → 32 500 ; 7 000 000 → 56 000 ; 8 250 000 → 66 000 ; B.2 et B.3) et des bornes de paliers ;
  - hors paliers ; grille paramétrable ;
  - paiement OM (montant versé, registre, journal), frais saisis, espèces sans frais, solde insuffisant frais compris ;
  - estimation MSG-M03-06 sur la fiche ;
  - jours ouvrés (week-end, dates fixes et mobiles) et échéance des BP ;
  - paramètres ODM, contrôle des valeurs par type, double validation ;
  - taux du jour : saisie, correction motivée, droits ;
  - n° OM (RG-M02-05) et rôles complémentaires.
- Import des référentiels : chef d'atelier de l'onglet 5 (compte rattaché ou non au service), logistique.
- Lot 0 : le paiement OM de 300 000 GNF débite désormais 303 000 GNF.
- **Total** : **193 tests PHP** et 59 tests JavaScript passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 19 vérifications. On y passe successivement par :
  - la saisie du taux du jour par Youssouf TOURE (Trésorerie) ;
  - le paiement du bon BC-2026-0004 en Orange Money : 7 500 GNF de frais, 757 500 GNF versés et débités de la caisse OM ;
  - les paramètres ODM et la grille OM dans Paramétrage, et le passage au « bon groupé » mis en double validation ;
  - la fiche d'une utilisatrice importée sans matricule : n° OM refusé puis accepté, rôle « chef d'atelier » mis en double validation.

### 26.8 Déploiement

```bash
php artisan migrate        # taux_change, users.numero_om, frais OM des bons, paramètres ODM et jours fériés
php artisan optimize:clear
npm run build
```

Après le déploiement :
- l'administrateur complète `jours_feries` avec les fêtes mobiles de l'année ;
- la Trésorerie saisit le taux du jour avant le premier paiement d'un ODM extérieur ;
- les n° Orange Money et le statut cadre des salariés sont à renseigner (fiche utilisateur ou prochain classeur des référentiels).

## 27. Version v27 — Module M12, lot M12-1 : modèle de données, calcul et numérotation des ODM (8 Octobre 2026)

Branche `m12-odm`. Ce lot n'a pas d'écran : il pose les tables, le calcul des indemnités et la numérotation utilisés par les lots suivants.

### 27.1 Modèle de données

La table `ordres_mission` d'origine n'était qu'une ébauche (un collaborateur, un seul bon) et n'avait jamais été alimentée : la migration la remplace, après avoir vérifié qu'elle est vide. La clé étrangère `bons_caisse.odm_id` (lot 3) est reconstruite vers la nouvelle table.

| Table | Contenu |
|-------|---------|
| `ordres_mission` | Un **segment** de mission : l'ODM initial (rang 1) ou une prolongation, liée au segment précédent (`segment_precedent_id`) et à l'ODM initial (`mission_id`). Numéro, préfixe, séquence, année ; type, nature technique ; site, service, code analytique (Q22) ; demandeur, initiateur ; but, clients, destinations, véhicule ; dates de départ, de retour prévue et réelle, motif d'un départ passé ; prise en charge, « à refacturer » ; hébergement extérieur, référence billet ; statut, version, clé de soumission, total ; barèmes figés (RG-M12-25) ; dérogation au chevauchement ; dates de soumission, validation, clôture, annulation, rappel |
| `odm_participants` | Un salarié et ce qui est repris du référentiel (nom, matricule, service, statut cadre, n° OM), base vie, calcul (jours, nuits, nuitée de rattrapage, indemnité FCFA et GNF, hébergement, rattrapage, facture d'hébergement, total), retrait d'une prolongation, trop-perçu et sa régularisation, bon généré |
| `odm_ordres_reparation` | OR liés : 8 chiffres commençant par 110, VENTE ou GARANTIE |
| `odm_etapes` | Circuit propre à l'ODM, par version : niveau, rôle, statut, valideur, « au titre de », dates, commentaire, relance, escalade |
| `odm_historique` | Journal de l'ODM (remplace le fil d'e-mails « Neemba Service_Mission ») |
| `compteurs_odm` | Dernier numéro par préfixe et par année |

Ajouts :
- `services.prefixe_odm` (« AT » pour Technique, décision Q28) et `services.diffusion_odm` (liste de diffusion, RG-M12-24) ;
- `bons_caisse.odm_participant_id` et `bons_caisse.genere_par_odm`.

Modèles : `OrdreMission`, `ParticipantOdm`, `OrdreReparationOdm`, `EtapeOdm`, `HistoriqueOdm`. `BonCaisse::ordreMission()` devient un BelongsTo via `odm_id` ; `BonCaisse::participantOdm()` est ajouté. Les blocs « Ordre de mission » de la fiche du bon et de l'archivage affichent les nouveaux champs.

- **Statuts** (RG-M12-21) : Brouillon, Soumis, En validation, Rejeté, Validé, Bons générés, Payé, Clôturé, Annulé.
- **Circuit** (`OrdreMission::NIVEAUX`, RG-M12-11) : chef d'atelier ou chef d'équipe, puis DAF (DAF, DAF adjoint ou chef comptable, décision Q27), puis DP (ou DP adjoint). L'étape RH n'est insérée que si le paramètre `odm_etape_rh` est actif.
- **Prolongation** : libellé « Prolongation n de N°xxx » (décision Q23) ; `segmentsDeLaMission()` donne la chaîne.

### 27.2 Calcul des indemnités (`App\Services\Odm\CalculOdm`)

Calcul pur : il reçoit les barèmes et ne lit rien en base. Il peut ainsi être rejoué à l'identique avec les barèmes figés à la validation (RG-M12-25). `CalculOdm::baremesEnVigueur()` lit les paramètres du groupe « Ordres de mission » et la grille des frais OM.

| Élément | Intérieur | Extérieur |
|---------|-----------|-----------|
| Jours | retour − départ + 1 | idem |
| Nuits | jours − 1 ; 0 sur base vie | jours − 1 ; 0 si la filiale d'accueil héberge |
| Nuitée de rattrapage | prolongation, participant non logé sur base vie au segment précédent : 1 nuit, sur une ligne distincte | comptée ; l'hébergement suit la facture |
| Indemnité | jours × 250 000, en 2 lignes égales | jours × 22 000 ou 34 000 FCFA × taux, arrondi au franc ; sans taux ou sans statut cadre : non calculée |
| Hébergement | nuits × 500 000 | filiale : 0 ; facture payée avant le départ : son montant (Q24) ; au retour : 0 (bon complémentaire à la clôture) |

- Le calcul donne aussi, pour information, les frais OM et le montant versé si le caissier retient Orange Money.
- **Contrôle de mission** (RG-M12-19) : sur la chaîne des segments, nuits payées (nuits + nuitées de rattrapage) = jours − 1.
- `FraisOrangeMoney::calculer()` accepte une grille figée.

### 27.3 Numérotation (`App\Services\Odm\NumeroteurOdm`, RG-M12-03)

- Format `N°[séquence]/[préfixe]/[AA]`, ex. N°285/AT/26. Une séquence par préfixe et par année, compteur verrouillé ; un numéro déjà attribué n'est jamais réutilisé.
- Préfixe : celui du service ; à défaut, les trois premières lettres du nom du service (« LOG » pour Logistique).
- `definirDepart()` reprend la numérotation des carnets papier (ex. dernier numéro 285 → prochain ODM N°286/AT/26).

### 27.4 Tests

- **`tests/Unit/CalculOdmTest.php`** (9 tests), sur les exemples de l'annexe B :
  - B.1 : 5 jours, 4 nuits, 1 250 000 en deux lignes de 625 000, 2 000 000 d'hébergement, total 3 250 000, frais OM 32 500, versé 3 282 500 ;
  - B.2 : 6 565 000 versés en deux bons, 6 552 000 en un bon groupé ;
  - B.3 : prolongation à 7 jours, 6 nuits et 1 nuitée de rattrapage, total 5 250 000, versé 5 292 000 ; mission cohérente (12 jours, 11 nuits) ;
  - B.4 : mission KOUROUMA, 57 nuits payées pour 59 jours (écart − 1), 58 selon la règle ;
  - base vie, ODM extérieur (cadre, non-cadre, 3 modes d'hébergement, sans taux, sans statut), barèmes paramétrés.
- **`tests/Feature/M12/ModeleOdmTest.php`** (9 tests) : numérotation par préfixe et par année, reprise des carnets, numéro jamais réutilisé, préfixe du service, barèmes en vigueur, relations (participants, bons, OR, segments), circuit avec ou sans RH, journal, statuts.
- **Total** : **211 tests PHP** et 59 tests JavaScript passent.
- La migration a été vérifiée sur une copie de la base de développement (aller, retour, aller), puis appliquée.

### 27.5 Déploiement

```bash
php artisan migrate        # ordres_mission remplacée (la migration s'arrête si la table contient des données)
php artisan optimize:clear
npm run build
```

Après le déploiement, renseigner les préfixes des services émetteurs d'ODM et le dernier numéro de chaque carnet (écran prévu au lot M12-2).

## 28. Version v28 — Module M12, lot M12-2 : saisie et soumission des ordres de mission (8 Octobre 2026)

Branche `m12-odm`. US-01 à US-06 et US-11 de la spec v2.2 (§7.3). Le circuit de validation (visas) arrive au lot M12-3.

### 28.1 Formulaire « Ordre de mission »

Menu **Ordres de mission** → **Nouvel ordre de mission** (`Pages/Odm/Formulaire.jsx`). Une seule page, en cinq blocs, avec le panneau **Calcul** à droite :

| Bloc | Contenu |
|------|---------|
| Type et service émetteur | Intérieur / extérieur ; frais à la charge de Neemba ou du client ; service émetteur (celui du demandeur par défaut) ; code analytique du service (Q22) ; nature technique, pré-cochée pour Technique et Aftermarket (PO-06) ; véhicule |
| Mission | Destinations et clients en étiquettes ; but (10 caractères minimum, compteur) |
| Dates | Départ, retour prévu, nombre de jours ; motif obligatoire si le départ est passé ; à l'étranger : mode d'hébergement et référence billet / Wanda |
| OR liés | N° de 8 chiffres commençant par 110, type Vente ou Garantie ; obligatoires pour une mission technique |
| Participants | Recherche des salariés actifs ; service, statut cadre et n° OM repris du référentiel ; « logé sur base vie » (intérieur) ou facture d'hébergement (extérieur, payée avant le départ) |

- **Enregistrement automatique** : 800 ms après la dernière modification, le brouillon est enregistré. Il est créé à la première saisie, et l'adresse de la page devient celle du brouillon.
- **Calcul en direct** : le serveur renvoie le calcul de chaque participant (`CalculOdm`). Le panneau affiche jours et nuits, les deux lignes de 125 000 × jours avec leurs libellés paramétrés, l'hébergement, la nuitée de rattrapage, le total et l'estimation des frais OM (MSG-M03-06). À l'étranger, il indique le taux utilisé pour l'estimation, ou l'absence de taux. Aucun calcul n'est refait à l'écran.
- Le nombre maximal de participants (paramètre) est appliqué dès l'ajout.

### 28.2 Soumission (`SoumettreOdm`)

Dans une transaction, ODM verrouillé :
1. les informations des participants sont relues dans le référentiel : un statut cadre renseigné par les RH entre-temps est pris en compte ;
2. le calcul est refait ;
3. les contrôles bloquants (`ReglesOdm`) sont refaits ; toutes les erreurs sont renvoyées, chacune avec son champ, et un clic sur le message place le curseur sur le champ ;
4. le numéro est attribué (`NumeroteurOdm`) ; un ODM rejeté garde le sien et passe à la version suivante ;
5. les étapes du circuit sont créées (chef d'atelier en attente, DAF et DP à venir), statut **Soumis** ;
6. après la transaction, notifications à la **liste de diffusion du service** (RG-M12-24) et aux **chefs d'atelier du service** (premier visa).

La même clé d'idempotence rejouée renvoie l'ODM déjà soumis (double clic) ; un échec ne consomme aucun numéro.

| Contrôle | Message |
|----------|---------|
| Type, service, code analytique, prise en charge, dates obligatoires | MSG-BC-001, MSG-APP-021 (code inconnu) |
| Au moins une destination ; but de 10 caractères | MSG-APP-012, MSG-APP-011 |
| Retour avant le départ | MSG-M12-02 |
| Départ passé sans motif | MSG-APP-015 |
| Mission technique sans OR ; OR mal formé | MSG-M12-01, MSG-M03-07 |
| Aucun participant ; trop de participants ; salarié inactif | MSG-APP-013, MSG-APP-014, MSG-APP-017 |
| Extérieur : mode d'hébergement, statut cadre, facture d'hébergement | MSG-APP-016, MSG-M12-04, MSG-APP-018 |
| Chevauchement | MSG-M12-03 |

### 28.3 Chevauchement et dérogation (RG-M12-16, SC-26)

- **`ChevauchementOdm`** : un participant ne peut figurer sur deux ODM dont les périodes se chevauchent, bornes comprises. Le retour réel compte s'il est saisi, sinon le retour prévu. Les brouillons et les ODM annulés ne comptent pas (Q25). Deux missions qui se suivent (retour le 26, départ le 27) ne se chevauchent pas.
- **Dérogation** :
  - le demandeur la demande depuis le message d'erreur, avec un motif d'au moins 10 caractères (MSG-APP-022) ; le DAF et le DAF adjoint sont notifiés ;
  - dans la liste des ODM, le DAF voit le bloc « Dérogations au chevauchement à décider », avec les conflits. Il accorde ou refuse avec un motif, et le demandeur est notifié ;
  - une dérogation accordée lève le contrôle à la soumission ;
  - si les dates ou les participants changent ensuite, la dérogation est **retirée** (décision Q33) ;
  - tout est tracé dans le journal de l'ODM.

### 28.4 Liste, fiche, droits

- **Liste** (`Pages/Odm/Index.jsx`) : numéro (ou « Brouillon »), type, destinations, période, participants, total, statut ; recherche à la frappe (numéro, destination, but, participant), filtres statut et type, 20 lignes.
- **Fiche** (`Pages/Odm/Show.jsx`) : en-tête, bandeaux de rejet et de dérogation, onglets Détails, Calcul et Historique ; boutons Reprendre, Annuler, Décider de la dérogation.
- **Visibilité** (`OrdreMission::visiblesPar`) :
  - le demandeur et l'initiateur voient leurs ODM, y compris les brouillons ;
  - une fois l'ODM soumis : les participants, le chef d'atelier du service, les valideurs, et les rôles DAF, DAF adjoint, chef comptable, DP, DP adjoint, Trésorerie, RH et administrateur (décision Q34) ;
  - le DAF voit aussi un brouillon dont la dérogation est demandée.
- **Modification** : demandeur ou initiateur, en brouillon ou rejeté (MSG-APP-020, MSG-APP-019).
- **Annulation** (RG-M12-22, partie demandeur) : tant qu'aucun bon n'est généré ; les étapes ouvertes du circuit sont closes. L'annulation par le DAF viendra avec M12-6.

### 28.5 Paramétrage des services

Dans Paramétrage → Services, chaque service reçoit :
- son **préfixe des ODM** (ex. AT) ;
- sa **liste de diffusion** (cases à cocher parmi les utilisateurs actifs) ;
- le **dernier n° du carnet papier** de l'année : le prochain ODM prendra le numéro suivant. Le compteur ne recule jamais (décision Q35).

Sans préfixe, un service prend les trois premières lettres de son nom (Q35).

### 28.6 Tests

- **`tests/Feature/M12/SaisieOdmTest.php`** (16 tests) :
  - brouillon pré-rempli, calcul B.1 renvoyé par le serveur, informations reprises du référentiel ;
  - plusieurs participants dont un sur base vie (SC-21, SC-27), retrait d'un participant ;
  - soumission : numéro, étapes, notifications, idempotence ;
  - champs obligatoires sans consommer de numéro ; mission technique et OR (SC-22) ; dates ; nombre maximal ;
  - ODM extérieur : hébergement, statut cadre relu, facture ;
  - chevauchement (SC-26), brouillons et annulés ignorés, missions consécutives ;
  - dérogation du DAF, retirée si les dates changent ;
  - droits de modification, visibilité de la liste, annulation, écrans, reprise du carnet.
- **`tests/js/odm.test.js`** (7 tests) : format des OR, étiquettes, dates, nature technique, codes du service, participants, données du brouillon, erreurs par champ.
- **Total** : **227 tests PHP** et **66 tests JavaScript** passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 23 vérifications. Philippe KOLIE (Technique) crée un ODM pour Yacouba BARRY :
  - OR invalide refusé à la saisie ;
  - brouillon enregistré automatiquement, total de 3 250 000 GNF calculé par le serveur ;
  - soumission : N°1/AT/26.

  Un second ODM sur la même période est bloqué (MSG-M12-03). Philippe demande une dérogation, que Mohamed DIAKITE (DAF) accorde ; le second ODM part en N°2/AT/26. Thomas BANGOURA (chef d'atelier) voit les deux ODM et a été notifié deux fois.

### 28.7 Déploiement

Aucune migration. `php artisan optimize:clear`, puis `npm run build`. Après le déploiement, renseigner dans Paramétrage → Services le préfixe, la liste de diffusion et le dernier numéro du carnet papier de chaque service émetteur d'ODM.

## 29. Version v29 — Module M12, lot M12-3 : circuit de validation des ordres de mission (8 Octobre 2026)

Branche `m12-odm`. US-07 de la spec v2.2 (§7.3), RG-M12-11, RG-M12-12 et RG-M12-25.

### 29.1 Le circuit (`App\Services\Odm\CircuitOdm`)

| Niveau | Qui vise | Délai (§6.7) |
|--------|----------|--------------|
| Chef d'atelier / chef d'équipe | Rôle `chef_atelier` du service émetteur, de préférence sur le site de l'ODM | `sla_responsable_service` |
| DAF | DAF, DAF adjoint ou chef comptable (Q27) | `sla_daf` |
| RH (si `odm_etape_rh` est actif) | Rôle `rh` | `sla_daf` |
| Directeur Pays | DP ou DP adjoint | `sla_directeur_pays` |

- **Suppléant** : nouvelle fonctionnalité de délégation **« Visa des ordres de mission »** (`visa_odm`). Elle peut être déléguée par le chef d'atelier, le DAF, le DAF adjoint, le chef comptable, le DP, le DP adjoint et les RH. Le suppléant vise **au titre du** titulaire ; c'est enregistré sur l'étape. Une délégation de validation des bons ne donne pas le visa des ODM (décision Q36).
- **Incompatibilités** (RG-M01-04) : le demandeur, l'initiateur et les participants ne visent pas l'ODM. Si personne d'autre ne peut viser un niveau (ni titulaire compatible, ni suppléant), l'étape est **sautée** et l'ODM passe au niveau supérieur ; c'est tracé dans le journal. Le dernier niveau n'est jamais sauté : il reste en attente (décision Q37).
- Les statuts : **Soumis** jusqu'au premier visa, **En validation** ensuite, **Validé** après le dernier.

### 29.2 Visa et rejet (`App\Services\Odm\ValiderOdm`)

- **Viser** : commentaire facultatif ; l'étape suivante passe en attente et ses valideurs (titulaires et suppléants) sont notifiés.
- **Validation finale** :
  - le calcul est **figé** : les barèmes en vigueur à la soumission sont enregistrés avec l'ODM, ainsi que la date de validation (RG-M12-07, RG-M12-25). Un barème modifié ensuite ne change plus le calcul ;
  - le demandeur reçoit **MSG-M12-08** (« ODM N°… validé. Vous pouvez générer le ou les bons de caisse. ») ;
  - la liste de diffusion du service est notifiée (RG-M12-24).
- **Rejeter** :
  - motif obligatoire, 10 caractères au minimum (MSG-APP-024) ;
  - les étapes à venir passent à « Non atteint » ;
  - l'ODM revient au demandeur avec un bandeau de rejet, garde son numéro, et les barèmes sont libérés ;
  - à la resoumission, la version passe à 2 et un nouveau circuit démarre, avec les barèmes en vigueur ce jour-là (décision Q38).
- Hors de son tour, ou sans droit, la tentative est refusée (MSG-APP-023).

### 29.3 Délais, relances, escalade

Commande **`odm:relancer-visas`**, planifiée toutes les heures :
- **à l'échéance** de l'étape (attribution + délai), une relance aux valideurs ;
- **au double du délai** (paramètre `sla_multiplicateur_escalade`), une escalade : notification au niveau suivant, ou au DAF pour le niveau DP (§6.7).

Chaque relance et chaque escalade est notée dans le journal de l'ODM. Les délais sont comptés en heures calendaires, comme pour les bons ; les heures ouvrées de la v2.2 relèvent du plan dédié aux écarts (`docs/ORDRE_DE_MISSIONS.md`, §11).

### 29.4 Écrans

- **Ordres de mission** : nouveau bloc « Ordres de mission à viser (n) », pour chaque personne qui peut viser maintenant (titulaire ou suppléant), avec un bouton Examiner. La page « Validations » reste celle des bons : les valideurs d'ODM n'y ont pas tous accès (chef d'atelier, DP adjoint…) (décision Q39).
- **Fiche de l'ODM** :
  - encadré « Votre visa est attendu », avec la mention « au titre de » pour un suppléant, et les boutons **Viser** (commentaire facultatif) et **Rejeter** (motif obligatoire) ;
  - onglet **Validations** : une ligne par niveau, regroupées par version, avec le valideur, « au titre de », la date et la durée, l'attente, l'échéance (en rouge si dépassée), les valideurs possibles de l'étape en cours, les étapes sautées et non atteintes ;
  - onglet Calcul : « Calcul figé à la validation » une fois l'ODM validé.
- Un suppléant ouvre la fiche de l'ODM qu'il doit viser, même s'il n'est pas dans le périmètre de la liste.

### 29.5 Tests

- **`tests/Feature/M12/CircuitOdmTest.php`** (10 tests) :
  - SC-20 complet : chef d'atelier, DAF adjoint, DP ; ODM validé, barèmes figés, notifications, journal ;
  - ordre du circuit et périmètre du chef d'atelier ;
  - rejet motivé puis resoumission en version 2, même numéro ;
  - participant chef d'atelier : étape sautée ;
  - suppléant « au titre de » ; une délégation de validation des bons ne suffit pas ;
  - DP adjoint quand le DP est demandeur ; étape RH en option ;
  - calcul figé ; relance à l'échéance et escalade au double du délai.
- **Total** : **237 tests PHP** et 66 tests JavaScript passent.
- La migration a été vérifiée sur une copie de la base de développement (aller, retour, aller), puis appliquée.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 16 vérifications. On y passe successivement par :
  - un ODM de Philippe KOLIE, visé par Thomas BANGOURA (chef d'atelier) depuis le bloc « à viser » ;
  - le rejet par Mohamed DIAKITE (DAF), avec un motif trop court d'abord refusé ;
  - la correction et la resoumission en version 2 ;
  - les visas du chef d'atelier, du DAF et de Mamadou LO (DP) : MSG-M12-08, statut Validé, onglet Validations avec les deux versions, calcul figé.

### 29.6 Déploiement

```bash
php artisan migrate        # odm_etapes.statut : valeur « sautee »
php artisan optimize:clear
npm run build
```

Le planificateur (cron `schedule:run`) lance `odm:relancer-visas` toutes les heures.

## 30. Version v30 — Module M12, lot M12-4 : génération des bons de caisse et paiement (8 Octobre 2026)

Branche `m12-odm`. US-08 et US-15 de la spec v2.2 (§7.3) ; RG-M12-10, RG-M12-13 à RG-M12-15, RG-M12-21, RG-M12-29 ; RG-M03-15 et RG-M03-22.

### 30.1 Génération (`App\Services\Odm\GenererBonsOdm`)

Depuis la fiche d'un ODM **Validé**, son demandeur clique sur **Générer les bons de caisse**.

| Mode (paramètre `odm_mode_generation`) | Bons créés |
|-----------------------------------------|------------|
| Un bon par participant (défaut, PO-03) | Un BD par participant, du montant de ses indemnités ; le bon est relié au participant |
| Bon groupé | Un BD du total de l'ODM, versé au participant désigné ; tous les participants sont reliés à ce bon |

- **Champs repris de l'ODM et verrouillés** (RG-M03-22) :
  - bénéficiaire (le participant), son n° Orange Money en téléphone de retrait ;
  - montant, site, service, code analytique, OR ;
  - motif « Indemnités de mission — ODM N°…, destinations, du … au … — participant » ;
  - catégorie **mission**, mode souhaité **Orange Money** (le caissier décide au paiement).

  L'assistant refuse de modifier un bon généré : la policy `modifier` et `EnregistrementBon` lèvent MSG-APP-025.
- **Pas de justificatif** : l'ODM en tient lieu (RG-M03-15, RG-M12-29). Le contrôle 4 l'indique ; la catégorie « mission », non proposée dans l'assistant, est admise pour un bon généré.
- **Circuit complet** : chaque bon est soumis aussitôt (`SoumettreBon`) et suit le circuit des bons : chef de service, CDG, Finance, puis DP au-delà du seuil de 1 500 000 GNF (annexe B.1 : le bon de 3 250 000 GNF aura aussi le visa du DP).
- **BP d'avance pour frais réels** (paramètre `odm_genere_bp`, SC-37) :
  - en option à la génération : montant, motif et participant bénéficiaire ;
  - le BP est lié à la mission : retour = retour prévu de l'ODM, échéance 3 jours ouvrés après (RG-M07-02) ;
  - un seul BP actif par ODM (MSG-APP-032) ; refusé si le paramètre est désactivé (MSG-APP-031).
- **Tout ou rien** : si un bon est refusé par les contrôles, aucun bon n'est créé.
- **Regénération** : un participant dont le bon a été annulé reçoit un nouveau bon à la génération suivante ; sinon, MSG-APP-030.
- L'ODM passe **Bons générés**, et la génération est notée dans son journal avec les numéros des bons.

### 30.2 Prise en charge client (RG-M12-15, SC-28)

- Un ODM à la charge du client est marqué **« à refacturer »** dès sa validation finale, avec ses OR.
- Variante A (défaut, PO-04) : bons générés normalement.
- Variante B : aucun bon (MSG-APP-028) ; la fiche l'indique.

### 30.3 ODM extérieur (RG-M12-10, SC-29)

- **À la génération**, le montant est estimé au **dernier taux saisi**. Sans aucun taux, la génération est refusée (MSG-APP-029). Le bon garde sa part en FCFA, sa part fixe en GNF (hébergement, nuitée de rattrapage), le taux estimé et le montant estimé : nouvelles colonnes de `bons_caisse`.
- **Au paiement**, le montant est recalculé avec le **taux du jour** : part FCFA × taux + part fixe. Sans taux du jour, le paiement est bloqué avant toute consommation de l'OTP (**MSG-M12-05**).
- Le taux appliqué est enregistré, et l'écart est journalisé sur le bon, par exemple : « estimé 1 595 000 GNF au taux 14,5, payé 1 650 000 GNF (écart +55 000 GNF) ».
- Les frais OM portent sur le montant recalculé.
- Dans l'onglet Bons de l'ODM, la Trésorerie voit le taux estimé et le taux appliqué (US-15).

### 30.4 Suivi du paiement (RG-M12-21)

`PaiementOdm::apresPaiement`, appelé à la fin de `BonCaisse::marquerCommePaye` : quand tous les bons actifs de l'ODM sont payés (payé, en attente de régularisation, régularisé, archivé), l'ODM passe **Payé**, avec une entrée de journal.

### 30.5 Écrans

- **Fiche de l'ODM** :
  - encadré « Bons de caisse de l'ordre de mission » : mode de génération, participants avec leur montant et leur n° OM, choix du bénéficiaire d'un bon groupé, option BP ; alerte si aucun taux n'existe pour un ODM extérieur ;
  - onglet **Bons** : numéro (lien vers la fiche du bon), bénéficiaire, montant, taux estimé et appliqué, statut, montant versé et frais OM.
- **Fiche du bon** : le bloc « Ordre de mission » montre le numéro, les destinations et les dates de l'ODM.

### 30.6 Tests

- **`tests/Feature/M12/GenerationBonsOdmTest.php`** (10 tests) :
  - un BD par participant (champs repris, circuit avec DP, sans pièce) ; bons verrouillés ; regénération après annulation ;
  - bon groupé et frais OM de 52 000 (B.2) ;
  - BP d'avance (tout ou rien, échéance en jours ouvrés, un seul) et paramètre désactivé ;
  - ODM non validé ou autre utilisateur ;
  - prise en charge client, variantes A et B ;
  - ODM extérieur : sans taux, estimation à 14,5, paiement bloqué sans taux du jour, puis recalculé à 15 (1 650 000 GNF, frais 16 500 GNF) avec l'écart journalisé ;
  - ODM « Payé » quand tous ses bons le sont.
- **Total** : **247 tests PHP** et 66 tests JavaScript passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 13 vérifications.
  1. Philippe KOLIE crée un ODM à deux participants : 6 500 000 GNF.
  2. L'ODM est visé par le chef d'atelier, le DAF et le DP.
  3. Philippe génère deux BD et un BP de 500 000 GNF ; l'onglet Bons les montre en validation chez le chef de service.
  4. Un BD approuvé est payé en Orange Money par Youssouf TOURE (frais de 32 500 GNF) ; l'ODM reste « Bons générés », car deux bons restent à payer.

### 30.7 Déploiement

```bash
php artisan migrate        # bons_caisse : montant_fcfa, montant_gnf_fixe, taux_change_estime, taux_change_applique, montant_estime
php artisan optimize:clear
npm run build
```

## 31. Version v31 — Module M12, lot M12-5 : prolongations, vue mission, rappel (8 Octobre 2026)

Branche `m12-odm`. US-09 de la spec v2.2 (§7.3) ; RG-M12-17, RG-M12-18, RG-M12-19 et RG-M12-28 ; scénario SC-24, annexes B.3 et B.4.

### 31.1 Prolongation (`App\Services\Odm\ProlongerOdm`)

Sur la fiche du **dernier segment validé** d'une mission (statuts Validé, Bons générés ou Payé), son demandeur clique sur **Prolonger la mission** et indique la nouvelle date de retour.

- Un nouveau segment est créé **en brouillon**, lié au précédent et à l'ODM initial. Son rang est 2, 3…
- **Départ = retour du segment précédent + 1 jour**. Le départ et le type d'ODM sont figés.
- En-tête, OR et participants sont repris :
  - un participant peut être **retiré** : il reste sur le segment, marqué « retiré » ;
  - aucun participant ne peut être **ajouté** (MSG-APP-033).
- **Nuitée de rattrapage** (RG-M12-18) : une nuit d'hébergement du segment précédent, sur une ligne distincte, pour chaque participant qui n'y était pas logé sur base vie. Le message MSG-M12-06 s'affiche à la création et dans le bandeau du formulaire.
- Même circuit que l'ODM initial. **Numéro propre** à la soumission, et libellé « Prolongation n de N°xxx » (Q23). La liste de diffusion est notifiée de la prolongation (RG-M12-24).
- Refus (MSG-APP-034) :
  - le segment n'est pas encore validé ;
  - une prolongation est déjà en cours ;
  - l'utilisateur n'est pas le demandeur ;
  - le retour est antérieur au départ (MSG-M12-02).
- Exemple B.3 : le segment 1, du 22/09 au 26/09, est prolongé du 27/09 au 03/10. Le calcul donne 7 jours, 6 nuits et 1 nuitée de rattrapage, soit 5 250 000 GNF.

### 31.2 Vue mission (`App\Services\Odm\VueMission`, RG-M12-19)

L'onglet **Mission** de la fiche montre :
- les **segments** de la mission, du premier au dernier : numéro, libellé de prolongation, période, jours, total, statut ;
- les **cumuls par participant** : jours, nuits payées (nuits + nuitées de rattrapage), montant ;
- le **contrôle** nuits = jours − 1, hors jours où le participant n'est pas hébergé aux frais de Neemba (base vie, ou filiale d'accueil à l'étranger) ;
- le coût total de la mission.

Les brouillons, les segments rejetés et les segments annulés ne comptent pas.

**Signalement au DAF** : après la validation finale d'un segment, une incohérence sur la mission est notifiée au DAF et au DAF adjoint, par exemple « Incohérence sur la mission N°… : x nuits payées pour y jours. (participant) » (MSG-M12-07). Elle est aussi notée dans le journal de l'ODM initial.

Le calcul de la plateforme ne produit pas d'écart. Le contrôle repère une donnée faussée, ou une reprise de l'historique comme la mission KOUROUMA (annexe B.4) : 57 nuits payées pour 59 jours, signalées. Le calcul n'est pas adapté pour reproduire cet écart (PO-21).

### 31.3 Rappel avant la fin d'un segment (RG-M12-28)

La commande **`odm:rappeler-fin-segment`** est planifiée chaque jour à 7 h.
- Pour le dernier segment d'une mission en cours, elle envoie au demandeur, **2 jours ouvrés** avant le retour prévu (paramètre `odm_delai_rappel`), une notification : « La mission N°… se termine le … : prolongez-la si elle continue, ou clôturez-la au retour. »
- Le rappel n'est envoyé qu'une fois ; c'est tracé dans le journal.
- Un segment déjà prolongé ne reçoit pas de rappel.

### 31.4 Tests

- **`tests/Feature/M12/ProlongationOdmTest.php`** (7 tests) :
  - SC-24 / B.3 : prolongation, nuitée de rattrapage, numéro N°2/AT/26 et libellé, notification de la diffusion ;
  - vue mission (12 jours, 11 nuits, 8 500 000 GNF) ; seconde prolongation ;
  - participants : retrait, ajout refusé, base vie sans rattrapage ; type et départ figés ;
  - refus de prolongation ;
  - mission KOUROUMA (B.4) : écart signalé ;
  - incohérence signalée au DAF à la validation ;
  - rappel le jeudi 24/09 pour un retour le samedi 26/09, une seule fois ; pas de rappel pour un segment déjà prolongé.
- **Total** : **254 tests PHP** et 66 tests JavaScript passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 12 vérifications. Un ODM du 20/10 au 24/10 est validé, puis prolongé jusqu'au 28/10 :
  - le formulaire montre MSG-M12-06, le départ figé au 25/10, aucune recherche de participant et la ligne de rattrapage ;
  - le total du segment est de 3 000 000 GNF ;
  - la prolongation part en N°2/AT/26, puis est visée ;
  - l'onglet Mission indique 9 jours, « Cohérent (8 = 9 − 1) » et un coût de 6 250 000 GNF.

### 31.5 Déploiement

Aucune migration. `php artisan optimize:clear`, puis `npm run build`. Le planificateur lance `odm:rappeler-fin-segment` chaque jour à 7 h.

## 32. Version v32 — Module M12, lot M12-6 : clôture, annulation, hébergement à l'étranger (8 Octobre 2026)

Branche `m12-odm`. US-10 de la spec v2.2 (§7.3) ; RG-M12-20, RG-M12-22, RG-M12-26 et RG-M12-27 ; scénarios SC-25 et SC-29 ; décisions Q24 et Q26.

### 32.1 Clôture (`App\Services\Odm\CloturerOdm`, RG-M12-20)

Sur le **dernier segment validé** d'une mission, le demandeur clique sur **Clôturer la mission** et saisit la date de retour réelle.

| Retour | Effet |
|--------|-------|
| À la date prévue | Clôture simple : statut **Clôturé**, date de retour réelle et date de clôture enregistrées |
| Anticipé | Calcul refait au réel (jours et nuits réels, barèmes figés). Pour chaque participant **déjà payé** : trop-perçu = montant versé − montant réel (MSG-M12-09), à **reverser en caisse** ou à **retenir sur salaire** (choix à la clôture). Un bon **non encore payé** est annulé et remplacé par un bon au montant réel, soumis aussitôt (Q26) |
| Tardif | Refusé : « Retour après la date prévue : prolongez la mission avant de la clôturer. » (MSG-APP-036) |

- Un participant sans bon actif (bons jamais générés, ou bon remplacé) reçoit son bon au montant réel au moment de la clôture (Q47).
- Le calcul utilise désormais le retour réel s'il est saisi (`OrdreMission::dateFin()`).
- À l'étranger, le trop-perçu porte sur l'indemnité en FCFA, au taux appliqué au paiement ; l'hébergement ne dépend pas du nombre de jours.
- La clôture est notifiée à la liste de diffusion (RG-M12-24). Les caissiers sont prévenus d'un reversement attendu, les RH d'une retenue à opérer.
- Un segment déjà prolongé ne se clôture pas : la clôture se fait sur le dernier segment (MSG-APP-035).

### 32.2 Régularisation du trop-perçu (`RegulariserTropPercu`)

L'encadré « Trop-perçus à régulariser » de la fiche affiche, pour chaque participant concerné, le montant, le mode, l'état et la date de régularisation.

- **Reversement en caisse** : le caissier clique sur **Enregistrer le reversement**. Le montant est inscrit au registre de la caisse espèces du site, à défaut de la caisse principale de Conakry, avec la nouvelle nature `reversement_odm`. Le caissier peut ouvrir la fiche de l'ODM pour cela.
- **Retenue sur salaire** : les RH cliquent sur **Confirmer la retenue**.
- Personne d'autre ne peut régulariser (MSG-APP-040). Chaque régularisation est tracée dans le journal de l'ODM.

### 32.3 Annulation (`AnnulerOdm`, RG-M12-22)

- **Demandeur** : tant qu'aucun bon n'est généré (lot M12-2).
- **DAF ou DAF adjoint** : ensuite, avec un motif d'au moins 10 caractères (MSG-APP-039). Les bons non payés de l'ODM sont annulés, et leurs étapes de validation en attente sont retirées. Bouton **Annuler (DAF)** sur la fiche.
- Si un bon de l'ODM est payé, l'annulation est refusée (MSG-APP-037) : l'ODM se clôture avec régularisation.

### 32.4 Hébergement à l'étranger payé au retour (RG-M12-26, Q24)

Pour un ODM extérieur en mode « facture payée au retour », la fenêtre de clôture demande le montant de la facture de chaque participant.

- Un **bon complémentaire** est préparé **en brouillon** : BD, catégorie Hébergement, rattaché à l'ODM, au nom du participant, mode Orange Money.
- Le demandeur l'ouvre dans l'assistant, joint la facture (justificatif obligatoire, MSG-BC-017), puis le soumet ; le bon suit le circuit normal.
- Le bon reste rattaché à l'ODM quand on le modifie : `EnregistrementBon` ne retire plus le lien d'un BD.

La référence du billet ou du bon de commande Wanda (RG-M12-27) est saisie dans le formulaire depuis M12-2.

### 32.5 Correction

Dans le formulaire ODM, deux enregistrements automatiques rapprochés pouvaient créer **deux brouillons**, si le second partait avant la réponse du premier. Le parcours navigateur l'a révélé. La création est désormais unique : un enregistrement lancé pendant la création attend l'identifiant du brouillon, puis le met à jour.

### 32.6 Tests

- **`tests/Feature/M12/ClotureOdmTest.php`** (8 tests) :
  - clôture au retour prévu ; bons jamais générés créés au réel à la clôture ;
  - retour anticipé : trop-perçu de 1 500 000 GNF, bon non payé remplacé par un bon de 1 750 000 GNF, reversement inscrit au registre de la caisse espèces ;
  - retenue sur salaire confirmée par les RH ;
  - refus : retour tardif, retour avant le départ, autre utilisateur, segment déjà prolongé ;
  - annulation par le DAF, refusée si un bon est payé ;
  - hébergement payé au retour : bon complémentaire en brouillon, facture exigée, lien conservé.
- **Total** : **262 tests PHP** et 66 tests JavaScript passent.
- La migration a été vérifiée sur une copie de la base de développement (aller, retour, aller), puis appliquée.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 11 vérifications. On y passe successivement par :
  - un ODM à deux participants, du 20/10 au 24/10, clôturé au 22/10 : MSG-M12-09, bon non payé remplacé, statut Clôturé ;
  - le reversement de 1 500 000 GNF encaissé par Youssouf TOURE ;
  - l'annulation par Mohamed DIAKITE (DAF) d'un second ODM et de ses deux bons.

### 32.7 Déploiement

```bash
php artisan migrate        # odm_participants : regularise_le, regularise_par_id, bon_complement_id
php artisan optimize:clear
npm run build
```

## 33. Version v33 — Module M12, lot M12-7 : impression et tableau de bord (8 Octobre 2026)

Branche `m12-odm`. US-13, US-14 et US-15 de la spec v2.2 (§7.3) ; RG-M12-23 ; scénario SC-28 (reporting).

### 33.1 Impression (RG-M12-23)

Le bouton **Imprimer (PDF)** de la fiche d'un ODM numéroté (route `odm.pdf`, gabarit `resources/views/exports/odm-pdf.blade.php`, dompdf) produit deux pages :

1. **Ordre de mission — autorisation de circuler** :
   - en-tête : entité, site, service, numéro, mention « Prolongation n de N°… » ;
   - type, destinations, clients, but, période (retour réel s'il est saisi), véhicule, OR, prise en charge (« à refacturer »), hébergement et billet à l'étranger, code analytique, demandeur ;
   - personnel autorisé à circuler : nom, matricule, service, statut, base vie.
2. **Fiche d'indemnités** :
   - calcul par participant : jours, nuits et rattrapage, les deux lignes d'indemnité (ou FCFA et GNF à l'étranger), hébergement, total ;
   - total de l'ODM, avec le montant en lettres et le taux utilisé à l'étranger ;
   - la mention « Indemnités forfaitaires : aucun justificatif d'utilisation n'est exigé au retour » (RG-M12-29).

Chaque page reprend les **visas électroniques horodatés** de la version en cours : niveau, valideur, « au titre de », décision, date et heure.

La mise en page est sobre et fonctionnelle. Elle sera alignée sur les fiches papier N°282 et N°285/AT/26 dès qu'elles seront transmises (voir `docs/ORDRE_DE_MISSIONS.md`, « À obtenir »).

### 33.2 Tableau de bord (US-14)

Menu **Ordres de mission** → **Tableau de bord** (`Pages/Odm/TableauDeBord.jsx`, `App\Services\Odm\TableauBordOdm`). Accès : DAF, DAF adjoint, chef comptable, DP, DP adjoint, administrateur.

- **Indicateurs** : missions en cours, salariés en mission, coût des missions en cours, montant et nombre d'ODM à refacturer, missions aux nuits incohérentes (en rouge).
- **Missions en cours** : une ligne par mission, sur son dernier segment validé. On y lit le nombre de segments, le service, les destinations, les participants, la période, la durée cumulée, le coût cumulé (vue mission), le statut et une alerte d'incohérence.
  - Une prolongation encore en circuit ne retire pas la mission de la liste.
  - Une mission dont un segment est clôturé n'y figure plus.
- **Dérogations au chevauchement** demandées ou accordées : motif, décision, auteur, date.
- **ODM à refacturer** (à la charge du client, RG-M12-15) : clients, OR avec leur type, période, total, statut.
- **Export Excel** en trois onglets : missions en cours, dérogations, à refacturer (`App\Exports\TableauBordOdmExport`).

La Trésorerie voit le taux estimé et le taux appliqué de chaque bon dans l'onglet Bons de la fiche (US-15, depuis M12-4).

### 33.3 Tests

- **`tests/Feature/M12/ImpressionTableauOdmTest.php`** (2 tests) :
  - PDF : réponse `application/pdf`, accès refusé hors périmètre, contenu du gabarit (numéro, participant, OR, libellés, calcul figé, montant en lettres, visas des trois niveaux) ;
  - tableau de bord : droits, indicateurs, missions en cours (prolongation en brouillon sans effet, mission clôturée retirée), à refacturer, export Excel.
- **Total** : **264 tests PHP** et 66 tests JavaScript passent.
- **Parcours dans un navigateur (Edge)**, sur une copie jetable de la base : 8 vérifications.
  - Un ODM à la charge du client est validé, puis son PDF est produit.
  - Mohamed DIAKITE ouvre le tableau de bord : mission en cours, ODM à refacturer avec son OR, export Excel.
  - Le demandeur n'a pas accès au tableau de bord.

### 33.4 Déploiement

Aucune migration. `php artisan optimize:clear`, puis `npm run build`.

## 34. Version v34 — Module M12, lot M12-8 : recette et bilan du module « Ordres de mission » (8 Octobre 2026)

Branche `m12-odm`, partie de `lot5-suivi`. Commits locaux, non poussés, non déployés.

### 34.1 Scénarios de la spec (annexe A) et tests

| Scénario | Sujet | Tests |
|----------|-------|-------|
| SC-20 | ODM intérieur, une personne (B.1) | `CalculOdmTest`, `SaisieOdmTest`, `CircuitOdmTest` |
| SC-21 | ODM à plusieurs personnes | `SaisieOdmTest` |
| SC-22 | ODM technique avec OR | `SaisieOdmTest` |
| SC-23 | Génération des bons, individuels ou groupé (B.2) | `GenerationBonsOdmTest` |
| SC-24 | Prolongation, nuitée de rattrapage (B.3) | `CalculOdmTest`, `ProlongationOdmTest` |
| SC-25 | Ajustement au réel et clôture | `ClotureOdmTest` |
| SC-26 | Chevauchement de périodes | `SaisieOdmTest` |
| SC-27 | Mission sur base vie | `CalculOdmTest`, `SaisieOdmTest` |
| SC-28 | ODM à la charge du client | `GenerationBonsOdmTest`, `ImpressionTableauOdmTest` |
| SC-29 | ODM extérieur | `CalculOdmTest`, `SaisieOdmTest`, `GenerationBonsOdmTest`, `ClotureOdmTest` |
| SC-37 | ODM générant un BP | `GenerationBonsOdmTest` |

L'annexe B est vérifiée au franc près, dans `CalculOdmTest` (calcul) et dans les tests de génération et de paiement (frais OM et montants versés) :
- B.1 : 3 250 000 + 32 500 = 3 282 500 ;
- B.2 : 6 565 000 en deux bons, 6 552 000 en un bon groupé ;
- B.3 : prolongation à 5 250 000 et 5 292 000 versés ;
- B.4 : écart de la mission KOUROUMA signalé.

**Total** : **264 tests PHP** (90 pour M12) et **66 tests JavaScript** (7 pour M12) passent.

### 34.2 Parcours complet dans un navigateur (Edge)

Le parcours est rejoué sur une copie jetable de la base de développement (`.env.e2e`, port 8001, SMS et OpenAI désactivés). 15 vérifications, aucune erreur JavaScript :

1. Philippe KOLIE crée un ODM pour deux techniciens, dont un logé sur base vie. Le serveur calcule 4 500 000 GNF ; l'ODM est soumis en N°1/AT/26.
2. Thomas BANGOURA (chef d'atelier), Mohamed DIAKITE (DAF) et Mamadou LO (DP) visent : MSG-M12-08.
3. Philippe génère deux BD et un BP d'avance pour frais réels ; les trois partent dans leur circuit.
4. Youssouf TOURE paie le BD de Yacouba BARRY en Orange Money : 3 282 500 GNF versés.
5. Philippe prolonge la mission jusqu'au 31/10. MSG-M12-06 s'affiche : la nuitée de rattrapage ne concerne que le participant hors base vie. La prolongation est validée en N°2/AT/26.
6. Retour anticipé le 28/10 : la prolongation est clôturée et ses bons sont générés au montant réel.
7. Vue mission : 9 jours du 20/10 au 28/10 ; « Cohérent (8 = 9 − 1) » pour Yacouba ; contrôle sans objet pour le participant sur base vie.
8. Le PDF est produit ; le tableau de bord du DAF ne compte plus la mission parmi celles en cours.

Chaque lot a en outre été parcouru séparément (§26 à §33), soit au total 116 vérifications dans le navigateur. Ces parcours ont révélé et fait corriger plusieurs défauts : l'OTP sans service SMS, le matricule obligatoire à la modification d'un compte importé, et le brouillon d'ODM créé en double.

### 34.3 Bilan du module M12

Les 29 règles RG-M12, les 9 messages MSG-M12 et les 11 scénarios sont couverts. Le suivi détaillé est dans `docs/ORDRE_DE_MISSIONS.md`, entièrement coché, et les décisions provisoires Q22 à Q47 sont dans `docs/questions.md`.

| Lot | Contenu | devBook |
|-----|---------|---------|
| M12-0 | Frais Orange Money au paiement, taux du jour, jours ouvrés, paramètres ODM, rôles, n° OM | §26 |
| M12-1 | Modèle de données, calcul (annexe B), numérotation | §27 |
| M12-2 | Formulaire, soumission, chevauchement et dérogation | §28 |
| M12-3 | Circuit chef d'atelier → DAF → DP, suppléants, rejet, calcul figé, relances | §29 |
| M12-4 | Génération des bons, BP, prise en charge client, ODM extérieur au taux du jour | §30 |
| M12-5 | Prolongations, vue mission, rappel | §31 |
| M12-6 | Clôture, trop-perçu, annulation par le DAF, hébergement payé au retour | §32 |
| M12-7 | PDF, tableau de bord du DAF, export Excel | §33 |

**Migrations M12** : `2026_10_12_000001` à `000005`, appliquées à la base de développement après vérification sur une copie. La 000004 a d'abord été appliquée par erreur ; son aller-retour a été vérifié ensuite.

**Commandes planifiées ajoutées** :
- `odm:relancer-visas` (toutes les heures) ;
- `odm:rappeler-fin-segment` (chaque jour à 7 h).

### 34.4 Ce qui reste hors de ce chantier

| Point | Où / attendu de |
|-------|-----------------|
| Mise en page du PDF alignée sur les fiches papier N°282 et N°285/AT/26 | Fiches à transmettre par Neemba |
| Listes de diffusion, préfixes et derniers numéros des carnets de chaque service ; chefs d'atelier de chaque service | Neemba, puis Paramétrage → Services et Utilisateurs |
| Jours fériés mobiles de l'année (Q29) | Administrateur |
| Archivage des pièces et des PDF de l'ODM | Avec le module d'archivage (M15) |
| Avance carburant du véhicule de mission (US-12) | M13 |
| Délais de validation en heures ouvrées, DP adjoint sur les bons, numérotation des bons par caisse, et les autres écarts de la v2.2 sur M03 | Plan dédié (`docs/ORDRE_DE_MISSIONS.md`, §11) |
| Points ouverts PO-02 à PO-09 et PO-21 | Paramètres en place ; réponses de Neemba attendues |

### 34.5 Déploiement du module

```bash
php artisan migrate        # 5 migrations M12
php artisan optimize:clear
npm run build
```

Ensuite :
1. saisir le taux du jour (Trésorerie) ;
2. compléter les jours fériés ;
3. renseigner les services (préfixe, diffusion, carnet), les rôles « chef d'atelier » et les n° Orange Money des salariés ;
4. vérifier que le planificateur Laravel tourne (`schedule:run`).

## 35. Version v35 — Visa d'un bon par son bénéficiaire : étape sautée au lieu d'une erreur 403 (9 Octobre 2026)

Branche `m12-odm`. Commits locaux, non poussés, non déployés.

### 35.1 Le défaut signalé

Un chef de service, participant d'un ODM, recevait « 403 Forbidden » en voulant valider les bons générés pour la mission. Trois causes :
- **règle absente** : la spec v2.2 interdit de viser un bon dont on est demandeur ou bénéficiaire (RG-M01-04, RG-M03-24), et veut que le bon passe alors au suppléant, à défaut au niveau supérieur (RG-M04-09). Rien de cela n'existait pour les bons (c'était fait pour les ODM, Q37) ;
- **contrôle de service** : le bon relève du service émetteur de l'ODM, et le chef d'un autre service était refusé par un `abort(403)` sans explication. La fiche du bon lui montrait pourtant le bouton « Valider » ;
- **rôle principal seul** : un chef de service désigné par un rôle complémentaire n'était reconnu ni dans les listes, ni dans les notifications.

Dans la base de développement, les bons BC-2026-0011 à 0015 (ODM N°1/DIR/26, service Direction) attendaient un chef de service qui n'existe pas : la Direction n'en a aucun. Thierry GOMIS, chef de service Aftermarket et bénéficiaire de deux de ces bons, ne pouvait de toute façon pas les viser.

### 35.2 Ce qui change

`App\Services\BonCaisse\CircuitBon` décide désormais qui vise un bon :
- **titulaires** de l'étape : rôle principal ou complémentaire ; pour le chef de service, celui du service du bon ;
- **suppléants** : délégation active « validation » d'un titulaire ; le suppléant vise « au titre de » ce titulaire ;
- **incompatibilité** : le demandeur, l'initiateur et le bénéficiaire ne visent jamais le bon ;
- **étape sautée** (RG-M04-09) : quand personne ne peut viser l'étape, le bon passe au niveau supérieur. L'étape est notée « Sautée » (`validations.statut = saute`), avec le motif, dans la fiche et le journal. Le dernier niveau (Finance, ou DP au-delà du seuil) n'est jamais sauté : il attend un titulaire ou un suppléant.

Le saut est appliqué à la soumission (`SoumettreBon`, `BonCaisse::soumettre`), après chaque visa, et toutes les heures par la commande `bons:sauter-etapes` pour les bons déjà en attente (option `--simulation`).

Le même contrôle sert partout :
- visa, rejet et demande de complément : un refus affiche son motif au lieu d'un 403 (« Vous êtes demandeur ou bénéficiaire de ce bon… », « Ce bon relève du chef de service Direction… ») ;
- bouton « Valider » de la fiche, liste « À valider », bloc du tableau de bord ;
- notifications de soumission, de visa et de relance (SMS compris) : elles vont aux valideurs réels de l'étape en cours ;
- circuit prévisionnel : il nomme les suppléants (« X (suppléant de Y) ») et exclut demandeur et bénéficiaire.

Les rôles reçus par délégation comprennent aussi les rôles complémentaires du titulaire, limités aux rôles de validation.

### 35.3 Décision Q48

Un service sans chef de service désigné (ou dont le chef est inactif) : l'étape est sautée comme en RG-M04-09, avec le motif « aucun chef de service n'est désigné pour le service … ». Sinon, ses bons resteraient bloqués sans que personne ne soit prévenu. Révisable par Thierno ; la vraie réponse reste de désigner le chef de service de chaque service.

### 35.4 Tests et base de développement

- `tests/Feature/M03/IncompatibilitesValidationTest.php`, 8 tests : chef bénéficiaire sauté vers le CDG, refus expliqué sans 403, commande de reprise, suppléant au titre du titulaire, saut après un visa, dernier niveau jamais sauté, service sans chef, rôle complémentaire.
- Fixtures M12 complétées d'un chef de service et d'un CDG (sinon leurs étapes seraient sautées).
- **Total** : **272 tests PHP** et **66 tests JavaScript** passent.
- Migration `2026_10_13_000001_etape_bon_sautee` vérifiée sur une copie (aller, retour, aller), puis appliquée en dev. `bons:sauter-etapes` y a fait passer BC-2026-0011 à 0015 au CDG.

### 35.5 Déploiement

```bash
php artisan migrate
php artisan bons:sauter-etapes --simulation   # bons qui seront débloqués
php artisan bons:sauter-etapes
npm run build
```

## 36. Version v36 — ODM : prise en charge des frais ligne par ligne (9 Octobre 2026)

Branche `m12-prise-en-charge`, partie de `m12-odm`. Commits locaux, non poussés, non déployés.

### 36.1 La demande

La direction veut choisir, **pour chaque participant et pour chaque frais**, qui le prend en charge. Jusqu'ici, le choix « Frais à la charge de » valait pour tout l'ODM (Neemba ou client), et le paramètre « variante A / B » décidait du sort de l'argent pour tous les ODM du client.

Choix validés avec l'utilisateur :
- **payeurs** : Neemba ou le client ;
- **toutes les lignes** ont leur choix :
  - intérieur : indemnité de repas, indemnité de déplacement, hébergement, nuitée de rattrapage ;
  - extérieur : indemnité (FCFA), facture d'hébergement payée avant le départ, facture payée au retour ;
- **une ligne du client** se règle sur la ligne même, de deux façons :
  - avancée par Neemba, puis refacturée : elle reste dans le bon de caisse ;
  - payée directement par le client : elle sort du bon de caisse ;
- **le choix de l'en-tête** devient le défaut appliqué à toutes les lignes ; il affiche « Mixte » quand elles diffèrent.

Une ligne vaut donc `neemba`, `client_avance` ou `client_direct`.

### 36.2 Ce qui change

**Données** (migration `2026_10_14_000001_odm_prise_en_charge_par_ligne`) :
- `odm_participants` :
  - `prises_en_charge` (json, une valeur par ligne) ;
  - `montant_bon`, `montant_refacturable`, `montant_client_direct` ;
- `ordres_mission` :
  - `prise_en_charge` accepte `mixte` ;
  - `mode_client` (avance ou direct) ;
  - `montant_a_refacturer`.

Reprise des ODM existants : un ODM « client » passe toutes ses lignes en avance (variante A) ou en paiement direct (variante B), selon le paramètre en vigueur. Le paramètre garde sa clé et devient « Ligne à la charge du client : mode proposé par défaut ».

**Calcul** (`CalculOdm`, toujours pur) :
- `lignes()` : lignes présentes et montants ;
- `repartition()` : bon de caisse = lignes Neemba + lignes client avancées ; à refacturer = lignes client avancées ; payé par le client = lignes client directes ;
- `priseEnChargeGlobale()` : en-tête déduit des lignes qui ont un montant (une ligne « sans objet », comme l'hébergement sur base vie, ne compte pas).

Le `total` reste le coût complet de la mission (vue mission, contrôle des nuits, PDF). Les frais Orange Money portent sur ce que Neemba verse.

**Bons et suite** :
- **bon par participant** : `montant_bon` ; un participant dont le client paie tout n'a pas de bon ; si c'est le cas de tous, aucun bon (MSG-APP-028 reformulé) ;
- **bon groupé** : somme des `montant_bon` ;
- **motif** : « hors X GNF payés par le client » quand il y en a ;
- **ODM extérieur** : l'indemnité payée par le client sort de la part en FCFA recalculée au paiement ;
- **« à refacturer »** : vrai dès qu'une ligne du client est avancée ; montant au tableau de bord et à l'export ;
- **clôture** : trop-perçu calculé sur le montant du bon, et non plus sur le total ; facture payée au retour : pas de bon complémentaire si le client la paie directement, ajout au « à refacturer » s'il est avancé (Q50) ;
- **prolongation** : choix repris ; la nuitée de rattrapage suit le choix de l'hébergement ;
- **soumission** : une ligne du client exige le client (MSG-APP-042, Q51).

**Écrans** :
- **en-tête** : sélecteur Neemba / Client, puis « Avancé · refacturé » ou « Payé par le client ». Il s'applique à toutes les lignes, avec le message « Appliqué aux n lignes de frais ». Une confirmation est demandée si des lignes avaient été ajustées une à une ;
- **carte « Prise en charge des frais »** :
  - une section par participant, chaque ligne avec son montant serveur et son choix ;
  - boutons « Tout Neemba » et « Tout client » par participant et pour tous ;
  - lignes « sans objet » grisées ;
  - ventilation en pied de section : bon de caisse, à refacturer, payé par le client ;
- **panneau Calcul** : étiquette violette « Client » ou « Client · direct » sur les lignes concernées, ventilation par participant et pour l'ODM ;
- **fiche** :
  - badge « À refacturer · montant » ;
  - résumé « n lignes sur m à la charge du client » ;
  - onglet Calcul avec les choix en lecture seule ;
  - génération des bons au montant versé, « Aucun bon : frais payés par le client » ;
- **PDF** : repères (C) et (CD), légende et ventilation ;
- **tableau de bord du DAF et export** : colonnes « À refacturer », « Payé par le client » et coût de la mission.

### 36.3 Tests et vérifications

- `tests/Unit/CalculOdmTest.php` : 6 tests de plus (défaut Neemba, hébergement payé par le client, avance refacturée, tout payé par le client et valeurs invalides, extérieur avec et sans taux, en-tête déduit).
- `tests/Feature/M12/PriseEnChargeOdmTest.php` : 8 tests (choix par ligne et « mixte », défaut de l'en-tête, client obligatoire, bons hors paiement direct, à refacturer et tableau de bord, extérieur, trop-perçu, prolongation).
- `tests/js/odm.test.js` : 5 tests Vitest des utilitaires (choix global, par ligne, par participant, état « mixte », nouveau participant).
- **Total** : **286 tests PHP** et **71 tests JavaScript** passent.
- **Migration** : vérifiée sur une copie de la base de dev (aller, retour, aller, et reprise d'un ODM « client »), puis appliquée en dev.
- **Parcours dans Edge** (instance jetable) : 21 vérifications, aucune erreur JavaScript :
  1. choix global « Client » appliqué aux 5 lignes ;
  2. ajustements et « Mixte » ;
  3. confirmation avant écrasement ;
  4. soumission bloquée sans client ;
  5. visas, puis un seul bon de 3 250 000 GNF : le participant payé directement n'en a pas ;
  6. badge « À refacturer · 1 250 000 GNF » ;
  7. PDF et tableau de bord ;
  8. affichage sur téléphone (375 px).

### 36.4 Déploiement

```bash
php artisan migrate
php artisan optimize:clear
npm run build
```

Vérifier ensuite, dans Paramétrage → Ordres de mission, le mode proposé par défaut pour une ligne du client.

## 37. Version v37 — Spec v2.3 : prise en charge des frais d'ODM par lignes et par parts, référentiel clients (12 Octobre 2026)

Branche `m12-v23`, partie de `m12-prise-en-charge`. Commits locaux, non poussés, non déployés.

### 37.1 Ce que change la v2.3

`docs/Spec_Fonctionnelle_v2.3_Neemba_P2.docx` (09/10) ne change que la prise en charge des frais d'ODM (décision du 09/10). Elle **remplace le modèle livré au §36** :

| Règle | Contenu |
|---|---|
| RG-M12-05 | La prise en charge quitte l'en-tête de l'ODM ; Neemba 100 % par défaut. |
| RG-M12-15 | Variante A (défaut) : bons pour le **montant total**, ODM « à refacturer ». Variante B : aucun bon, seulement si **toutes** les parts sont client. |
| RG-M12-30 | Lignes de frais : participant × segment × élément (indemnité, hébergement, rattrapage, hébergement à l'étranger payé en GNF), avec quantité, taux, montant. |
| RG-M12-31 | Parts : payeur, client (référentiel), OR, code analytique, montant ou pourcentage ; somme ≠ ligne : soumission bloquée. |
| RG-M12-32 | Saisie rapide sur un participant ou tout l'ODM. |
| RG-M12-33 | Client de l'OR proposé ; client différent ou part client sans OR : avertissement, commentaire obligatoire. |
| RG-M12-34 | Récapitulatif Neemba / client pour les valideurs ; lecture pour caissier, Comptabilité, CDG, Trésorerie, DAF. |
| RG-M12-35 | Figement à la validation, reprise en prolongation, retour anticipé au prorata, traçabilité. |
| RG-M12-36 | Frais OM répartis entre les parts (prorata par défaut, ou 100 % Neemba, PO-24). |
| RG-M12-37 | « À refacturer » dès qu'une part est client ; montant par client et par OR. |
| §6.5 | Référentiel clients ; plus de saisie libre sur l'ODM ; OR rattaché à un client. |

Choix de l'utilisateur (12/10) :
- **alignement sur la v2.3** : le « payé directement par le client » du §36 est retiré ;
- **indemnité** : une seule ligne, l'affichage en deux lignes de 125 000 restant dans le calcul et le PDF ;
- **périmètre** : M12 complet, tableau de bord et export ; M16 (imputation) plus tard ;
- **référentiel clients** : écran avec import Excel ou CSV.

### 37.2 Référentiel clients (lot PC-1)

- **Données** :
  - table `clients` (code unique, raison sociale, actif) ;
  - table `odm_clients` : clients de la mission ;
  - `odm_ordres_reparation.client_id`.
- **Reprise** : les noms saisis librement deviennent des clients `REP-…` (Q54). En dev, `REP-SMD` et `REP-CBG`.
- **Écran `Referentiels/Clients`** (chef comptable, CDG, administrateur ; menu « Clients ») :
  - recherche, ajout, modification, désactivation ;
  - import Excel ou CSV (point-virgule détecté) avec aperçu : créés, mis à jour, inchangés, ignorés avec leur motif.
- **ODM** :
  - les clients et le client de chaque OR se choisissent dans le référentiel (`ChoixClient`, recherche `api.referentiels.clients`) ;
  - un client choisi sur un OR rejoint les clients de la mission ;
  - un client désactivé n'est plus proposé, mais reste sur les ODM qui le portent.
- **OR** : ils sont désormais mis à jour par numéro (et non recréés), car les parts les référencent.

### 37.3 Lignes de frais et parts (lots PC-2 à PC-4)

- **Données** :
  - `odm_lignes_frais` : élément, quantité, taux, devise, montant ;
  - `odm_parts` : payeur, client, OR, code analytique (null : celui de l'ODM), pourcentage, montant, commentaire ;
  - `ordres_mission.montant_neemba`.
  - Supprimés : `prise_en_charge`, `mode_client` et les colonnes du §36.
  - Reprise : une part par ligne, Neemba ou client (premier client de l'ODM).
- **Calcul** :
  - `CalculOdm::lignesFrais`, `repartirLigne`, `recapitulatif`, `repartirFrais` ;
  - service `RepartitionOdm` : synchronisation des lignes, parts saisies, totaux, photographie, récapitulatif, parts d'un bon.
  - **Le pourcentage fait foi** (Q52) : la part suit la ligne quand les dates, le retour réel ou le taux du paiement changent. La part la plus forte reçoit l'arrondi.
- **Règles de soumission** : répartition ≠ 100 % (MSG-APP-043), part client sans client (MSG-APP-042), avertissement sans commentaire (MSG-APP-044), code analytique inconnu (MSG-APP-045).
- **Circuit** :
  - récapitulatif dans le bloc de visa et en tête de la fiche ;
  - caissier et CDG en lecture ;
  - « à refacturer » posé à la validation finale.
- **Traçabilité (Q53)** : photographie de la répartition à la soumission et à la validation. Chaque modification après la première soumission est inscrite au journal (action « Répartition des frais », parts avant et après).
- **Bons** :
  - au total (variante A) ; variante B seulement si tout est client ;
  - ODM extérieur : la ligne d'indemnité prend le montant au taux du paiement ;
  - clôture : retour anticipé au prorata ; la facture payée au retour devient le montant de sa ligne.
- **Prolongation** : parts reprises (OR du segment) ; la nuitée de rattrapage reprend celles de l'hébergement.

### 37.4 Écrans, reporting, PDF (lots PC-3 et PC-5)

- **Formulaire** :
  - carte « Prise en charge des frais » : par participant, lignes « quantité × taux », pastille « Neemba 100 % » et bouton « Répartir » ;
  - éditeur de parts : payeur, client, OR, code analytique, % ou GNF, avertissement avec commentaire, « Reste à répartir » ;
  - saisie rapide « Appliquer à ses lignes / à tout l'ODM » dans une boîte de dialogue.
- **Panneau Calcul** : total, puis part Neemba et part de chaque client.
- **Fiche du bon** : répartition des lignes payées, en lecture seule, avec les frais OM répartis.
- **Tableau de bord** : « À refacturer par client et par OR » (montant et part déjà payée).
- **Export** : onglet « Frais à refacturer », une ligne par part client.
- **PDF** : parts client sous chaque participant et récapitulatif.

### 37.5 Tests et vérifications

- **Tests** :
  - `ClientsTest` (3) ;
  - `RepartitionV23Test` (12) ;
  - `CalculOdmTest` réécrit pour la v2.3 (5 tests de plus) ;
  - Vitest : 5 tests des utilitaires de répartition ;
  - tests M12 existants adaptés.
- **Total** : **292 tests PHP** et **71 tests JavaScript** passent.
- **Migrations** `2026_10_15_000001` et `000002` :
  - vérifiées sur une copie de la base de dev (aller, retour, aller ; reprise de l'ODM N°2/DIR/26 « mixte ») ;
  - appliquées en dev, totaux inchangés.
- **Parcours dans Edge** (instance jetable) : 19 vérifications, aucune erreur JavaScript.
  1. client SAG créé ;
  2. OR rattaché à SAG ;
  3. hébergement 60/40 avec le client de l'OR proposé ;
  4. saisie rapide, avertissement et commentaire obligatoire ;
  5. récapitulatif du chef d'atelier (SAG 2 050 000 GNF) ;
  6. bon au total, répartition sur le bon ;
  7. tableau de bord par client et OR, export ;
  8. affichage sur téléphone.

### 37.6 Hors de ce chantier

- **M16 (imputation comptable)** : écritures par part, compte de charge selon l'élément, compte refacturable (paramètre `compte_frais_refacturables`, PO-25), corrections par la Comptabilité (RG-M16-05 et 06), colonnes payeur / client / OR à l'export des écritures (RG-M16-03).
- **Export Excel général des bons (M14)**, avec une ligne par part.
- **Fichier des clients de Neemba (PO-26)**, à importer dès réception.

### 37.7 Déploiement

```bash
php artisan migrate        # 2026_10_15_000001 (clients) et 000002 (lignes et parts)
php artisan optimize:clear
npm run build
```

Ensuite :
- importer la liste des clients (écran Clients) ;
- rattacher les OR en cours à leur client ;
- vérifier les paramètres « Frais Orange Money sur un ODM partagé » et « ODM à la charge de clients ».

## 38. Version v38 — M16 imputation comptable et export comptable ; M14 export Excel des bons (12 Octobre 2026)

Branche `m16-imputation`, partie de `m12-v23`. Commits locaux, non poussés, non déployés.

### 38.1 Périmètre

Ce lot traite ce qui restait du chantier v2.3 (devBook §37.6).
- **M16** (spec v2.3, §8.11) : RG-M16-01 à 06.
- **M14** (§8.9) : export Excel des bons au format opérationnel, une ligne par part pour les bons d'ODM.
- **Hors lot** : le fichier des clients de Neemba (PO-26), qui n'est pas encore fourni ; l'écran d'import des clients est prêt (§37.2).

Choix de l'utilisateur (12/10) :
- **nouveau rôle « Comptabilité »** (`comptable`) : rôle complémentaire, aussi reconnu à l'import des référentiels. Le Chef comptable, le DAF et le DAF adjoint imputent aussi ;
- **code journal par mode de paiement** : paramètres « code journal espèces / Orange Money / MTN Money / chèque / virement ».

### 38.2 Données

Migration `2026_10_16_000001_m16_imputation_comptable` :
- **`imputations`** : un bon, statut (brouillon, validée, exportée), code journal, motif de correction, saisie, validation, export ;
- **`lignes_imputation`** : nature (dépense, frais OM), compte, libellé, montant, code analytique ; pour un bon d'ODM, payeur, client, OR et ligne de frais ;
- **`exports_comptables`** : période, nombre d'écritures, montant, auteur ;
- **paramètres du nouveau groupe « Comptabilité (M16) »** :
  - comptes : `compte_bp` (421100), `compte_frais_om` (PO-17), `compte_odm_indemnite`, `compte_odm_hebergement`, `compte_frais_refacturables` (PO-25, venu du groupe ODM) ;
  - codes journaux : `code_journal_*`, un par mode de paiement ;
  - délai : `imputation_delai_alerte` (1 jour ouvré).

  Les comptes et codes journaux restent vides tant que la Comptabilité ne les a pas fournis. L'écran les signale.

### 38.3 Règles (service `App\Services\Comptabilite\ImputationBon`)

| Règle | Mise en œuvre |
|---|---|
| RG-M16-01 | Une ou plusieurs lignes ; à la validation : compte obligatoire (MSG-APP-047), somme = montant versé, frais OM compris (MSG-APP-048) ; un bon non payé ne s'impute pas (MSG-APP-051). |
| RG-M16-02 | Pré-remplissage : BP au 421100, frais OM au compte paramétré, code journal selon le mode de paiement retenu. |
| RG-M16-03 | Export Excel ou CSV générique (point-virgule, BOM UTF-8) : date, n° de bon, compte, libellé, montant, code journal, code analytique, payeur, client, OR. Les imputations exportées sont figées ; chaque export reste téléchargeable. |
| RG-M16-04 | Commande `comptabilite:alerter-non-imputes`, chaque jour ouvré à 8 h : notification aux Chefs comptables pour les bons payés non imputés au-delà de J+1 ouvré. |
| RG-M16-05 | Bon d'ODM : une ligne par part. Part Neemba au compte de l'élément (indemnité, ou hébergement / rattrapage), part client au compte des frais refacturables, avec code analytique, client et OR. Frais OM répartis selon le paramètre (RG-M12-36), part client refacturable. |
| RG-M16-06 | Tant que l'écriture n'est pas exportée, la Comptabilité peut corriger la répartition (payeur, client, OR, code analytique, montants, partage d'une ligne). Motif obligatoire (MSG-APP-050) ; chaque ligne de frais reste entière (MSG-APP-049). L'ODM reprend les parts corrigées : journal « Répartition des frais », source « imputation », avant / après et motif. Une écriture exportée ne se modifie plus (MSG-APP-046). |

### 38.4 Écrans

- **« Imputation comptable »** (menu ; Comptabilité, Chef comptable, DAF, DAF adjoint) :
  - compteurs : à imputer, en retard, à exporter ;
  - onglets « À imputer », « À exporter », « Exportées » ;
  - période par défaut : depuis le plus ancien bon non imputé, pour qu'aucun retard ne soit masqué ;
  - export Excel ou CSV et historique des exports ;
  - bandeau des paramètres comptables encore vides.
- **Imputation d'un bon** :
  - en-tête du bon : montant, frais OM, montant versé, mode et code journal, lien vers l'ODM ;
  - lignes modifiables, avec ajout, partage d'une ligne d'ODM et suppression ;
  - total comparé au montant versé ;
  - pour un bon d'ODM, contrôle de chaque ligne de frais et motif de correction ;
  - lecture seule une fois exportée.
- **Fiche du bon** : bouton « Imputation comptable » avec son état ; la Comptabilité consulte les bons payés.
- **Liste des bons** : bouton « Exporter (Excel) » (M14). Il exporte la liste filtrée, avec la même visibilité ; il est proposé à la Finance, la Comptabilité, au CDG, à la Direction, à la Trésorerie et à l'administrateur.
  - Colonnes : n° de bon, date, type, statut, bénéficiaire, motif, montant, frais OM, montant versé, caisse, site, service, code analytique, OR, mode, date de paiement, délais par étape (heures), puis ODM, élément, payeur, client, OR et code analytique de la part, montant de la part.
  - Un bon d'ODM donne une ligne par part.

### 38.5 Tests et vérifications

- **`tests/Feature/M16/ImputationComptableTest.php`** (7 tests) :
  1. droits et liste ;
  2. contrôles et validation ;
  3. BP et frais OM pré-remplis ;
  4. bon d'ODM avec une ligne par part, puis correction avec motif reportée sur l'ODM ;
  5. export qui fige les écritures ;
  6. alerte J+1 ouvré ;
  7. export Excel des bons.
- **Total** : **299 tests PHP** et **71 tests JavaScript** passent.
- **Migration** : vérifiée sur une copie de la base de dev (aller, retour, aller), puis appliquée en dev.
- **Parcours dans Edge** (instance jetable) : 14 vérifications, aucune erreur JavaScript.
  1. liste et retards ;
  2. BP au 421100 ;
  3. compte obligatoire, ligne partagée ;
  4. export Excel des écritures, puis bon en lecture seule ;
  5. export des bons ;
  6. affichage sur téléphone.

  Le parcours a révélé un défaut, corrigé : la période par défaut (30 derniers jours) masquait des bons de juin non imputés.

### 38.6 Déploiement

```bash
php artisan migrate        # 2026_10_16_000001
php artisan optimize:clear
npm run build
```

Ensuite :
- renseigner les comptes et codes journaux dans Paramétrage → Comptabilité ;
- attribuer le rôle « Comptabilité » aux comptables ;
- vérifier que le planificateur tourne (alerte de 8 h).

## 39. Version v39 — PDF de l'ordre de mission sur le modèle papier de Neemba (13 Octobre 2026)

Branche `odm-pdf`, partie de `m16-imputation`. Commits locaux, non poussés, non déployés.

### 39.1 Origine

Neemba a fourni son formulaire papier, `docs/ODM .xlsm` (fiches N°285 et N°282, non commitées). Le PDF de l'ODM (RG-M12-23) en reprend les rubriques, aux couleurs de l'application.

Choix de l'utilisateur (13/10) :
- **une seule page pour l'ODM**, avec tous les passagers et une ligne de calcul par participant ;
- **rubrique « Service concerné »** cochée selon le service émetteur : Technique et Aftermarket → SERVICE, Location → LOCATION, Logistique → LOGISTIQUE, les autres → ADMINISTRATION ; BUS n'est jamais coché ;
- **signatures** : le visa électronique (nom, date et heure, état), plus un cadre pour une signature ou un cachet manuscrit.

### 39.2 Contenu du PDF

Dans l'ordre du formulaire papier :
1. **En-tête** : logo (`public/logo.png`) et adresse de Neemba Guinée ; titre « ORDRE DE MISSION », puis « INDEMNITÉS N°… du <date> ».
   - La date est celle de la validation, à défaut celle de la soumission.
   - Une prolongation affiche son libellé et le n° du segment précédent ; un ODM extérieur porte la pastille « Mission à l'étranger ».
2. **Phrase** « Autorisons la(es) personne(s) suivante(s) à se rendre en mission à : » + destinations.
3. **Service concerné** : n° d'OR et les 5 cases.
4. **Mission** : clients, demandeur, destinations, type, but, dates, véhicule, code analytique.
5. **Prise en charge** : une ligne par indemnité (repas, déplacement ; une seule ligne pour un ODM extérieur), plus l'hébergement.
   - Colonnes Neemba / client, avec le montant et les codes clients, calculés à partir des parts v2.3 ; le pourcentage fait foi.
   - « Autres à préciser » : base vie, hébergement à l'étranger, billet.
6. **Passagers** : noms et prénoms séparés, matricule, service, n° Orange Money.
7. **Calcul** : barèmes en en-tête (125 000 / jour, 500 000 / nuit), une ligne par participant (montant, frais de retrait OM, total versé), ligne TOTAL, montant en lettres ; encadré du montant de l'ODM avec la répartition Neemba / clients (RG-M12-34).
8. **Visas et signatures** : le demandeur, puis une colonne par étape du circuit de la version en cours ; avant la soumission, le circuit prévu, « À venir ».
9. **Pied de page** sur chaque page : n° de l'ODM, date d'édition, « Page x / y ».
   - Le numéro de page est écrit par le contrôleur, car DomPDF ne connaît pas `counter(pages)` en CSS.
10. **Filigrane** tant que l'ODM n'est pas validé : « BROUILLON », « NON VALIDÉ » (en circuit ou rejeté) ou « ANNULÉ ».

**Charte graphique**, reprise de `tailwind.config.js` :
- or `#fdc911` : bandeau, filets, cases cochées ;
- marine `#0f172a` / `#1e3a8a` : titres, en-têtes de tableau, encadré du montant ;
- violet : parts client ;
- police : DejaVu Sans.

### 39.3 Code

- **`PresentationOdm::pdf()`** s'appuie sur `detail()` et ajoute :
  - la rubrique ;
  - la grille de prise en charge (`priseEnCharge`) ;
  - les passagers ;
  - la date du titre ;
  - le filigrane ;
  - les signataires (`signataires`) ;
  - l'adresse (`ADRESSE_PDF`).
- **`OrdreMission`** : `RUBRIQUES_PDF`, `RUBRIQUE_PAR_SERVICE`, `STATUTS_VALIDES`.
- **`resources/views/exports/odm-pdf.blade.php`** est réécrit : une page, sans la fiche d'indemnités séparée.
- **Piège DomPDF** : une remise à zéro `* { margin: 0 }` annule les marges de `@page`, qui sont portées par la racine du document.

### 39.4 Vérifications

- **`ImpressionTableauOdmTest`** : le test du PDF est mis à jour et 3 tests sont ajoutés (rubrique selon le service, prise en charge mixte avec code client, filigrane d'un brouillon).
- **Total** : **302 tests PHP**, tous verts.
- **Relecture visuelle** des PDF convertis en images, sur 5 cas :
  - équivalent de la fiche N°285 : 28 jours en base vie, 7 000 000 + 56 000 = 7 056 000 GNF ;
  - équivalent de la fiche N°282 : 11 jours, 10 nuits ;
  - 6 participants avec prise en charge mixte (deux pages, signatures non coupées) ;
  - ODM extérieur ;
  - brouillon.

## 40. Version v40 — Génération sélective des bons d'un ODM ; accès à l'ODM depuis la fiche du bon (13 Octobre 2026)

Branche `odm-generation-selective`, partie de `odm-pdf`. Commits locaux, non poussés, non déployés.

### 40.1 Demande et choix de l'utilisateur (13/10)

- **Génération des bons depuis l'ODM** : globale, mais aussi individuelle (un bon) ou par sélection (cases à cocher).
- **Bons générés** : soumis aussitôt, comme avant ; pas d'étape de brouillon.
- **Mode groupé** (paramètre `odm_mode_generation`) : la sélection forme un bon groupé, versé au participant désigné parmi les participants choisis. Les autres pourront former un autre bon groupé.
- **Fiche du bon** : le lien vers l'ODM, jusque-là un petit lien en bas de page, devient visible.

### 40.2 Génération (`GenererBonsOdm`)

- **Option `participants`** (identifiants d'utilisateurs) :
  - absente : tous les participants sans bon actif ;
  - liste : ces participants seulement ;
  - vide : le bon provisoire seul.
- **Participant étranger à l'ODM** : refusé avec MSG-APP-053. Les participants qui ont déjà leur bon sont ignorés ; si plus rien n'est à générer, MSG-APP-030.
- **`participantsSansBon()`** : participants présents sans bon actif (jamais généré, ou annulé). Il sert :
  - à la génération ;
  - à la clôture (bons au réel, Q26) ;
  - au statut « Payé ».
- **Mode groupé** : `bonGroupe()` reçoit les participants retenus. Avant, un seul bon groupé était possible par ODM.
- **Correction RG-M12-21** (`PaiementOdm::apresPaiement`) : l'ODM ne passe « Payé » que si tous ses bons actifs sont payés **et** que chaque participant a le sien. Sans cela, une génération partielle aurait rendu l'ODM « Payé », et la génération du reste serait devenue impossible.

### 40.3 Écrans

- **Fiche de l'ODM, bloc « Bons de caisse de l'ordre de mission »** :
  - progression « x / n bons générés » ;
  - tableau des participants : case à cocher, nom et n° OM, montant, bon généré (numéro, statut, lien) ou « À générer », bouton « Générer ce bon » ;
  - boutons « Générer la sélection (n) · total » et « Générer tous les bons (m) » ;
  - fenêtre de confirmation : liste et total, bénéficiaire du bon groupé, bon provisoire éventuel.
- **Fiche du bon** :
  - bandeau en tête, aux couleurs Neemba : « Bon généré depuis un ordre de mission », n°, destinations, période, nombre de participants, statut, bouton « Ouvrir l'ordre de mission » ;
  - bouton « Voir l'ordre de mission » dans la section « Ordre de mission » ;
  - le bouton n'est proposé qu'à qui peut ouvrir l'ODM (`estVisiblePar`).

### 40.4 Vérifications

- **`GenerationBonsOdmTest`** : 4 tests ajoutés.
  1. génération sélective puis reste, avec participant étranger refusé ;
  2. bon groupé par sélection ;
  3. pas « Payé » tant qu'un participant attend son bon ;
  4. bandeau de l'ODM sur la fiche du bon.
- **Total** : **306 tests PHP** et **71 tests JavaScript** passent.
- **Parcours dans Edge** (instance jetable) : 17 vérifications, aucune erreur JavaScript.
  1. ODM à 3 participants ;
  2. un bon seul ;
  3. sélection ;
  4. « Générer tous les bons » ;
  5. bandeau et accès à l'ODM depuis le bon ;
  6. téléphone, sans défilement horizontal.

## 41. Version v41 — Conformité v2.3, lot A « contrôle interne » (17 Octobre 2026)

Branche `v23-conformite`, partie de `odm-generation-selective`. Commits locaux, non poussés, non déployés.

### 41.1 Origine

Le 14/10, un audit du code contre la spec v2.3 (modules M01 à M15, hors M12, M14 et M16 déjà faits) a relevé :
- M01–M03 : 11 règles faites, 17 partielles, 13 absentes ;
- M04–M07 : 8 faites, 12 partielles, 9 absentes ;
- M08–M15 : environ 10 faites, 30 partielles, 30 absentes.

Ordre retenu par l'utilisateur :
- A — contrôle interne ;
- B — bénéficiaires et tiers ;
- C — numérotation par caisse ;
- D — saisie et paiement ;
- E — régularisation des BP ;
- F — caisses (M08 à M10) ;
- G — gouvernance ;
- H — carburant (M13).

Décisions pour les lots suivants (Q62) :
- numérotation par caisse : les anciens numéros restent tels quels ;
- tiers déjà saisis en texte libre : repris comme tiers « proposés », à valider.

Commit préalable `c6d2bfd` (failles relevées par l'audit) :
- **régularisation d'un BP** : la route n'avait aucun contrôle ; elle est réservée au demandeur ;
- **PDF d'un bon** : il suit désormais les droits de la fiche ;
- **vue PDF du bon** : elle plantait depuis le lot 1 (`$seuildp`), elle est réparée.

### 41.2 Règles traitées

| Règle | Mise en œuvre |
|---|---|
| §8.2, RG-M05-04 | `User::ROLES_ETAPES_BON` donne les rôles de chaque étape. **Finance** : DAF, DAF adjoint ou Chef comptable. **Directeur Pays** : DP ou DP adjoint. Ces rôles servent aux titulaires (`CircuitBon::titulaires`), aux rôles effectifs (`rolesValidationEffectifs`), aux délégations et à la route « Validations ». |
| RG-M06-01, RG-M08-12, RG-M01-04 | `ControleCaissier::refus` : le caissier paie les bons de son site. Si la caisse a un gestionnaire désigné, seuls le gestionnaire, le suppléant ou leur délégué au paiement la tiennent. Le caissier ne paie jamais un bon dont il est bénéficiaire. Le contrôle s'applique à l'OTP, à sa vérification et au paiement. La fiche affiche le motif à la place du formulaire. |
| RG-M04-07 | `AnnulerBon::peutAnnuler` : le demandeur ou l'initiateur annule tant qu'aucun visa n'est donné (une étape sautée ne compte pas comme un visa). Ensuite, seuls le DAF ou le DAF adjoint annulent, jusqu'à l'approbation, avec un motif tracé « Annulé par le DAF ». Un bon payé ne s'annule jamais. |
| RG-M03-04 | Un BP n'est émis qu'au profit d'un salarié (MSG-M03-02). Les visiteurs du groupe viendront au lot B. |
| RG-M03-26 | Une pièce déjà soumise bloque la soumission (MSG-M03-16). La confirmation de la SFD v1.3 est refusée, et l'écran demande de retirer ou de remplacer la pièce. |
| RG-M03-20, RG-M04-05 | Contrôle 13 : même bénéficiaire et même montant sur `doublon_delai_jours` (7 jours par défaut). Il bloque jusqu'à ce que le demandeur confirme ; la confirmation est conservée dans `bons_caisse.doublon_probable`. Les valideurs voient l'alerte sur la fiche. Les bons d'ODM n'y sont pas soumis. |
| RG-M03-21, RG-M07-06, RG-M04-05 | Contrôle 14 : BP du bénéficiaire en retard. Le paramètre `blocage_bp_en_retard` vaut aucun (alerte), partiel (BP bloqué, BD signalé ; valeur par défaut) ou total. Le demandeur peut demander une dérogation au DAF, avec un motif ; le DAF est notifié. Le DAF ou son adjoint l'accorde ou la refuse depuis la fiche, avec un motif. Tout est tracé dans `bons_caisse.derogation_bp` et l'historique. Un bandeau signale le BP en retard aux valideurs. |
| RG-M03-17 | Seuil DP de repli : 1 500 000 GNF dans `Parametre::seuilDP()`. **La valeur réelle en production est à vérifier**, car la migration d'origine insère 5 000 000. |

### 41.3 Données

Migration `2026_10_17_000001_lot_a_controle_interne` :
- colonnes `bons_caisse.doublon_probable` et `derogation_bp` (JSON) ;
- paramètres `doublon_delai_jours` et `blocage_bp_en_retard` (groupe « Bons de caisse »).

Elle a été vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.

### 41.4 Vérifications

- **`tests/Feature/LotA/ControleInterneTest.php`** (6 tests) :
  1. adjoints ;
  2. caissier de site et bénéficiaire ;
  3. gestionnaire de caisse ;
  4. BP pour un tiers ;
  5. doublon probable ;
  6. BP en retard et dérogation.
- **Tests adaptés** : pièce déjà soumise bloquante, annulation d'un bon soumis.
- **Total** : **315 tests PHP** et **71 tests JavaScript** passent.
- **Parcours dans Edge**, sur une copie de la base de dev avec les comptes réels : 20 vérifications, aucune erreur JavaScript.
  1. doublon confirmé et bandeaux sur la fiche ;
  2. annulation d'un bon soumis sans visa ;
  3. BP bloqué, dérogation demandée ;
  4. dérogation accordée par Mohamed DIAKITE ;
  5. BP soumis ;
  6. visa Finance donné par le Chef comptable Mamadou Alpha BAH ;
  7. caissier bénéficiaire refusé ;
  8. téléphone.

### 41.5 Déploiement

```bash
php artisan migrate        # 2026_10_17_000001
php artisan optimize:clear
```

Ensuite :
- vérifier `seuil_validation_dp` (1 500 000 attendu) ;
- désigner les gestionnaires et suppléants des caisses ;
- régler `blocage_bp_en_retard` si le défaut « partiel » ne convient pas.

## 42. Version v42 — Conformité v2.3, lot B « bénéficiaires : tiers et visiteurs du groupe » (17 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés.

### 42.1 Règles traitées

| Règle | Mise en œuvre |
|---|---|
| RG-M03-03, ANO-06 | Le bénéficiaire est choisi dans un référentiel : salarié, visiteur du groupe ou tiers validé. Il n'y a plus de saisie libre. Le nom et le téléphone (n° OM, à défaut le téléphone) sont repris du référentiel, et le téléphone reste modifiable pour un tiers ou un visiteur. Un ancien type saisi librement est refusé à la saisie (MSG-APP-059). |
| RG-M02-06, US-03 | Le demandeur **propose** un tiers depuis l'étape 2 (« Proposer un tiers »). Le tiers n'est pas sélectionnable tant qu'il n'est pas validé : il apparaît grisé, « en attente de validation », pour celui qui l'a proposé (MSG-M03-03). La Comptabilité, le Chef comptable, le CDG, le DAF et le DAF adjoint sont notifiés ; leur notification ouvre l'écran des tiers. Ils valident, ou refusent avec un motif, et le demandeur est notifié. Un tiers créé par l'un d'eux est validé d'emblée. Un tiers se désactive, il ne se supprime pas. |
| RG-M02-07 | Si le téléphone, le n° OM ou le NIF est déjà celui d'un tiers non refusé (formats normalisés), la proposition est bloquée avec le nom du tiers existant (MSG-M03-04). Le contrôle vaut aussi à la modification. |
| RG-M03-04 | Un BP n'est pas accepté pour un tiers (MSG-M03-02). Un BP est accepté pour un visiteur du groupe actif. |
| §6.5 Visiteurs | Fiche : nom, prénom, filiale, matricule groupe, n° OM, département d'accueil, directeur responsable de la régularisation, période de séjour. Elle est tenue par les RH, la direction financière et l'administrateur. Le directeur s'affiche quand on choisit le visiteur, et un visiteur hors période de séjour est signalé. |
| RG-M03-20, RG-M03-21 | Le doublon probable et les BP en retard reconnaissent le bénéficiaire par son tiers ou son visiteur, et non plus par son seul nom. |

### 42.2 Données

Migration `2026_10_17_000002_lot_b_tiers_et_visiteurs` :
- nouvelles tables `tiers` et `visiteurs` ;
- `bons_caisse.tiers_id` et `visiteur_id` ;
- `type_beneficiaire` passe d'une énumération à une chaîne (employe, visiteur, tiers, plus les anciens types) ;
- reprise (Q62) : les noms distincts des bons « fournisseur / prestataire / autre » deviennent des tiers **proposés**, d'origine « reprise ». Les anciens bons gardent leur texte et sont rattachés au tiers repris. Les noms identiques à la casse et aux espaces près ne forment qu'un tiers.

La migration a été vérifiée sur une copie de la base (aller, retour, aller, avec deux bons de test fusionnés en un seul tiers), puis appliquée en dev. La base de dev n'a aucun tiers à reprendre.

### 42.3 Écrans

- **Assistant, étape 2** : trois cartes, Salarié, Visiteur du groupe et Tiers, chacune avec sa recherche. Le choix d'un tiers propose aussi « Proposer un tiers » (raison sociale, type, téléphone, n° OM, NIF).
- **Référentiels → Tiers** : onglets Proposé, Validé, Refusé et Désactivé, avec leurs compteurs ; Valider, Refuser (motif), Modifier, Désactiver ou Réactiver ; les tiers repris sont signalés.
- **Référentiels → Visiteurs du groupe** : liste et fiche du visiteur.

### 42.4 Vérifications

- **`tests/Feature/LotB/TiersVisiteursTest.php`** (5 tests) :
  1. proposition en attente ;
  2. doublon ;
  3. validation, refus, choix dans un bon, BP refusé, désactivation ;
  4. création par un valideur ;
  5. visiteur et BP, avec BP en retard du visiteur.
- **Tests adaptés** : un ancien bénéficiaire libre est refusé ; un tiers sans téléphone payé en espèces bloque toujours.
- **Total** : **320 tests PHP** et **71 tests JavaScript** passent.
- **Parcours dans Edge**, sur une copie de la base de dev : 14 vérifications, aucune erreur JavaScript.
  1. proposition par Souadou ;
  2. doublon ;
  3. validation par le CDG Maïmouna BARRY ;
  4. choix du tiers et téléphone repris ;
  5. BP refusé au tiers ;
  6. visiteur créé par l'administrateur ;
  7. BP au visiteur ;
  8. téléphone.

### 42.5 Déploiement

```bash
php artisan migrate        # 2026_10_17_000002
```

Ensuite :
- faire valider par la Comptabilité ou le CDG les tiers repris (Référentiels → Tiers, onglet « Proposé ») ;
- faire saisir aux RH les visiteurs du groupe en séjour.

## 43. Version v43 — Conformité v2.3, lot C « numérotation par caisse et caisse de rattachement » (17 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés.

### 43.1 Règles traitées

| Règle | Mise en œuvre |
|---|---|
| RG-M03-18 | Numéro attribué à la soumission sur la **caisse émettrice**, au format `BC-[code caisse]-[AAAA]-[00001]`, avec une séquence par caisse et par année (table `compteurs_bons`, verrouillée). Le nombre de chiffres vient du paramètre `numero_bon_chiffres` (5), ou de la caisse. Les numéros déjà attribués (`BC-AAAA-NNNN`) ne changent pas (Q62). Repli sans aucune caisse paramétrée : ancienne séquence annuelle. |
| RG-M08-11 | « Numéro de départ » de la caisse, qui reprend le carnet papier. Il ne compte que pour la première séquence de la caisse ; l'année suivante repart à 1. Exemple : Atelier à 5123 sur 7 chiffres, soit `BC-ATL-2026-0005123`. |
| RG-M02-03, RG-M03-11 | **Caisse de rattachement** de l'utilisateur (`users.caisse_id`, à défaut la caisse principale espèces de son site). Elle devient la caisse émettrice du bon, et le site du bon est celui du profil : il ne se modifie plus dans l'assistant. Pour un bon « pour le compte de », ce sont ceux du titulaire. Pour un bon d'ODM, ceux du demandeur de l'ODM. |
| RG-M08-01 | En espèces, une caisse émettrice active paie ses propres bons : la caisse Atelier paie ceux de l'Atelier. En Orange Money, c'est toujours la caisse OM. |
| RG-M06-01, RG-M08-12 | Un caissier rattaché à une caisse voit et paie les bons que cette caisse émet ou paie. Sans rattachement, il reste limité à son site (lot A). |

### 43.2 Données

Migration `2026_10_17_000003_lot_c_numerotation_par_caisse` :
- `caisses.code_numerotation`, `numero_depart`, `chiffres_numero` ;
- codes de la spec (§6.1) posés sur les caisses existantes : CKY, ATL, OM, BOK, SAG, SGL, SGT, COR ;
- table `compteurs_bons` ;
- `users.caisse_id` (rattachement initial : caisse principale du site) ;
- `bons_caisse.caisse_emettrice_id` ;
- paramètre `numero_bon_chiffres`.

La migration a été vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.

### 43.3 Écrans

- **Paramétrage → Caisses** : « Code des numéros », « Numéro de départ » et « Chiffres », avec un aperçu du premier numéro. Le formulaire défile désormais, boutons toujours visibles : il dépassait la hauteur d'un écran.
- **Utilisateurs** (création et modification) : « Caisse de rattachement ».
- **Assistant** : le site est en lecture seule (repris du profil), et le résumé affiche la caisse émettrice.

### 43.4 Vérifications

- **`tests/Feature/LotC/NumerotationCaisseTest.php`** (4 tests) :
  1. une séquence par caisse, anciens numéros inchangés ;
  2. carnet Atelier repris sur 7 chiffres, payé par la caisse Atelier, remise à 1 l'année suivante ;
  3. caissier rattaché à une caisse ;
  4. paramétrage de la numérotation.
- **Tests adaptés** : format des numéros et site figé.
- **Total** : **324 tests PHP** et **71 tests JavaScript** passent.
- **Parcours dans Edge**, sur une copie de la base de dev : 9 vérifications, aucune erreur JavaScript.
  1. paramétrage de l'Atelier ;
  2. Raby TOUNKARA rattachée à l'Atelier ;
  3. son bon `BC-ATL-2026-0005123`, payé par la caisse Atelier ;
  4. bon de Souadou `BC-CKY-2026-00001` ;
  5. téléphone.

### 43.5 Déploiement

```bash
php artisan migrate        # 2026_10_17_000003
```

Ensuite :
- vérifier les codes des caisses ;
- saisir le numéro de départ de chaque carnet (Atelier : « BC N° 0005xxx ») et ses chiffres ;
- activer la caisse Atelier ;
- rattacher les utilisateurs de l'Atelier et les caissiers à leur caisse.

## 44. Version v44 — Conformité v2.3, lot D « saisie et paiement » (14 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés.

### 44.1 Saisie (D1)

| Règle | Mise en œuvre |
|---|---|
| RG-M03-13 | OR facultatifs, proposés pour toutes les catégories. Format : 8 chiffres commençant par 110 (MSG-M03-07). Chaque OR porte un type, vente ou garantie (`bons_caisse.types_or`, MSG-APP-062). `references_or` garde la liste des numéros, utilisée par les exports et la comptabilité. Les anciens OR restent sans type. Les bons d'ODM reprennent le type des OR de l'ODM. |
| RG-M03-05 | Motif de 10 à 250 caractères. |
| RG-M03-14 | Motif d'urgence choisi dans la liste paramétrable (MSG-M03-08). La justification libre devient facultative (« Précisions »). |
| RG-M03-15 | Un BD est justifié par une facture ou une proforma seulement (MSG-M03-09). Le reçu, le ticket carburant et le devis ne suffisent plus. |
| RG-M03-16 | Un scan sous 300 dpi est signalé (MSG-APP-064) sans bloquer, et une pièce peu lisible compte comme justificatif. |
| RG-M03-09 | Si le mode Orange Money est choisi, l'assistant affiche l'estimation des frais (MSG-M03-06, route `api.referentiels.frais-om`, paliers du serveur). |
| RG-M03-10 | `bons_caisse.numero_om` est obligatoire en Orange Money. Il est pré-rempli depuis le référentiel du bénéficiaire (salarié, visiteur ou tiers). S'il est modifié, un motif est obligatoire (MSG-APP-063) ; la modification est tracée au journal. Les bons d'ODM reprennent le n° OM du participant. |

### 44.2 Validation et paiement (D2)

| Règle | Mise en œuvre |
|---|---|
| RG-M04-04 | Table `motifs_rejet` avec la liste initiale de la spec, tenue dans Paramétrage → « Motifs de rejet » (ajout, modification, désactivation). Les motifs de la SFD v1.3 sont repris désactivés : les rejets passés restent lisibles. |
| RG-M06-04 | `bons_caisse.reference_paiement` est obligatoire pour Orange Money, MTN Money, chèque et virement (MSG-APP-065). Elle est saisie au paiement et affichée sur la fiche. |
| RG-M06-07, §6.2 | Le caissier choisit parmi espèces, Orange Money, chèque et virement. Le mode « Autre » n'est plus proposé. MTN Money est ajouté, inactif par défaut (paramètre `mode_mtn_actif`) ; il est payé hors caisse, avec son code journal en M16. Les colonnes de mode passent d'une énumération à une chaîne. |
| RG-M06-01 (révision du lot C) | Le caissier décide du mode. Un caissier rattaché à une caisse paie donc les bons qu'elle émet, ceux qu'elle paie dans le mode qu'il retient (la caisse OM paie tout bon en OM), et les anciens bons de son site sans caisse émettrice. Défaut relevé au parcours : Youssouf TOURE ne pouvait plus payer un ancien bon souhaité en OM. |

### 44.3 Données

Migrations :
- `2026_10_17_000004_lot_d_saisie` : `types_or`, `numero_om`, `motif_numero_om` ;
- `2026_10_17_000005_lot_d_validation_paiement` : `motifs_rejet`, `reference_paiement`, modes en chaîne, paramètre `mode_mtn_actif`.

Chacune a été vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.

### 44.4 Vérifications

- **`tests/Feature/LotD/SaisieV23Test.php`** (3 tests) : n° OM ; estimation des frais ; motif et justificatifs.
- **`tests/Feature/LotD/ValidationPaiementV23Test.php`** (2 tests) : motifs de rejet ; référence et modes du paiement.
- **`NumerotationCaisseTest`** : un test ajouté, le caissier et le mode qu'il retient.
- **Tests adaptés** :
  - scan faible non bloquant ;
  - facture exigée en plus du ticket ;
  - motif d'urgence de la liste ;
  - OR 110 avec type ;
  - référence dans les paiements des tests existants.
- **Total** : **330 tests PHP** et **71 tests JavaScript** passent.
- **Parcours dans Edge**, sur une copie de la base de dev : 16 vérifications, aucune erreur JavaScript.
  1. n° OM repris et modifié avec motif ;
  2. estimation des frais ;
  3. OR refusé, puis OR « Garantie » ;
  4. fiche du bon ;
  5. rejet par Thierry GOMIS avec un motif de la spec ;
  6. paiement par chèque par Youssouf TOURE, référence exigée ;
  7. téléphone.
- **Incident** : un build du front a échoué par manque de mémoire (Windows, fichier d'échange plein). L'instance de test a été arrêtée avant tout parcours ; le build relancé a réussi.

### 44.5 Déploiement

```bash
php artisan migrate        # 2026_10_17_000004, 2026_10_17_000005
```

Ensuite :
- relire la liste des motifs de rejet et des motifs d'urgence ;
- activer MTN Money si Neemba l'utilise ;
- prévenir les demandeurs qu'un BD exige désormais une facture ou une proforma.

## 45. Version v45 — Conformité v2.3, lot E « régularisation des BP » (14 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés.

### 45.1 Justification conforme à l'Annexe 3 (RG-M07-03)

| Élément | Mise en œuvre |
|---|---|
| Écran | `BonsCaisse/Justification.jsx`, route `bons-caisse.justification`, ouvert depuis l'encart « Justification » de la fiche du BP (`Components/CarteJustificationBp.jsx`). |
| En-tête | Date, nom, prénom, matricule, département de la personne qui a reçu l'avance, montant de l'avance, motif, date limite. |
| Lignes | Jusqu'à 10 : intitulé, code analytique actif, n° de compte, montant, pièce. La pièce se dépose depuis la ligne (type « Justificatif de BP (Annexe 3) », empreinte et qualité contrôlées comme toute pièce) ou se choisit parmi les pièces du BP, y compris celles jointes avant le paiement. |
| Calculs | Montant justifié ; montant à retourner en caisse (déclaré, au plus l'écart, zéro par défaut) ; restant à justifier (doit être nul pour soumettre, MSG-APP-071) ; trop justifié à percevoir. Mêmes règles côté serveur (`JustifierBp::calculs`) et écran (`utils/justification.js`). |
| Visas | Initiateur → N+1 de l'initiateur (chef de son service) → Contrôle de gestion → Comptabilité (comptable ou Chef comptable). Suppléants par délégation « validation » ; demandeur, bénéficiaire et initiateur exclus ; une étape sans valideur possible est sautée et tracée, sauf la Comptabilité. Un rejet exige un motif (10 caractères) et renvoie à l'initiateur ; la resoumission ouvre une nouvelle version. |
| Visiteur (RG-M07-01) | L'initiateur est le directeur du département d'accueil du visiteur ; il ouvre et soumet la justification. |
| Visibilité | Le valideur de l'étape en cours, ceux qui ont visé et l'initiateur consultent le BP. |

### 45.2 Issue de la régularisation (RG-M07-08)

- Au dernier visa, le BP passe « Régularisé ».
- Trop justifié : un BD de complément est créé, approuvé, numéroté par la caisse émettrice du BP et lié à lui (`bons_caisse.bp_regularise_id`) ; le caissier le paie comme tout bon. Sa fiche rappelle le BP régularisé.
- Reliquat : le caissier l'encaisse depuis la fiche du BP (« Encaisser le reliquat ») ; écriture d'entrée « Reliquat de BP rétrocédé en caisse » (`retrocession_bp`) dans la caisse espèces du BP.
- Le paiement d'un BP ne le régularise plus, même si des pièces ont été jointes à l'avance (elles sont reprises dans la justification). L'ancienne régularisation directe par le demandeur (`bons-caisse.regulariser`) est retirée.
- Écart avec la spec : la spec écrit « un BD du montant réel lié au BP ». Le montant réel est porté par les lignes de la justification (avec leur code analytique) ; seul le trop justifié donne lieu à un BD, pour ne pas décaisser deux fois l'avance. Voir Q69.

### 45.3 Suivi des BP en retard

| Règle | Mise en œuvre |
|---|---|
| RG-M07-04 | `bons:relancer-regularisation` chaque vendredi à 8 h (au lieu de chaque jour) : notification au nom du caissier qui a payé le BP, à la personne concernée (le directeur d'accueil pour un visiteur), en copie le chef de son service, le Chef comptable, la Trésorerie et le DAF. Une justification en cours de visa n'est pas relancée. |
| RG-M07-05 | `bons:transmettre-bp-rh` chaque jour à 8 h 15 : un BP dont l'échéance est dépassée est transmis aux RH (`bons_caisse.suivi_rh`), notifiés. Les RH tracent sur la fiche l'email d'information de la DRH, puis seulement la retenue sur salaire (MSG-APP-075) ; l'intéressé, puis le DAF, sont notifiés. Le tableau « BP en retard » est ouvert aux RH et au DP adjoint, avec l'état de la justification et du suivi RH. |
| RG-M07-07 | `bons:rapport-bp-mensuel` le 1er du mois à 8 h : notification au Directeur Pays et à son adjoint (nombre et montant des BP non régularisés, en retard, transmis aux RH), avec le lien vers le tableau. |

### 45.4 Données

Migration `2026_10_18_000001_lot_e_justification_bp` :
- tables `justifications_bp`, `justification_lignes`, `justification_visas` ;
- `bons_caisse.bp_regularise_id`, `bons_caisse.suivi_rh` ;
- type de pièce `justificatif_bp`.

Vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.

### 45.5 Vérifications

- **`tests/Feature/LotE/JustificationBpTest.php`** (6 tests) : circuit complet et reliquat ; BD de complément ; contrôles et rejet ; étape sautée et visiteur ; paiement sans régularisation ; relance, suivi RH, rapport mensuel.
- **`tests/js/justification.test.js`** : calculs de l'écran.
- **`DroitsBonTest`** : le test de l'ancienne régularisation directe devient « seul le demandeur justifie son BP ».
- **Total** : **336 tests PHP** et **74 tests JavaScript** passent.
- **Parcours dans Edge**, sur une copie de la base de dev : 26 vérifications, aucune erreur JavaScript.
  1. Souadou BARRY établit la justification (2 lignes, pièces déposées depuis les lignes, 200 000 GNF à retourner) ;
  2. Thierry GOMIS rejette, Souadou corrige et resoumet (version 2) ;
  3. visas de Thierry GOMIS, Maimouna BARRY, Mamadou Alpha BAH : BP régularisé ;
  4. Youssouf TOURE encaisse le reliquat (solde et registre) ;
  5. BP en retard transmis aux RH ; information de la DRH puis retenue tracées (rôle RH donné à Raby TOUNKARA dans la copie seulement) ;
  6. téléphone.

### 45.6 Déploiement

```bash
php artisan migrate        # 2026_10_18_000001
```

Ensuite :
- vérifier que le planificateur tourne (relance du vendredi, transmission RH, rapport mensuel) ;
- donner le rôle « Ressources humaines » aux personnes de la DRH ;
- donner le rôle « Comptabilité » aux comptables qui visent les justifications ;
- prévenir les demandeurs : un BP se régularise désormais par la justification et ses visas.

## 46. Version v46 — Audit de conformité à la spec v2.3 et plan de mise en œuvre (15 Octobre 2026)

Branche `v23-conformite`. Aucun code modifié : audit, fichier de suivi et mise à jour de ce devBook.

### 46.1 Démarche

À la demande de l'utilisateur, toutes les règles de la spec v2.3 ont été relevées et comparées au code, une à une, après les lots A à E. Le relevé couvre :
- 152 règles RG ;
- 48 user stories ;
- 36 messages ;
- les exigences générales (§1.4, §2.3), le socle et les anomalies (§5), les paramètres (§6) ;
- les 27 points ouverts et les 37 scénarios.

L'audit a été fait en lecture seule, module par module. Les points graves ont été vérifiés directement dans le code et dans la base de dev.

Le résultat est le fichier de suivi **[docs/CONFORMITE.md](docs/CONFORMITE.md) (alors nommé `CONFORMITE_V23.md`)**. Pour chaque règle, il donne :
- l'état (fait, partiel, à faire) ;
- ce qui manque ;
- la cause du retard :
  - **L** : lot prévu, pas encore réalisé ;
  - **O** : écart relevé par l'audit ;
  - **N** : attend Neemba ;
  - **C** : choix à confirmer ;
  - **P3** : lot P3 de la spec ;
- le lot qui traite l'écart.

### 46.2 Résultat

| | Règles RG | Fait | Partiel | À faire |
|---|---:|---:|---:|---:|
| Total | 152 | 94 | 38 | 20 |

- **Faits ou presque** :
  - M03 : 24 règles sur 26 ;
  - M12 : 36 sur 37 ;
  - M16 : 6 sur 6.
- **À construire** :
  - M08 (réapprovisionnement, encaissement client, rapprochement Orange Money) ;
  - M09 et M10 (clôture, rapport au format Neemba, PV d'arrêté et inventaire) ;
  - M13 (carburant).
- **Messages** :
  - M03 : 5 sur 16 n'ont pas le texte exact ;
  - M08 et M13 : 11 sont à créer.
- **M11** : l'email manque pour presque tous les événements.

### 46.3 Écarts graves relevés (lot R)

1. **Visa Finance sauté** : quand le DP est requis, l'étape Finance peut être sautée faute de valideur. `CircuitBon::estDerniereEtape` ne protège que le DP. Or la spec exige le visa de la Finance avant tout décaissement (§8.2).
2. **BD de complément du lot E** : il naît « Approuvé », donc sans visa Finance.
3. **OTP** : le SMS part au demandeur quand le bénéficiaire n'a pas de téléphone, et un OTP vérifié reste accepté 10 minutes de plus (RG-M06-02).
4. **SLA** : la base contient 4/8/4/8 h, la spec demande 4/24/8/24 h. Il n'y a pas d'escalade DP → DAF pour les bons, et les délais sont comptés en heures calendaires au lieu d'heures ouvrées (§6.7).
5. **Écran de visa** (`Validations/Show`) : il n'affiche pas les alertes de doublon ni de BP en retard (RG-M04-05).
6. **Rôle Comptabilité** : il ne voit pas les ODM dans la liste (RG-M12-34).
7. **Code analytique** : celui modifié par le CDG n'est pas contrôlé par le serveur (RG-M04-03).
8. **Messages** : MSG-M03-01, 05, 10, 11 et 15 n'ont pas le texte exact.

### 46.4 Plan proposé (à valider)

**R → F1 → F2 → G → I → H → P3**, environ 23 jours :

| Lot | Contenu | Durée |
|---|---|---|
| R | Correctifs ci-dessus | ≈ 1,5 j |
| F1 | Caisses (M08) | ≈ 4 j |
| F2 | Clôture, rapport et inventaire (M09, M10) | ≈ 3 j |
| G | Gouvernance (entité, domaine, paramètres datés, double validation, signataires habilités, délégations, journal protégé, archivage) | ≈ 4 j |
| I | Reçu signé, emails, tableau de bord du DAF | ≈ 3 j |
| H | Carburant (M13) | ≈ 4 j |
| P3 | Hors ligne, reprise des données, performance, recette | ≈ 4 j |

Le détail et les cases à cocher sont au §11 de `CONFORMITE.md`.

### 46.5 Décisions proposées

Elles sont détaillées au §13 de `CONFORMITE.md`. Elles seront reportées dans `docs/questions.md` une fois tranchées.

| N° | Question | Proposition |
|---|---|---|
| Q69 | BD de la régularisation | Seul le trop justifié (déjà codé), à confirmer par la Comptabilité |
| Q70 | Comptes Addvalis hors @neemba.com ? | Domaine exigé, sauf pour l'administrateur |
| Q71 | Brouillon abandonné : supprimé ou annulé ? | Annulé et tracé |
| Q72 | DP adjoint : toujours ou en l'absence du DP ? | En l'absence du DP |
| Q73 | Commentaire de rejet obligatoire ? | Toujours, 10 caractères minimum |
| Q74 | Destinataires et périmètre de la relance des BP | Chef de service ; tous les BP en attente |
| Q75 | Assistant trésorier | Rôle à part, avec les droits du caissier |

### 46.6 À obtenir

- **De Neemba** :
  - valeurs réelles des caisses, solde de l'Atelier ;
  - signataires habilités (PO-15), codes de service (PO-14) ;
  - destinataires du rapport hors Conakry (PO-18), listes de diffusion des ODM ;
  - format du relevé Orange Money ;
  - comptes et codes journaux (PO-17, PO-25), fichier clients (PO-26) ;
  - données de reprise (PO-16), SMTP et SMS (PO-23).
- **De Thierno** : le cahier Excel des 37 scénarios, indispensable à la recette (§10.1 de la spec).

### 46.7 Ce devBook

- **En-tête** : date et lecture du document.
- **Chapitre 0** (nouveau) : état du projet, branches, état des modules.
- **Chapitres 1.3, 4 et 5** : mis à jour.
  - règles clés à jour ;
  - 15 rôles ;
  - circuit et statuts actuels ;
  - caisses de la spec §6.1 ;
  - services de la spec §6.3 ;
  - types de pièces.
- **Chapitres 3 et 6 à 18** : conservés comme historique.

## 47. Version v47 — Intégration de la spec v2.4 : comparaison et plan validé (16 Octobre 2026)

Branche `v23-conformite`. Aucun code modifié dans cette étape : comparaison des deux specs, audit d'impact, plan validé et fichier de suivi mis à jour.

### 47.1 Ce que change la v2.4

`docs/Spec_Fonctionnelle_v2.4_Neemba_P2.docx` (14/10/2026) intègre le comité du 14/10 et le cadrage IT du 08/10.

La comparaison automatique des règles de la v2.3 et de la v2.4, en ignorant les mentions de version, donne :
- **13 règles nouvelles** :
  - RG-M01-07, 08, 09 ;
  - RG-M02-10, 11 ;
  - RG-M03-27, 28 ;
  - RG-M04-10 ;
  - RG-M12-38 à 42.
- **11 règles modifiées** :
  - RG-M01-02 ;
  - RG-M02-08 ;
  - RG-M03-04, 11, 13, 15 ;
  - RG-M07-01 ;
  - RG-M12-02, 14 ;
  - RG-M13-01 ;
  - RG-M16-03.
- **Messages** : MSG-M03-17, MSG-M12-10 et 11 sont nouveaux ; MSG-M03-02 et MSG-M12-01 ont un nouveau texte.
- **Points ouverts et scénarios** : PO-28 à PO-31 et SC-38 à SC-41 sont nouveaux.
- **Calendrier** : un bloc E de 8 jours ouvrés (19 au 28/10) s'insère après S1. La fin passe au 16/12/2026.

| Sujet | Impact sur le code existant |
|---|---|
| Visiteurs du groupe : BD seulement, à titre exceptionnel (motif d'une liste fermée, commentaire, Finance réservée au DAF, « refacturation inter-filiale ») | À refaire : les lots B et E permettaient un BP de visiteur |
| ODM : un bon par participant | À retirer : mode groupé et sa sélection (Q61) |
| ODM : aucune saisie hors barème | Les lignes calculées sont déjà verrouillées ; seul le bon complémentaire de la clôture reste modifiable |
| ODM : types d'OR en référentiel, « OR à régulariser » | À adapter : constante et enum VENTE / GARANTIE ; règle « au moins un OR » |
| Caisse ≠ site ≠ client ; site analytique du bon | À refaire : une caisse a un seul site, et le site du bon détermine la caisse payeuse |
| Entité transverse (droits, numérotation, référentiels, gabarit, rapports, vue consolidée) | Nouveau : seulement du texte libre sur les utilisateurs et les ODM |
| Double authentification (OTP email à la connexion, OTP SMS pour viser une demande urgente) | Nouveau |
| Gestionnaire des référentiels ; barèmes ODM datés, sans double validation | Nouveau : aujourd'hui l'administrateur tient tout, et les barèmes passent par la double validation |
| Carburant : besoin porté par l'ODM (véhicule, immatriculation, itinéraire) | À adapter : un seul champ « véhicule » |
| ERP Helium | Deux commentaires à renommer |

### 47.2 Décisions du 16/10 (utilisateur)

- **Ordre** : on corrige d'abord l'existant contredit (lot V), puis on fait le bloc E, puis la suite du plan v2.3.
- **Suivi** : un seul fichier, `docs/CONFORMITE.md`, mis à jour en v2.4. Il remplace `CONFORMITE_V23.md`.
- **Double authentification** : active partout. En dev, le code est écrit dans le journal (mail « log »). Le paramètre de secours `double_authentification_active` est sous double validation.
- **Données existantes** : les BP de visiteurs et les éventuels bons groupés déjà émis gardent leur cycle ; seules les nouvelles saisies sont bloquées.

### 47.3 Plan

**R → V → E1 → E2 → E3 → E4 → O → F1 → F2 → G → I → H → P3**, environ 33 jours. Le détail et les cases à cocher sont au §11 de `docs/CONFORMITE.md`. Le plan complet est dans `C:\Users\HP\.claude\plans\analyse-mon-devbook-md-et-rosy-sparrow.md`.

| Lot | Contenu | Durée |
|---|---|---|
| R | Terminer les correctifs de l'audit v2.3 (codés, pas commités) | 0,5 j |
| V | Visiteurs en BD seulement, bon groupé retiré, bon complémentaire verrouillé, Helium | 1,5 j |
| E1 | Entité transverse : table `entites`, `entite_id`, droits entité × rôle, vue consolidée, numérotation, gabarit | 2,5 j |
| E2 | Double authentification : table `codes_otp`, connexion en deux temps, OTP SMS de visa urgent, urgence sur l'ODM | 1,5 j |
| E3 | Gestionnaire des référentiels, barèmes ODM datés (`baremes_odm`), types d'OR (`types_or`) | 2 j |
| E4 | Liaison caisse ↔ site, caisse OM sans site, site analytique modifiable et corrigé par le CDG | 2 j |
| O | OR à régulariser (échéance, relances, blocage optionnel, suivi) ; immatriculation et itinéraire | 2 j |
| F1, F2, G, I, H, P3 | Suite du plan v2.3, ajustée à la v2.4 | 21 j |

### 47.4 État de la conformité (v2.4)

| | Règles RG | Fait | Partiel | À faire |
|---|---:|---:|---:|---:|
| Total | 165 | 88 | 45 | 32 |

Les règles du lot R passeront à ✅ à son commit.

### 47.5 Décisions proposées (à reporter dans `docs/questions.md` au fil des lots)

| N° | Question | Proposition |
|---|---|---|
| Q76 | Urgence sur l'ODM, pour l'OTP de visa ? | Oui : mêmes niveaux et motifs que le bon |
| Q77 | L'OTP de visa porte-t-il sur le rejet ? | Non : seulement sur l'approbation |
| Q78 | Qui tient les sites et les caisses ? | L'administrateur, avec double validation ; le gestionnaire des référentiels tient le reste |
| Q79 | Bon visiteur sans DAF ni DAF adjoint disponible | Le bon attend ; la Finance n'est jamais sautée |
| Q80 | OR saisi après coup | Reporté sur les bons de l'ODM, avec trace |
| Q81 | Qui a la vue consolidée ? | Paramètre ; par défaut DAF, DAF adjoint, DP, DP adjoint, administrateur |

### 47.6 À obtenir

- **De Neemba** : types d'OR (PO-28), réponse aux propositions du 14/10 (PO-29), motifs du bon visiteur (PO-30), date des environnements cloud (PO-31), SMTP et SMS (PO-23).
- **De Thierno** : le cahier Excel des 41 scénarios et la spec technique mise à jour (cloud, double authentification).
- **Rappel** : la liste du §46.6 reste valable.

## 48. Version v48 — Lot R : correctifs de l'audit v2.3 (16 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Ce lot corrige les écarts graves relevés par l'audit du 15/10 (§46.3).

### 48.1 Règles traitées

| Règle | Mise en œuvre |
|---|---|
| §8.2 (visa Finance avant tout décaissement) | `CircuitBon::estEtapeObligatoire` : seules les étapes Chef de service et CDG peuvent être sautées. La Finance et le Directeur Pays attendent un titulaire ou un suppléant, même quand le DP est requis. |
| RG-M07-08, §8.2 | Le BD de complément d'un BP (`JustifierBp::finaliser`) ne naît plus « Approuvé ». Il est créé « En attente Finance » ; ses étapes Chef de service et CDG sont notées sautées, car déjà visées dans la justification. La Finance est notifiée. |
| RG-M06-02 | Le code OTP part au téléphone du bénéficiaire seulement. Sans téléphone, aucun code n'est envoyé (plus de repli sur le demandeur). Un code expiré ne paie plus, même vérifié (plus de prolongation de 10 min). Un envoi manqué ne crée pas de code. Le paiement trace « Reçu par » au journal. |
| RG-M06-01 | Le statut `APPROUVE` s'affiche « Bon à payer » (`BonCaisse::STATUTS_LABELS`, `utils/statuts.js`). |
| RG-M04-05 | L'écran de visa (`Validations/Show`) affiche les alertes de doublon et de BP en retard. Le composant `AlertesDuBon` est partagé avec la fiche du bon ; il reçoit `AlertesBon::pourAffichage`. |
| RG-M04-03 | Le code analytique saisi par le CDG est contrôlé par le serveur : il doit être actif et rattaché au service (`ReglesSaisie::regleCodeDuService`). |
| RG-M12-34 | Le rôle `comptable` entre dans `OrdreMission::ROLES_VISION_GLOBALE`. |
| §6.7 | Les SLA prennent les valeurs de la spec : chef de service 4 h, CDG 24 h, Finance 8 h, DP 24 h. Ils sont comptés en heures ouvrées (`App\Support\HeuresOuvrees`). Les paramètres `heure_ouverture` / `heure_fermeture` valent 8 h et 17 h par défaut ; les jours fériés et les week-ends ne comptent pas. Un DP en retard est escaladé au DAF (`RelancerValidationsSLA::ESCALADE_MAP`). Les visas d'ODM suivent les mêmes heures ouvrées (`RelancerVisasOdm`). |
| RG-M03-17 | Le seuil du visa DP en base passe à 1 500 000. La migration d'origine posait 5 000 000, et seul le seeder corrigeait la valeur. |
| §5.1, Q73 | Au rejet, le commentaire est toujours obligatoire, avec 10 caractères au moins, quel que soit le motif (`ChampsRejet.jsx`, `ValidationController::rejeter`). |
| §10.1 | Messages au texte exact de la spec : MSG-M03-01, 05, 10, 11 et 15. Les clés MSG-BC de la SFD v1.3 (002, 012, 021, 020, 032) restent, car l'API et le catalogue les utilisent, avec le texte de la spec. MSG-M03-15 nomme le prochain valideur et le délai de l'étape (`CircuitBon::prochaineEtape`), par exemple « Prochaine étape : Chef de service (Thierry GOMIS), délai 4 h ouvrées ». |

### 48.2 Données

Migration `2026_10_19_000001_lot_r_sla_heures_ouvrees`, sur les données seulement :
- SLA ;
- heures d'ouverture et de fermeture ;
- seuil DP.

Son retour en arrière remet les anciennes valeurs. Vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.

### 48.3 Vérifications

- `tests/Feature/LotR/CorrectifsAuditTest.php` (11 tests) et `tests/Unit/HeuresOuvreesTest.php` (10 tests).
- Tests adaptés aux nouveaux textes :
  - `MessagesTest`, `CaissesTest`, `SaisieBonTest`, `GenerationBonsOdmTest` ;
  - `tests/js/messages.test.js`, `statuts.test.js` ;
  - `JustificationBpTest`, `IncompatibilitesValidationTest`, `SaisieOdmTest`.
- **Total** : 358 tests PHP et 74 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base de dev : 11 vérifications, sans erreur JavaScript.
  1. Message de soumission avec le prochain valideur et le délai.
  2. Doublon visible sur l'écran de visa.
  3. Rejet avec explication obligatoire.
  4. « Bon à payer » chez le caissier.
  5. SLA en base.
  6. Téléphone.

### 48.4 Déploiement

```bash
php artisan migrate        # 2026_10_19_000001
```

Les délais de visa changent :
- CDG et DP : de 8 h à 24 h ;
- Finance : de 4 h à 8 h ;
- les délais ne courent plus le soir ni le week-end.

Prévenir les valideurs.

## 49. Version v49 — Lot V : corrections v2.4 de l'existant (16 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Ce lot retire ou corrige ce que la spec v2.4 rend faux dans le code déjà écrit (§47.1). Décision du 16/10 : les données existantes gardent leur cycle ; seules les nouvelles saisies sont bloquées.

### 49.1 Visiteurs du groupe : BD seulement (RG-M03-04, RG-M03-28, RG-M04-10, RG-M07-01)

| Règle | Mise en œuvre |
|---|---|
| RG-M03-04 | `ReglesSaisie` refuse un BP pour un visiteur, comme pour un tiers (nouveau texte de MSG-M03-02). Dans l'assistant, le type BP est désactivé à l'étape 1 quand le bénéficiaire est un visiteur ou un tiers. À l'étape 2, un bandeau rouge rappelle MSG-M03-02 si un BP est en cours. |
| RG-M03-28 | Nouvelle table `motifs_visiteur`, tenue au Paramétrage (onglet « Motifs bon visiteur »). Elle est vide au départ : la liste est attendue du DAF (PO-30). Le bon porte `motif_visiteur` (liste) et `commentaire_visiteur` (10 caractères au moins), avec MSG-M03-17. `EnregistrementBon` marque le bon `refacturation_inter_filiale` et recopie `filiale_refacturation` depuis le visiteur. Le bandeau « Bon exceptionnel… refacturation inter-filiale » (`AlertesDuBon`) s'affiche sur la fiche et sur l'écran de visa. L'export Excel des bons a deux colonnes de plus : « Refacturation inter-filiale » et « Filiale ». |
| RG-M04-10 | `CircuitBon::titulaires` réserve l'étape Finance d'un bon visiteur à `daf` et `daf_adjoint` (`CircuitBon::ROLES_FINANCE_VISITEUR`), selon le paramètre `visiteur_finance_daf_seul` (défaut : oui). `requeteAViser` retire ces bons de la liste du Chef comptable. `motifRefus` explique le refus. La Finance n'est jamais sautée (lot R) : si ni le DAF ni son adjoint ne peuvent viser, le bon attend (Q79). |
| M11 | `NotificationService::notifierBonVisiteur` prévient le DAF et le DAF adjoint à la soumission (type `bon_visiteur`). |
| RG-M07-01 | Plus de nouveau BP de visiteur. Les BP de visiteurs déjà émis gardent leur régularisation (`JustifierBp::initiateurDe`, directeur d'accueil) ; la migration les liste au journal. |

### 49.2 ODM

| Règle | Mise en œuvre |
|---|---|
| RG-M12-14 | Un bon par participant. Sont retirés : le paramètre `odm_mode_generation` (migration), son entrée dans `Parametre::CHOIX`, `GenererBonsOdm::bonGroupe`, l'option `beneficiaire_groupe_id` et l'écran du mode groupé (`Odm/Show.jsx`). La génération globale, individuelle ou par sélection reste (Q61 révisée). Les bons groupés déjà émis restent valides : paiement, répartition et imputation acceptent toujours plusieurs participants par bon. |
| RG-M12-38, RG-M12-26 | Le bon complémentaire d'un hébergement à l'étranger payé au retour ne naît plus en brouillon modifiable. À la clôture, chaque facture est jointe avec son montant (MSG-M03-09 si elle manque). Le bon est créé `genere_par_odm` (montant verrouillé), reçoit la facture (`PiecesBon::ajouter`) et est soumis aussitôt (`SoumettreBon`). `EnregistrementOdm` ignore une facture d'hébergement hors d'un ODM extérieur payé avant le départ. |

### 49.3 Libellés

- ERP Helium au lieu d'Infor IE dans les commentaires de la migration M16 et d'`ExportEcritures`.
- Les visiteurs ne sont plus annoncés comme pouvant recevoir un BP : `Visiteur`, `VisiteurController`, `Visiteurs.jsx`, menu.

### 49.4 Données

Migration `2026_10_20_000001_lot_v_visiteurs_et_bon_par_participant` :
- table `motifs_visiteur` ;
- colonnes `motif_visiteur`, `commentaire_visiteur`, `refacturation_inter_filiale`, `filiale_refacturation` sur `bons_caisse`, et reprise des bons existants au profit d'un visiteur ;
- paramètre `visiteur_finance_daf_seul` ajouté ; `odm_mode_generation` retiré.

Vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.

### 49.5 Vérifications

- **Nouveaux tests** : `tests/Feature/LotV/CorrectionsV24Test.php` (3 tests) :
  - Finance réservée au DAF, paramètre ;
  - notification, bandeau, export ;
  - motifs paramétrables.
- **Test ajouté** : `SaisieOdmTest::test_aucune_saisie_hors_bareme_sur_un_odm_interieur`.
- **Tests réécrits pour la v2.4** :
  - `TiersVisiteursTest` : BP refusé pour un visiteur, BD exceptionnel ;
  - `GenerationBonsOdmTest` : un bon par participant, même si un bon groupé est demandé ;
  - `PrerequisTest` : paramètre retiré, autre paramètre « choix » ;
  - `ClotureOdmTest` : facture obligatoire, bon complémentaire verrouillé et soumis.
- **Total** : 361 tests PHP et 74 tests JavaScript passent.
- **Parcours dans Edge** sur une copie de la base de dev, 15 vérifications, sans erreur JavaScript :
  1. motif ajouté au Paramétrage ;
  2. BP refusé pour un visiteur et type BP désactivé ;
  3. BD visiteur avec motif et commentaire, soumis ; bandeau, marquage, notification du DAF ;
  4. bon absent des validations du Chef comptable ; écran de visa du DAF avec le bandeau ;
  5. ODM sans mention du bon groupé ;
  6. téléphone.

  Une vérification SQL du script a échoué à cause de l'encodage de la console Windows (« Côte ») ; la valeur en base a été contrôlée à la main et elle est juste.

### 49.6 Déploiement

```bash
php artisan migrate        # 2026_10_20_000001
```

Ensuite :
- saisir la liste des motifs du bon visiteur fournie par le DAF (PO-30). Tant qu'elle est vide, un bon au profit d'un visiteur ne peut pas être soumis ;
- prévenir les demandeurs : un visiteur du groupe ne reçoit plus qu'un BD exceptionnel ;
- prévenir le Chef comptable : la Finance de ces bons revient au DAF et à son adjoint.

## 50. Version v50 — Lot E1 : entité transverse (16 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Premier lot du bloc E de la spec v2.4 (RG-M01-02, RG-M01-08, §2.1, SC-41). Neemba Guinée est seule active ; Neemba Mining est prévue, inactive : sa mise en service fait l'objet d'une proposition distincte.

### 50.1 Modèle

| Élément | Mise en œuvre |
|---|---|
| Table `entites` | Champs : code, nom, raison sociale, adresse, logo, actif. **NGU** Neemba Guinée est active ; **NMI** Neemba Mining est inactive. Modèle `Entite`, avec `parDefaut()`, `lignesAdresse()` et `cheminLogo()`. |
| `entite_id` obligatoire | Sur `users`, `caisses`, `bons_caisse` et `ordres_mission`, repris sur Neemba Guinée. |
| `entite_id` facultatif | Vide signifie « commun à toutes les entités ». Sur `services`, `codes_analytiques`, `clients`, `tiers`, `visiteurs`, `motifs_urgence`, `motifs_rejet`, `motifs_visiteur` et `parametres`. Les lignes existantes sont rattachées à Neemba Guinée ; les paramètres restent communs. |
| Champs texte | `users.entite` et `ordres_mission.entite` sont conservés. Le texte du compte, rempli par l'import des référentiels, désigne l'entité par son nom ou son code ; à l'enregistrement, il renseigne `entite_id`. **Attention : `$user->entite` lit ce texte ; la relation se lit par `entite()`.** |
| Droits par entité × rôle | `roles_utilisateurs.entite_id` : vide, le rôle vaut pour toutes les entités. Les rôles existants sont repris sur l'entité du compte. `User::ajouterRoles($roles, $entite)` prend l'entité du compte par défaut. `listeRoles()` ne retient, pour l'utilisateur connecté, que les rôles de son entité courante (le rôle principal vaut dans l'entité du compte). `listeRolesToutesEntites()` les donne tous. La portée `User::avecRoleDans($roles, $entite)` sert aux circuits. |

### 50.2 Cloisonnement

- **Trait `App\Models\Concerns\AppartientAUneEntite`** : portée globale `entite` et entité par défaut à la création.
  - Appliqué aux bons, ODM, caisses et référentiels. Pour les bons et les ODM, l'entité par défaut est celle du demandeur.
  - La portée filtre sur l'**entité courante** de l'utilisateur connecté. Un référentiel commun (entité vide) reste visible partout.
  - Pas de filtre en vue consolidée, ni hors session (commandes, tâches planifiées).
  - Listes, tableaux de bord, rapports, exports, imputation et liaison des routes suivent donc l'entité : un bon d'une autre entité répond 404.
  - `deLEntite($id)` lève la portée pour une entité donnée (null : toutes).
- **`App\Support\EntiteCourante`**, entité de travail de l'utilisateur connecté :
  - par défaut, celle du compte ; au choix, une autre entité active où il a des droits ;
  - choix gardé en session ; calcul mis en cache dans la requête ;
  - vue consolidée pour les rôles du paramètre `roles_vue_consolidee` (défaut : DAF, DAF adjoint, DP, DP adjoint, administrateur ; Q81), seulement si plusieurs entités sont actives.
- **Écrans** :
  - route `entite.choisir` ;
  - sélecteur `SelecteurEntite` dans le bandeau, affiché seulement avec plusieurs entités ou la vue consolidée ;
  - données partagées `auth.entite`.
- **Valideurs** : `CircuitBon::titulaires` et `CircuitOdm::titulaires` ne retiennent que les valideurs de l'entité du bon ou de l'ODM.

### 50.3 Numérotation, impression, saisie, exports

| Élément | Mise en œuvre |
|---|---|
| Numérotation | **Bons** : déjà par caisse, et la caisse porte l'entité. Le code de numérotation est unique dans l'entité, et `NumeroteurBon` ne cherche le plus grand numéro que dans l'entité. **ODM** : `compteurs_odm` unique sur (entité, préfixe, année) ; `NumeroteurOdm::prochain`, `definirDepart` et `dernier` prennent l'entité. |
| Gabarit d'impression | Le PDF du bon prend le logo et la raison sociale de l'entité du bon. Le PDF de l'ODM prend le nom, l'adresse et le logo de l'entité de l'ODM. |
| Saisie (RG-M03-11) | L'entité du demandeur est affichée et verrouillée dans l'assistant. L'ODM prend l'entité du demandeur ; la prolongation la reprend ; les bons générés prennent celle de l'ODM. |
| Paramétrage | Onglet « Entités » : nom, raison sociale, adresse, logo. La mise en service ne se fait pas depuis l'écran. Sur la fiche utilisateur, l'entité se choisit quand plusieurs entités sont actives. |
| Exports | L'export Excel des bons gagne la colonne « Entité ». L'export comptable gagne « Site analytique » et « Entité » (RG-M16-03). |

### 50.4 Données

Migration `2026_10_21_000001_lot_e1_entite_transverse` :
- table `entites` ;
- colonnes `entite_id` et reprise sur Neemba Guinée ;
- index uniques par entité sur `roles_utilisateurs` et `compteurs_odm` ;
- paramètre `roles_vue_consolidee`.

Vérifiée sur une copie de la base : aller, retour, aller, retour, aller. Le premier retour a échoué : la clé étrangère de `compteurs_odm` s'appuyait sur le nouvel index unique. Elle est maintenant retirée d'abord. La migration a ensuite été appliquée en dev.

### 50.5 Vérifications

- `tests/Feature/LotE1/EntiteTest.php`, 7 tests, Neemba Mining activée pour le test :
  - isolation des bons ;
  - vue consolidée réservée aux rôles habilités ;
  - droits par entité × rôle et valideurs de l'entité ;
  - numérotation des ODM par entité ;
  - référentiels propres et communs ;
  - entité à la saisie (avec le champ texte du compte rempli) et dans l'export ;
  - sans seconde entité active, rien ne change à l'écran.
- `ImputationComptableTest` : colonnes de l'export comptable.
- **Total** : 368 tests PHP et 74 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base de dev, Neemba Mining activée dans la copie : 15 vérifications, sans erreur JavaScript.
  - Onglet « Entités », entité de l'assistant.
  - Pas de sélecteur pour le demandeur.
  - Sélecteur du DAF ; bon de Mining en 404, puis ouvert en vue consolidée.
  - PDF du bon ; téléphone.

  Défaut relevé par le parcours : l'assistant plantait sur un compte réel. Son champ texte « entite » masquait la relation ; c'est corrigé, et un test couvre le cas.

### 50.6 Déploiement

```bash
php artisan migrate        # 2026_10_21_000001
```

Rien ne change à l'écran tant que seule Neemba Guinée est active. La mise en service de Neemba Mining, qui fait l'objet d'une proposition distincte, demandera :
- de l'activer en base ;
- de créer ses caisses, référentiels et comptes ;
- d'attribuer, depuis l'écran Utilisateurs, les rôles des personnes qui travaillent dans les deux entités. Cet écran est encore à faire : RG-M01-08 reste partiel sur ce seul point.

---

## 51. Version v51 — Lot E2 : double authentification (19 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Deuxième lot du bloc E de la spec v2.4 (RG-M01-07, §2.3, SC-40). Décisions appliquées : Q76 (urgence sur l'ODM) et Q77 (le code ne porte que sur l'approbation).

### 51.1 Connexion en deux temps

| Étape | Mise en œuvre |
|---|---|
| Mot de passe | `LoginRequest::verifierIdentifiants()` contrôle l'email et le mot de passe **sans ouvrir la session** (même limite d'essais qu'avant). Un compte désactivé est refusé (MSG-APP-084). |
| Code | Un code à 6 chiffres part par email (Mailable `CodeConnexion`, vue `emails/code-connexion`). Le compte en attente est gardé en session sous la clé `2fa` ; l'écran `Auth/CodeConnexion` montre l'adresse masquée et la durée. |
| Ouverture de la session | `CodeConnexionController::store` : `Auth::login` seulement après le bon code, puis retour à la page demandée. |
| Erreurs | Code faux : MSG-APP-078 avec les essais restants ; au dernier essai, le code tombe. Code expiré ou épuisé : MSG-APP-079. Envoi impossible (SMTP) : MSG-APP-080. |
| Renvoi | Bouton « Renvoyer un code » : une minute au moins entre deux envois (MSG-APP-085) ; le nouveau code remplace l'ancien (MSG-APP-077). |
| Routes | `login.code` (GET, POST) et `login.code.renvoyer`, dans le groupe `guest`. |

Comme la session ne s'ouvre qu'après le code, les tests qui utilisent `actingAs` ne changent pas. Seul `AuthenticationTest` est réécrit.

### 51.2 Visa d'une demande urgente

- Pour **approuver** un bon ou un ODM « urgent » ou « très urgent », le valideur demande un code par SMS (bouton « Recevoir le code par SMS »), puis le saisit. Le bouton d'approbation reste désactivé tant que le code n'a pas 6 chiffres. Le rejet et la demande de complément ne demandent pas de code (Q77).
- Composant partagé `CodeVisa` : écran de visa (`Validations/Show`), boîte « Approuver » de la fiche du bon (`BonsCaisse/Show`), bloc de visa de la fiche ODM (`Odm/Show`).
- Envoi : routes `validations.code-visa` et `odm.code-visa`, ouvertes au seul valideur de l'étape en cours (403 sinon). Le SMS part au téléphone du compte (`NimbaSmsService`) et nomme le bon ou l'ODM. Sans téléphone : MSG-APP-082. Réponse : MSG-APP-083, numéro masqué.
- Contrôle : `ValidationController::approuver` et `OrdreMissionController::viser`. Le code est lié au valideur **et** au bon ou à l'ODM : il ne sert pas pour une autre demande.
- Sans fournisseur SMS, en local, en test et en recette (`e2e`), le code est écrit dans le journal (« Code de visa (SMS non envoyé…) : NNNNNN »). En production, l'envoi est refusé (MSG-APP-080).
- Distinct de l'OTP de paiement au bénéficiaire (`otp_validations`, RG-M06-02), qui ne change pas.

### 51.3 Urgence sur l'ODM (Q76)

- Colonnes `ordres_mission.niveau_urgence` (« normale » par défaut) et `motif_urgence` ; mêmes niveaux que le bon (`BonCaisse::NIVEAUX_URGENCE`) et même liste de motifs (`motifs_urgence`).
- Formulaire de l'ODM : champ « Urgence » après le véhicule ; « Motif de l'urgence » apparaît pour un ODM urgent ou très urgent.
- `ReglesOdm` : un ODM urgent sans motif de la liste ne se soumet pas (MSG-M03-08). Un ODM repassé en « normale » perd son motif.
- Fiche de l'ODM : urgence et motif affichés.

### 51.4 Données et paramètres

Migration `2026_10_22_000001_lot_e2_double_authentification` :
- table `codes_otp` : compte, objet (`connexion`, `visa_bon`, `visa_odm`), cible, canal (email, SMS), **empreinte** du code (HMAC avec la clé de l'application ; jamais le code en clair), essais, expiration, consommation ;
- colonnes d'urgence de l'ODM ;
- paramètres du groupe « Sécurité », réglés par l'administrateur dans l'onglet « Sécurité », avec effet immédiat (§51.7) :

| Paramètre | Défaut | Rôle |
|---|---|---|
| `double_authentification_active` | oui | Paramètre de secours : sur « non », connexion par mot de passe seul (panne SMTP) |
| `otp_connexion_duree` | 10 min | Durée du code de connexion |
| `otp_connexion_tentatives` | 5 | Essais par code (connexion et visa) |
| `otp_visa_urgent_actif` | oui | Paramètre de secours : sur « non », visa urgent sans code (panne SMS) |
| `otp_visa_duree` | 5 min | Durée du code de visa |

Service : `App\Services\Securite\DoubleAuthentification` (émission, envoi, vérification, masquage). Modèle `CodeOtp`.

### 51.5 Vérifications

- `tests/Feature/Auth/AuthenticationTest.php`, réécrit (10 tests) : code exigé, code faux puis épuisé, code expiré, renvoi après une minute, écran du code sans mot de passe, compte désactivé, paramètre de secours.
- `tests/Feature/LotE2/CodeVisaUrgentTest.php` (8 tests, SMS simulé) : visa d'un bon urgent (SC-40), code lié au bon, bon normal et rejet sans code, demande de code réservée au valideur, compte sans téléphone, paramètre de secours, ODM urgent (motif exigé, puis visa avec le code), ODM repassé en « normale ».
- **Total** : 382 tests PHP et 74 tests JavaScript passent (389 PHP après l'onglet « Sécurité », §51.7).
- Migration : aller, retour, aller sur une copie de la base, puis appliquée en dev.
- Parcours dans Edge sur une copie de la base de dev, avec les comptes réels : 25 vérifications, sans erreur JavaScript.
  - Connexion de Souadou BARRY : écran du code, pas de session avant le code, code faux, renvoi trop tôt, code juste.
  - Bon urgent visé par le chef de service (écran de visa) puis par le CDG (fiche du bon), chacun avec son code SMS lu dans le journal.
  - ODM urgent : motif exigé ; visa du chef d'atelier avec le code.
  - Écran du code sur téléphone.

  Défaut relevé par le parcours : le message « Patientez une minute » du renvoi ne s'affichait pas (erreur portée par la page, pas par le formulaire, et masquée par l'erreur précédente). C'est corrigé.

### 51.6 Déploiement

```bash
php artisan migrate        # 2026_10_22_000001
```

À vérifier avant la mise en production :
- **SMTP** (PO-23) : sans serveur d'email, personne ne peut se connecter. En cas de panne, désactiver le code de connexion dans Paramétrage > Sécurité, ou sur le serveur : `php artisan securite:double-authentification desactiver`.
- **SMS** : le jeton Nimba doit être en place ; sinon, les visas urgents sont bloqués en production. En cas de panne, désactiver le code de visa dans Paramétrage > Sécurité, ou : `php artisan securite:double-authentification desactiver --visa`.
- **Téléphones** : chaque valideur doit avoir un numéro sur son compte.

### 51.7 Onglet « Sécurité » : l'administrateur active ou désactive la double authentification (19/10/2026)

Demande de l'utilisateur, le 19/10 : l'administrateur doit pouvoir activer et désactiver la double authentification et ses réglages. Décision Q82.

- **Onglet « Sécurité »** du paramétrage (composant `ReglagesSecurite`), réservé à l'administrateur :
  - deux interrupteurs : « Code de connexion par email » et « Code SMS pour viser une demande urgente ». Chacun s'applique dès qu'on le bascule ; son état affiché est celui enregistré ;
  - durée du code de connexion (1 à 60 min), essais par code (1 à 10), durée du code de visa (1 à 30 min) ;
  - une confirmation est demandée avant de couper un interrupteur (« Désactiver maintenant ») ; le rallumer est immédiat ;
  - les durées et essais s'enregistrent avec le bouton « Enregistrer les durées et essais » ; une durée est grisée quand son code est coupé ;
  - état des canaux : serveur d'email configuré ou non (en mode `log`, les codes sont écrits dans le journal), fournisseur SMS configuré ou non ;
  - valideurs actifs (bons et ODM) sans numéro de téléphone, qui ne peuvent pas recevoir le code de visa ;
  - les dix derniers changements (date, réglage, avant → après, auteur).
- **Effet immédiat, sans second validateur** (`ReglagesSecurite::appliquer`, route `parametrage.securite.update`) :
  - chaque changement est tracé dans le journal des modifications (« Modifications Admin »), approuvé par son auteur ;
  - une double validation encore en attente sur le même réglage est close ;
  - les autres administrateurs reçoivent une notification quand un interrupteur change.
- Ces cinq réglages sortent de l'onglet « Seuils » ; la route générique des paramètres les refuse.
- **Secours sur le serveur**, quand plus personne ne peut se connecter : `php artisan securite:double-authentification desactiver` (ou `activer`, et `--visa` pour le code de visa). Le changement est écrit dans le journal applicatif.
- **Vérifications** :
  - `tests/Feature/LotE2/ReglagesSecuriteTest.php`, 7 tests : écran, désactivation tracée et autres administrateurs prévenus, connexion directe, réactivation et durée, bornes, accès réservé, double validation close, commande de secours ;
  - 389 tests PHP et 74 tests JavaScript passent ;
  - parcours dans Edge sur une copie de la base, 16 vérifications, sans erreur JavaScript : désactivation avec confirmation, trace, autre administrateur prévenu, connexion sans code, réactivation à 15 min, téléphone.
- Sur la base de dev, deux valideurs n'ont pas de téléphone : Mamadou Alpha BAH et Saliou BOIRO.
- **Correctif du 19/10 (retour de l'utilisateur, « la désactivation ne marche pas »)** : dans la première version, l'interrupteur ne changeait que l'écran, et le badge affichait « Désactivé » avant tout enregistrement. Il fallait encore cliquer sur « Enregistrer les réglages », plus bas, et rien n'était enregistré sinon. L'interrupteur s'applique désormais tout de suite. Le parcours a aussi relevé un plantage du bouton des durées (`form.transform(...)` chaîné) ; il est corrigé. Parcours dans Edge rejoué : 20 vérifications, sans erreur JavaScript.

---

## 52. Version v52 — Lot E3 : gestionnaire des référentiels, barèmes ODM datés, types d'OR (19 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Troisième lot du bloc E de la spec v2.4 (RG-M01-09, RG-M02-10, RG-M02-08, RG-M02-01, RG-M03-13, §6.5, §6.6). Décisions appliquées : Q78, Q83, Q84.

### 52.1 Gestionnaire des référentiels (RG-M01-09)

- Nouveau rôle complémentaire `gestionnaire_referentiels` (« Gestionnaire des référentiels »), attribué depuis l'écran Utilisateurs. Ce n'est pas un rôle principal : la colonne `users.role` ne le connaît pas.
- **Paramétrage partagé en deux** (routes et onglets) ; un compte qui a les deux rôles voit tout :

| Rôle | Onglets |
|---|---|
| Administrateur | Entités, Sites, Caisses, Seuils (paramètres techniques), Sécurité ; comptes et droits (écran Utilisateurs) |
| Gestionnaire des référentiels | Services, Codes analytiques, Types de document, Motifs d'urgence, de rejet et du bon visiteur, Types d'OR, Barèmes ODM |

- Le groupe de routes `/parametrage` est ouvert aux deux rôles ; chaque route d'écriture porte son propre `role:`. Le serveur n'envoie à l'écran que les données des onglets visibles (prop `onglets`).
- **Clients** (Q83) : gestionnaire des référentiels, Comptabilité, Chef comptable et CDG ; l'administrateur perd l'accès (menu et routes).
- Les changements de codes analytiques restent soumis à la double validation, approuvée par un administrateur, jusqu'au second validateur paramétrable du lot G.
- **À faire chez Neemba** : désigner le gestionnaire (CDG ou Comptabilité selon la spec) et lui attribuer le rôle. Tant que personne ne l'a, les référentiels métier ne se modifient plus.

### 52.2 Barèmes ODM datés (RG-M02-10, RG-M02-01, §6.6)

- Table `baremes_odm` : entité (vide = commun), clé, valeur, date d'effet, saisi par, commentaire. Modèle `BaremeOdm` :
  - `CLES` : indemnité journalière, libellés des deux lignes d'indemnité, hébergement par nuit, indemnités extérieures FCFA non-cadre et cadre ;
  - `ligneEnVigueur($cle, $date, $entite)` : dernière valeur dont la date d'effet est passée ; la valeur de l'entité l'emporte sur la valeur commune ; sans entité, l'entité par défaut ;
  - `enVigueur()`, `tous()`.
- Reprise : les six paramètres du groupe « odm » deviennent des barèmes communs, datés du jour de leur création (08/10/2026 en dev). Les paramètres sont retirés ; une double validation en attente sur eux est close.
- `CalculOdm::baremesEnVigueur($date, $entite)` lit le référentiel. La soumission fige les barèmes du jour pour l'entité de l'ODM (`parametres_figes`), comme avant ; un brouillon est recalculé avec ceux du jour.
- **Onglet « Barèmes ODM »** (composant `Parametrage/BaremesOdm`) :
  - valeurs en vigueur, date de début, prochaine valeur datée ;
  - nouvelle valeur : barème, valeur (montant entier ou libellé), date d'effet (aujourd'hui ou plus tard), commentaire ;
  - historique (date d'effet, valeur, auteur, date de saisie, commentaire) ; une valeur « à venir » peut être retirée, une valeur en vigueur non (Q84).
- Aucune double validation (RG-M02-08 : barèmes exclus).

### 52.3 Types d'OR en référentiel (§6.5, RG-M03-13, PO-28)

- Table `types_or` (entité, code, libellé, actif, ordre), avec `vente` et `garantie` au départ. Modèle `TypeOr` : `options()` (types actifs), `codesActifs()`, `libelles()` (tous, pour l'affichage), `libelle()`, `decrire()`.
- `odm_ordres_reparation.type` passe de l'enum `vente/garantie` à une chaîne. La constante `OrdreReparationOdm::TYPES` disparaît.
- Les contrôles (assistant du bon, saisie de l'ODM) n'acceptent que les codes actifs. MSG-APP-062 : « Choisissez le type de l'OR :numero dans la liste des types d'OR. »
- Les listes viennent du serveur :
  - assistant du bon : prop `typesOr` ; le clic sur le type d'un OR passe au type suivant de la liste ;
  - formulaire de l'ODM : prop `typesOr`.
- Les libellés viennent aussi du serveur : fiche du bon (`typesOrLibelles`), fiche et PDF de l'ODM (`type_label`), tableau de bord des ODM, export Excel des bons (colonne « OR » avec le type de chaque OR).
- Onglet « Types d'OR » : ajout (code en minuscules, figé ensuite), libellé modifiable, désactivation ; au moins un type reste actif. Un type désactivé n'est plus proposé ; les OR déjà saisis gardent son libellé.

### 52.4 Données

Migration `2026_10_23_000001_lot_e3_referentiels_metier` : tables `types_or` et `baremes_odm`, reprise des barèmes, colonne `type` des OR d'ODM. Vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.

### 52.5 Vérifications

- `tests/Feature/LotE3/ReferentielsMetierTest.php`, 7 tests : onglets et droits répartis (clients compris), reprise des barèmes, barème à venir appliqué aux ODM soumis après sa date (l'ODM déjà soumis garde l'ancien), date passée refusée, libellé, retrait d'une valeur à venir seulement, barème d'entité prioritaire, types d'OR (ajout, ODM, fiche, formulaire, désactivation), un type reste actif.
- `SaisieBonTest` : un type ajouté est accepté à la saisie du bon, désactivé il est refusé.
- Tests adaptés : écrans des référentiels passés au gestionnaire ; barèmes lus dans le référentiel ; six paramètres « odm » au lieu de douze.
- **Total** : 397 tests PHP et 74 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base (rôle donné dans la copie à Maïmouna BARRY, CDG) : 19 vérifications, sans erreur JavaScript.
  - Administrateur : onglets techniques seulement, pas de menu Clients.
  - Gestionnaire : onglets métier, menu Clients.
  - Barème à venir enregistré sans double validation, affiché, puis retiré ; libellé changé le jour même.
  - Type « Interne » ajouté, proposé dans le formulaire de l'ODM et dans l'assistant du bon.
  - Téléphone.

### 52.6 Déploiement

```bash
php artisan migrate        # 2026_10_23_000001
```

Puis attribuer le rôle « Gestionnaire des référentiels » à la personne désignée par Neemba (écran Utilisateurs, rôles complémentaires).

---

## 53. Version v53 — Lot E4 : caisses et sites, site analytique (19 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Dernier lot du bloc E de la spec v2.4 (RG-M02-11, RG-M03-27, RG-M03-11, §6.1, SC-33). Décisions : Q85, Q86.

### 53.1 Une caisse sert un ou plusieurs sites (RG-M02-11, §6.1)

- Table `caisse_site` (caisse, site). La colonne `caisses.site_id` est retirée ; la migration reprend chaque caisse avec son site, **sauf la caisse Orange Money**, qui n'a plus de site.
- `Caisse::sites()` et `Site::caisses()` passent par la liaison.
  - `Caisse::duSite($nom)` : caisses qui servent ce site.
  - `sites_libelle` : « Conakry », « Boke, Sangaredi » ou « Aucun site ».
  - `sertLeSite($nom)`.
- Compatibilité : l'attribut `site_id` reste accepté à la création (fabriques de test, seeders, nouveau site) ; il ajoute ce site aux sites servis.
- **Écran des caisses** :
  - colonne « Sites servis » ;
  - cases à cocher des sites dans la fiche, à la création et à la modification (au moins un pour une caisse espèces, aucun pour la caisse OM) ;
  - changement d'effet immédiat (Q86) ; plafonds, seuils, avance et solde restent en double validation.
- Les écrans qui affichaient « le site de la caisse » ne plantent plus sur une caisse sans site :
  - tableau de bord (soldes par caisse) ;
  - mouvements de caisse (le mouvement prend le premier site servi) ;
  - caisses de rattachement de l'écran Utilisateurs ;
  - alerte de solde (caissiers des sites servis) ;
  - import des référentiels.

### 53.2 Caisse payeuse et plafond de retrait

- `BonCaisse::caissePayeuse()` ne dépend plus du site du bon. En espèces, c'est la caisse émettrice active ; à défaut, une caisse qui sert le site **du demandeur**, puis la caisse principale de Conakry. En OM : la caisse OM.
- Le plafond de retrait contrôlé à la saisie est celui de cette caisse (ReglesSaisie, contrôles, paiement).
- Replis par site (caissier sans caisse de rattachement) : `ControleCaissier::bonDuSite` dit si la caisse émettrice du bon sert le site du caissier ; un ancien bon sans caisse émettrice garde son site. Ce repli sert au paiement, à la visibilité du bon et à la liste des bons.
- Rapport journalier : celui d'un site reprend les caisses qui servent ce site ; la caisse OM n'apparaît que dans « Tous les sites », jusqu'au rapport par caisse du lot F2 (Q85).

### 53.3 Site analytique (RG-M03-27)

- **Saisie** : à l'étape 1 de l'assistant, le champ « Site analytique » vaut par défaut le site du demandeur et se choisit dans la liste des sites actifs. Changer de titulaire sans avoir choisi de site reprend le site du nouveau titulaire. La caisse émettrice et la caisse qui paie ne changent pas.
- Contrôle : site existant et actif, au brouillon comme à la soumission.
- **CDG** : à son étape, sur l'écran de visa comme dans la fiche du bon, un sélecteur « Site analytique » s'ajoute à la correction du code analytique. Une correction est tracée au journal du bon : action `modification_site`, « Site analytique corrigé par le CDG : Boke → Sangaredi ».
- Libellé « Site analytique » sur l'écran de visa et la fiche du bon. La colonne « Site analytique » de l'export comptable existe depuis le lot E1.

### 53.4 Données

Migration `2026_10_24_000001_lot_e4_caisses_sites`.
- Vérifiée sur une copie de la base de dev, aller, retour, aller. Au retour, la caisse OM retrouve Conakry.
- Puis appliquée en dev : sept caisses gardent leur site, la caisse OM n'en a plus.

### 53.5 Vérifications

- `tests/Feature/LotE4/CaissesSitesTest.php`, 5 tests (SC-33) :
  - un site a deux caisses ; la caisse OM n'a pas de site ; écrans sans plantage ;
  - une caisse sert deux sites et un caissier de chacun la paie ;
  - sites servis au paramétrage (obligatoires en espèces, interdits en OM) ;
  - site analytique sans effet sur la caisse ni sur le plafond ;
  - correction par le CDG avec trace.
- Tests adaptés : le site saisi n'est plus ignoré (lot C) ; la caisse d'un nouveau site se cherche par ses sites servis.
- **Total** : 402 tests PHP et 74 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base : 16 vérifications, sans erreur JavaScript.
  - Caisse OM « Aucun site » ; Boké qui sert aussi Sangarédi.
  - Tableau de bord et mouvements de caisse affichés.
  - Site analytique Boké choisi à la saisie, caisse de Conakry conservée.
  - Correction par le CDG en Sangarédi, tracée.
  - Téléphone.

### 53.6 Déploiement

```bash
php artisan migrate        # 2026_10_24_000001
```

Ensuite, vérifier les sites servis de chaque caisse à l'écran des caisses. Le tableau §6.1 de la spec donne un site par caisse, et aucun pour la caisse OM.

---

## 54. Version v54 — Lot O : OR à régulariser, besoin carburant de l'ODM (19 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Spec v2.4 : RG-M12-02, RG-M12-39 à RG-M12-42, MSG-M12-01, 10 et 11, SC-38. Décisions : Q80, Q87.

### 54.1 « OR à régulariser » (RG-M12-02, RG-M12-39)

- **Formulaire** : pour une mission technique encore sans OR, la case « OR à régulariser » apparaît sous les OR liés. Elle se décoche dès qu'un OR est saisi, et ne vaut que pour une mission technique.
- `ReglesOdm` accepte une mission technique sans OR quand la case est cochée. Le nouveau texte de MSG-M12-01 : « Mission technique : rattachez au moins un OR ou cochez « OR à régulariser ». »
- **Validation finale** : échéance `or_echeance` = validation + `odm_delai_or` jours ouvrés (5 par défaut). Le demandeur est prévenu (MSG-M12-10).
- **OR rattaché après coup** (service `RegulariserOr`, route `odm.ajouter-or`) :
  - depuis la fiche de l'ODM (« Rattacher un OR » : n°, type du référentiel, client facultatif), par le demandeur, l'initiateur ou un chef d'atelier titulaire de l'étape ; l'ODM doit être soumis ou plus avancé ;
  - format contrôlé (MSG-M03-07), type actif, pas de doublon (MSG-APP-086) ; sinon MSG-APP-087 ;
  - l'OR garde sa date et son auteur (`saisi_apres_coup_le`, `saisi_par`) ; historique de l'ODM `or_ajoute` (avant / après) ;
  - l'indicateur tombe au premier OR ;
  - Q80 : l'OR est ajouté aux OR des bons de l'ODM (sauf annulés), avec une ligne `or_ajoute` au journal de chaque bon.

### 54.2 Relances, retard, blocage (RG-M12-40)

- Commande `odm:relancer-or`, chaque jour à 7 h 15 (Q87). Chaque envoi part une seule fois et entre dans l'historique de l'ODM :
  - J+3 (jours ouvrés après la validation) : relance au demandeur (MSG-M12-10) ;
  - jour de l'échéance : seconde relance ;
  - lendemain de l'échéance : alerte au chef d'atelier (titulaires de l'étape) et au DAF (et DAF adjoint).
- **Blocage du prochain ODM** : paramètre `odm_blocage_or_retard` (désactivé par défaut). Actif, la création d'un ODM est refusée au demandeur qui a un OR en retard (MSG-M12-11).

### 54.3 Suivi (RG-M12-41)

- Tableau de bord des ODM :
  - indicateur « OR à régulariser (n) — x en retard » ;
  - section « OR à régulariser » : ODM, demandeur, chef d'atelier, validation, échéance, retard en jours, statut.
- Export Excel du tableau de bord : onglet « OR à régulariser », ajouté en dernier.

### 54.4 Besoin carburant (RG-M12-42)

- Colonnes `immatriculation` (en majuscules) et `itineraire` ; `vehicule` garde le modèle du véhicule.
- Champs du formulaire ; fiche de l'ODM (« Véhicule » : modèle et immatriculation ; « Itinéraire ») ; PDF.
- Obligatoires dès qu'une avance carburant est demandée : au lot H, avec le bon carburant rattaché à l'ODM. Le carburant reste hors des lignes de frais.

### 54.5 Défaut corrigé : urgence de l'ODM au formulaire

`donneesOdm` (resources/js/utils/odm.js) n'envoyait ni l'urgence ni le motif d'urgence ajoutés au lot E2 : choisis à l'écran, ils n'étaient pas enregistrés. Les tests et le parcours du lot E2 passaient par l'API. La fonction envoie désormais l'urgence, le motif, la case « OR à régulariser », l'immatriculation et l'itinéraire. Un test JavaScript et le parcours dans Edge le vérifient.

### 54.6 Données

Migration `2026_10_25_000001_lot_o_or_a_regulariser` :
- colonnes de l'ODM : indicateur, échéance, dates des relances et de l'alerte, immatriculation, itinéraire ;
- date et auteur des OR saisis après coup ;
- paramètres `odm_delai_or` (5) et `odm_blocage_or_retard` (non).

Vérifiée sur une copie de la base de dev (aller, retour, aller), puis appliquée en dev.

### 54.7 Vérifications

- `tests/Feature/LotO/OrARegulariserTest.php`, 6 tests (SC-38) :
  - soumission sans OR avec la case ;
  - échéance et message à la validation ;
  - OR rattaché après coup, refus (tiers, format, type, doublon), report sur les bons ;
  - relances J+3 et échéance, alerte de retard, une seule fois chacune ; tableau de bord et export ;
  - blocage optionnel ;
  - besoin carburant (fiche, PDF).
- Tests adaptés : texte de MSG-M12-01, nombre d'onglets de l'export, nombre de paramètres « odm ».
- **Total** : 408 tests PHP et 75 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base : 14 vérifications, sans erreur JavaScript.
  - Case cochée au formulaire, avec immatriculation et itinéraire, puis soumission.
  - Visas de Thierry GOMIS (chef d'atelier dans la copie), Mohamed DIAKITE et Mamadou LO.
  - Échéance ; ligne au tableau de bord du DAF.
  - Échéance passée dans la copie, commande lancée : chef d'atelier et DAF alertés.
  - OR rattaché par Souadou BARRY.
  - Urgence choisie au formulaire, enregistrée.
  - Téléphone.

### 54.8 Déploiement

```bash
php artisan migrate        # 2026_10_25_000001
```

La commande `odm:relancer-or` est planifiée (`routes/console.php`) ; le planificateur doit tourner (`schedule:run`).

---

## 55. Version v55 — Lot F0 : PV d'arrêté de caisse (19 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Spec v2.4 : RG-M10-02, RG-M10-03, Annexe 2, RG-M08-05 (préalable du réapprovisionnement).

Décisions de l'utilisateur du 19/10 :
- Q88 : le billetage et le PV d'arrêté par caisse passent avant F1, parce que la demande de réapprovisionnement s'appuie dessus ;
- Q89 : rôle « Membre du CODIR » pour F1.

### 55.1 PV d'arrêté (RG-M10-02, Annexe 2)

- Table `pv_arretes`, modèle `PvArrete`, service `App\Services\Caisse\ArreteCaisse`, contrôleur `PvArreteController`, menu « PV d'arrêté ».
- **Établissement** par le gérant de la caisse, la trésorerie ou le Chef comptable (Q90). Le gérant est le gestionnaire, le suppléant ou un caissier rattaché à la caisse. Un PV contient :
  - date et heure ;
  - caisse et implantation (sites servis) ;
  - motif : inventaire de fin de mois, avant un réapprovisionnement, contrôle inopiné ou arrêté de caisse ;
  - billetage par coupure, de 20 000 à 50 GNF (pour la caisse Orange Money : solde lu sur la plateforme) ;
  - solde physique ;
  - solde comptable de la caisse au moment de l'arrêté ;
  - écart, affiché au fil de la saisie ;
  - observations.
- Numéro `PV-<code caisse>-<AAAAMMJJ>-<rang>`. Un PV ne se modifie pas : un nouveau comptage donne un nouveau PV.
- **Signatures** : gérant de la caisse, trésorerie, Chef comptable. Chacune est donnée une fois, par trois personnes différentes : un compte qui a deux de ces rôles n'en signe qu'une.
- **Consultation** : le gérant voit les PV de sa caisse ; DAF, DAF adjoint, Chef comptable, trésorerie, DP, DP adjoint et administrateur voient toutes les caisses. Un demandeur n'y a pas accès.
- **Impression** : PDF « Procès-verbal d'arrêté de caisse » (Annexe 2), avec l'en-tête de l'entité, le billetage, les soldes, l'écart en rouge et les trois signatures.

### 55.2 Écart → ajustement d'inventaire (RG-M10-03)

- À la troisième signature, un écart non nul crée un mouvement de caisse `ajustement_inventaire` « En attente » : montant de l'écart, sens entrée (excédent) ou sortie (manquant), lien au PV. Le DAF est prévenu.
- Seuls le DAF et le DAF adjoint le valident, avec un motif de 10 caractères au moins. La boîte « Valider » des mouvements de caisse demande ce motif. Un manquant débite la caisse, un excédent la crédite.
- La colonne `mouvements_caisse.type` passe de l'enum à une chaîne : les types du M08 suivent au lot F1.

### 55.3 Données

Migration `2026_10_26_000001_lot_f0_pv_arrete_caisse`. Vérifiée sur une copie de la base de dev (aller, retour, aller), puis appliquée en dev.

### 55.4 Vérifications

- `tests/Feature/LotF0/PvArreteTest.php`, 4 tests :
  - établissement par le gérant, refus sur une autre caisse, visibilité, PDF ;
  - un compte seulement rattaché à la caisse n'est pas gérant ;
  - trois signatures, un même compte ne signe pas deux fois ;
  - ajustement validé par le DAF seul, avec motif, et débit de la caisse ;
  - aucun ajustement sans écart ;
  - caisse Orange Money.
- **Total** : 412 tests PHP et 75 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base, avec les comptes réels : 14 vérifications, sans erreur JavaScript.
  - Youssouf TOURE (caissier, aussi trésorerie) établit le PV de Conakry avec un écart de −10 000 et signe comme gérant, sans pouvoir signer aussi pour la trésorerie.
  - Mariamagbè CAMARA (trésorerie) et Mamadou Alpha BAH (Chef comptable) signent. Le Chef comptable est rattaché à la caisse sans être gérant.
  - PDF.
  - Mohamed DIAKITE (DAF) valide l'ajustement avec un motif : le solde de la caisse baisse de 10 000.
  - Accès refusé à un demandeur ; téléphone.
- Défauts relevés en cours de lot, corrigés :
  - tout compte rattaché à la caisse aurait pu signer comme gérant : dans la base, chaque compte est rattaché à la caisse de Conakry ;
  - le billetage s'affichait de 50 à 20 000 : le JSON stocké en base trie les clés.

### 55.5 Déploiement

```bash
php artisan migrate        # 2026_10_26_000001
```

Renseigner le gestionnaire (et le suppléant) de chaque caisse à l'écran des caisses. Sinon, seul un caissier rattaché à la caisse en est le gérant.

---

## 56. Version v56 — Lot F1 : caisses, réapprovisionnement, encaissement client, réconciliation OM (19 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Spec v2.4, module M08 : RG-M08-02 à RG-M08-10, RG-M08-12, RG-M08-13, MSG-M08-01 à 06, US-01 à US-10, SC-15 à SC-17, SC-35.

Le lot est livré en trois parties, chacune avec ses commits : F1a (alertes, encaissements, visibilité), F1b (réapprovisionnement), F1c (réconciliation Orange Money).

### 56.1 F1a — Alertes de seuil, encaissements clients, visibilité par caisse

- **Alerte de seuil (RG-M08-03, MSG-M08-01)** : envoyée une fois, quand le solde franchit le seuil vers le bas, quel que soit le mouvement (paiement, ajustement, transfert). Elle part après la validation de la transaction, pour ne pas alerter sur un mouvement annulé. Destinataires : gérants de la caisse, trésorerie, Chef comptable ; le DAF ne la reçoit plus. Un rappel part chaque jour à 8 h tant que le solde reste sous le seuil, une fois par jour au plus (`caisses.alerte_seuil_le`, `alerte_seuil_rappel_le`, service `AlerteSeuil`).
- **Encaissement client (RG-M08-10, MSG-M08-05, 06)** :
  - seulement sur une caisse autorisée (Conakry principale), par son gérant ou la trésorerie ;
  - champs : client du référentiel, n° de pièce (OR ou facture), montant, mode (espèces ou chèque), observations, pièce ;
  - un encaissement en espèces qui porterait le solde au-dessus du plafond est refusé, et la trésorerie est alertée pour une remise en banque ;
  - reçu PDF A5 numéroté `REC-<code caisse>-<année>-<00001>`, montant en lettres.

  Table `encaissements_clients`, écran « Encaissements clients ».
- **Visibilité (RG-M08-12)** : service `AccesCaisse`. Le caissier voit et opère sa caisse. Les rôles transverses voient toutes les caisses : DAF, DAF adjoint, Chef comptable, trésorerie, DP, DP adjoint, administrateur. Cela vaut pour les mouvements, le tableau de bord et les PV d'arrêté.
- **Mouvements autorisés (RG-M08-02)** : le retrait libre et l'ajustement manuel disparaissent. Nouvelles natures au registre : `ajustement_inventaire`, `encaissement_client`, `transfert_emis`.

### 56.2 F1b — Demande de réapprovisionnement (RG-M08-04 à RG-M08-09)

Table `demandes_reapprovisionnement`, modèle `DemandeReapprovisionnement`, service `App\Services\Caisse\Reapprovisionnement`, contrôleur `ReapprovisionnementController`, menu « Réapprovisionnements ».

- **Demande** par le gérant de la caisse :
  - PV d'arrêté du jour de la caisse, signé par le gérant, et rapprochement comptable signé, joint en pièce. Sans eux : MSG-M08-03. L'écart du PV (billetage − solde comptable) est affiché ;
  - montant proposé = plafond de caisse − solde. Pour une caisse en avance fixe (Atelier) : avance − solde. Le montant se baisse avec un motif de 10 caractères au moins ;
  - le solde après réapprovisionnement ne dépasse jamais le plafond (MSG-M08-02), contrôlé aussi au fil de la saisie ;
  - rien au-delà de l'habilitation du DP : paramètre `habilitation_dp_reappro`, 500 000 000 GNF ;
  - une seule demande en cours par caisse. Numéro `DRA-<code caisse>-<année>-<0001>`.
- **Circuit** :
  1. trésorerie : valide, en réduisant le montant au besoin avec un motif, ou refuse ;
  2. Finance (Chef comptable, DAF, DAF adjoint) : vise ;
  3. DP ou DP adjoint : approuve.

  Chaque étape notifie la suivante. Le refus est motivé, à toute étape avant l'approbation, et notifié au demandeur. Personne ne vise une demande qu'il a faite, ni deux étapes de la même demande.
- **Absence du DP (Q89, Q91)** : nouveau rôle complémentaire « Membre du CODIR » (`membre_codir`). Quand aucun DP ni DP adjoint actif n'existe, ou que le délai de l'étape DP (`sla_directeur_pays`, en heures ouvrées) est écoulé depuis le visa Finance, deux membres du CODIR différents approuvent ensemble. Le premier visa prévient les autres membres. Les membres du CODIR voient les demandes de toutes les caisses.
- **Exécution (RG-M08-08)** par la trésorerie, avec :
  - l'origine des fonds : chèque déposé en banque, mise à disposition par la banque, Orange Money ; virement pour la caisse OM ; transfert depuis une autre caisse si la caisse l'autorise (`caisses.reappro_par_transfert`, activé par la migration pour les caisses en avance fixe, case à l'écran des caisses) ;
  - une référence, une pièce et le montant émis.

  Un transfert débite la caisse source (écriture `transfert_emis`), dont le solde doit couvrir le montant (Q93).
- **Réception (RG-M08-09)** : la caisse n'est créditée (nature `approvisionnement`) qu'à la confirmation du gérant, avec le montant reçu et la pièce (bordereau, copie de mise à disposition, reçu). Un écart entre émis et reçu alerte le DAF et le DAF adjoint (MSG-M08-04) et s'affiche sur la demande.
- **Plus de mouvement saisi à la main (RG-M08-02, Q94)** : les routes `mouvements-caisse.create` et `store`, et l'écran de création, sont retirés. Le bouton de la liste des mouvements ouvre la demande de réapprovisionnement. Les mouvements déjà en attente restent validables.
- **Gérant sans rôle caissier (Q95)** : le gestionnaire ou le suppléant d'une caisse active (exemple : la gérante de la caisse Atelier) reçoit le pseudo-rôle `gerant_caisse`. Il ouvre les PV d'arrêté et les réapprovisionnements (routes et menu), ce qui manquait au lot F0.

### 56.3 F1c — Réconciliation hebdomadaire de la caisse Orange Money (RG-M08-13, §5.4)

Table `reconciliations_om`, modèle `ReconciliationOm`, service `App\Services\Caisse\ReconciliationOrangeMoney`, contrôleur `ReconciliationOmController`, menu « Réconciliation OM ».

- **Établie par la trésorerie**, pour une semaine du lundi au dimanche (la semaine écoulée est proposée), avec le relevé exporté de la plateforme en CSV ou Excel. Numéro `ROM-<code caisse>-<année ISO>-S<semaine>`.
- **Lecture du relevé (Q96)** : le format n'est pas fixé, l'API Orange Money étant hors périmètre. L'en-tête est reconnu dans les 15 premières lignes :
  - « Date » ;
  - « Montant » signé, ou « Débit » et « Crédit » ;
  - si possible « Référence », « Libellé », « Solde ».

  Sont acceptés : le séparateur point-virgule ou virgule, les dates JJ/MM/AAAA ou Excel, les montants avec espaces. Les lignes hors de la semaine sont écartées. Un relevé sans date ni montant est refusé (MSG-APP-103).
- **Rapprochement automatique** : une écriture du brouillard et une ligne du relevé de même sens et de même montant sont rapprochées si leurs dates sont au plus à `reconciliation_om_delai_jours` jours (2 par défaut). L'écran liste :
  - les lignes du relevé absentes du brouillard ;
  - les écritures absentes du relevé ;
  - les lignes rapprochées, avec l'écriture correspondante ;
  - les soldes du brouillard (début, fin), le solde de fin du relevé et l'écart.
- **Solde du relevé (Q97)** : lu dans la colonne « Solde », sinon saisi. Sans solde, la réconciliation n'est pas établie (MSG-APP-104), parce que l'écart en dépend.
- **Validation** par la trésorière, le Chef comptable et le DAF (ou son adjoint), trois personnes différentes. Le Chef comptable et le DAF sont prévenus à l'établissement, la trésorerie à la validation. Tant qu'elle n'est pas validée, la trésorerie peut la refaire avec un nouveau relevé, et les signatures repartent de zéro. Validée, elle est verrouillée.
- **Rappel** chaque lundi à 9 h (`caisse:rappeler-reconciliation-om`) :
  - à la trésorerie, si la réconciliation de la semaine écoulée n'est pas établie ;
  - aux signataires manquants, si elle n'est pas validée.

### 56.4 Données

Migrations (chacune vérifiée sur une copie de la base de dev, aller, retour, aller, puis appliquée en dev) :
- `2026_10_27_000001_lot_f1a_alertes_encaissements_clients` : colonnes d'alerte sur `caisses`, table `encaissements_clients` ;
- `2026_10_28_000001_lot_f1b_reapprovisionnement` : table `demandes_reapprovisionnement`, `caisses.reappro_par_transfert` (activé pour l'Atelier), paramètre `habilitation_dp_reappro` ;
- `2026_10_29_000001_lot_f1c_reconciliation_om` : table `reconciliations_om`, paramètre `reconciliation_om_delai_jours`.

Messages : MSG-M08-01 à 06 au texte de la spec ; MSG-APP-090 à 104.

### 56.5 Vérifications

- Tests :
  - `tests/Feature/LotF1/CaissesM08Test.php` (4) : alerte au franchissement puis rappel quotidien, encaissement client et reçu, refus au plafond avec alerte à la trésorerie, visibilité par caisse ;
  - `tests/Feature/LotF1/ReapprovisionnementTest.php` (6) :
    - PV du jour signé et rapprochement exigés, montant proposé ;
    - plafond, baisse motivée, habilitation ;
    - circuit complet jusqu'à la réception avec écart ;
    - deux membres du CODIR (délai du DP écoulé, puis sans DP actif) ;
    - transfert depuis Conakry vers l'Atelier ;
    - refus motivé, auto-visa refusé, routes manuelles retirées ;
  - `tests/Feature/LotF1/ReconciliationOmTest.php` (4) :
    - rapprochement d'un relevé CSV à la française ;
    - trois signatures, reprise avant validation, verrouillage, une personne une signature ;
    - refus (caisse espèces, non-trésorerie, semaine future, relevé illisible, solde manquant) et relevé Excel ;
    - rappel du lundi ;
  - tests existants adaptés : alerte à la trésorerie, approvisionnement historique validé, demande sur la caisse d'un autre site refusée.
- **Total** : 426 tests PHP et 75 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base, avec les comptes réels, sans erreur JavaScript :
  - **F1a** (15 vérifications) : encaissement client par Youssouf TOURE et reçu PDF, refus au plafond, visibilité par caisse, alerte au rappel quotidien ;
  - **F1b** (24 vérifications) :
    - Youssouf TOURE n'a pas de PV du jour et est renvoyé au PV : il l'établit et le signe ;
    - il demande 249 250 000 (plafond 250 000 000 − solde 750 000), avec le rapprochement ;
    - Mariamagbè CAMARA valide, Mamadou Alpha BAH vise, Mamadou LO approuve ;
    - la trésorerie exécute par chèque, sans transfert proposé pour Conakry ;
    - Youssouf reçoit 10 000 de moins : caisse créditée du reçu, DAF alerté ;
    - Youssouf, aussi trésorerie, ne valide pas sa propre demande ;
    - Raby TOUNKARA, gestionnaire de la caisse Atelier sans rôle caissier, a les menus et le montant proposé (15 000 000 − solde) ;
    - téléphone ;
  - **F1c** (14 vérifications) :
    - Mariamagbè CAMARA importe un relevé sans colonne « Solde » : le solde de fin est demandé ;
    - écart de −75 000 ;
    - la ligne inconnue du relevé ressort, l'écriture du brouillard est rapprochée ;
    - trois validations, relevé téléchargeable ;
    - un demandeur n'y a pas accès ; téléphone.
- Défauts relevés en cours de lot, corrigés :
  - l'alerte de plafond d'un encaissement refusé disparaissait avec la transaction annulée ;
  - la trésorerie ne voyait aucune caisse (ancienne liste de rôles) ;
  - la gérante de la caisse Atelier, sans rôle caissier, n'ouvrait ni les PV d'arrêté ni les réapprovisionnements ;
  - une réconciliation sans solde de relevé aurait été validée sans écart.

### 56.6 Déploiement

```bash
php artisan migrate        # 2026_10_27_000001, 2026_10_28_000001, 2026_10_29_000001
```

- Attribuer le rôle « Membre du CODIR » aux membres désignés par Neemba (écran des utilisateurs, rôles complémentaires).
- Vérifier, à l'écran des caisses, le gestionnaire et le suppléant de chaque caisse et la case « Réapprovisionnée par transfert » (activée pour l'Atelier).
- Le planificateur exécute `caisse:verifier-seuils` à 8 h et `caisse:rappeler-reconciliation-om` le lundi à 9 h.
- Obtenir de Neemba un relevé Orange Money exporté, pour confirmer la lecture de son en-tête (Q96).

---

## 57. Version v57 — Lot F2 : clôture journalière par caisse, REPORTING CAISSE, inventaires et contrôles inopinés (20 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Spec v2.4 : RG-M09-01 à RG-M09-05, RG-M10-01 (RG-M10-02 et 03 faites au lot F0), PO-18, SC-18, SC-19.

### 57.1 Clôture journalière par caisse (RG-M09-03)

- Table `clotures_caisse`, modèle `ClotureCaisse`, service `App\Services\Caisse\ClotureJournaliere`, écran « Clôtures de caisse » (`ClotureCaisseController`).
- **Clôture manuelle** : le gérant de la caisse clôture sa journée depuis l'écran, avec des observations facultatives. Cela vaut pour un caissier rattaché, ou pour le gestionnaire ou le suppléant (pseudo-rôle `gerant_caisse`). Un autre compte est refusé (MSG-APP-106) ; une journée déjà close aussi (MSG-APP-107), ainsi qu'une journée à venir (MSG-APP-108).
- **Clôture automatique** à 23 h 45 (`caisse:cloturer-journees`), pour chaque caisse active dont la journée n'est pas close (Q99). Les journées oubliées, si le planificateur s'est arrêté, sont rattrapées sur 31 jours au plus. Elles ne remontent jamais avant la mise en service, fixée par le paramètre `clotures_caisse_depuis`, que la migration règle au jour de son passage. Sans cette date, la première nuit aurait clôturé après coup les semaines précédentes.
- **Contenu figé à la clôture** :
  - solde initial ;
  - total des encaissements et des décaissements, solde final ;
  - solde final de la veille (clôture précédente) et écart de contrôle ;
  - nombre d'opérations ;
  - auteur (ou « automatique »), date.
- **Verrou** : `Caisse::ecrire`, par où passe toute écriture, refuse une opération datée d'une journée close (MSG-APP-105). Cela couvre le paiement d'un bon, l'encaissement client, la réception d'un réapprovisionnement, le reliquat de BP, le reversement d'ODM, le mouvement validé et l'ajustement d'inventaire. Une correction passe le lendemain.
- L'écran montre, pour chaque caisse visible, la journée en cours : solde initial, encaissements, décaissements, solde final, nombre d'opérations, écart avec la veille. Il propose le bouton « Clôturer la journée » au gérant, puis liste l'historique des journées closes, avec qui a clôturé, quand, et l'état de l'envoi.

### 57.2 Rapport « REPORTING CAISSE » (RG-M09-01, RG-M09-02, RG-M09-05)

- Services `ReportingCaisse` (une journée d'une caisse, lue dans le registre) et `RapportsCaisse` (fichiers et envoi). Le classeur Excel s'appuie sur `ReportingCaisseExport` ; le PDF sur `exports/reporting-caisse-pdf`.
- **Une feuille par jour et par caisse**, au format de Neemba :
  - en-tête « REPORTING CAISSE » : société et adresse de l'entité (Neemba Guinée, Matam Route du Niger), période, caisse ;
  - contrôle : solde final de la veille, solde initial du jour, ligne « Écart », en rouge si elle n'est pas nulle ;
  - synthèse : solde initial, + encaissements, − décaissements, solde final ;
  - encaissements (date, n° pièce, libellé, montant) et décaissements (date, libellé, n° pièce, montant). Pour un bon, le libellé est le motif du bon et le n° de pièce le n° du bon. Pour les autres écritures, on prend la nouvelle colonne `ecritures_caisse.piece` : n° du reçu, de la demande de réapprovisionnement, du mouvement ou de l'ODM.
- **Onglet « Détail »** : pour chaque bon décaissé, le bénéficiaire, le service, le mode de paiement, le montant versé, puis le valideur et le délai en heures de chaque étape (chef de service, CDG, Finance, DP).
- Une reprise de solde du jour fait partie du solde initial, pas des flux.
- Téléchargement en PDF ou en Excel depuis l'écran, pour une journée ou une période de 62 jours au plus, sur les seules caisses visibles.

### 57.3 Envoi avant 8 h (RG-M09-04, PO-18)

- `rapports:envoyer-quotidien`, à 7 h : rapport de la veille, par caisse, en PDF et en Excel (`ReportingCaisseQuotidien`). Une journée restée ouverte est d'abord clôturée. Une journée déjà envoyée n'est pas renvoyée ; une journée sans opération n'est pas envoyée (Q100).
- **Destinataires** : ceux de la caisse (`caisses.destinataires_rapport`, repris du référentiel de Neemba ; pour Conakry : Chef comptable, assistant trésorier, responsable trésorerie, DAF). À défaut, le gestionnaire et le suppléant de la caisse (sinon ses caissiers) et la trésorerie (PO-18). L'envoi est daté et les destinataires sont gardés sur la clôture.
- L'envoi par site du socle est remplacé. Le tableau en temps réel par site de l'écran « Rapports Caisse » reste disponible (Q102).

### 57.4 Inventaires et contrôles inopinés (RG-M10-01)

- Service `InventairesCaisse`, commande quotidienne `caisse:verifier-inventaires` (8 h 15).
- **Inventaire de fin de mois** : un PV d'arrêté « inventaire de fin de mois » dans le mois (Q101). Le dernier jour ouvré du mois, s'il manque, un rappel part au gérant, à la trésorerie et au Chef comptable. Le mois suivant, une alerte part au Chef comptable (garant) et au DAF.
- **Inventaire avant réapprovisionnement** : exigé par la demande (lot F1b).
- **Contrôle inopiné** : un PV « contrôle inopiné » par période. Elle est trimestrielle pour Conakry, Boké et Sangarédi (réglé sur la caisse par la migration) et mensuelle pour les autres (paramètre `frequence_controle_inopine`, réglable par caisse à l'écran des caisses).
  - Il est établi par la trésorerie ou le Chef comptable, jamais par le gérant de la caisse (MSG-APP-109).
  - Pour qu'il reste inopiné, seul le Chef comptable reçoit le rappel, dans les 7 derniers jours de la période. Une période écoulée sans contrôle alerte le Chef comptable et le DAF.
- **Une seule alerte** par sujet (clé dans la notification).
- **Pas de retard compté** pour une période terminée avant la mise en service (paramètre `inventaires_suivis_depuis`, premier jour du mois de la migration).
- **Suivi** sur l'écran des PV d'arrêté, pour les rôles transverses seulement : inventaire du mois et du mois précédent, fréquence, contrôle de la période en cours, dernier contrôle. Il n'est pas montré aux gérants.

### 57.5 Données

Migrations (vérifiées sur une copie de la base de dev, aller, retour, aller, puis appliquées en dev) :
- `2026_10_30_000001_lot_f2a_cloture_journaliere` : table `clotures_caisse`, colonne `ecritures_caisse.piece`, paramètre `clotures_caisse_depuis` ;
- `2026_10_31_000001_lot_f2b_inventaires_controles` : `caisses.frequence_controle` (trimestrielle pour CKY-ESP, BOK-ESP, SAN-ESP), paramètres `frequence_controle_inopine` et `inventaires_suivis_depuis`.

Messages : MSG-APP-105 à 109.

### 57.6 Vérifications

- `tests/Feature/LotF2/ClotureJournaliereTest.php` (5) :
  - clôture automatique de deux journées, totaux, contrôle avec la veille ;
  - libellé et pièce du rapport, valideurs et délais ;
  - aucun rattrapage avant la mise en service ;
  - journée close verrouillée, clôture réservée au gérant et unique ;
  - écart avec la veille en rouge à l'écran ;
  - PDF et Excel (feuilles par jour, « Détail », ligne « Écart ») ;
  - envoi aux destinataires de la caisse ou, à défaut, au gestionnaire et à la trésorerie ; pièces jointes ; pas d'envoi sans opération ni en double.
- `tests/Feature/LotF2/InventairesControlesTest.php` (4) :
  - fréquence et période, dernier jour ouvré, réglage à l'écran des caisses ;
  - rappel puis alerte d'inventaire ;
  - rappel du contrôle au seul Chef comptable, alerte après la période, refus au gérant, contrôle par la trésorerie ;
  - suivi réservé aux rôles transverses.
- **Total** : 435 tests PHP et 75 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base, avec les comptes réels : 21 vérifications, sans erreur JavaScript.
  - Youssouf TOURE encaisse puis clôture la journée de Conakry ; un second encaissement est refusé ;
  - PDF et Excel ; envoi au journal ; clôture automatique des autres caisses, sans remonter avant la mise en service ;
  - contrôle inopiné refusé au gérant ;
  - Mamadou Alpha BAH voit le suivi (Conakry trimestriel) ;
  - la gérante de l'Atelier, Raby TOUNKARA, ne le voit pas, mais clôture sa caisse ;
  - téléphone.
- Défauts relevés en cours de lot, corrigés :
  - un écart nul s'écrivait comme une cellule vide dans l'Excel ;
  - la première clôture automatique aurait clôturé après coup toutes les journées passées (paramètre de mise en service) ;
  - le formulaire du PV n'affichait pas l'erreur du motif.

### 57.7 Déploiement

```bash
php artisan migrate        # 2026_10_30_000001, 2026_10_31_000001
```

- Le planificateur exécute `caisse:cloturer-journees` à 23 h 45, `rapports:envoyer-quotidien` à 7 h et `caisse:verifier-inventaires` à 8 h 15.
- Vérifier les destinataires du rapport de chaque caisse. Pour Conakry, la spec cite aussi Mor CISS (DAF adjoint), qui n'a pas encore de compte.
- Le serveur email (PO-23) est nécessaire à l'envoi ; en son absence, les rapports restent téléchargeables à l'écran.

---

## 58. Version v58 — Lot T : trésorerie et rôles métier visibles (20 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Demande de l'utilisateur du 20/10 : « à aucun moment je ne vois un rôle de trésorier ou de trésorerie ».

### 58.1 Constat

Le rôle « Trésorerie » existait, et ses validations aussi (réapprovisionnements, PV d'arrêté, réconciliation OM, taux du jour, alertes) ; deux comptes l'avaient. Mais il était invisible :
- le rôle principal ne pouvait prendre que les 7 valeurs du socle (énumération en base) ; la trésorerie n'était qu'une case parmi les rôles complémentaires ;
- la liste des utilisateurs, le bandeau et le profil n'affichaient que le rôle principal : Mariamagbè CAMARA apparaissait « Demandeur », Mamadou Alpha BAH aussi ;
- le filtre par rôle ne cherchait que le rôle principal.

Même défaut pour le Chef comptable, le DAF adjoint, le DP adjoint et la Comptabilité.

### 58.2 Ce qui a changé

- **Rôle principal** (Q103) : `users.role` devient une chaîne contrôlée par l'application (`User::ROLES_PRINCIPAUX`). On peut choisir Trésorerie, Chef comptable, DAF adjoint, DP adjoint, Comptabilité, Assistant trésorier, chef d'atelier, RH ou Logistique. Seuls le gestionnaire des référentiels et le membre du CODIR, qui s'ajoutent à un métier, restent complémentaires. Le champ s'appelle « Rôle principal » à l'écran.
- **Reprise** (migration `2026_11_01_000001_lot_t_roles_principaux`) : un compte « Demandeur » qui a un rôle métier le prend comme rôle principal (ordre `User::PRIORITE_PRINCIPAL`, aussi utilisé par l'import des référentiels). En dev : Mariamagbè CAMARA → Trésorerie, Mamadou Alpha BAH → Chef comptable ; Youssouf TOURE reste Caissier. « Demandeur » reste parmi leurs rôles. Le retour arrière rend l'énumération et remet « Demandeur ».
- **Assistant trésorier** (spec v2.4, chapitre 3 « Caissier / assistant trésorier », Q75) : nouveau rôle, avec les droits du caissier (`User::ROLES_EQUIVALENTS` : `listeRoles()` et `avecRoleDans()` le comptent comme caissier).
- **Affichage de tous les rôles** : liste des utilisateurs (badges, rôle principal d'abord), bandeau et menu (« Caissier · Trésorerie »), profil ; libellés partagés avec tous les écrans (`auth.roles_libelles`), délégations et notifications comprises. « Demandeur » n'est montré que s'il est le seul rôle.
- **Filtre** : `parRole` cherche le rôle principal et les rôles complémentaires.
- **Changer de rôle principal ne fait plus perdre de rôle** :
  - l'ancien rôle principal reste coché parmi les rôles complémentaires ; on le décoche pour le retirer (avec la double validation) ;
  - le crochet du modèle ne supprime plus l'ancien rôle d'office ;
  - à la validation du rôle principal, les rôles complémentaires du même formulaire, déjà validés, sont réappliqués : l'ordre des deux validations ne change plus le résultat ;
  - avant, un passage Demandeur → Trésorerie pouvait faire perdre « demandeur » (et le droit de créer un bon).
- **Délégations** : le délégué ne reçoit plus le rôle principal du titulaire quelle que soit la délégation, mais seulement ses rôles qui ont une fonctionnalité déléguée (`Delegation::FONCTIONNALITES_PAR_ROLE`). Une délégation d'initiation d'une trésorière ne donne pas accès aux écrans de trésorerie.
- **Contrôles qui ne lisaient que le rôle principal**, passés à tous les rôles :
  - tableau de bord ;
  - visa DAF du rapport (DAF adjoint compris) ;
  - notifications DAF / DP ;
  - escalade SMS ;
  - alertes d'archives ;
  - accès aux données OCR (même règle que la fiche du bon) ;
  - destinataires des rapports ;
  - bouton de validation rapide de la liste des bons (le Chef comptable tient l'étape Finance).
- **Bloc « À traiter »** sur le tableau de bord (`TachesCaisse`) : réapprovisionnements, PV d'arrêté à signer, réconciliations OM, ajustements d'inventaire, taux du jour, caisses sous leur seuil, journée de caisse à clôturer (à partir de 17 h). Chacun n'y voit que ce qu'il peut faire.

### 58.3 Vérifications

- `tests/Feature/LotT/RolesTresorerieTest.php` (8) :
  - création d'un compte Trésorerie ;
  - affichage de tous les rôles et filtre ;
  - passage en Trésorerie sans perdre « demandeur » ;
  - ordre des validations sans effet ;
  - ancien rôle conservé s'il reste coché ;
  - assistant trésorier ;
  - délégation qui ne transmet pas la trésorerie ;
  - bloc « À traiter ».

  `Socle/RolesTest` est adapté à la nouvelle règle (l'ancien rôle principal reste).
- **Total** : 443 tests PHP et 75 tests JavaScript passent. Migration vérifiée sur une copie de la base de dev (aller, retour, aller), puis appliquée en dev.
- Parcours dans Edge sur une copie de la base, avec les comptes réels : 16 vérifications, sans erreur JavaScript.
  - Mariamagbè CAMARA s'affiche « Trésorerie » et voit son bloc « À traiter » (PV à signer, taux du jour, caisses sous le seuil) ;
  - Mamadou Alpha BAH s'affiche « Chef comptable » ;
  - l'administrateur voit « Caissier · Trésorerie » pour Youssouf TOURE, filtre sur la trésorerie (2 comptes) ;
  - « Trésorerie » et « Assistant trésorier » sont proposés comme rôle principal ;
  - en changeant de rôle principal, « Demandeur » reste coché ;
  - téléphone.

### 58.4 Déploiement

```bash
php artisan migrate        # 2026_11_01_000001
```

Vérifier ensuite le rôle principal de chaque compte dans Utilisateurs. La spec désigne Youssouf TOURE comme assistant trésorier (et non trésorerie) : à confirmer avec Neemba avant de changer ses rôles.

---

## 59. Version v59 — Demandes urgentes : visa sans code, alerte SMS des ODM (20 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Décision de l'utilisateur du 20/10 (Q104).

### 59.1 Contexte

La spec v2.4 (RG-M01-07, comité du 14/10) demande deux codes :
- un code par email à la connexion ;
- un code par SMS au valideur pour viser une demande urgente ou très urgente (BD, BP, ODM).

Le second a été fait au lot E2 : le valideur, déjà sur la demande, cliquait pour recevoir le code et le saisissait pour viser. L'utilisateur a relevé que ce code ne prévient personne et que le valideur est déjà authentifié par le code de connexion. Il ne couvre que des cas rares (session laissée ouverte, poste partagé), au prix d'une étape de plus quand il faut aller vite et d'une dépendance au fournisseur SMS. Décision : le supprimer, et prévenir à la place les valideurs des ODM urgents par SMS.

### 59.2 Code SMS de visa retiré

- Le visa d'un bon ou d'un ODM urgent se fait comme celui d'une demande normale.
- Retirés :
  - `DoubleAuthentification::exigeCodeVisa` et `envoyerCodeVisa` ;
  - le contrôle dans `ValidationController::approuver` et `OrdreMissionController::viser` ;
  - les routes `validations.code-visa` et `odm.code-visa` ;
  - le composant `CodeVisa` et ses trois écrans (validation d'un bon, fiche du bon, fiche de l'ODM) ;
  - les réglages `otp_visa_urgent_actif` et `otp_visa_duree` (migration `2026_11_02_000001_retrait_code_visa_urgent`, que le retour arrière remet) ;
  - l'option `--visa` de la commande de secours ;
  - les messages MSG-APP-081 à 083.
- **Inchangés** : le code de connexion par email (durée, essais, interrupteur de secours, commande `securite:double-authentification`), et l'OTP de paiement au bénéficiaire (RG-M06-02).
- Les codes de visa déjà émis restent dans `codes_otp`, pour l'historique.

### 59.3 Alerte SMS des ODM urgents

- `NotificationsOdm::aViser`, appelé à la soumission puis à chaque visa, envoie en plus de la notification un SMS à chaque valideur possible de l'étape qui s'ouvre (titulaires et suppléants), si l'ODM est urgent ou très urgent. Exemple :
  `NEEMBA [URGENT] - L'ordre de mission N°5/DIR/26 de … (du … au …) attend votre visa (DAF). Merci d'agir rapidement.` (très urgent : « immédiatement »).
- Un valideur sans téléphone, ou un SMS qui ne part pas, n'arrête pas le circuit : la notification dans l'application reste, et le cas est écrit au journal.
- Les bons gardent la règle de la spec (RG-M03, §6.2) : SMS pour un bon très urgent, email pour un bon urgent.
- L'onglet Sécurité remplace l'encart du code de visa par « Alertes SMS des demandes urgentes » : état du fournisseur SMS et valideurs sans numéro de téléphone.

### 59.4 Vérifications

- `tests/Feature/LotE2/UrgenceVisaTest.php` (4) remplace `CodeVisaUrgentTest` :
  - un bon très urgent se vise sans code ; plus de routes ni de réglages de code de visa ;
  - un ODM urgent exige son motif, prévient le chef d'atelier par SMS à la soumission, se vise sans code, puis prévient le DAF ;
  - très urgent (« immédiatement »), normal (aucun SMS), valideur sans téléphone ;
  - motif d'urgence effacé quand l'ODM repasse en normal.

  `ReglagesSecuriteTest` est adapté.
- **Total** : 439 tests PHP et 75 tests JavaScript passent. Migration vérifiée sur une copie de la base de dev (aller, retour, aller), puis appliquée en dev.
- Parcours dans Edge sur une copie de la base : 9 vérifications, sans erreur JavaScript.
  - Mohamed DIAKITE (DAF) vise l'ODM très urgent N°5/DIR/26 sans code ;
  - l'alerte SMS de l'étape suivante est tentée (consignée au journal, pas de fournisseur SMS en test) ;
  - l'onglet Sécurité n'a plus qu'un interrupteur, celui du code de connexion.

### 59.5 Déploiement

```bash
php artisan migrate        # 2026_11_02_000001
```

Les alertes SMS ne partent qu'une fois le fournisseur SMS configuré (PO-23) et les numéros des valideurs saisis (l'onglet Sécurité liste ceux qui manquent).

---

## 60. Version v60 — Rôles en attente et justifications de BP à viser, visibles (22 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Signalement de l'utilisateur du 22/10.

### 60.1 Constats

- **Gestionnaire des référentiels « invisible »** : le rôle n'était pas attribué. La demande (modification n° 5, 21/10) attendait la double validation d'un autre administrateur. L'auteur ne valide pas sa propre demande, et rien ne la signalait : pas de notification aux autres administrateurs, pas de compteur, rien sur la fiche de l'utilisateur.
- **Visa « Comptabilité » d'une justification de BP** : le droit existait (`JustificationBp::ROLES_ETAPES['comptabilite']` = Comptabilité et Chef comptable ; Gando Diallo et Mamadou Alpha BAH en étaient valideurs). Mais le bouton « Viser » n'est que dans la carte « Justification du BP » de la fiche du bon, et aucun écran ne listait les justifications à viser. Seule la notification envoyée à l'ouverture de l'étape y menait ; Gando Diallo, créé après, ne l'a pas reçue.
- **DAF** : la spec (RG-M07-03) ne lui donne pas ce visa. Décision du 22/10 : conforme à la spec (Q106).

### 60.2 Corrections

- **Demandes en attente** (Q107) :
  - événement `created` de `ModificationEnAttente` : chaque modification en attente notifie les autres administrateurs actifs (type `modification_a_valider`, description lisible, par exemple « Rôles de Thierno DIALLO : Demandeur, Gestionnaire des référentiels »). Tous les écrans et l'import sont couverts ;
  - compteur sur « Modifications Admin » (`auth.compteurs`, demandes des autres seulement) et ligne « Modifications à valider » dans « À traiter » ;
  - demande affichée sur la ligne de l'utilisateur et en bandeau sur sa fiche ;
  - message après enregistrement : il nomme les administrateurs prévenus, ou signale qu'il n'y en a aucun autre.
- **Justifications de BP à viser** :
  - `JustifierBp::aViserPar()` ;
  - section « Justifications de BP à viser » dans « Validations » (BP, bénéficiaire, avance, justifié, étape, date, bouton « Examiner » vers la carte `#justification` de la fiche du bon) ;
  - ligne dans « À traiter » ;
  - menu et route « Validations » ouverts à la Comptabilité.
- **Libellé** : « Validations » affiche le libellé du rôle (« Chef comptable ») au lieu du code.
- **Test des barèmes ODM** (lot E3) rendu indépendant de la date de création de la base de test : les barèmes repris sont datés du jour de la migration.

### 60.3 Vérifications

- `tests/Feature/LotT/DemandesEtJustificationsTest.php` (2) :
  - demande de rôle notifiée à l'autre administrateur et pas à l'auteur ; visible dans la liste, la fiche, le compteur et « À traiter » ;
  - après validation, onglets des référentiels ouverts ;
  - justification à l'étape Comptabilité visible pour un Chef comptable nommé après l'ouverture de l'étape, et pour la Comptabilité ; pas pour le DAF ni un demandeur.
- **Total** : 444 tests PHP et 75 tests JavaScript passent.
- Parcours dans Edge sur une copie de la base, avec les comptes réels : 11 vérifications, sans erreur JavaScript.
  - Thierno DIALLO voit sa demande en attente ;
  - Baba DIAKITE a le compteur et la ligne « À traiter », puis approuve ; Thierno a alors les onglets des référentiels ;
  - Gando Diallo retrouve la justification du BP BC-CKY-2026-00006 dans « Validations » et ouvre sa carte ;
  - le DAF n'a pas de justification à viser.
- **Build** : non régénéré, la machine manque de mémoire ; `npm run dev` sert les sources.

### 60.4 À faire en dev

La demande n° 5 (gestionnaire des référentiels pour Thierno DIALLO) attend toujours : un autre administrateur (Baba DIAKITE ou Amadou Bailo Diallo) doit l'approuver dans « Modifications Admin ».

---

## 61. Version v61 — Mot de passe initial (22 Octobre 2026)

Branche `v23-conformite`. Commits locaux, non poussés, non déployés. Signalement de l'utilisateur du 22/10 : « certains utilisateurs n'ont pas pour mot de passe par défaut Neemba@2026 ».

### 61.1 Constat

- 12 comptes importés du référentiel le 07/10 (n° 8 à 19 : Mamadou Alpha BAH, Saliou BOIRO, Mariamagbè CAMARA, Kadiatou SOUMAH, Raby TOUNKARA, Thomas BANGOURA, Philippe KOLIE, Mariama BARRY, Astou CAMARA, Baba DIAKITE, Yacouba BARRY, Abdoul-Rahim BARRY) avaient un mot de passe aléatoire.
- À cette date, `ImportReferentiels::creerCompte` donnait `Str::random(40)`. Le passage à `Neemba@2026` (commit f9c1c88 du 09/10) ne valait que pour les comptes créés ensuite.
- Le rapport d'import disait encore « Aucun mot de passe n'est communiqué ».

### 61.2 Décision (Q108)

Remettre `Neemba@2026` sur ces comptes, et imposer à chaque titulaire de choisir son mot de passe à la première connexion : sans cela, en production, tout le monde connaîtrait le mot de passe de tout le monde.

### 61.3 Réalisation

- **Migration** `2026_11_03_000001_mot_de_passe_initial` : `users.mot_de_passe_a_changer` (faux par défaut ; les comptes existants ne sont pas marqués) et réglage `changement_mot_de_passe_initial` (actif).
- **`User::MOT_DE_PASSE_INITIAL`** = `Neemba@2026`.
- **Comptes marqués « à changer »** :
  - créés par l'import (le rapport donne le mot de passe initial) ;
  - créés par l'administrateur ;
  - dont un administrateur a saisi le mot de passe sur la fiche (pas sur la sienne).
- **Démarqués** quand le titulaire choisit son mot de passe : écran imposé, « Mot de passe oublié », profil.
- **Écran imposé** :
  - middleware `ExigerChangementMotDePasse` (groupe `web`) : après le code de connexion, tout écran mène à « Choisissez votre mot de passe » (`/mot-de-passe-initial`) ; seule la déconnexion reste ouverte ; requête JSON : 409 ;
  - le mot de passe initial et l'ancien sont refusés ; 8 caractères au moins ;
  - page `Auth/MotDePasseInitial.jsx`.
- **Paramétrage > Sécurité** : interrupteur « Mot de passe initial à changer », appliqué au clic, tracé, autres administrateurs prévenus. Coupé, le mot de passe initial reste utilisable (recette).
- **Commande** `utilisateurs:mot-de-passe-initial {ids*} {--tous}` : remet le mot de passe initial (ids donnés, ou tous les comptes actifs qui ne l'ont pas) et marque les comptes.
- **Dev** : migration appliquée, commande lancée sur les comptes 8 à 19 ; les 21 comptes acceptent `Neemba@2026`.

### 61.4 Vérifications

- `tests/Feature/Securite/MotDePasseInitialTest.php` (5) :
  - écran imposé, 409 en JSON, mot de passe initial refusé, puis accès libre ;
  - réglage coupé : pas d'écran imposé ;
  - création et réinitialisation par l'administrateur marquent le compte ; sa propre fiche, non ;
  - « Mot de passe oublié » démarque ;
  - commande (ids, `--tous`, sans argument : refus).
- **Total** : 448 tests PHP et 75 tests JavaScript passent.
- Migration vérifiée sur une copie (montée, retour, remontée).
- Parcours dans Edge sur une copie de la base (7 vérifications) : Baba DIAKITE se connecte avec `Neemba@2026`, saisit le code, arrive sur l'écran imposé ; `Neemba@2026` est refusé ; son mot de passe est enregistré ; à la reconnexion, tableau de bord direct ; l'interrupteur est dans l'onglet Sécurité.

### 61.5 À savoir en recette

Avec l'interrupteur actif, chacun des 12 comptes choisira son mot de passe à sa première connexion. Pour tester librement avec `Neemba@2026`, couper l'interrupteur dans Paramétrage > Sécurité, et le rallumer avant la mise en production.
