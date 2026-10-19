# NEEMBA — Récapitulatif des lots réalisés

*Mis à jour le 19/10/2026. Sources : le devBook (§12 à §56), `docs/CONFORMITE.md`, `docs/questions.md` et l'historique git.*

Ce document reprend, lot par lot, ce qui a été construit dans la plateforme NEEMBA : bons de caisse et ordres de mission de Neemba Guinée, réalisés par Addvalis. Pour chaque lot, il donne l'objectif, ce qui a été fait, les décisions prises et les vérifications. Le détail technique de chaque lot se trouve au paragraphe indiqué du devBook.

**État au 19/10/2026**
- Branche de travail `v23-conformite`. Tout le travail d'octobre est en commits locaux : rien n'est poussé ni déployé depuis la mise en production de juin (branche `main`).
- 426 tests PHP et 75 tests JavaScript passent.
- Spec v2.4 : 125 règles RG faites sur 165, 29 partielles, 11 à faire.

---

## Sommaire

1. [Vue d'ensemble](#1-vue-densemble)
2. [Le socle de mars à juin 2026](#2-le-socle-de-mars-à-juin-2026)
3. [Saisie du bon de caisse, SFD v1.3 (lots 0 à 5)](#3-saisie-du-bon-de-caisse-sfd-v13-lots-0-à-5)
4. [Module « Ordres de mission », spec v2.2 (M12-0 à M12-8)](#4-module--ordres-de-mission--spec-v22-m12-0-à-m12-8)
5. [Évolutions des ODM et comptabilité (spec v2.3)](#5-évolutions-des-odm-et-comptabilité-spec-v23)
6. [Conformité à la spec v2.3 (lots A à E)](#6-conformité-à-la-spec-v23-lots-a-à-e)
7. [Intégration de la spec v2.4 (lots R, V, E1 à E4, O, F0, F1)](#7-intégration-de-la-spec-v24-lots-r-v-e1-à-e4-o-f0-f1)
8. [Ce qui reste à faire](#8-ce-qui-reste-à-faire)
9. [Ce qu'il faut obtenir de Neemba](#9-ce-quil-faut-obtenir-de-neemba)

---

## 1. Vue d'ensemble

| Période | Lot | Objet | Branche | Tests PHP / JS | Contrôles dans Edge | devBook |
|---|---|---|---|---|---|---|
| mars–juin 2026 | Socle v1 à v18 | Première version, pilote Conakry, mise en production | `main` | — | — | §12 à §18 |
| 06/10 | Lot 0 | Sécurité et stabilisation | `lot0-stabilisation` | 35 | — | §19 |
| 07/10 | Lot 1 | Socle transverse (formats, messages, statuts, journal, multi-rôles) | `lot1-socle` | — / 43 | — | §20 |
| 07/10 | Lot 2 | Caisses et registre de caisse | `lot2-caisses` | 100 / 43 | — | §21 |
| 07/10 | Lot 3 | Assistant de saisie du bon (M03-A) | `lot3-assistant` | 133 / 56 | 32 | §22 |
| 07/10 | Lot 4 | Pièces et lecture des tickets carburant (M03-B) | `lot4-pieces` | 148 / 59 | 18 | §23 |
| 07/10 | Référentiels | Import du classeur de Neemba | `lot4-referentiels` | 159 / 59 | — | §24 |
| 07/10 | Lot 5 | « Pour le compte de », liste et fiche du bon (M03-C) | `lot5-suivi` | 173 / 59 | 14 | §25 |
| 08/10 | M12-0 à M12-8 | Module « Ordres de mission » complet | `m12-odm` | 264 / 66 | 116 en tout | §26 à §34 |
| 09/10 | Visa du bénéficiaire | Étape sautée au lieu d'une erreur 403 | `m12-odm` | 272 / 66 | — | §35 |
| 09/10 | Prise en charge | Frais d'ODM ligne par ligne | `m12-prise-en-charge` | 286 / 71 | 21 | §36 |
| 12/10 | Spec v2.3 ODM | Frais répartis en parts, référentiel clients | `m12-v23` | 292 / 71 | 19 | §37 |
| 12/10 | M16 et M14 | Imputation comptable, export des écritures, export des bons | `m16-imputation` | 299 / 71 | 14 | §38 |
| 13/10 | PDF de l'ODM | Sur le modèle papier de Neemba | `odm-pdf` | 302 | 5 cas relus | §39 |
| 13/10 | Génération sélective | Bons d'un ODM pour tous, un seul ou une sélection | `odm-generation-selective` | 306 / 71 | 17 | §40 |
| 14–17/10 | Lots A à E | Conformité à la spec v2.3 | `v23-conformite` | 336 / 74 | 85 | §41 à §45 |
| 15–16/10 | Audits | Audit v2.3, comparaison v2.4, plan validé | `v23-conformite` | — | — | §46, §47 |
| 16/10 | Lot R | Correctifs de l'audit v2.3 | `v23-conformite` | 358 / 74 | 11 | §48 |
| 16/10 | Lot V | Corrections v2.4 de l'existant | `v23-conformite` | 361 / 74 | 15 | §49 |
| 16/10 | Lot E1 | Entité transverse | `v23-conformite` | 368 / 74 | 15 | §50 |
| 19/10 | Lot E2 | Double authentification, onglet « Sécurité » | `v23-conformite` | 389 / 74 | 25 + 20 | §51 |
| 19/10 | Lot E3 | Gestionnaire des référentiels, barèmes datés, types d'OR | `v23-conformite` | 397 / 74 | 19 | §52 |
| 19/10 | Lot E4 | Caisses et sites, site analytique | `v23-conformite` | 402 / 74 | 16 | §53 |
| 19/10 | Lot O | OR à régulariser, besoin carburant de l'ODM | `v23-conformite` | 408 / 75 | 14 | §54 |
| 19/10 | Lot F0 | PV d'arrêté de caisse (Annexe 2) | `v23-conformite` | 412 / 75 | 14 | §55 |
| 19/10 | Lot F1 | Caisses : alertes, encaissement client, réapprovisionnement, réconciliation OM | `v23-conformite` | 426 / 75 | 15 + 24 + 14 | §56 |

Les branches sont en chaîne : chacune part de la précédente.

Chaque lot d'octobre suit la même procédure :
1. Code, tests PHP et JavaScript.
2. Migration vérifiée sur une copie de la base (aller, retour, aller), puis appliquée en dev.
3. Build du front.
4. Parcours dans Edge sur une copie jetable de la base, avec les comptes réels.
5. Mise à jour du devBook, du suivi de conformité et du registre des décisions.
6. Trois commits locaux : code, build, documentation.

---

## 2. Le socle de mars à juin 2026

Avant octobre, l'application existait et tournait en production depuis le 2 juin 2026. Les paragraphes §12 à §18 du devBook la décrivent ; résumé :

- **Après la réunion du 31/03 (§12, §13)** :
  - urgence justifiée par un motif ;
  - une caisse par site avec plafond et seuil d'alerte ;
  - mouvements de caisse validés par le DAF ou le DP ;
  - délégations de pouvoirs avec acceptation du délégué ;
  - ventilation analytique corrigeable par le CDG ;
  - double validation des modifications sensibles de l'administrateur ;
  - écran « BP en retard » ; alertes d'expiration des archives.
- **v15 et v16 (§14, §15)** :
  - motif obligatoire pour régulariser un BP ;
  - compteur du temps écoulé sur les BP ;
  - visibilité stricte par rôle ;
  - rapports journaliers calculés en temps réel et envoyés par email (Excel, PDF).
- **v17 (§16)** : alertes de seuil de caisse par SMS et notification, une fois par jour et par site.
- **Module OCR (§17)** : lecture des factures et des tickets carburant pour pré-remplir le bon ; c'est une aide, jamais une obligation.
- **v18 (§18)** :
  - mise en production sur neemba.wedrive.africa et pilote à Conakry, avec 7 comptes réels ;
  - seuil du DP à 1 500 000 GNF ;
  - plan de test de 11 scénarios et fiche GO / NO GO.

---

## 3. Saisie du bon de caisse, SFD v1.3 (lots 0 à 5)

Objectif d'ensemble : couvrir le module M03 de la SFD v1.3 (16 user stories de saisie et de suivi du bon). À la fin du lot 5, les 16 user stories sont couvertes.

### Lot 0 — Sécurité et stabilisation (06/10, §19)
**Objectif** : fermer une faille, reprendre le travail non enregistré de juin et août, et corriger des défauts qui faussaient l'argent et les rapports.
- L'inscription libre est supprimée : seul l'administrateur crée les comptes.
- Évolutions du DAF intégrées :
  - solde Espèces et solde Orange Money par site ;
  - arrêté de caisse avec billetage ;
  - six motifs de rejet.
- **Paiements corrigés** :
  - un paiement OM ou un virement ne débite plus la caisse espèces ;
  - l'OTP n'est plus consommé si le solde manque ;
  - le double paiement est bloqué.
- **Rapports** : le rapport automatique de 7 h 30, vide en usage réel, est recalculé ; le rapport manuel aussi (date, ouverture, totaux).
- Tests sur une base MySQL dédiée, sans SMS ni appel à l'IA.
- **Vérifications** : 35 tests. Registre des décisions ouvert (Q1 à Q13).

### Lot 1 — Socle transverse (07/10, §20)
**Objectif** : poser les règles communes à toute la saisie.
- **Présentation unique** à l'écran, dans les PDF, les emails et les SMS : montants, dates, durées, fuseau de Conakry.
- **Montant en lettres** conforme à la grammaire, recalculé par le serveur ; les montants faux déjà imprimés sont corrigés.
- **Messages et statuts** : catalogue MSG-BC-001 à 042 au texte exact de la SFD ; statut « Annulé » ; couleurs des statuts.
- **Journal d'audit** de chaque champ modifié (avant / après).
- **Plusieurs rôles** par utilisateur ; seul le rôle demandeur crée un bon.
- **Décisions** :
  - Q14 : plus d'archivage automatique ;
  - Q11 : orthographe « Souadou » ;
  - Q15 : « millions de francs guinéens ».

### Lot 2 — Caisses et registre de caisse (07/10, §21)
**Objectif** : l'argent n'est plus porté par le site mais par des caisses, et chaque mouvement est inscrit à un registre.
- Caisses espèces ou OM, plafond de retrait, seuil d'alerte, mode standard ou avance fixe. Un solde ne se modifie jamais directement : chaque mouvement écrit une ligne au registre (solde avant / après).
- **Reprise** : une caisse espèces par site, une caisse OM unique, une caisse Atelier inactive. Totaux identiques avant et après la reprise (38 500 000 GNF).
- **Caisse payeuse** selon le mode de paiement ; plafond de retrait contrôlé.
- **Alertes et soldes** : alertes de seuil et soldes du tableau de bord par caisse.
- **Rapport journalier** : il lit le registre (ouverture exacte, détail espèces / OM).
- **Paramétrage** : onglet « Caisses » ; plafond, seuil et correction de solde en double validation.
- **Décisions** : Q2 (plafonds), Q3 (caisse Atelier inactive), Q13 (ouverture lue dans le registre).
- **Vérifications** : 100 tests PHP.

### Lot 3 — Assistant de saisie du bon, M03-A (07/10, §22)
**Objectif** : remplacer les formulaires par un assistant en 5 étapes, pour créer, reprendre un brouillon ou corriger un bon rejeté.
- **Brouillon** : ouvrir « Nouveau bon » ne crée rien. Le brouillon naît au premier enregistrement, sans numéro.
- **Contrôles** : chaque « Suivant » est contrôlé par le serveur, avec les messages de la SFD ; une étape en erreur passe en rouge.
- **Étape 5** : les 12 contrôles avec un lien « Corriger », et le circuit prévu avec le nom des valideurs.
- **Numéro** BC-AAAA-NNNN donné à la soumission seulement, sans trou ; un bon rejeté garde son numéro et passe en version suivante.
- **Étape du montant** : caisse payeuse, plafond et seuil du DP affichés ; montant en lettres ; véhicule et OR selon la catégorie ; photo sur mobile.
- **Annulation et délais** :
  - annulation motivée d'un brouillon ou d'un bon rejeté ;
  - un brouillon abandonné depuis 30 jours est annulé ;
  - délais divisés par deux pour un bon très urgent.
- **Décisions** : Q1 (catégories), Q9 (brouillons sans numéro), Q16 (ventilation réservée au CDG).
- **Vérifications** : 133 tests PHP, 56 JS, 32 contrôles dans le navigateur.

### Lot 4 — Pièces justificatives et tickets carburant, M03-B (07/10, §23)
**Objectif** : contrôler la lisibilité des justificatifs, repérer une pièce déjà présentée, et lire les tickets carburant avec vérification par l'utilisateur.
- **Qualité mesurée au dépôt** : sous 150 dpi la pièce est bloquée ; de 150 à 299 dpi, un avertissement s'affiche.
- **Pièce déjà utilisée sur un autre bon** : repérée par son empreinte ; justification demandée ; bandeau pour le CDG.
- **Lecture d'un ticket carburant** :
  - chaque champ montre sa confiance et une case « Vérifié » ; la lecture ne se valide qu'une fois toutes les cases cochées ;
  - avertissements : prix au litre, montant en lettres, immatriculation, date de mission.
- **Pièce soumise** : elle ne se supprime plus, on la remplace par une nouvelle version.
- **Décisions** : Q17 ; Q8 (lecture manuelle par défaut, aucune image ne quitte le serveur).
- **Vérifications** : 148 tests PHP, 18 contrôles dans le navigateur.

### Référentiels de paramétrage de Neemba (07/10, §24)
**Objectif** : charger les données réelles de Neemba (utilisateurs, codes analytiques, services, caisses, valideurs) depuis leur classeur Excel.
- **Import par commande** : simulation par défaut, avec un compte rendu pour Neemba (appliqué, en double validation, anomalies, valeurs « à confirmer »).
- **Import prudent** :
  - il n'applique pas une valeur « à confirmer » et ne supprime rien ;
  - il ne crée pas de code hors liste ;
  - il n'ajoute pas un rôle à privilèges à un compte existant.
- **Résultat de la v01** : 12 comptes créés, 22 codes complétés, 21 demandes de double validation.
- **Décisions** : Q5 (rôles déclarés), Q19, Q11, Q2.
- **Vérifications** : 159 tests PHP.

### Lot 5 — « Pour le compte de », liste et fiche du bon, M03-C (07/10, §25)
**Objectif** : permettre à un suppléant de saisir pour un titulaire, et donner à chacun une liste et une fiche complètes.
- **« Pour le compte de »** : délégation « Initiation de bons » ; le bon prend le site et le service du titulaire ; mention « Initié par X pour le compte de Y ».
- **Faille corrigée** : cette délégation donnait aussi les droits de validation du titulaire.
- **Liste** :
  - le bénéficiaire voit ses bons ;
  - filtres, recherche, colonne « Âge » ;
  - cartes Total, À valider, Payés, Rejetés.
- **Fiche** : bandeau du rejet avec « Corriger et resoumettre » ; onglets Validations par version, Pièces, chronologie.
- **Décisions** : Q6, Q4.
- **Vérifications** : 173 tests PHP, 14 contrôles dans le navigateur.

---

## 4. Module « Ordres de mission », spec v2.2 (M12-0 à M12-8)

Objectif d'ensemble : remplacer le papier et le fil d'emails « Service_Mission » par des ordres de mission (ODM) en ligne. Le parcours couvre la saisie, le calcul des indemnités, les visas, la génération des bons de caisse, les prolongations, la clôture, l'impression et le tableau de bord. Tout a été fait le 08/10, sur la branche `m12-odm`.

### M12-0 — Prérequis (§26)
- **Frais Orange Money** au paiement, par palier (1 %, puis 0,8 %). Le seuil du DP porte sur la dépense, hors frais.
- **Taux du jour FCFA → GNF** saisi par la Trésorerie ; sans lui, le paiement d'un ODM extérieur est bloqué.
- **Jours ouvrés** (week-ends et jours fériés exclus) ; échéance d'un BP de mission.
- **Paramètres des ODM** (indemnités, hébergement, barème FCFA, participants…), sous double validation.
- **Rôles et fiche utilisateur** : chef d'atelier, DP adjoint, logistique ; n° OM, statut cadre et rôles complémentaires sur la fiche.
- **Décisions** : Q29, Q31.

### M12-1 — Modèle, calcul et numérotation (§27)
- Un ODM est fait de **segments** (ODM initial puis prolongations), avec ses participants, ses OR liés et un circuit.
- **Calcul** :
  - jours = retour − départ + 1, nuits = jours − 1 (0 sur base vie) ;
  - à l'intérieur : 250 000 GNF par jour et 500 000 GNF par nuit ;
  - à l'extérieur : indemnité en FCFA convertie au taux du jour.
- **Barèmes figés** à la validation ; contrôle nuits = jours − 1.
- **Numéro** N°[séquence]/[préfixe du service]/[AA], avec reprise des carnets papier.
- **Décisions** : Q22 à Q28.
- **Vérifications** : les exemples B.1 à B.4 de la spec sont retrouvés au franc près.

### M12-2 — Saisie et soumission (§28)
- **Formulaire** d'une page, avec le calcul en direct et l'enregistrement automatique. Les participants sont choisis parmi les salariés ; base vie et facture d'hébergement à l'étranger se saisissent par participant.
- **Soumission** : contrôles bloquants, numéro attribué, circuit créé, liste de diffusion notifiée.
- **Chevauchement** : un participant ne peut pas être sur deux ODM à la fois, sauf dérogation accordée par le DAF.
- **Liste et fiche** : liste des ODM, fiche (Détails, Calcul, Historique) ; annulation par le demandeur avant la génération des bons.
- **Décisions** : Q25, Q33 à Q35.

### M12-3 — Circuit de validation (§29)
- **Circuit** : chef d'atelier, puis Finance (DAF, DAF adjoint ou Chef comptable), puis DP ou DP adjoint ; suppléants par délégation « Visa des ODM ».
- **Incompatibilités** : demandeur, initiateur et participants ne visent pas. Une étape sans valideur est sautée, sauf la dernière.
- **Rejet** motivé : l'ODM passe en version 2. Relances et escalade toutes les heures.
- **Décisions** : Q36 à Q39.

### M12-4 — Génération des bons et paiement (§30)
- Depuis un ODM validé, un bon définitif par participant (ou un bon groupé, retiré depuis par la v2.4), repris de l'ODM et verrouillé. Aucun justificatif n'est demandé : l'ODM en tient lieu.
- **Circuit des bons** : ils sont soumis aussitôt dans le circuit normal ; un BP d'avance pour frais réels est possible en option.
- **ODM à la charge du client** : marqué « à refacturer ».
- **ODM extérieur** : montant recalculé au taux du jour au paiement.

### M12-5 — Prolongations, vue mission, rappel (§31)
- **Prolongation** depuis le dernier segment, avec une nuitée de rattrapage et son propre circuit.
- **Onglet Mission** : cumuls par participant et contrôle de cohérence. Une incohérence est signalée au DAF.
- **Rappel** au demandeur deux jours ouvrés avant la fin d'un segment.

### M12-6 — Clôture, annulation, hébergement à l'étranger (§32)
- **Clôture** à la date de retour réelle. Un retour anticipé crée un trop-perçu, reversé en caisse ou retenu sur salaire par les RH.
- **Annulation** par le DAF ou son adjoint ; jamais si un bon est payé.
- **Hébergement à l'étranger payé au retour** : il donne un bon complémentaire.
- **Décisions** : Q24, Q26, Q47.

### M12-7 — Impression et tableau de bord (§33)
- **PDF** avec les visas électroniques horodatés (refait au §39 sur le modèle papier).
- **Tableau de bord** du DAF et de la direction : missions en cours, dérogations, ODM à refacturer, export Excel.

### M12-8 — Recette et bilan (§34)
- 29 règles RG-M12, 9 messages et 11 scénarios couverts.
- Parcours complet dans le navigateur, avec la création, les trois visas, la génération, le paiement OM, la prolongation, le retour anticipé et la clôture.
- **Vérifications** : 264 tests PHP, dont 90 pour M12 ; 116 contrôles dans le navigateur sur l'ensemble des lots M12.

---

## 5. Évolutions des ODM et comptabilité (spec v2.3)

### Visa d'un bon par son bénéficiaire (09/10, §35)
**Problème** : un chef de service participant à un ODM tombait sur une erreur « 403 » en voulant viser les bons de la mission.
- **Règle centrale** `CircuitBon` : elle désigne qui vise (titulaires, rôles complémentaires compris, et suppléants). Demandeur, initiateur et bénéficiaire ne visent jamais.
- **Étape sans valideur** : sautée et tracée, sauf la Finance et le DP. Le saut s'applique à la soumission, après chaque visa et toutes les heures.
- **Messages** : le refus explique son motif au lieu d'afficher « 403 ».
- **Notifications** : elles vont aux vrais valideurs.
- **Décision** : Q48, un service sans chef de service voit l'étape sautée.

### Prise en charge des frais ligne par ligne (09/10, §36)
- Pour chaque participant et chaque frais : payé par Neemba, avancé puis refacturé au client, ou payé directement par le client.
- Carte « Prise en charge » ; badge « À refacturer ».
- Ce modèle a été remplacé le 12/10 par la spec v2.3.

### Spec v2.3 : frais répartis en parts, référentiel clients (12/10, §37)
- **Parts** : chaque ligne de frais se répartit entre Neemba et un ou plusieurs clients, en pourcentage ou en montant, avec client, OR et code analytique. Neemba prend 100 % par défaut. Une saisie rapide s'applique à un participant ou à tout l'ODM.
- **Contrôles** : 100 % atteints, client obligatoire, commentaire si la part ne suit pas l'OR, code analytique connu.
- **Référentiel clients** : ajout, modification, désactivation, import Excel avec aperçu.
- **Restitution** : récapitulatif Neemba / clients pour les valideurs et les rôles financiers ; « À refacturer par client et par OR » au tableau de bord.
- **Décisions** : Q52 à Q55.

### M16 imputation comptable et M14 export des bons (12/10, §38)
- **Rôle** « Comptabilité » ; la Comptabilité impute chaque bon payé en une ou plusieurs lignes.
- **Pré-remplissage** : comptes, BP en 421100, frais OM, code journal par mode de paiement.
- **Bon d'ODM** : une ligne par part, client et OR repris. La Comptabilité peut corriger la répartition, avec motif, tant que l'écriture n'est pas exportée.
- **Export des écritures** (Excel ou CSV), figé à l'export.
- **Alerte** aux Chefs comptables des bons non imputés à J+1.
- **Export Excel de la liste des bons** (M14), une ligne par part pour les bons d'ODM.
- **Décisions** : Q56 à Q60.

### PDF de l'ODM sur le modèle papier (13/10, §39)
- Une page reprenant les rubriques des fiches N°285 et N°282 :
  - en-tête de Neemba Guinée ;
  - rubrique du service cochée ;
  - blocs Mission, Prise en charge et Passagers ;
  - calcul par participant et montant en lettres ;
  - visas électroniques et cadre de signature.
- Filigrane « BROUILLON », « NON VALIDÉ » ou « ANNULÉ » tant que l'ODM n'est pas validé.

### Génération sélective des bons d'un ODM (13/10, §40)
- **Génération** pour tous les participants, un seul ou une sélection ; les bons sont soumis aussitôt.
- **Correction** : l'ODM ne passe « Payé » que si chaque participant a son bon payé.
- **Bandeau de l'ODM** sur la fiche du bon, avec « Ouvrir l'ordre de mission ».
- **Décision** : Q61.

---

## 6. Conformité à la spec v2.3 (lots A à E)

Un audit du code contre la spec v2.3 (14/10) a fixé l'ordre des lots. Ils sont tous sur la branche `v23-conformite`.

### Lot A — Contrôle interne (17/10, §41)
- **Failles corrigées** : régularisation d'un BP réservée au demandeur ; PDF d'un bon soumis aux droits de la fiche.
- **Rôles par étape** : Finance (DAF, DAF adjoint, Chef comptable) ; DP (DP, DP adjoint).
- **Paiement** :
  - caissier du site, ou gestionnaire et suppléant de la caisse ;
  - jamais un bon dont le caissier est bénéficiaire.
- **Annulation** : par le demandeur avant le premier visa, puis seulement par le DAF ou son adjoint, avec motif.
- **Doublon probable** (même bénéficiaire, même montant sur 7 jours) : la soumission est bloquée jusqu'à confirmation.
- **BP en retard** du bénéficiaire : blocage paramétrable (aucun, partiel, total) et dérogation accordée par le DAF.
- **Décisions** : Q62 à Q64.
- **Vérifications** : 20 contrôles dans Edge.

### Lot B — Tiers et visiteurs du groupe (17/10, §42)
- **Bénéficiaire** choisi dans un référentiel : salarié, visiteur du groupe ou tiers validé.
- **Tiers** :
  - proposé par le demandeur et validé par la Comptabilité ou le CDG ;
  - doublons de téléphone, de n° OM ou de NIF bloqués ;
  - un tiers se désactive, il ne se supprime pas.
- **Fiche visiteur** tenue par les RH (filiale, séjour, directeur d'accueil).
- **Reprise** : les anciens noms libres deviennent des tiers « proposés ».
- **Décision** : Q65.

### Lot C — Numérotation par caisse et caisse de rattachement (17/10, §43)
- **Numéro** `BC-[code caisse]-[année]-[00001]`, avec une séquence par caisse ; le numéro de départ reprend le carnet papier (ex. `BC-ATL-2026-0005123`).
- **Caisse de rattachement** pour chaque utilisateur : elle émet ses bons et, en espèces, les paie.
- **Codes de la spec** posés sur les caisses (CKY, ATL, OM, BOK, SAG, SGL, SGT, COR).
- **Décision** : Q66.

### Lot D — Saisie et paiement (14/10, §44)
- **OR** facultatifs, 8 chiffres commençant par 110, avec un type vente ou garantie.
- **Motif et urgence** : motif de 10 à 250 caractères ; motif d'urgence pris dans une liste.
- **Justificatifs** : un BD exige une facture ou une proforma ; un scan sous 300 dpi n'est qu'un avertissement.
- **Orange Money** : frais estimés ; n° OM obligatoire, et motif si on le modifie.
- **Paiement** :
  - motifs de rejet de la spec ;
  - référence de paiement obligatoire hors espèces ;
  - le caissier choisit le mode ; « Autre » est retiré, MTN Money ajouté (inactif).
- **Décisions** : Q67, Q68.

### Lot E — Régularisation des BP (14/10, §45)
- **Justification** du BP selon l'Annexe 3 : jusqu'à 10 lignes (intitulé, code analytique, compte, montant, pièce), avec les calculs « à retourner » et « trop justifié ».
- **Circuit de la justification** : initiateur, N+1, CDG, Comptabilité ; rejet motivé et nouvelle version.
- **Au dernier visa** : le BP est « Régularisé ». Un trop justifié donne un BD de complément ; un reliquat est encaissé par le caissier.
- **Relances et suivi** :
  - relance chaque vendredi ;
  - BP échu transmis aux RH, qui tracent la retenue sur salaire ;
  - rapport mensuel au DP.
- **Décision** : Q69, seul le trop justifié donne un BD ; écart assumé avec la spec.
- **Vérifications** : 26 contrôles dans Edge.

---

## 7. Intégration de la spec v2.4 (lots R, V, E1 à E4, O, F0, F1)

### Audit v2.3 et intégration de la v2.4 (15 et 16/10, §46, §47)
- **Audit v2.3** (15/10) : 152 règles relevées une à une. Résultat : 94 faites, 38 partielles, 20 à faire. Huit écarts graves relevés.
- **Suivi** : création du fichier `docs/CONFORMITE.md`, qui donne pour chaque règle son état, ce qui manque, la cause et le lot qui la traite.
- **Spec v2.4** (14/10) comparée à la v2.3 : 13 règles nouvelles, 11 modifiées, 3 messages nouveaux et 2 messages reformulés, 4 points ouverts, 4 scénarios. Elle ajoute :
  - l'entité transverse et la double authentification ;
  - le gestionnaire des référentiels ;
  - les barèmes ODM datés ;
  - les types d'OR et l'« OR à régulariser » ;
  - le modèle caisse ≠ site, avec le site analytique ;
  - les visiteurs du groupe en BD seulement, et un bon par participant.
- **Décisions du 16/10** :
  - corriger l'existant d'abord ;
  - un seul fichier de suivi ;
  - double authentification active partout, avec un paramètre de secours ;
  - les données existantes gardent leur cycle.
- **Plan validé** : R → V → E1 → E2 → E3 → E4 → O → F1 → F2 → G → I → H → P3, environ 33 jours ; F0 a été inséré avant F1 le 19/10.

### Lot R — Correctifs de l'audit v2.3 (16/10, §48)
- **Visa Finance** : il n'est jamais sauté, même quand le DP est requis. Le BD de complément d'un BP passe par la Finance.
- **OTP de paiement** :
  - envoyé au bénéficiaire seulement ;
  - un code expiré ne paie plus ;
  - « Reçu par » est tracé.
- **Statut** : « Bon à payer » au lieu d'« Approuvé ».
- **Écran de visa** : il montre les alertes de doublon et de BP en retard.
- **Code analytique du CDG** : contrôlé par le serveur.
- **Comptabilité** : elle voit les ODM.
- **Délais de visa (SLA)** selon la spec : 4 h, 24 h, 8 h et 24 h, en heures ouvrées, avec escalade DP → DAF.
- **Rejet** : commentaire toujours obligatoire (Q73). Messages MSG-M03 au texte exact ; la soumission nomme le prochain valideur et son délai.
- **Vérifications** : 358 tests PHP, 11 contrôles dans Edge.

### Lot V — Corrections v2.4 de l'existant (16/10, §49)
- **Visiteurs du groupe** : plus de BP, seulement un BD exceptionnel.
  - Motif d'une liste fermée, commentaire obligatoire, marquage « refacturation inter-filiale » avec la filiale.
  - Finance réservée au DAF et à son adjoint ; le DAF est notifié.
  - Colonnes dans l'export.
- **ODM** :
  - un bon par participant, le bon groupé est retiré ;
  - le bon complémentaire de l'hébergement à l'étranger est verrouillé, créé avec sa facture et soumis aussitôt.
- **Libellés** : l'ERP s'appelle désormais Helium.
- **Décision** : Q79, un bon visiteur attend le DAF.
- **Vérifications** : 361 tests PHP, 15 contrôles dans Edge.

### Lot E1 — Entité transverse (16/10, §50)
- **Entités** : table avec Neemba Guinée active et Neemba Mining prévue, inactive. Chaque compte, caisse, bon, ODM et référentiel porte son entité.
- **Droits par entité × rôle**, et filtre automatique sur l'entité courante.
- **Vue consolidée** pour les rôles d'un paramètre (Q81) ; sélecteur d'entité dans le bandeau.
- **Valideurs** de l'entité du bon ou de l'ODM.
- **Numérotation** des ODM par entité ; logo et adresse de l'entité sur les PDF.
- **Saisie et exports** : entité affichée et verrouillée à la saisie ; colonnes « Entité » et « Site analytique » dans les exports.
- **Vérifications** : 368 tests PHP, 15 contrôles dans Edge.

### Lot E2 — Double authentification (19/10, §51)
- **Connexion en deux temps** : mot de passe, puis code à 6 chiffres envoyé par email.
  - Le code dure 10 min et accepte 5 essais ; on peut en redemander un après une minute.
  - Un compte désactivé est refusé.
- **Visa d'une demande urgente ou très urgente** (bon ou ODM) : code envoyé par SMS au valideur, lié à la demande. Le rejet n'en demande pas (Q77).
- **Urgence sur l'ODM**, avec motif (Q76).
- **Stockage** : les codes sont enregistrés en empreinte, jamais en clair.
- **Onglet « Sécurité »** (demande du 19/10, Q82) :
  - l'administrateur active ou désactive le code de connexion et le code de visa, et règle durées et essais ;
  - effet immédiat, tracé, et autres administrateurs prévenus ;
  - état des envois email et SMS, et liste des valideurs sans téléphone ;
  - commande de secours sur le serveur.
- **Correctif** : l'interrupteur affichait « Désactivé » sans rien enregistrer ; il s'applique désormais au clic.
- **Vérifications** : 389 tests PHP ; 25 contrôles dans Edge, puis 20 pour l'onglet.

### Lot E3 — Gestionnaire des référentiels, barèmes ODM datés, types d'OR (19/10, §52)
- **Rôle « Gestionnaire des référentiels »** : il tient services, codes analytiques, types de document, motifs, types d'OR, barèmes et clients.
  - L'administrateur garde entités, sites, caisses, seuils et sécurité (Q78).
  - Clients : gestionnaire, Comptabilité et CDG (Q83).
- **Barèmes ODM datés** :
  - nouvelle valeur avec une date d'effet (aujourd'hui ou plus tard), sans double validation ;
  - historique conservé ; une valeur à venir peut être retirée ;
  - un ODM est calculé avec les barèmes du jour de sa soumission (Q84).
- **Types d'OR** en référentiel (Vente, Garantie, et d'autres à ajouter) ; listes et libellés servis par le serveur partout.
- **Vérifications** : 397 tests PHP, 19 contrôles dans Edge.

### Lot E4 — Caisses et sites, site analytique (19/10, §53)
- **Caisses et sites** :
  - une caisse sert un ou plusieurs sites, un site peut avoir plusieurs caisses ;
  - la caisse Orange Money n'a pas de site ;
  - sites servis cochés à l'écran des caisses (Q86).
- **Caisse payeuse** tirée de la caisse émettrice, et non du site du bon.
- **Site analytique** : par défaut le site du demandeur, modifiable à la saisie, corrigé par le CDG à son étape, avec une trace.
- **Écrans** : ils ne plantent plus sur une caisse sans site.
- **Rapport journalier** : la caisse OM n'apparaît que dans « Tous les sites » jusqu'au lot F2 (Q85).
- **Vérifications** : 402 tests PHP, 16 contrôles dans Edge.

### Lot O — OR à régulariser, besoin carburant (19/10, §54)
- **« OR à régulariser »** : une mission technique peut être soumise sans OR. À la validation, une échéance de 5 jours ouvrés est fixée.
- **OR rattaché après coup** sur la fiche, par le demandeur ou le chef d'atelier, tracé et reporté sur les bons (Q80).
- **Relances** à J+3 et à l'échéance, puis alerte au chef d'atelier et au DAF (Q87).
- **Blocage du prochain ODM** du demandeur, en option, désactivé par défaut.
- **Suivi** : section et onglet « OR à régulariser » au tableau de bord.
- **Besoin carburant** : immatriculation et itinéraire sur l'ODM, la fiche et le PDF.
- **Correctif** : l'urgence de l'ODM choisie au formulaire n'était pas enregistrée (lot E2).
- **Vérifications** : 408 tests PHP, 14 contrôles dans Edge.

### Lot F0 — PV d'arrêté de caisse (19/10, §55)
Le lot F0 a été sorti de F2, parce que la demande de réapprovisionnement de F1 s'appuie dessus (Q88).
- **PV d'arrêté par caisse** (Annexe 2) :
  - billetage de 20 000 à 50 GNF, ou solde lu pour la caisse OM ;
  - solde comptable et écart.
- **Signatures** : le gérant de la caisse, la trésorerie et le Chef comptable, par trois personnes différentes (Q90). PDF au format Annexe 2.
- **Écart** : il crée un ajustement d'inventaire validé par le DAF avec motif. Le manquant débite la caisse, l'excédent la crédite.
- **Décision** : Q89, le rôle « Membre du CODIR » sera fait au lot F1.
- **Correctif** : tout compte rattaché à la caisse aurait pu signer comme gérant.
- **Vérifications** : 412 tests PHP, 14 contrôles dans Edge.

### Lot F1 — Caisses, réapprovisionnement, encaissement client, réconciliation OM (19/10, §56)
Le module M08 de la spec, livré en trois parties.
- **F1a, alertes et encaissements** :
  - l'alerte de seuil part une fois, au franchissement vers le bas, au gérant, à la trésorerie et au Chef comptable, puis un rappel chaque jour tant que le solde reste bas ;
  - l'encaissement client se fait à Conakry principale, avec un reçu PDF ; en espèces, il est refusé s'il dépasse le plafond, et la trésorerie est alertée pour une remise en banque ;
  - le caissier voit sa caisse, les rôles transverses toutes ; plus de retrait libre.
- **F1b, réapprovisionnement** :
  - le gérant demande avec le PV d'arrêté du jour et le rapprochement signé ;
  - montant proposé : plafond − solde (Atelier : avance − solde), baissé seulement avec un motif ;
  - plafond de caisse et habilitation du DP (500 000 000) respectés ;
  - circuit : trésorerie (qui peut réduire), Finance, DP. En l'absence du DP et de son adjoint, deux membres du CODIR approuvent ensemble (nouveau rôle) ;
  - la trésorerie exécute avec l'origine des fonds (chèque, mise à disposition, Orange Money, virement pour la caisse OM, transfert depuis une autre caisse pour l'Atelier) ;
  - la caisse n'est créditée qu'à la confirmation de réception ; un écart alerte le DAF ;
  - les mouvements manuels disparaissent ; la gérante de l'Atelier, sans rôle caissier, a accès aux écrans de sa caisse.
- **F1c, réconciliation Orange Money** :
  - chaque semaine, la trésorerie importe le relevé de la plateforme (CSV ou Excel) ;
  - les écritures du brouillard sont rapprochées automatiquement (même sens, même montant, dates proches) ;
  - les lignes restantes des deux côtés et l'écart de solde sont affichés ;
  - la trésorière, le Chef comptable et le DAF valident ; rappel le lundi.
- **Décisions** : Q89, Q91 à Q98 (absence du DP, PV signé par le gérant, transfert au registre, format du relevé, chèque hors solde).
- **Correctifs** : l'alerte d'un encaissement refusé disparaissait ; la trésorerie ne voyait aucune caisse ; la gérante de l'Atelier n'ouvrait pas ses écrans ; une réconciliation sans solde de relevé aurait été validée sans écart.
- **Vérifications** : 426 tests PHP, 53 contrôles dans Edge (15 + 24 + 14).

---

## 8. Ce qui reste à faire

| Lot | Contenu | Durée estimée |
|---|---|---|
| **F2** | Clôture journalière par caisse, rapport « REPORTING CAISSE » par caisse, envoi avant 8 h, inventaires obligatoires et contrôles inopinés | ≈ 3 j |
| **G** | **Gouvernance** : | ≈ 3 j |
| | – domaine @neemba.com, assistant trésorier | |
| | – date d'effet des autres paramètres, second validateur paramétrable | |
| | – signataires habilités, délégations, brouillons abandonnés | |
| | – journal protégé, archivage | |
| **I** | Reçu signé, emails de toutes les notifications, rapport des bons visiteurs par filiale, tableau de bord du DAF | ≈ 3 j |
| **H** | Carburant (M13) : avance carburant payée par un bon rattaché à l'ODM | ≈ 4 j |
| **P3** | Saisie hors ligne, reprise des données, performance, recette des 41 scénarios | ≈ 4 j |

L'échéance de la spec est le 16/12/2026.

---

## 9. Ce qu'il faut obtenir de Neemba

- **Rôles à attribuer** : gestionnaire des référentiels (CDG ou Comptabilité), Comptabilité, RH.
- **Caisses** :
  - gestionnaire et suppléant de chaque caisse ;
  - membres du CODIR (rôle à attribuer) ;
  - un relevé Orange Money exporté, pour confirmer l'import de la réconciliation ;
  - sites servis à vérifier ;
  - numéros de départ des carnets papier.
- **Listes** :
  - types d'OR (PO-28) ;
  - motifs du bon visiteur (PO-30) ;
  - fichier des clients (PO-26) ;
  - comptes et codes journaux (PO-17, PO-25) ;
  - signataires habilités (PO-15).
- **Infrastructure** :
  - serveur email et fournisseur SMS (PO-23), indispensables à la double authentification en production ;
  - numéros de téléphone des valideurs ;
  - date des environnements cloud (PO-31).
- **Réponses** : réponse aux propositions du 14/10 (PO-29) et confirmation des décisions marquées « à confirmer » dans `docs/questions.md`.
- **Recette** : cahier Excel des 41 scénarios, à demander à Thierno DIALLO.
