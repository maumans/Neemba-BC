# NEEMBA - Gestion de Caisse (devBook)

> **Application interne de gestion des bons de caisse pour l'entreprise NEEMBA**
> Dernière mise à jour : 15 Avril 2026 (v17 — Alertes proactives seuil de caisse : SMS + push aux caissiers, commande planifiée caisse:verifier-seuils)

---

## 1. Présentation du Projet

### 1.1 Objectif
NEEMBA Cash Management est une application web interne permettant de gérer le cycle de vie complet des **bons de caisse** au sein de l'entreprise NEEMBA. L'application couvre la création, la validation hiérarchique, le paiement, la régularisation et l'archivage des demandes de fonds.

### 1.2 Contexte Métier
L'entreprise NEEMBA opère en **Guinée (GNF)** avec plusieurs sites et services. Chaque demande de fonds suit un workflow de validation hiérarchique strict avant décaissement par la caisse.

### 1.3 Règles Métier Clés
| Règle | Valeur |
|-------|--------|
| Montant maximum par bon | **20 000 000 GNF** (paramétrable) |
| Seuil validation Directeur Pays | **≥ 5 000 000 GNF** (paramétrable) |
| Format numéro de bon | **BC-AAAA-NNNN** (ex: BC-2026-0001) |
| Types de bons | **BD** (Définitif), **BP** (Provisoire) |
| Devise | **GNF** (Franc Guinéen) |

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

### 4.1 Les 7 Rôles du Système

| Rôle | Code | Description | Droits principaux |
|------|------|-------------|-------------------|
| Demandeur | `demandeur` | Tout employé qui peut faire une demande de fonds | Créer, voir ses propres bons |
| Responsable Service | `responsable_service` | Chef de service, premier niveau de validation | Valider/Rejeter les bons de son service |
| Contrôle de Gestion | `controle_gestion` | Vérification budgétaire | Valider/Rejeter après le chef de service |
| DAF | `daf` | Directeur Administratif et Financier | Valider/Rejeter après le CDG, viser rapports |
| Directeur Pays | `directeur_pays` | Validation finale pour les gros montants | Valider/Rejeter si montant ≥ 5M GNF |
| Caissier | `caissier` | Décaissement des fonds | Payer les bons approuvés, rapports de caisse |
| **Administrateur** | `administrateur` | **Administrateur système** | **Gestion utilisateurs, paramétrage, archivage, accès complet** |

### 4.2 Workflow de Validation

```
BROUILLON
    │
    ▼ (soumettre)
EN_ATTENTE_CHEF_SERVICE
    │
    ├── ✅ Approuvé → EN_ATTENTE_CDG
    │                      │
    │                      ├── ✅ → EN_ATTENTE_DAF
    │                      │            │
    │                      │            ├── ✅ (montant < 5M) → APPROUVE
    │                      │            ├── ✅ (montant ≥ 5M) → EN_ATTENTE_DP
    │                      │            │                            │
    │                      │            │                            ├── ✅ → APPROUVE
    │                      │            │                            └── ❌ → REJETE
    │                      │            └── ❌ → REJETE
    │                      └── ❌ → REJETE
    └── ❌ → REJETE

APPROUVE → PAYE (par le caissier) → REGULARISE (pour BP) → ARCHIVE
```

### 4.3 Statuts Complets

| Statut | Code | Label FR |
|--------|------|----------|
| Brouillon | `BROUILLON` | Brouillon |
| Attente Chef Service | `EN_ATTENTE_CHEF_SERVICE` | En attente Chef Service |
| Attente CDG | `EN_ATTENTE_CDG` | En attente Contrôle de Gestion |
| Attente DAF | `EN_ATTENTE_DAF` | En attente DAF |
| Attente DP | `EN_ATTENTE_DP` | En attente Directeur Pays |
| Approuvé | `APPROUVE` | Approuvé |
| Rejeté | `REJETE` | Rejeté |
| Payé | `PAYE` | Payé |
| Régularisé | `REGULARISE` | Régularisé |
| Archivé | `ARCHIVE` | Archivé |

---

## 5. Données Référentielles NEEMBA

### 5.1 Sites

| Code | Nom du Site | Localisation |
|------|-------------|--------------|
| Conakry | Siège Conakry | Conakry, Guinée |
| Kamsar | Site Kamsar | Kamsar, Boké |
| Fria | Site Fria | Fria, Moyen-Guinée |
| Kankan | Site Kankan | Kankan, Haute-Guinée |
| Kindia | Site Kindia | Kindia, Basse-Guinée |
| Boké | Site Boké | Boké, Boké |
| Labé | Site Labé | Labé, Moyenne-Guinée |
| Nzérékoré | Site Nzérékoré | Nzérékoré, Guinée Forestière |

### 5.2 Services

| Code Service | Nom du Service |
|--------------|----------------|
| Direction Générale | Direction Générale |
| Finance | Finance et Comptabilité |
| Informatique | Informatique / IT |
| Logistique | Logistique et Approvisionnement |
| Ressources Humaines | Ressources Humaines |
| Commercial | Commercial et Marketing |
| Juridique | Juridique et Conformité |
| Opérations | Opérations et Production |
| HSE | Hygiène, Sécurité et Environnement |
| Communication | Communication et Relations Publiques |
| Maintenance | Maintenance et Technique |
| Achats | Achats et Procurement |

### 5.3 Types de Documents (Pièces Jointes)

| Code | Label |
|------|-------|
| `facture` | Facture |
| `devis` | Devis |
| `bon_commande` | Bon de commande |
| `ordre_mission` | Ordre de mission |
| `recu` | Reçu |
| `justificatif` | Justificatif |
| `autre` | Autre document |

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
| 3 | Déploiement production | ⬜ À faire |

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
| 🟡 | Notifications email | Emails transactionnels (validation, paiement, relance) | À faire |
| 🟢 | Tests unitaires | PHPUnit pour les modèles et contrôleurs | À faire |
| 🟢 | Déploiement production | Configuration serveur NEEMBA | À faire |

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

*Ce document est maintenu à jour au fur et à mesure du développement de l'application NEEMBA Cash Management.*
