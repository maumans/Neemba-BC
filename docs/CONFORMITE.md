# Conformité à la spécification : état des règles et plan de mise en œuvre

> **Source de référence** : `docs/Spec_Fonctionnelle_v2.4_Neemba_P2.docx` (Thierno DIALLO, 14/10/2026). Elle remplace la v2.3 du 09/10, auditée le 15/10.
>
> La v2.4 intègre le comité du 14/10 et le cadrage IT du 08/10. Elle compte :
> - 165 règles RG (13 nouvelles et 11 modifiées par rapport à la v2.3) ;
> - 48 user stories ;
> - 39 messages ;
> - les exigences générales (§2), le socle (§5) et les paramètres (§6) ;
> - 31 points ouverts PO et 41 scénarios SC.
>
> Les règles nouvelles ou modifiées par la v2.4 portent la mention **[v2.4]** dans les tableaux.
>
> **Méthode** : audit du code de la branche `v23-conformite` règle par règle (15/10, après les lots A à E), puis comparaison de la v2.4 avec la v2.3 (16/10). Les preuves sont dans le devBook et les sections citées.
>
> **Branche de travail** : `v23-conformite`. Commits locaux, ni push ni déploiement sans demande.
>
> **Mise à jour** : à la fin de chaque lot, cocher les cases du §11, passer l'état des règles à ✅ et ajouter une ligne au journal (§14).

---

## 1. Légende

**État d'une règle**
- ✅ **Fait** : codé, testé, conforme au texte de la spec.
- 🟡 **Partiel** : une partie existe ; la colonne « Ce qui manque » dit quoi.
- ❌ **À faire** : rien, ou presque, n'existe. S'applique aussi à du code existant que la v2.4 rend faux.

**Pourquoi ce n'est pas encore fait** (colonne « Cause »)
- **L** : le lot qui la traite est prévu, pas encore réalisé.
- **O** : écart relevé par l'audit (reste du socle SFD v1.3, ou mise en œuvre incomplète d'un lot déjà fait).
- **N** : attend une donnée ou une décision de Neemba (point ouvert PO-xx ou liste à fournir).
- **C** : choix de développement fait, à confirmer (décision Qxx de `docs/questions.md`).
- **P3** : livrable du lot P3 de la spec (extension aux sites, hors ligne, recette d'ensemble).
- **V4** : changement de la v2.4 qui remet en cause du code déjà écrit.

**Lot** : lot du plan (§11) qui traite l'écart.

---

## 2. Synthèse au 16/10/2026

| Module | Règles | ✅ | 🟡 | ❌ | Lot(s) |
|---|---:|---:|---:|---:|---|
| M01 Comptes, rôles, sécurité | 9 | 1 | 4 | 4 | E1, E2, E3, G, P3 |
| M02 Référentiels et paramétrage | 11 | 5 | 3 | 3 | E3, E4, G |
| M03 Saisie du bon | 28 | 24 | 4 | 0 | E1, E3, E4, G |
| M04 Circuit de validation | 10 | 9 | 0 | 1 | G |
| M05 Délégations | 4 | 1 | 2 | 1 | G |
| M06 Paiement | 8 | 7 | 0 | 1 | I |
| M07 Bons provisoires | 8 | 5 | 3 | 0 | I |
| M08 Caisses et réapprovisionnement | 13 | 2 | 3 | 8 | F1 |
| M09 Clôture et rapport journalier | 5 | 0 | 4 | 1 | F2 |
| M10 Inventaire et contrôle | 3 | 0 | 1 | 2 | F2 |
| M12 Ordres de mission | 42 | 37 | 2 | 3 | O |
| M13 Carburant | 15 | 3 | 8 | 4 | H |
| M15 Archivage et audit | 3 | 0 | 3 | 0 | G |
| M16 Imputation comptable | 6 | 5 | 1 | 0 | E1, E4 |
| **Total des règles RG** | **165** | **99** | **38** | **28** | |

Les lots R et V sont terminés et commités le 16/10 : leurs règles sont à ✅.

**Messages** :

| Module | Conformes | Total |
|---|---:|---:|
| M03 | 17 | 17 |
| M12 | 8 | 11 |
| M08 | 0 | 6 |
| M13 | 0 | 5 |

**Notifications (M11)** : les événements existent dans l'application, mais l'email manque presque partout ; les événements v2.4 sont à créer.

**Tableau de bord du DAF (M14)** : incomplet ; les rapports v2.4 sont à créer.

**Ce qui bloque le passage en réel à Conakry (P1)** :
- le socle entité, la double authentification, les référentiels et les caisses / sites (bloc E) ;
- le réapprovisionnement et l'encaissement (F1) ;
- la clôture, le rapport et l'inventaire (F2) ;
- les signataires habilités (G).

M12 v2.4 (O) et M13 (H) relèvent de P2. Le hors ligne et la reprise relèvent de P3.

---

## 3. Exigences générales (§1.4, §2, §10.1)

| Exigence | État | Ce qui manque | Cause | Lot |
|---|:-:|---|:-:|:-:|
| §1.4 / §10.1 Aucun seuil, plafond, taux, barème ni délai codé en dur | 🟡 | Constantes restantes : 10 Mo, 20 fichiers, 50 Mo, dpi, SLA très urgent ÷ 2, coupures, jour de relance, écarts carburant, table d'escalade, conservation 5 ans | O | G |
| §1.4 Paramètres avec historique et date d'effet | ❌ | Barèmes ODM au lot E3 (RG-M02-10) ; les autres paramètres au lot G (RG-M02-01) | O | E3, G |
| §2.1 Entité transverse, Neemba Guinée seule active, Neemba Mining prévue **[v2.4]** | 🟡 | Texte libre sur les utilisateurs et les ODM seulement ; voir RG-M01-02 et RG-M01-08 | V4, L | E1 |
| §2.2 ERP Helium (intégration hors périmètre) **[v2.4]** | ✅ | Libellés corrigés (lot V) | | |
| §2.3 Mobile, tablette, poste fixe | ✅ | — | | |
| §2.3 Saisie hors ligne (PWA) | ❌ | Ni manifeste ni service worker | P3 | P3 |
| §2.3 Import contrôlé de la reprise, validé par le Chef comptable | 🟡 | Manquent : soldes d'ouverture, BP papier, ODM en cours, délégations, validation par le Chef comptable | P3, N (PO-16) | P3 |
| §2.3 Performance (< 3 s, < 25 s, < 60 s, 50 utilisateurs) | ❌ | Aucune mesure | P3 | P3 |
| §2.3 Hébergement cloud Neemba, tests de sécurité, double authentification **[v2.4]** | 🟡 | Hébergement selon la spec technique et PO-31 ; double authentification : voir RG-M01-07 | N, L | E2, P3 |
| §10.1 Messages au texte exact | 🟡 | Voir les messages de chaque module | O, L | R, V, O, F1, H |
| §10.1 Chaque action tracée au journal d'audit | 🟡 | Délégations, mouvements et rapports de caisse incomplets ; journal non protégé (RG-M15-02) | O | G |

---

## 4. Socle à conserver et anomalies (§5)

| Élément | État | Ce qui manque | Cause | Lot |
|---|:-:|---|:-:|:-:|
| §5.1 Circuit à plusieurs niveaux, motif de 10 caractères minimum | ✅ | Rejet : commentaire toujours obligatoire, 10 caractères (Q73, lot R) | C | |
| §5.1 BP et BD, J+3 / J+2 | ✅ | — | | |
| §5.1 OTP à 6 chiffres, 5 min paramétrables | ✅ | Destinataire : voir RG-M06-02 | | |
| §5.1 Délégation avec acceptation du délégué | ✅ | — | | |
| §5.1 Notifications colorées selon l'urgence ; alertes email | ✅ | Email limité aux bons urgents (voir M11) | | |
| §5.1 Export Excel et PDF | ✅ | — | | |
| §5.1 Conservation paramétrable | 🟡 | Durée en constante | O | G |
| §5.1 Solde négatif bloqué | ✅ | — | | |
| ANO-01, 03, 05, 06, 07, 09, 10 et ANO-02, 04, 08, 11 à 14 | ✅ | Lots 0 à 5. Caisse Atelier inactive tant que son solde réel n'est pas saisi (N) | | |
| §5.3 Urgence motivée, SMS très urgent ; code analytique modifiable par le CDG ; délais par étape ; rapport Excel ; ventilation | ✅ | — | | |
| §5.3 Double validation des modifications de l'administrateur | 🟡 | Voir RG-M01-05 | O | G |
| §5.3 Niveau administrateur en cas d'absences simultanées | ❌ | Rien n'est prévu | O | G |
| §5.3 Processus de réapprovisionnement | ❌ | Voir M08 | L | F1 |

---

## 5. Référentiels et paramètres (§6, M02)

### 5.1 Tables et barèmes

| Élément | État | Ce qui manque | Cause | Lot |
|---|:-:|---|:-:|:-:|
| §6.1 Trois montants par caisse | 🟡 | Plafond de caisse jamais contrôlé | L | F1 |
| §6.1 Les 8 caisses et leurs valeurs | 🟡 | Valeurs à vérifier en base ; Atelier inactive | N | — |
| §6.1 Modèle caisse ≠ site ≠ client ; la caisse OM n'a pas de site **[v2.4]** | ❌ | Une caisse a un seul site (`caisses.site_id` obligatoire), la caisse OM est rattachée à Conakry | V4 | E4 |
| §6.1 Encaissement client réservé à Conakry principale | 🟡 | Simple drapeau | L | F1 |
| §6.2 Modes de paiement | ✅ | — | | |
| §6.3 Liste des 10 services | ✅ | — | | |
| §6.3 Code service numérique (100 à 900) | 🟡 | Champ libre, sans contrôle | N (PO-14) | G |
| §6.4 Codes analytiques (6 caractères, par service, désactivables) | 🟡 | Longueur contrôlée à l'import seulement | O | G |
| §6.5 Utilisateurs | 🟡 | Ni @neemba.com, ni entité gérée, ni champ suppléant | O, L | E1, G |
| §6.5 Visiteurs : BD seulement, filiale reprise pour la refacturation **[v2.4]** | ✅ | Lot V | | |
| §6.5 Tiers | ✅ | — | | |
| §6.5 Clients, ajoutés par le gestionnaire des référentiels **[v2.4]** | 🟡 | Tenus par la Comptabilité, le CDG et l'administrateur ; fichier attendu (PO-26) | L, N | E3 |
| §6.5 Types d'OR en référentiel (VENTE, GARANTIE, autres à venir) **[v2.4]** | ❌ | Constante VENTE / GARANTIE ; enum en base pour l'ODM | V4 | E3 |
| §6.6 Seuil DP > 1 500 000 hors frais OM | ✅ | Corrigé en base par la migration du lot R | | |
| §6.6 Habilitation du DP : 500 000 000 | ❌ | Ni paramètre ni contrôle | L | F1 |
| §6.6 Barèmes ODM en référentiel daté, sans circuit de validation **[v2.4]** | ❌ | Paramètres soumis à la double validation, sans date d'effet | V4 | E3 |
| §6.6 Frais OM, taux du jour, délai de régularisation | ✅ | — | | |
| §6.6 Jour de relance des BP : vendredi | 🟡 | Codé en dur | O | G |
| §6.6 Coupures du billetage | 🟡 | Constante | O | G |
| §6.7 SLA 4/24/8/24 h, escalade DP → DAF, heures ouvrées | ✅ | Lot R (migration `2026_10_19_000001`, `HeuresOuvrees`, heures 8 h–17 h paramétrables) | | |

### 5.2 Règles M02

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M02-01 | Date d'effet sur tout paramètre ; calcul selon la date de soumission | 🟡 | ODM figés à la soumission ; le reste n'a ni date d'effet ni historique ; seuil DP relu au jour du visa | O | E3, G |
| RG-M02-02 | Paramètre désactivé ou remplacé, jamais supprimé | ✅ | — | | |
| RG-M02-03 | Rattachement entité / site / caisse ; rôles transverses voient tout | 🟡 | CDG, DAF et DP voient leur étape seulement ; Trésorerie aucun bon ; entité non appliquée | O | E1, G |
| RG-M02-04 | Statut cadre obligatoire pour l'ODM extérieur | ✅ | — | | |
| RG-M02-05 | N° OM : 9 chiffres commençant par 6 | ✅ | — | | |
| RG-M02-06 | Tiers proposé non sélectionnable avant validation | ✅ | Lot B | | |
| RG-M02-07 | Doublon de tiers bloqué | ✅ | Lot B | | |
| RG-M02-08 | Second validateur paramétrable (DAF ou Chef comptable) ; **barèmes ODM exclus [v2.4]** | 🟡 | Seul l'administrateur valide, rôle non paramétrable ; les barèmes passent encore par la double validation | O, V4 | E3, G |
| RG-M02-09 | Signataires habilités par service et par niveau | ❌ | Aucune liste | L, N (PO-15) | G |
| RG-M02-10 | Barèmes ODM tenus par le gestionnaire des référentiels, sans circuit, avec date d'effet et historique **[v2.4]** | ❌ | — | L | E3 |
| RG-M02-11 | Une caisse sert un ou plusieurs sites ; un site a une ou plusieurs caisses ; la caisse OM n'a pas de site ; la caisse ne porte ni client ni lieu de mission **[v2.4]** | ❌ | Liaison caisse ↔ site en 1:1 ; caisse payeuse déduite du site du bon | V4 | E4 |

---

## 6. M01 Comptes, rôles et sécurité

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M01-01 | Comptes au domaine neemba.com uniquement | ❌ | Aucun contrôle (Q70) | O | G |
| RG-M01-02 | Entité transverse : utilisateurs, caisses, bons, ODM, référentiels, paramètres ; Neemba Guinée active, Mining inactive **[v2.4]** | 🟡 | Texte libre sur les utilisateurs et les ODM seulement | V4, L | E1 |
| RG-M01-03 | Nouveaux rôles (dont assistant trésorier) | 🟡 | Pas de rôle « assistant trésorier » (Q75) | O | G |
| RG-M01-04 | Incompatibilités | ✅ | — | | |
| RG-M01-05 | Second validateur avant l'effet d'un changement sensible | 🟡 | Effet immédiat pour les codes analytiques, l'activation d'un compte, la caisse de rattachement, les sites, les services et les motifs | O | G |
| RG-M01-06 | Interface adaptative ; hors ligne en P3 | 🟡 | Hors ligne | P3 | P3 |
| RG-M01-07 | Double authentification : OTP par email à la connexion (durée et essais paramétrables) ; OTP par SMS au valideur pour viser une demande urgente (BD, BP, ODM) **[v2.4]** | ❌ | — | L | E2 |
| RG-M01-08 | Droits par entité × rôle ; numérotation, référentiels, gabarit du bon, rapports par entité ; vue consolidée **[v2.4]** | ❌ | — | L | E1 |
| RG-M01-09 | Rôle « gestionnaire des référentiels », distinct de l'administrateur **[v2.4]** | ❌ | L'administrateur tient les référentiels | L | E3 |

---

## 7. M03 Saisie du bon de caisse

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M03-01 | Type BD ou BP choisi en premier | ✅ | | | |
| RG-M03-02 | BP : 421100 ; mission → date de retour ; échéance affichée | ✅ | | | |
| RG-M03-03 | Bénéficiaire du référentiel | ✅ | | | |
| RG-M03-04 | BP au profit d'un salarié seulement ; visiteur ou tiers → BD **[v2.4]** | ✅ | Lot V : refus serveur (MSG-M03-02), BP non proposé à l'écran | | |
| RG-M03-05 à 10 | Motif, montant, mode, plafond espèces, frais OM, n° OM | ✅ | | | |
| RG-M03-11 | Entité et caisse pré-remplies et verrouillées ; site analytique selon RG-M03-27 **[v2.4]** | 🟡 | Caisse faite (lot C) ; entité ni affichée ni verrouillée | L | E1 |
| RG-M03-12 | Service et code analytique filtré | ✅ | | | |
| RG-M03-13 | OR : 8 chiffres en 110 ; type choisi dans le référentiel des types d'OR **[v2.4]** | 🟡 | Format fait (lot D) ; type en constante VENTE / GARANTIE | V4 | E3 |
| RG-M03-14 | Urgence, motif de la liste, SMS très urgent | ✅ | | | |
| RG-M03-15 | BD : facture ou proforma ; exception du BD issu d'un ODM, confirmée le 14/10 **[v2.4]** | ✅ | | | |
| RG-M03-16 | Formats, tailles, alerte sous 300 dpi | ✅ | Limites en dur (§3) | | G |
| RG-M03-17 | Visa DP au-delà de 1 500 000, affiché avant soumission | ✅ | Lot R (MSG-M03-11) | | |
| RG-M03-18 | Numéro par caisse et par année | ✅ | Lot C | | |
| RG-M03-19 | Brouillon supprimé après 30 jours | 🟡 | Annulé et tracé au lieu d'être supprimé (Q71) | C | G |
| RG-M03-20 à 26 | Doublon probable, BP en retard, bon d'ODM verrouillé, rejet et versions, pas d'auto-visa, journal, pièce déjà soumise | ✅ | Lots A, M12 | | |
| RG-M03-27 | Site analytique, distinct de la caisse ; site du demandeur par défaut, modifiable à la saisie ; corrigé par le CDG avec trace **[v2.4]** | 🟡 | Le site existe, mais il est figé sur celui du demandeur et sert à trouver la caisse payeuse | V4 | E4 |
| RG-M03-28 | Bon visiteur : BD exceptionnel, motif d'une liste fermée (PO-30) et commentaire ; Finance réservée au DAF ; marqué « refacturation inter-filiale » avec la filiale ; reporting **[v2.4]** | ✅ | Lot V (liste des motifs attendue du DAF, PO-30 ; rapport par filiale au lot I) | | |

**User stories M03** : couvertes, sauf US-08 (entité, lot E1).

**Messages M03**

| Message | État | Écart | Lot |
|---|:-:|---|:-:|
| MSG-M03-01, 03 à 16 | ✅ | Lot R : MSG-M03-01, 05, 10, 11, 15 au texte exact (les clés MSG-BC-002, 012, 021, 020, 032 de la SFD v1.3 portent le même texte) ; MSG-M03-15 nomme le prochain valideur et le délai | |
| MSG-M03-02, MSG-M03-17 **[v2.4]** | ✅ | Lot V | |

---

## 8. Modules allégés

### 8.1 M04 Circuit de validation

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| Circuit §8.2 | Visa Finance avant tout décaissement | ✅ | Lot R : Finance et DP jamais sautés ; complément de BP soumis à la Finance | | |
| RG-M04-01, 02, 04, 06, 07, 09 | Circuit affiché, bon d'ODM, motifs de rejet, délais, annulation, étape sautée | ✅ | | | |
| RG-M04-03 | Code analytique modifié par le CDG, tracé | ✅ | Lot R : code actif et du service, contrôlé par le serveur | | |
| RG-M04-05 | Bandeaux à chaque étape | ✅ | Lot R : alertes sur l'écran de visa (`AlertesDuBon`) | | |
| RG-M04-08 | Seuls les signataires désignés visent | ❌ | Dépend de RG-M02-09 | L, N | G |
| RG-M04-10 | Bon visiteur : Finance réservée au DAF ou DAF adjoint, pas au Chef comptable **[v2.4]** | ✅ | Lot V : paramètre `visiteur_finance_daf_seul` ; le bon attend si ni l'un ni l'autre ne peut viser (Q79) | | |

### 8.2 M05 Délégations et suppléances

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M05-01 | Suppléant : période, types de visa, montants | 🟡 | Pas de plafond de montant | O | G |
| RG-M05-02 | Acceptation du délégué | ✅ | | | |
| RG-M05-03 | Caissier informé des délégations | ❌ | | O | G |
| RG-M05-04 | Absence du DP et de l'adjoint, visa conjoint | 🟡 | DP adjoint équivalent au DP (Q72) ; pas de visa conjoint | O, C | G |

### 8.3 M06 Paiement et décaissement

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M06-01 | Caissier de sa caisse, statut « Bon à payer » | ✅ | Lots A, C, R | | |
| RG-M06-02 | OTP au bénéficiaire, 5 min, vaut accusé de réception | ✅ | Lot R : sans repli sur le demandeur, sans prolongation, « Reçu par » tracé | | |
| RG-M06-03, 04, 06, 07, 08 | Contrôles au paiement, référence, mise à jour de la caisse, mode décidé par le caissier, code journal | ✅ | Codes journaux attendus (PO-17) | | |
| RG-M06-05 | Reçu PDF signé ; signature sur tablette | ❌ | | L | I |

### 8.4 M07 Bons provisoires et régularisation

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M07-01 | BP réservé aux salariés ; 421100 ; **pas de BP pour un visiteur [v2.4]** | ✅ | Lot V ; les BP de visiteurs déjà émis gardent leur cycle (décision du 16/10) | | |
| RG-M07-02, 03, 06, 07 | Échéance, justification Annexe 3, blocage, rapport mensuel | ✅ | Lots A et E | | |
| RG-M07-08 | Régularisation : BD lié, reliquat, complément | 🟡 | Seul le trop justifié donne un BD (Q69, à confirmer) ; le complément passe le visa Finance (lot R) | C | — |
| RG-M07-04 | Relance du vendredi au nom du caissier, avec copies | 🟡 | Dans l'application seulement, sans email ; destinataires selon Q74 | O, C | I |
| RG-M07-05 | « En retard », transmis aux RH, retenue après l'email de la DRH | 🟡 | Pas d'état « En retard » affiché ; email de la DRH tracé, pas envoyé | O | I |

### 8.5 M09 Clôture journalière et rapport journalier

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M09-01 | Format Neemba « REPORTING CAISSE », par jour et par caisse | 🟡 | Rapport par site au format v1.3 | L | F2 |
| RG-M09-02 | Ligne « Écart » de contrôle | 🟡 | Absente du rapport | L | F2 |
| RG-M09-03 | Clôture par le caissier ; jour clos bloqué | ❌ | | L | F2 |
| RG-M09-04 | Génération à 23 h 45, envoi avant 8 h, destinataires par caisse | 🟡 | Envoi à 7 h 30 ; mauvais destinataires | L, N (PO-18) | F2 |
| RG-M09-05 | Onglet « Détail » : valideurs et délais par étape | 🟡 | Partiel | L | F2 |

### 8.6 M10 Inventaire et contrôle de caisse

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M10-01 | Inventaires et contrôles inopinés | ❌ | | L | F2 |
| RG-M10-02 | PV d'arrêté Annexe 2 | 🟡 | Billetage par site ; pas de PV par caisse ni de signatures | L | F2 |
| RG-M10-03 | Écart → ajustement en attente du DAF | ❌ | | L | F2 |

### 8.7 M11 Notifications, relances et escalades

| Événement | Application | Email | SMS | Ce qui manque | Lot |
|---|:-:|:-:|:-:|---|:-:|
| Soumission | ✅ | 🟡 urgents | 🟡 très urgents | Email pour tous | I |
| Relance et escalade SLA | ✅ | ❌ | ✅ | Email | I |
| Rejet, approbation, paiement | ✅ | ❌ | ❌ | Email ; approbation filtrée par caisse | I |
| Relance BP, BP en retard (RH) | ✅ | ❌ | ❌ | Email | I |
| Tiers proposé, validé, refusé | ✅ | ❌ | ❌ | Email | I |
| Seuil franchi ; demande de réapprovisionnement ; écart de réception | 🟡 | ❌ | 🟡 | Avec M08 | F1 |
| ODM soumis, validé, prolongé, clôturé, rejeté ; incohérence de nuitées | 🟡 | ❌ | ❌ | Email ; rejet à la liste de diffusion | I |
| Avance carburant, fiche de régularisation, retenue (RH) | 🟡 | ❌ | ❌ | Avec M13 | H, I |
| OR à régulariser : relance J+3, échéance, retard **[v2.4]** | ❌ | ❌ | ❌ | | O, I |
| Bon au profit d'un visiteur du groupe (DAF) **[v2.4]** | ✅ | ❌ | ❌ | Email | I |
| OTP de connexion (email) et de visa urgent (SMS) **[v2.4]** | ❌ | ❌ | ❌ | | E2 |

SMTP et fournisseur SMS sont à confirmer par l'IT de Neemba (PO-23, cloud Neemba retenu le 08/10).

### 8.8 M14 Tableaux de bord et reporting

| Élément | État | Ce qui manque | Cause | Lot |
|---|:-:|---|:-:|:-:|
| Tableau de bord du DAF | 🟡 | Bons en attente par étape, BP en cours, alertes doublon, vue unique ; Chef comptable et DAF adjoint exclus | O | I |
| Export Excel opérationnel ; « Frais à refacturer » ; rapport mensuel des BP | ✅ | | | |
| Rapport « OR à régulariser » (RG-M12-41) **[v2.4]** | ❌ | | L | O |
| Bons au profit de visiteurs, par filiale d'origine (RG-M03-28) **[v2.4]** | 🟡 | Colonnes de l'export faites (lot V) ; rapport par filiale au lot I | L | I |
| Tous les tableaux, rapports et exports filtrables par entité, avec vue consolidée **[v2.4]** | ❌ | | L | E1 |

### 8.9 M15 Archivage et journal d'audit

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M15-01 | Tout archivé, recherchable | 🟡 | PDF générés non conservés ; rien côté ODM | O | G |
| RG-M15-02 | Journal ni modifiable ni supprimable | 🟡 | Pas de verrou ; suppression en cascade ; couverture incomplète | O | G |
| RG-M15-03 | 5 ans, purge sur décision | 🟡 | Constante ; pas de purge | O | G |

### 8.10 M16 Imputation comptable

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M16-01, 02, 04, 05, 06 | Imputation, pré-remplissage, alerte J+1, parts d'ODM, corrections | ✅ | devBook §38 ; comptes et journaux attendus (PO-17, PO-25) | | |
| RG-M16-03 | Export : ajoute le **site analytique** et l'**entité** ; aucun format Helium **[v2.4]** | 🟡 | Les deux colonnes manquent | V4 | E1, E4 |

---

## 9. Modules détaillés M08, M12, M13

### 9.1 M08 Caisses, réapprovisionnement et caisse Atelier

**Existant** :
- un registre par caisse ;
- des « mouvements » manuels validés en une fois par le DAF ou le DP ;
- un arrêté de caisse par site avec billetage.

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M08-01 | Solde par caisse ; Atelier distincte | ✅ | Solde réel de l'Atelier à saisir | N | |
| RG-M08-02 | Mouvements autorisés, pièce pour chaque encaissement | 🟡 | Retrait libre ; manquent encaissement client, transfert, ajustement signé | L | F1 |
| RG-M08-03 | Alerte une fois + rappel quotidien ; caissier, Trésorerie, Chef comptable | 🟡 | Alerte répétée ; mauvais destinataires | L | F1 |
| RG-M08-04 à 10 | Plafond, demande avec rapprochement et PV, montant proposé, circuit et habilitation du DP, origine des fonds, réception, encaissement client | ❌ | | L | F1 |
| RG-M08-11 | Carnet Atelier | ✅ | Lot C | | |
| RG-M08-12 | Caissier : sa caisse ; rôles transverses : toutes | 🟡 | Filtres par site ; Trésorerie et Chef comptable exclus | L | F1 |
| RG-M08-13 | Rapprochement hebdomadaire de la caisse OM | ❌ | Format du relevé OM à obtenir | L, N | F1 |

User stories : US-01, 02, 05, 09 partielles ; US-03, 04, 06, 07, 08, 10 à faire.

Messages : MSG-M08-01 à 06 à créer (F1).

### 9.2 M12 Ordres de mission

Le suivi détaillé reste dans `docs/ORDRE_DE_MISSIONS.md`. RG-M12-01, 03 à 13, 15 à 33 et 35 à 37 sont faites (devBook §26 à §40).

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M12-02 | Mission technique : au moins un OR, **ou ODM marqué « OR à régulariser » [v2.4]** | 🟡 | L'OR est obligatoire, sans l'option | V4 | O |
| RG-M12-14 | **Un bon par participant ; bon groupé abandonné, paramètre à retirer [v2.4]** | ✅ | Lot V : paramètre et option retirés ; les bons groupés déjà émis restent valides | | |
| RG-M12-24 | Liste de diffusion du service | ✅ | Listes réelles à saisir | N | |
| RG-M12-34 | Répartition consultable par la Comptabilité | ✅ | Lot R : rôle `comptable` dans la vue globale des ODM | | |
| RG-M12-38 | **Aucune saisie hors barème ; hôtel au forfait de 500 000 [v2.4]** | ✅ | Lot V : bon complémentaire de la clôture verrouillé, créé avec sa facture et soumis aussitôt ; facture d'hébergement ignorée hors ODM extérieur payé avant le départ | | |
| RG-M12-39 | **« OR à régulariser » : soumission sans OR, OR saisi après coup et tracé, l'indicateur tombe [v2.4]** | ❌ | | L | O |
| RG-M12-40 | **Délai de 5 jours ouvrés, relances J+3 et échéance, alerte au chef d'atelier et au DAF, blocage optionnel [v2.4]** | ❌ | | L | O |
| RG-M12-41 | **Suivi au tableau de bord DAF et rapport M14 [v2.4]** | ❌ | | L | O |
| RG-M12-42 | **Besoin carburant sur l'ODM (véhicule, immatriculation, itinéraire) ; jamais une ligne de frais [v2.4]** | 🟡 | Un seul champ « véhicule » ; pas d'itinéraire | V4 | O |

**Messages M12**

| Message | État | Écart | Lot |
|---|:-:|---|:-:|
| MSG-M12-02 à 09 | ✅ | — | |
| MSG-M12-01 **[v2.4]** | ❌ | Nouveau texte : « …ou cochez « OR à régulariser ». » | O |
| MSG-M12-10, 11 **[v2.4]** | ❌ | Nouveaux | O |

### 9.3 M13 Carburant

**Existant** : la lecture des tickets à la saisie d'un BD :
- valeurs lues et validées, confiance, corrections ;
- alertes MSG-BC-022 à 026.

Le parcours « avance → tickets → fiche de régularisation » n'existe pas.

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M13-01 | Avance = BP au conducteur, **payée par un bon carburant rattaché à l'ODM ; véhicule, immatriculation, trajet repris de l'ODM [v2.4]** | ❌ | | L | H |
| RG-M13-02 | Montant fixé par la Logistique | ❌ | PO-11 | L, N | H |
| RG-M13-03, 08, 12 | Format du ticket, chiffres / lettres, saisie manuelle | ✅ | | | |
| RG-M13-04, 05 | IA avec confiance, confirmation champ par champ | 🟡 | Pas de moteur IA ; manquent n° de ticket et prix | L, N | H |
| RG-M13-06, 09, 10 | Véhicule de l'ODM, date de mission, prix ± 5 % | 🟡 | Comparaisons partielles, seuils en dur | L, O | H |
| RG-M13-07, 11 | Litres × prix ; doublon métier | ❌ | | L | H |
| RG-M13-13, 14, 15 | Fiche de la Trésorerie, archivage, tableau de suivi | 🟡 | | L | H |

User stories US-01 à 07 et messages MSG-M13-01 à 05 : à faire (H).

---

## 10. Points ouverts (§9) : valeur par défaut développée

| PO | Sujet | Défaut codé | État | Attendu de |
|---|---|---|:-:|---|
| PO-01, 02, 04 à 08, 10, 17, 19 à 22, 24, 25, 27 | Voir l'audit du 15/10 | Défauts de la spec en place | ✅ | Neemba, Comptabilité |
| PO-03 | Un bon par participant **[v2.4 : décidé le 14/10]** | Un bon par participant (lot V) | ✅ | — |
| PO-09 | Procédure ODM du groupe | Règles de la spec | — | Neemba |
| PO-11 | Calcul de l'avance carburant | Saisie libre par la Logistique | ❌ (H) | Neemba |
| PO-12 | Neemba Mining | Neemba Guinée seule active | 🟡 (E1) | Neemba |
| PO-13 | Codes analytiques, Atelier, CORICA | 22 radicaux importés | 🟡 | CDG |
| PO-14 | Codes de service numériques | Champ vide | 🟡 (G) | Neemba |
| PO-15 | Utilisateurs et signataires habilités | Import partiel | 🟡 (G) | Neemba, RH |
| PO-16 | Reprise des données | Import contrôlé | ❌ (P3) | Neemba, avant S5 |
| PO-18 | Destinataires du rapport hors Conakry | Gestionnaire + Trésorerie | ❌ (F2) | Neemba |
| PO-23 | Infrastructure **[v2.4 : cloud Neemba retenu le 08/10]** | — | — | IT Neemba |
| PO-26 | Référentiel clients (export Helium ou fichier) | Import prêt | 🟡 | Neemba |
| PO-28 **[v2.4]** | Liste complète des types d'OR | VENTE, GARANTIE | ❌ (E3) | Neemba |
| PO-29 **[v2.4]** | Propositions du 14/10 (OR à régulariser, carburant, visiteurs, site analytique, gestionnaire des référentiels) | Valeurs de la spec, en paramètres | ❌ (V, E3, E4, O) | Neemba, avant S6 |
| PO-30 **[v2.4]** | Motifs du bon visiteur | Liste vide, paramétrable (onglet « Motifs bon visiteur ») + commentaire | ✅ | DAF Neemba |
| PO-31 **[v2.4]** | Date des environnements cloud | — | — | IT Neemba |

---

## 11. Plan de mise en œuvre et suivi

Ordre validé le 16/10 : on corrige d'abord l'existant contredit par la v2.4, puis on fait le bloc E de la spec, puis la suite du plan v2.3.

**R → V → E1 → E2 → E3 → E4 → O → F1 → F2 → G → I → H → P3** (environ 33 jours, échéance de la spec au 16/12/2026).

Chaque lot comprend :
- tests automatiques PHP et JS ;
- migrations vérifiées sur une copie de la base (aller, retour, aller) ;
- build du front ;
- parcours dans Edge sur une copie jetable de la base, avec les rôles réels ;
- section du devBook ;
- commits locaux ;
- cases cochées ici.

Décision du 16/10 sur les données existantes : on garde l'historique et on bloque seulement les nouvelles saisies.

### Lot R — Correctifs de l'audit (≈ 0,5 j restant) ✅

- [x] Visa Finance jamais sauté (`CircuitBon::estEtapeObligatoire`) ; BD de complément soumis à la Finance
- [x] OTP au bénéficiaire seulement, sans prolongation ; « Reçu par » tracé
- [x] Statut « Bon à payer » ; bandeaux sur l'écran de visa ; code analytique du CDG contrôlé par le serveur
- [x] Rôle Comptabilité dans la vue globale des ODM
- [x] SLA 4/24/8/24 h en heures ouvrées, escalade DP → DAF ; seuil DP à 1 500 000 en base
- [x] Rejet : commentaire obligatoire (Q73)
- [x] Messages MSG-M03-01, 05, 10, 11, 15 au texte exact
- [x] Suite complète, build, parcours Edge, devBook, commits

### Lot V — Corrections v2.4 de l'existant (≈ 1,5 j) ✅

- [x] Visiteur → BD seulement :
  - [x] règle de saisie et assistant (MSG-M03-02) ;
  - [x] historique des BP déjà émis conservé et listé.
- [x] Bon visiteur exceptionnel :
  - [x] table `motifs_visiteur` et onglet de paramétrage (PO-30) ;
  - [x] motif et commentaire (MSG-M03-17) ;
  - [x] marque « refacturation inter-filiale » et filiale ;
  - [x] colonnes dans l'export.
- [x] Finance du bon visiteur réservée au DAF et au DAF adjoint (paramètre) ; notification du DAF (RG-M04-10)
- [x] Bon d'ODM groupé retiré :
  - [x] paramètre et option supprimés ;
  - [x] écran de génération mis à jour ;
  - [x] tests et Q61 révisés (RG-M12-14).
- [x] Bon complémentaire de la clôture verrouillé, créé avec sa facture et soumis aussitôt ; facture d'hébergement ignorée hors ODM extérieur payé avant le départ (RG-M12-38)
- [x] Libellé Helium

### Lot E1 — Entité transverse (≈ 2,5 j)

- [ ] Table `entites` (Neemba Guinée active, Neemba Mining inactive) ; `entite_id` sur les utilisateurs, caisses, bons, ODM, référentiels et paramètres
- [ ] Droits par entité × rôle (`roles_utilisateurs.entite_id`) ; entité courante en session ; vue consolidée pour les rôles du paramètre
- [ ] Filtre par entité :
  - [ ] listes, tableaux de bord, rapports, exports, imputation ;
  - [ ] circuit des valideurs, visibilité.
- [ ] Numérotation des bons et des ODM par entité ; gabarit PDF par entité ; entité affichée et verrouillée à la saisie
- [ ] Tests SC-41 (isolation, consolidé)

### Lot E2 — Double authentification (≈ 1,5 j)

- [ ] Table `codes_otp` (connexion, visa de bon, visa d'ODM)
- [ ] Connexion en deux temps :
  - [ ] code par email ;
  - [ ] durée et nombre d'essais paramétrables ;
  - [ ] paramètre de secours `double_authentification_active`, sous double validation.
- [ ] Visa d'une demande urgente ou très urgente : code par SMS au valideur (bons et ODM) ; niveau d'urgence ajouté à l'ODM (Q76)
- [ ] Tests SC-40 ; test de connexion réécrit

### Lot E3 — Gestionnaire des référentiels, barèmes datés, types d'OR (≈ 2 j)

- [ ] Rôle `gestionnaire_referentiels` :
  - [ ] routes et onglets répartis (sites et caisses restent à l'administrateur, Q78) ;
  - [ ] l'administrateur ne tient plus les référentiels métier.
- [ ] Barèmes ODM :
  - [ ] table `baremes_odm` datée, avec historique ;
  - [ ] reprise des paramètres ;
  - [ ] lecture à la date de soumission ;
  - [ ] hors double validation (RG-M02-10, RG-M02-08).
- [ ] Types d'OR :
  - [ ] table `types_or` (VENTE, GARANTIE) ;
  - [ ] enum de l'ODM remplacé ;
  - [ ] listes servies par le serveur ;
  - [ ] valeurs en dur retirées (RG-M03-13, PO-28).

### Lot E4 — Caisses, sites, site analytique (≈ 2 j)

- [ ] Liaison `caisse_site` ; caisse OM sans site ; écran des caisses multi-sites (RG-M02-11)
- [ ] Caisse payeuse indépendante du site du bon ; plafond de retrait lu sur la caisse ; corrections des plantages sur une caisse sans site
- [ ] Site analytique (RG-M03-27) :
  - [ ] modifiable à la saisie ;
  - [ ] corrigé par le CDG avec trace ;
  - [ ] colonnes site analytique et entité dans l'export comptable (RG-M16-03).
- [ ] Tests SC-33

### Lot O — ODM : OR à régulariser, besoin carburant (≈ 2 j)

- [ ] Case « OR à régulariser » ; MSG-M12-01 nouveau texte
- [ ] Échéance en jours ouvrés après la validation ; ajout d'OR après coup, tracé et reporté sur les bons (Q80)
- [ ] Commande `odm:relancer-or` :
  - [ ] relance à J+3 et à l'échéance (MSG-M12-10) ;
  - [ ] alerte au chef d'atelier et au DAF.
- [ ] Blocage optionnel du prochain ODM (MSG-M12-11)
- [ ] Section « OR à régulariser » au tableau de bord et à l'export (RG-M12-41)
- [ ] Immatriculation et itinéraire sur l'ODM, la fiche et le PDF (RG-M12-42)
- [ ] Tests SC-38

### Lot F1 — Caisses, réapprovisionnement, encaissement client (M08) (≈ 4 j)

- [ ] Types de mouvement :
  - [ ] retrait libre retiré ;
  - [ ] ajout de l'encaissement client, du transfert (activable par caisse) et de l'ajustement d'inventaire ;
  - [ ] pièce obligatoire pour chaque encaissement.
- [ ] Demande de réapprovisionnement :
  - [ ] montant proposé (plafond − solde ; Atelier : avance fixe − solde), baisse motivée ;
  - [ ] rapprochement signé et PV du jour (MSG-M08-03) ;
  - [ ] plafond de caisse respecté (MSG-M08-02).
- [ ] Circuit caissier → Trésorerie → Chef comptable / DAF → DP ; habilitation du DP à 500 000 000 ; DP absent : deux membres du CODIR
- [ ] Exécution par la Trésorerie avec l'origine des fonds ; confirmation de réception ; écart signalé au DAF (MSG-M08-04)
- [ ] Encaissement client à Conakry principale, reçu PDF, blocage au plafond (MSG-M08-05, 06)
- [ ] Alerte de seuil une fois, puis rappel quotidien ; destinataires caissier, Trésorerie, Chef comptable (MSG-M08-01)
- [ ] Visibilité par caisse ; rôles transverses voient toutes les caisses
- [ ] Rapprochement hebdomadaire de la caisse OM, avec trois visas
- [ ] Notifications de la demande et de l'écart

### Lot F2 — Clôture, rapport journalier, inventaire (M09, M10) (≈ 3 j)

- [ ] Clôture par le caissier, par caisse ; écriture impossible sur une journée close
- [ ] Rapport « REPORTING CAISSE » :
  - [ ] une feuille par jour et par caisse ;
  - [ ] bloc contrôle, ligne « Écart » en rouge ;
  - [ ] onglet « Détail ».
- [ ] Génération à 23 h 45 ; envoi avant 8 h aux destinataires de chaque caisse
- [ ] PV d'arrêté Annexe 2 par caisse, avec trois signatures
- [ ] Inventaires obligatoires, contrôles inopinés paramétrables ; écart → ajustement en attente du DAF

### Lot G — Gouvernance (reste après le bloc E) (≈ 3 j)

- [ ] Domaine @neemba.com (Q70) ; rôle « assistant trésorier » (Q75)
- [ ] Date d'effet et historique pour les autres paramètres ; valeurs du bon figées à la soumission (RG-M02-01)
- [ ] Valeurs codées en dur passées en paramètres (§1.4)
- [ ] Second validateur paramétrable ; double validation étendue (RG-M01-05, RG-M02-08)
- [ ] Signataires habilités (RG-M02-09, RG-M04-08) ; visibilité des rôles transverses (RG-M02-03)
- [ ] Délégations : montants, information du caissier, absence du DP et de son adjoint, niveau administrateur (Q72)
- [ ] Code service numérique ; code analytique de 6 caractères ; brouillons abandonnés (Q71)
- [ ] Journal d'audit protégé et étendu ; archivage des PDF et des pièces d'ODM ; purge sur décision

### Lot I — Paiement, notifications, pilotage (M06, M07, M11, M14) (≈ 3 j)

- [ ] Reçu PDF signé par le caissier ; signature sur tablette (RG-M06-05)
- [ ] Emails de la matrice M11, y compris les événements v2.4 (OR à régulariser, bon visiteur)
- [ ] ODM rejeté notifié à la liste de diffusion
- [ ] BP « En retard » ; email de la DRH ; relance du vendredi par email (Q74)
- [ ] Tableau de bord du DAF complet (entité et consolidé) ; rapport des bons visiteurs par filiale

### Lot H — Carburant (M13) (≈ 4 j)

- [ ] Moteur de lecture choisi et testé sur les 5 tickets
- [ ] Avance carburant (RG-M13-01 v2.4, RG-M13-02) :
  - [ ] BP au conducteur ;
  - [ ] payée par un bon carburant rattaché à l'ODM ;
  - [ ] véhicule, immatriculation et trajet repris de l'ODM ;
  - [ ] montant saisi par la Logistique.
- [ ] Ticket : n° et prix ; confiance ; confirmation champ par champ ; contrôles et seuils en paramètres
- [ ] Fiche de régularisation de la Trésorerie ; archivage ; tableau de suivi ; MSG-M13-01 à 05

### Lot P3 — Hors ligne, reprise, performance, recette (≈ 4 j)

- [ ] PWA : brouillons et photos hors ligne
- [ ] Import contrôlé de la reprise, validé par le Chef comptable (PO-16)
- [ ] Mesures de performance
- [ ] Recette des 41 scénarios avec le cahier Excel ; environnements cloud (PO-31)

---

## 12. Scénarios de recette (annexe A)

Le cahier Excel des scénarios n'est pas dans le dépôt : **à demander à Thierno**.

| Scénarios | Contenu | Prêt ? | Lot |
|---|---|:-:|:-:|
| SC-01 à SC-04, SC-10, SC-11, SC-14 | Bons, plafond, OM, rejet, annulation, décaissement | ✅ | |
| SC-05 | BP au salarié ; visiteur refusé en BP | ✅ | (lot V) |
| SC-06 à SC-09 | Régularisation des BP | ✅ | (I pour les emails) |
| SC-12 | Suppléant ; absence du DP et de son adjoint | 🟡 | G |
| SC-13 | Délai dépassé : relance et escalade | ✅ | (lot R) |
| SC-15 à 17, SC-35 | Encaissement client, réapprovisionnement, Atelier, rapprochement OM | ❌ | F1 |
| SC-18, SC-19 | Clôture, rapport, inventaire | ❌ | F2 |
| SC-20 à SC-22, SC-24 à SC-29, SC-37 | ODM | ✅ | |
| SC-23 | Génération des bons, un par participant | ✅ | (lot V) |
| SC-30 à SC-32 | Carburant | ❌ | H |
| SC-33 | Isolation des caisses et sites | 🟡 | E4, F1 |
| SC-34 | Imputation et export comptable | 🟡 | E1, E4 (colonnes v2.4) |
| SC-36 | Hors ligne | ❌ | P3 |
| SC-38 **[v2.4]** | ODM technique « OR à régulariser » | ❌ | O |
| SC-39 **[v2.4]** | Bon exceptionnel au profit d'un visiteur | ✅ | (lot V ; rapport par filiale au lot I) |
| SC-40 **[v2.4]** | Double authentification, visa urgent par SMS | ❌ | E2 |
| SC-41 **[v2.4]** | Isolation par entité et vue consolidée | ❌ | E1 |

---

## 13. Décisions à prendre avant ou pendant les lots

| N° | Question | Proposition par défaut | Lot |
|---|---|---|:-:|
| Q69 | BD de la régularisation : montant réel ou trop justifié seul ? | Trop justifié seul (codé), à confirmer par la Comptabilité | R |
| Q70 | Comptes Addvalis hors du domaine neemba.com ? | Domaine exigé, sauf pour l'administrateur | G |
| Q71 | Brouillon abandonné : supprimé ou annulé et tracé ? | Annulé et tracé | G |
| Q72 | DP adjoint : à tout moment ou en l'absence du DP ? | En l'absence du DP | G |
| Q73 | Commentaire de rejet obligatoire ? | Toujours, 10 caractères minimum (codé au lot R) | R |
| Q74 | Relance BP : destinataires, BP non échus ? | Chef de service ; tous les BP en attente | I |
| Q75 | Assistant trésorier : rôle à part ? | Oui, avec les droits du caissier | G |
| Q76 **[v2.4]** | Urgence sur l'ODM, pour l'OTP de visa ? | Oui : mêmes niveaux et motifs que le bon | E2 |
| Q77 **[v2.4]** | L'OTP de visa porte-t-il sur le rejet ? | Non : seulement sur l'approbation | E2 |
| Q78 **[v2.4]** | Qui tient les sites et les caisses ? | L'administrateur, avec double validation ; le gestionnaire des référentiels tient le reste | E3 |
| Q79 **[v2.4]** | Bon visiteur sans DAF ni DAF adjoint disponible | Le bon attend ; la Finance n'est jamais sautée (appliqué, lot V) | V |
| Q80 **[v2.4]** | OR saisi après coup | Reporté sur les bons de l'ODM, avec trace | O |
| Q81 **[v2.4]** | Qui a la vue consolidée ? | Paramètre ; par défaut DAF, DAF adjoint, DP, DP adjoint, administrateur | E1 |

**À obtenir de Neemba** :
- valeurs des caisses et solde de l'Atelier ;
- signataires habilités (PO-15), codes de service (PO-14) ;
- destinataires du rapport (PO-18), listes de diffusion des ODM ;
- relevé Orange Money ;
- comptes et journaux (PO-17, PO-25), fichier clients (PO-26) ;
- données de reprise (PO-16) ;
- SMTP et SMS (PO-23) ;
- **[v2.4]** types d'OR (PO-28), réponse aux propositions du 14/10 (PO-29), motifs du bon visiteur (PO-30), environnements cloud (PO-31).

**À obtenir de Thierno** :
- le cahier Excel des 41 scénarios ;
- la mise à jour de la spécification technique (cloud, double authentification).

---

## 14. Journal de suivi

| Date | Lot | Commit | Remarques |
|---|---|---|---|
| 15/10/2026 | — | — | Lecture intégrale de la spec v2.3 et audit du code après les lots A à E : 152 règles RG, dont 94 faites, 38 partielles et 20 à faire. Plan R → F1 → F2 → G → I → H → P3 proposé |
| 16/10/2026 | — | — | Spec v2.4 reçue et comparée à la v2.3 : 13 règles nouvelles, 11 modifiées, 4 messages, 4 points ouverts, 4 scénarios. Plan d'intégration validé : R → V → E1 → E2 → E3 → E4 → O → F1 → F2 → G → I → H → P3. 165 règles RG : 88 faites, 45 partielles, 32 à faire. Fichier renommé `CONFORMITE.md` |
| 16/10/2026 | R | (ce commit) | Correctifs de l'audit terminés : visa Finance jamais sauté, complément de BP soumis à la Finance, OTP au bénéficiaire seulement, « Bon à payer », alertes sur l'écran de visa, code analytique du CDG contrôlé, Comptabilité dans la vue des ODM, SLA 4/24/8/24 h en heures ouvrées avec escalade DP → DAF, rejet toujours motivé, messages MSG-M03-01, 05, 10, 11, 15 au texte exact. 358 tests PHP, 74 JS ; parcours Edge (11 vérifications). 165 règles : 93 faites, 40 partielles, 32 à faire. devBook §48 |
| 16/10/2026 | V | (ce commit) | Visiteurs du groupe en BD seulement : motif d'une liste fermée (vide, PO-30) et commentaire, « refacturation inter-filiale » avec la filiale, Finance réservée au DAF, DAF notifié, bandeau à chaque étape, colonnes de l'export. Bon d'ODM groupé retiré. Bon complémentaire de la clôture verrouillé, créé avec sa facture et soumis. Helium. 361 tests PHP, 74 JS ; parcours Edge (15 vérifications). 165 règles : 99 faites, 38 partielles, 28 à faire. devBook §49 |
