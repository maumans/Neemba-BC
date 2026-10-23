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

## 2. Synthèse au 19/10/2026

| Module | Règles | ✅ | 🟡 | ❌ | Lot(s) |
|---|---:|---:|---:|---:|---|
| M01 Comptes, rôles, sécurité | 9 | 4 | 4 | 1 | G, P3 |
| M02 Référentiels et paramétrage | 11 | 7 | 3 | 1 | G |
| M03 Saisie du bon | 28 | 27 | 1 | 0 | G |
| M04 Circuit de validation | 10 | 9 | 0 | 1 | G |
| M05 Délégations | 4 | 1 | 2 | 1 | G |
| M06 Paiement | 8 | 7 | 0 | 1 | I |
| M07 Bons provisoires | 8 | 5 | 3 | 0 | I |
| M08 Caisses et réapprovisionnement | 13 | 2 | 3 | 8 | F1 |
| M09 Clôture et rapport journalier | 5 | 0 | 4 | 1 | F2 |
| M10 Inventaire et contrôle | 3 | 2 | 0 | 1 | F2 |
| M12 Ordres de mission | 42 | 41 | 1 | 0 | H |
| M13 Carburant | 15 | 3 | 8 | 4 | H |
| M15 Archivage et audit | 3 | 0 | 3 | 0 | G |
| M16 Imputation comptable | 6 | 6 | 0 | 0 | — |
| **Total des règles RG** | **165** | **114** | **32** | **19** | |

Les lots R, V et E1 sont terminés et commités le 16/10 : leurs règles sont à ✅.

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
- la double authentification, les référentiels et les caisses / sites (fin du bloc E) ;
- le réapprovisionnement et l'encaissement (F1) ;
- la clôture, le rapport et l'inventaire (F2) ;
- les signataires habilités (G).

M12 v2.4 (O) et M13 (H) relèvent de P2. Le hors ligne et la reprise relèvent de P3.

---

## 3. Exigences générales (§1.4, §2, §10.1)

| Exigence | État | Ce qui manque | Cause | Lot |
|---|:-:|---|:-:|:-:|
| §1.4 / §10.1 Aucun seuil, plafond, taux, barème ni délai codé en dur | 🟡 | Constantes restantes : 10 Mo, 20 fichiers, 50 Mo, dpi, SLA très urgent ÷ 2, coupures, jour de relance, écarts carburant, table d'escalade, conservation 5 ans | O | G |
| §1.4 Paramètres avec historique et date d'effet | 🟡 | Barèmes ODM datés avec historique (lot E3) ; les autres paramètres au lot G (RG-M02-01) | O | G |
| §2.1 Entité transverse, Neemba Guinée seule active, Neemba Mining prévue **[v2.4]** | ✅ | Lot E1 (mise en service de Mining : proposition distincte) | | |
| §2.2 ERP Helium (intégration hors périmètre) **[v2.4]** | ✅ | Libellés corrigés (lot V) | | |
| §2.3 Mobile, tablette, poste fixe | ✅ | — | | |
| §2.3 Saisie hors ligne (PWA) | ❌ | Ni manifeste ni service worker | P3 | P3 |
| §2.3 Import contrôlé de la reprise, validé par le Chef comptable | 🟡 | Manquent : soldes d'ouverture, BP papier, ODM en cours, délégations, validation par le Chef comptable | P3, N (PO-16) | P3 |
| §2.3 Performance (< 3 s, < 25 s, < 60 s, 50 utilisateurs) | ❌ | Aucune mesure | P3 | P3 |
| §2.3 Hébergement cloud Neemba, tests de sécurité, double authentification **[v2.4]** | 🟡 | Double authentification faite (lot E2, RG-M01-07) ; hébergement selon la spec technique et PO-31 ; tests de sécurité | N | P3 |
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
| §6.1 Modèle caisse ≠ site ≠ client ; la caisse OM n'a pas de site **[v2.4]** | ✅ | Lot E4 : table `caisse_site`, caisse OM sans site, site analytique distinct de la caisse | | |
| §6.1 Encaissement client réservé à Conakry principale | 🟡 | Simple drapeau | L | F1 |
| §6.2 Modes de paiement | ✅ | — | | |
| §6.3 Liste des 10 services | ✅ | — | | |
| §6.3 Code service numérique (100 à 900) | 🟡 | Champ libre, sans contrôle | N (PO-14) | G |
| §6.4 Codes analytiques (6 caractères, par service, désactivables) | 🟡 | Longueur contrôlée à l'import seulement | O | G |
| §6.5 Utilisateurs | 🟡 | Ni @neemba.com, ni champ suppléant (entité : lot E1) | O | G |
| §6.5 Visiteurs : BD seulement, filiale reprise pour la refacturation **[v2.4]** | ✅ | Lot V | | |
| §6.5 Tiers | ✅ | — | | |
| §6.5 Clients, ajoutés par le gestionnaire des référentiels **[v2.4]** | ✅ | Lot E3 : gestionnaire des référentiels, avec la Comptabilité et le CDG (Q83) ; l'administrateur n'y a plus accès. Fichier attendu (PO-26) | | |
| §6.5 Types d'OR en référentiel (VENTE, GARANTIE, autres à venir) **[v2.4]** | ✅ | Lot E3 : table `types_or`, onglet « Types d'OR » ; autres types attendus (PO-28) | | |
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
| RG-M02-03 | Rattachement entité / site / caisse ; rôles transverses voient tout | 🟡 | Entité appliquée (lot E1) ; CDG, DAF et DP voient leur étape seulement ; Trésorerie aucun bon | O | G |
| RG-M02-04 | Statut cadre obligatoire pour l'ODM extérieur | ✅ | — | | |
| RG-M02-05 | N° OM : 9 chiffres commençant par 6 | ✅ | — | | |
| RG-M02-06 | Tiers proposé non sélectionnable avant validation | ✅ | Lot B | | |
| RG-M02-07 | Doublon de tiers bloqué | ✅ | Lot B | | |
| RG-M02-08 | Second validateur paramétrable (DAF ou Chef comptable) ; **barèmes ODM exclus [v2.4]** | 🟡 | Barèmes exclus (lot E3) ; seul l'administrateur valide, rôle non paramétrable | O | G |
| RG-M02-09 | Signataires habilités par service et par niveau | ❌ | Aucune liste | L, N (PO-15) | G |
| RG-M02-10 | Barèmes ODM tenus par le gestionnaire des référentiels, sans circuit, avec date d'effet et historique **[v2.4]** | ✅ | Lot E3 : table `baremes_odm`, onglet « Barèmes ODM », lecture à la date de soumission | | |
| RG-M02-11 | Une caisse sert un ou plusieurs sites ; un site a une ou plusieurs caisses ; la caisse OM n'a pas de site ; la caisse ne porte ni client ni lieu de mission **[v2.4]** | ✅ | Lot E4 : sites servis choisis à l'écran des caisses ; caisse payeuse tirée de la caisse émettrice | | |

---

## 6. M01 Comptes, rôles et sécurité

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M01-01 | Comptes au domaine neemba.com uniquement | ❌ | Aucun contrôle (Q70) | O | G |
| RG-M01-02 | Entité transverse : utilisateurs, caisses, bons, ODM, référentiels, paramètres ; Neemba Guinée active, Mining inactive **[v2.4]** | ✅ | Lot E1 : table `entites`, `entite_id` partout | | |
| RG-M01-03 | Nouveaux rôles (dont assistant trésorier) | ✅ | Lot T : assistant trésorier (droits du caissier) ; tous les rôles métier peuvent être rôle principal (Q103) | | |
| RG-M01-04 | Incompatibilités | ✅ | — | | |
| RG-M01-05 | Second validateur avant l'effet d'un changement sensible | 🟡 | Effet immédiat pour les codes analytiques, l'activation d'un compte, la caisse de rattachement, les sites, les services et les motifs | O | G |
| RG-M01-06 | Interface adaptative ; hors ligne en P3 | 🟡 | Hors ligne | P3 | P3 |
| RG-M01-07 | Double authentification : OTP par email à la connexion (durée et essais paramétrables) ; OTP par SMS au valideur pour viser une demande urgente (BD, BP, ODM) **[v2.4]** | 🟡 | Code de connexion fait (lot E2, Q82). Code SMS de visa retiré le 20/10 à la demande de l'utilisateur (Q104) : remplacé par une alerte SMS aux valideurs des ODM urgents. Écart à faire valider par Thierno. SMTP et SMS à confirmer (PO-23) | C | |
| RG-M01-08 | Droits par entité × rôle ; numérotation, référentiels, gabarit du bon, rapports par entité ; vue consolidée **[v2.4]** | 🟡 | Lot E1 : tout est fait côté serveur et à l'écran, sauf l'attribution d'un rôle dans une autre entité depuis l'écran Utilisateurs (utile à la mise en service de Mining) | L | Mise en service de Mining |
| RG-M01-09 | Rôle « gestionnaire des référentiels », distinct de l'administrateur **[v2.4]** | ✅ | Lot E3 : rôle complémentaire, onglets et routes répartis (Q78). À attribuer chez Neemba (CDG ou Comptabilité) | | |

---

## 7. M03 Saisie du bon de caisse

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M03-01 | Type BD ou BP choisi en premier | ✅ | | | |
| RG-M03-02 | BP : 421100 ; mission → date de retour ; échéance affichée | ✅ | | | |
| RG-M03-03 | Bénéficiaire du référentiel | ✅ | | | |
| RG-M03-04 | BP au profit d'un salarié seulement ; visiteur ou tiers → BD **[v2.4]** | ✅ | Lot V : refus serveur (MSG-M03-02), BP non proposé à l'écran | | |
| RG-M03-05 à 10 | Motif, montant, mode, plafond espèces, frais OM, n° OM | ✅ | | | |
| RG-M03-11 | Entité et caisse pré-remplies et verrouillées ; site analytique selon RG-M03-27 **[v2.4]** | ✅ | Lots C et E1 | | |
| RG-M03-12 | Service et code analytique filtré | ✅ | | | |
| RG-M03-13 | OR : 8 chiffres en 110 ; type choisi dans le référentiel des types d'OR **[v2.4]** | ✅ | Format (lot D) ; types du référentiel (lot E3) | | |
| RG-M03-14 | Urgence, motif de la liste, SMS très urgent | ✅ | | | |
| RG-M03-15 | BD : facture ou proforma ; exception du BD issu d'un ODM, confirmée le 14/10 **[v2.4]** | ✅ | | | |
| RG-M03-16 | Formats, tailles, alerte sous 300 dpi | ✅ | Limites en dur (§3) | | G |
| RG-M03-17 | Visa DP au-delà de 1 500 000, affiché avant soumission | ✅ | Lot R (MSG-M03-11) | | |
| RG-M03-18 | Numéro par caisse et par année | ✅ | Lot C | | |
| RG-M03-19 | Brouillon supprimé après 30 jours | 🟡 | Annulé et tracé au lieu d'être supprimé (Q71) | C | G |
| RG-M03-20 à 26 | Doublon probable, BP en retard, bon d'ODM verrouillé, rejet et versions, pas d'auto-visa, journal, pièce déjà soumise | ✅ | Lots A, M12 | | |
| RG-M03-27 | Site analytique, distinct de la caisse ; site du demandeur par défaut, modifiable à la saisie ; corrigé par le CDG avec trace **[v2.4]** | ✅ | Lot E4 : choisi à l'étape 1, corrigé par le CDG (écran de visa et fiche), action « modification du site » au journal ; colonne de l'export comptable (lot E1) | | |
| RG-M03-28 | Bon visiteur : BD exceptionnel, motif d'une liste fermée (PO-30) et commentaire ; Finance réservée au DAF ; marqué « refacturation inter-filiale » avec la filiale ; reporting **[v2.4]** | ✅ | Lot V (liste des motifs attendue du DAF, PO-30 ; rapport par filiale au lot I) | | |

**User stories M03** : toutes couvertes.

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
| RG-M09-01 | Format Neemba « REPORTING CAISSE », par jour et par caisse | ✅ | Lot F2 : une feuille par jour et par caisse, PDF et Excel | | |
| RG-M09-02 | Ligne « Écart » de contrôle | ✅ | Lot F2 : solde initial / solde final de la veille, en rouge si non nul | | |
| RG-M09-03 | Clôture par le caissier ; jour clos bloqué | ✅ | Lot F2 : gérant, à défaut automatique à 23 h 45 (Q99) ; aucune écriture sur une journée close | C | |
| RG-M09-04 | Génération à 23 h 45, envoi avant 8 h, destinataires par caisse | ✅ | Lot F2 : envoi à 7 h ; destinataires de la caisse, à défaut gestionnaire + trésorerie ; compte de Mor CISS à créer | N (PO-23) | |
| RG-M09-05 | Onglet « Détail » : valideurs et délais par étape | ✅ | Lot F2 | | |

### 8.6 M10 Inventaire et contrôle de caisse

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M10-01 | Inventaires et contrôles inopinés | ✅ | Lot F2 : inventaire de fin de mois, contrôles par période (trimestrielle pour Conakry, Boké, Sangarédi), rappels et alertes au Chef comptable (Q101) | C | |
| RG-M10-02 | PV d'arrêté Annexe 2 | ✅ | Lot F0 : PV par caisse, billetage de 20 000 à 50 (solde lu pour la caisse OM), écart, trois signatures, PDF | | |
| RG-M10-03 | Écart → ajustement en attente du DAF | ✅ | Lot F0 : ajustement d'inventaire créé à la troisième signature, validé par le DAF ou son adjoint avec motif | | |

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
| OR à régulariser : relance J+3, échéance, retard **[v2.4]** | ✅ | ❌ | ❌ | Dans l'application (lot O) ; email au lot I | I |
| Bon au profit d'un visiteur du groupe (DAF) **[v2.4]** | ✅ | ❌ | ❌ | Email | I |
| OTP de connexion (email) et de visa urgent (SMS) **[v2.4]** | — | ✅ | ✅ | Fait (lot E2) ; SMTP et SMS de Neemba à brancher (PO-23) | |

SMTP et fournisseur SMS sont à confirmer par l'IT de Neemba (PO-23, cloud Neemba retenu le 08/10).

### 8.8 M14 Tableaux de bord et reporting

| Élément | État | Ce qui manque | Cause | Lot |
|---|:-:|---|:-:|:-:|
| Tableau de bord du DAF | 🟡 | Bons en attente par étape, BP en cours, alertes doublon, vue unique ; Chef comptable et DAF adjoint exclus | O | I |
| Export Excel opérationnel ; « Frais à refacturer » ; rapport mensuel des BP | ✅ | | | |
| Rapport « OR à régulariser » (RG-M12-41) **[v2.4]** | ✅ | Lot O : section du tableau de bord des ODM et onglet de l'export | | |
| Bons au profit de visiteurs, par filiale d'origine (RG-M03-28) **[v2.4]** | 🟡 | Colonnes de l'export faites (lot V) ; rapport par filiale au lot I | L | I |
| Tous les tableaux, rapports et exports filtrables par entité, avec vue consolidée **[v2.4]** | ✅ | Lot E1 : portée globale sur l'entité courante, vue consolidée | | |

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
| RG-M16-03 | Export : ajoute le **site analytique** et l'**entité** ; aucun format Helium **[v2.4]** | ✅ | Lot E1 (le site analytique devient modifiable au lot E4) | | |

---

## 9. Modules détaillés M08, M12, M13

### 9.1 M08 Caisses, réapprovisionnement et caisse Atelier

**Existant** (après le lot F1) :
- un registre par caisse ;
- la demande de réapprovisionnement et son circuit, l'encaissement client, la réconciliation Orange Money ;
- le PV d'arrêté par caisse (lot F0) ; l'arrêté par site reste jusqu'au lot F2.

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M08-01 | Solde par caisse ; Atelier distincte | ✅ | Solde réel de l'Atelier à saisir | N | |
| RG-M08-02 | Mouvements autorisés, pièce pour chaque encaissement | ✅ | Lot F1 : plus de retrait libre ni de mouvement manuel ; encaissement client, transfert, ajustement d'inventaire | | |
| RG-M08-03 | Alerte une fois + rappel quotidien ; caissier, Trésorerie, Chef comptable | ✅ | Lot F1a | | |
| RG-M08-04 | Plafond de caisse jamais dépassé | ✅ | Lot F1b (MSG-M08-02) | | |
| RG-M08-05 | Rapprochement signé et PV du jour ; écart affiché | ✅ | Lot F1b (MSG-M08-03 ; PV signé par le gérant, Q92) | C | |
| RG-M08-06 | Montant proposé, baisse motivée | ✅ | Lot F1b | | |
| RG-M08-07 | Circuit, habilitation du DP, CODIR en son absence | ✅ | Lot F1b (rôle « Membre du CODIR », Q89, Q91) | C | |
| RG-M08-08 | Origine des fonds, virement OM, transfert activable | ✅ | Lot F1b (transfert au registre, sans bon, Q93) | C | |
| RG-M08-09 | Crédit à la réception, écart au DAF | ✅ | Lot F1b (MSG-M08-04) | | |
| RG-M08-10 | Encaissement client à Conakry, reçu, blocage au plafond | ✅ | Lot F1a (MSG-M08-05, 06 ; chèque hors solde, Q98) | C | |
| RG-M08-11 | Carnet Atelier | ✅ | Lot C | | |
| RG-M08-12 | Caissier : sa caisse ; rôles transverses : toutes | ✅ | Lot F1a ; gérant sans rôle caissier au lot F1b (Q95) | | |
| RG-M08-13 | Rapprochement hebdomadaire de la caisse OM | ✅ | Lot F1c ; lecture d'un relevé réel à confirmer (Q96) | N | |

User stories : US-01 à US-10 faites (lot F1).

Messages : MSG-M08-01 à 06 au texte de la spec (lot F1).

### 9.2 M12 Ordres de mission

Le suivi détaillé reste dans `docs/ORDRE_DE_MISSIONS.md`. RG-M12-01, 03 à 13, 15 à 33 et 35 à 37 sont faites (devBook §26 à §40).

| Règle | Contenu | État | Ce qui manque | Cause | Lot |
|---|---|:-:|---|:-:|:-:|
| RG-M12-02 | Mission technique : au moins un OR, **ou ODM marqué « OR à régulariser » [v2.4]** | ✅ | Lot O | | |
| RG-M12-14 | **Un bon par participant ; bon groupé abandonné, paramètre à retirer [v2.4]** | ✅ | Lot V : paramètre et option retirés ; les bons groupés déjà émis restent valides | | |
| RG-M12-24 | Liste de diffusion du service | ✅ | Listes réelles à saisir | N | |
| RG-M12-34 | Répartition consultable par la Comptabilité | ✅ | Lot R : rôle `comptable` dans la vue globale des ODM | | |
| RG-M12-38 | **Aucune saisie hors barème ; hôtel au forfait de 500 000 [v2.4]** | ✅ | Lot V : bon complémentaire de la clôture verrouillé, créé avec sa facture et soumis aussitôt ; facture d'hébergement ignorée hors ODM extérieur payé avant le départ | | |
| RG-M12-39 | **« OR à régulariser » : soumission sans OR, OR saisi après coup et tracé, l'indicateur tombe [v2.4]** | ✅ | Lot O : case au formulaire, « Rattacher un OR » sur la fiche (demandeur, chef d'atelier), report sur les bons (Q80) | | |
| RG-M12-40 | **Délai de 5 jours ouvrés, relances J+3 et échéance, alerte au chef d'atelier et au DAF, blocage optionnel [v2.4]** | ✅ | Lot O : paramètres `odm_delai_or` et `odm_blocage_or_retard`, commande `odm:relancer-or` | | |
| RG-M12-41 | **Suivi au tableau de bord DAF et rapport M14 [v2.4]** | ✅ | Lot O | | |
| RG-M12-42 | **Besoin carburant sur l'ODM (véhicule, immatriculation, itinéraire) ; jamais une ligne de frais [v2.4]** | 🟡 | Immatriculation et itinéraire faits (lot O) ; obligatoires dès une avance carburant, bon carburant rattaché : lot H | | H |

**Messages M12**

| Message | État | Écart | Lot |
|---|:-:|---|:-:|
| MSG-M12-02 à 09 | ✅ | — | |
| MSG-M12-01 **[v2.4]** | ✅ | (lot O) | |
| MSG-M12-10, 11 **[v2.4]** | ✅ | (lot O) | |

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
| PO-12 | Neemba Mining | Neemba Guinée seule active (lot E1) | ✅ | Neemba |
| PO-13 | Codes analytiques, Atelier, CORICA | 22 radicaux importés | 🟡 | CDG |
| PO-14 | Codes de service numériques | Champ vide | 🟡 (G) | Neemba |
| PO-15 | Utilisateurs et signataires habilités | Import partiel | 🟡 (G) | Neemba, RH |
| PO-16 | Reprise des données | Import contrôlé | ❌ (P3) | Neemba, avant S5 |
| PO-18 | Destinataires du rapport hors Conakry | Gestionnaire + Trésorerie | ✅ (F2) | Neemba |
| PO-23 | Infrastructure **[v2.4 : cloud Neemba retenu le 08/10]** | — | — | IT Neemba |
| PO-26 | Référentiel clients (export Helium ou fichier) | Import prêt | 🟡 | Neemba |
| PO-28 **[v2.4]** | Liste complète des types d'OR | VENTE, GARANTIE | Référentiel prêt (lot E3) ; liste attendue | Neemba |
| PO-29 **[v2.4]** | Propositions du 14/10 (OR à régulariser, carburant, visiteurs, site analytique, gestionnaire des référentiels) | Valeurs de la spec, en paramètres | ❌ (V, E3, E4, O) | Neemba, avant S6 |
| PO-30 **[v2.4]** | Motifs du bon visiteur | Liste vide, paramétrable (onglet « Motifs bon visiteur ») + commentaire | ✅ | DAF Neemba |
| PO-31 **[v2.4]** | Date des environnements cloud | — | — | IT Neemba |

---

## 11. Plan de mise en œuvre et suivi

Ordre validé le 16/10 : on corrige d'abord l'existant contredit par la v2.4, puis on fait le bloc E de la spec, puis la suite du plan v2.3.

**R → V → E1 → E2 → E3 → E4 → O → F0 → F1 → F2 → G → I → H → P3** (environ 33 jours, échéance de la spec au 16/12/2026). F0 (billetage et PV d'arrêté par caisse) est sorti de F2 le 19/10 : la demande de réapprovisionnement de F1 s'appuie dessus (Q88).

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

### Lot E1 — Entité transverse (≈ 2,5 j) ✅

- [x] Table `entites` (Neemba Guinée active, Neemba Mining inactive) ; `entite_id` sur les utilisateurs, caisses, bons, ODM, référentiels et paramètres
- [x] Droits par entité × rôle (`roles_utilisateurs.entite_id`) ; entité courante en session ; vue consolidée pour les rôles du paramètre
- [x] Filtre par entité :
  - [x] listes, tableaux de bord, rapports, exports, imputation ;
  - [x] circuit des valideurs, visibilité.
- [x] Numérotation des bons et des ODM par entité ; gabarit PDF par entité ; entité affichée et verrouillée à la saisie
- [x] Tests SC-41 (isolation, consolidé)

### Lot E2 — Double authentification (≈ 1,5 j)

- [x] Table `codes_otp` (connexion, visa de bon, visa d'ODM)
- [x] Connexion en deux temps :
  - [x] code par email ;
  - [x] durée et nombre d'essais paramétrables ;
  - [x] paramètre de secours `double_authentification_active`, sous double validation.
- [x] Visa d'une demande urgente ou très urgente : code par SMS au valideur (bons et ODM) ; niveau d'urgence ajouté à l'ODM (Q76)
- [x] Tests SC-40 ; test de connexion réécrit

### Lot E3 — Gestionnaire des référentiels, barèmes datés, types d'OR (≈ 2 j)

- [x] Rôle `gestionnaire_referentiels` :
  - [x] routes et onglets répartis (sites et caisses restent à l'administrateur, Q78) ;
  - [x] l'administrateur ne tient plus les référentiels métier.
- [x] Barèmes ODM :
  - [x] table `baremes_odm` datée, avec historique ;
  - [x] reprise des paramètres ;
  - [x] lecture à la date de soumission ;
  - [x] hors double validation (RG-M02-10, RG-M02-08).
- [x] Types d'OR :
  - [x] table `types_or` (VENTE, GARANTIE) ;
  - [x] enum de l'ODM remplacé ;
  - [x] listes servies par le serveur ;
  - [x] valeurs en dur retirées (RG-M03-13, PO-28).

### Lot E4 — Caisses, sites, site analytique (≈ 2 j)

- [x] Liaison `caisse_site` ; caisse OM sans site ; écran des caisses multi-sites (RG-M02-11)
- [x] Caisse payeuse indépendante du site du bon ; plafond de retrait lu sur la caisse ; corrections des plantages sur une caisse sans site
- [x] Site analytique (RG-M03-27) :
  - [x] modifiable à la saisie ;
  - [x] corrigé par le CDG avec trace ;
  - [x] colonnes site analytique et entité dans l'export comptable (RG-M16-03).
- [x] Tests SC-33

### Lot O — ODM : OR à régulariser, besoin carburant (≈ 2 j)

- [x] Case « OR à régulariser » ; MSG-M12-01 nouveau texte
- [x] Échéance en jours ouvrés après la validation ; ajout d'OR après coup, tracé et reporté sur les bons (Q80)
- [x] Commande `odm:relancer-or` :
  - [x] relance à J+3 et à l'échéance (MSG-M12-10) ;
  - [x] alerte au chef d'atelier et au DAF.
- [x] Blocage optionnel du prochain ODM (MSG-M12-11)
- [x] Section « OR à régulariser » au tableau de bord et à l'export (RG-M12-41)
- [x] Immatriculation et itinéraire sur l'ODM, la fiche et le PDF (RG-M12-42)
- [x] Tests SC-38

### Lot F0 — Billetage et PV d'arrêté par caisse (M10, sorti de F2) (≈ 1 j)

- [x] PV d'arrêté par caisse (Annexe 2) : date, heure, implantation, billetage de 20 000 à 50, solde physique, solde comptable, écart
- [x] Trois signatures (gérant de caisse, trésorerie, Chef comptable), par trois personnes différentes ; impression PDF
- [x] Écart → ajustement d'inventaire « En attente », validé par le DAF avec motif (RG-M10-03)
- [x] Tests ; parcours avec les comptes réels

### Lot F1 — Caisses, réapprovisionnement, encaissement client (M08) (≈ 4 j) ✅

- [x] Rôle « Membre du CODIR » : en l'absence du DP et du DP adjoint, deux membres approuvent ensemble (Q89)

- [x] Types de mouvement :
  - [x] retrait libre retiré ;
  - [x] ajout de l'encaissement client, du transfert (activable par caisse) et de l'ajustement d'inventaire ;
  - [x] pièce obligatoire pour chaque encaissement.
- [x] Demande de réapprovisionnement :
  - [x] montant proposé (plafond − solde ; Atelier : avance fixe − solde), baisse motivée ;
  - [x] rapprochement signé et PV du jour (MSG-M08-03) ;
  - [x] plafond de caisse respecté (MSG-M08-02).
- [x] Circuit caissier → Trésorerie → Chef comptable / DAF → DP ; habilitation du DP à 500 000 000 ; DP absent : deux membres du CODIR
- [x] Exécution par la Trésorerie avec l'origine des fonds ; confirmation de réception ; écart signalé au DAF (MSG-M08-04)
- [x] Encaissement client à Conakry principale, reçu PDF, blocage au plafond (MSG-M08-05, 06)
- [x] Alerte de seuil une fois, puis rappel quotidien ; destinataires caissier, Trésorerie, Chef comptable (MSG-M08-01)
- [x] Visibilité par caisse ; rôles transverses voient toutes les caisses
- [x] Rapprochement hebdomadaire de la caisse OM, avec trois visas
- [x] Notifications de la demande et de l'écart

### Lot F2 — Clôture, rapport journalier, inventaire (M09, M10) (≈ 3 j) ✅

- [x] Clôture par le caissier, par caisse ; écriture impossible sur une journée close
- [x] Rapport « REPORTING CAISSE » :
  - [x] une feuille par jour et par caisse ;
  - [x] bloc contrôle, ligne « Écart » en rouge ;
  - [x] onglet « Détail ».
- [x] Génération à 23 h 45 ; envoi avant 8 h aux destinataires de chaque caisse
- [x] PV d'arrêté Annexe 2 par caisse, avec trois signatures (lot F0)
- [x] Inventaires obligatoires, contrôles inopinés paramétrables ; écart → ajustement en attente du DAF

### Lot G — Gouvernance (reste après le bloc E) (≈ 3 j)

- [ ] Domaine @neemba.com (Q70)
- [x] Rôle « assistant trésorier » (Q75), fait au lot T
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
| SC-15 à 17, SC-35 | Encaissement client, réapprovisionnement, Atelier, rapprochement OM | ✅ | (lot F1) |
| SC-18, SC-19 | Clôture, rapport, inventaire | ✅ | (lot F2) |
| SC-20 à SC-22, SC-24 à SC-29, SC-37 | ODM | ✅ | |
| SC-23 | Génération des bons, un par participant | ✅ | (lot V) |
| SC-30 à SC-32 | Carburant | ❌ | H |
| SC-33 | Isolation des caisses et sites | ✅ | (lots E4 et F1) |
| SC-34 | Imputation et export comptable | ✅ | (colonnes v2.4 : lot E1) |
| SC-36 | Hors ligne | ❌ | P3 |
| SC-38 **[v2.4]** | ODM technique « OR à régulariser » | ✅ | (lot O) |
| SC-39 **[v2.4]** | Bon exceptionnel au profit d'un visiteur | ✅ | (lot V ; rapport par filiale au lot I) |
| SC-40 **[v2.4]** | Double authentification, visa urgent par SMS | 🟡 | Connexion : lot E2 ; visa urgent sans code depuis Q104 (scénario à revoir avec Thierno) |
| SC-41 **[v2.4]** | Isolation par entité et vue consolidée | ✅ | (lot E1) |

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
| Q75 | Assistant trésorier : rôle à part ? | Oui, avec les droits du caissier (appliqué, lot T) | T |
| Q76 **[v2.4]** | Urgence sur l'ODM, pour l'OTP de visa ? | Oui : mêmes niveaux et motifs que le bon (appliqué, lot E2) | E2 |
| Q77 **[v2.4]** | L'OTP de visa porte-t-il sur le rejet ? | Sans objet depuis Q104 (code de visa retiré) | E2 |
| Q104 **[v2.4]** | Faut-il un code SMS pour viser une demande urgente ? | Non (décision de l'utilisateur du 20/10) : le valideur est déjà authentifié à la connexion ; alerte SMS aux valideurs des ODM urgents à la place. Écart à RG-M01-07 à valider | — |
| Q82 **[v2.4]** | Qui active ou désactive la double authentification, avec quel circuit ? | L'administrateur, effet immédiat sans second validateur, tracé, administrateurs prévenus ; commande de secours sur le serveur (appliqué, lot E2) | E2 |
| Q78 **[v2.4]** | Qui tient les sites et les caisses ? | L'administrateur, avec double validation ; le gestionnaire des référentiels tient le reste (appliqué, lot E3) | E3 |
| Q83 **[v2.4]** | Qui tient les clients ? | Le gestionnaire des référentiels, avec la Comptabilité, le Chef comptable et le CDG (import du fichier) ; plus l'administrateur (appliqué, lot E3) | E3 |
| Q79 **[v2.4]** | Bon visiteur sans DAF ni DAF adjoint disponible | Le bon attend ; la Finance n'est jamais sautée (appliqué, lot V) | V |
| Q80 **[v2.4]** | OR saisi après coup | Reporté sur les bons de l'ODM, avec trace (appliqué, lot O) | O |
| Q81 **[v2.4]** | Qui a la vue consolidée ? | Paramètre ; par défaut DAF, DAF adjoint, DP, DP adjoint, administrateur (appliqué, lot E1) | E1 |
| Q89, Q91 **[v2.4]** | Membres du CODIR ; quand le DP est-il « absent » ? | Rôle « Membre du CODIR » ; deux membres quand aucun DP ni DP adjoint actif, ou délai de l'étape DP écoulé (appliqué, lot F1) | F1 |
| Q92 **[v2.4]** | PV du jour exigé pour la demande : signé par qui ? | Par le gérant de la caisse ; les deux autres signatures peuvent suivre (appliqué, lot F1) | F1 |
| Q93 **[v2.4]** | Transfert depuis une autre caisse « par bon de caisse » | Écriture au registre des deux caisses, sans bon (appliqué, lot F1) | F1 |
| Q96, Q97 **[v2.4]** | Format du relevé OM ; solde du relevé | En-tête reconnu (date, montant ou débit / crédit) ; solde lu ou saisi, obligatoire (appliqué, lot F1) | F1 |
| Q99 **[v2.4]** | Journée non clôturée par le gérant | Clôture automatique à 23 h 45, jamais avant la mise en service (appliqué, lot F2) | F2 |
| Q100 **[v2.4]** | Rapport d'une journée sans opération | Clôturée, téléchargeable, pas envoyée (appliqué, lot F2) | F2 |
| Q101 **[v2.4]** | Qu'est-ce qu'un inventaire de fin de mois, un contrôle inopiné ? | Un PV d'arrêté du motif correspondant dans la période ; contrôle par la trésorerie ou le Chef comptable, rappel au seul Chef comptable (appliqué, lot F2) | F2 |

**À obtenir de Neemba** :
- valeurs des caisses et solde de l'Atelier ;
- signataires habilités (PO-15), codes de service (PO-14) ;
- destinataires du rapport de chaque caisse (PO-18 : défaut appliqué), compte de Mor CISS (DAF adjoint), listes de diffusion des ODM ;
- un relevé Orange Money exporté de la plateforme, pour confirmer la lecture de l'import (Q96) ;
- les membres du CODIR (Q89) ;
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
| 16/10/2026 | E1 | (ce commit) | Entité transverse : table `entites` (Neemba Guinée active, Neemba Mining inactive), `entite_id` sur comptes, caisses, bons, ODM, référentiels, paramètres ; droits par entité × rôle ; portée globale sur l'entité courante ; vue consolidée (paramètre) ; sélecteur d'entité ; valideurs de l'entité du bon ou de l'ODM ; numérotation des ODM par entité ; gabarit PDF ; entité affichée à la saisie ; colonnes entité et site analytique dans les exports. 368 tests PHP, 74 JS ; parcours Edge (15 vérifications). 165 règles : 102 faites, 36 partielles, 27 à faire. devBook §50 |
| 19/10/2026 | E2 | (ce commit) | Double authentification : connexion en deux temps (mot de passe, puis code par email ; durée, essais, renvoi après une minute ; compte désactivé refusé), code de visa par SMS pour approuver un bon ou un ODM urgent (lié au valideur et à la demande ; rejet sans code), paramètres de secours, codes stockés en empreinte. Urgence et motif sur l'ODM (Q76). 382 tests PHP, 74 JS ; parcours Edge (25 vérifications). 165 règles : 103 faites, 36 partielles, 26 à faire. devBook §51 |
| 19/10/2026 | E2 | (ce commit) | Onglet « Sécurité » (demande de l'utilisateur, Q82) : l'administrateur active ou désactive le code de connexion et le code de visa, règle durées et essais ; effet immédiat, tracé, autres administrateurs prévenus ; état des canaux email et SMS, valideurs sans téléphone ; commande de secours `securite:double-authentification`. 389 tests PHP ; parcours Edge (16 vérifications). devBook §51.7 |
| 19/10/2026 | E3 | (ce commit) | Rôle « gestionnaire des référentiels » : onglets et routes répartis (l'administrateur garde entités, sites, caisses, seuils, sécurité ; le gestionnaire tient services, codes analytiques, types de document, motifs, types d'OR, barèmes, clients). Barèmes ODM datés (`baremes_odm`, reprise des paramètres, lecture à la date de soumission, sans double validation, valeur à venir annulable). Types d'OR en référentiel (`types_or`, enum de l'ODM retiré, listes servies par le serveur). 397 tests PHP, 74 JS ; parcours Edge (19 vérifications). 165 règles : 106 faites, 35 partielles, 24 à faire. devBook §52 |
| 19/10/2026 | E4 | (ce commit) | Caisses et sites : table `caisse_site` (reprise de `caisses.site_id`, retiré), caisse OM sans site, sites servis à l'écran des caisses (effet immédiat, Q86), écrans sans plantage pour une caisse sans site. Caisse payeuse tirée de la caisse émettrice (à défaut, site du demandeur), plafond de retrait de cette caisse ; replis par site via les sites servis. Site analytique modifiable à la saisie, corrigé par le CDG avec trace. 402 tests PHP, 74 JS ; parcours Edge (16 vérifications). 165 règles : 108 faites, 34 partielles, 23 à faire. devBook §53 |
| 19/10/2026 | O | (ce commit) | « OR à régulariser » : case au formulaire, soumission sans OR, échéance à la validation (5 jours ouvrés), OR rattaché après coup sur la fiche par le demandeur ou le chef d'atelier, tracé et reporté sur les bons (Q80), relances J+3 et à l'échéance, alerte de retard au chef d'atelier et au DAF (`odm:relancer-or`), blocage optionnel du prochain ODM, suivi au tableau de bord et à l'export. Immatriculation et itinéraire sur l'ODM. Défaut corrigé : l'urgence de l'ODM choisie au formulaire n'était pas enregistrée (lot E2). 408 tests PHP, 75 JS ; parcours Edge (14 vérifications). 165 règles : 112 faites, 33 partielles, 20 à faire. devBook §54 |
| 19/10/2026 | F0 | (ce commit) | Décision : billetage et PV d'abord (Q88), rôle « Membre du CODIR » pour F1 (Q89). PV d'arrêté par caisse (Annexe 2) : billetage de 20 000 à 50 ou solde lu (OM), solde comptable, écart, trois signatures par trois personnes, PDF ; écart → ajustement d'inventaire validé par le DAF avec motif (boîte de validation avec motif). Le gérant est le caissier rattaché, le gestionnaire ou le suppléant — pas tout compte rattaché à la caisse. 412 tests PHP (+4 F0), 75 JS ; parcours Edge (14 vérifications). 165 règles : 114 faites, 32 partielles, 19 à faire. devBook §55 |
| 19/10/2026 | F1 | (ce commit) | F1a : alerte de seuil au franchissement puis rappel quotidien (gérant, trésorerie, Chef comptable), encaissement client à Conakry avec reçu PDF et blocage au plafond, visibilité par caisse, plus de retrait libre. F1b : demande de réapprovisionnement (PV du jour signé, rapprochement, montant proposé, plafond, habilitation du DP), circuit trésorerie → Finance → DP ou deux membres du CODIR (Q89, Q91), exécution avec l'origine des fonds et transfert activable, crédit à la réception, écart au DAF ; plus de mouvement manuel ; gérant sans rôle caissier. F1c : réconciliation hebdomadaire OM (import du relevé, rapprochement automatique, écart, trois validations, rappel du lundi). 426 tests PHP, 75 JS ; parcours Edge (15 + 24 + 14 vérifications). 165 règles : 125 faites, 29 partielles, 11 à faire. devBook §56 |
| 20/10/2026 | F2 | (ce commit) | Clôture journalière par caisse (gérant, à défaut automatique à 23 h 45, jamais avant la mise en service), journée close verrouillée dans le registre ; rapport « REPORTING CAISSE » par caisse et par jour (contrôle avec la veille, écart en rouge, synthèse, encaissements, décaissements, onglet « Détail » avec valideurs et délais), PDF et Excel, envoi à 7 h aux destinataires de la caisse (défaut PO-18) ; inventaire de fin de mois et contrôles inopinés par période (trimestrielle pour Conakry, Boké, Sangarédi), rappels et alertes au Chef comptable, contrôle interdit au gérant, suivi réservé aux rôles transverses. 435 tests PHP, 75 JS ; parcours Edge (21 vérifications). 165 règles : 131 faites, 25 partielles, 9 à faire. devBook §57 |
| 20/10/2026 | T | (ce commit) | Demande de l'utilisateur : la trésorerie était invisible. Trésorerie, Chef comptable, DAF adjoint, DP adjoint, Comptabilité, Assistant trésorier… peuvent être rôle principal (Q103) ; reprise des comptes « Demandeur » ayant un rôle métier ; tous les rôles affichés (liste, bandeau, profil) et filtrés ; changer de rôle principal ne fait plus perdre de rôle ; délégation limitée aux rôles délégables ; contrôles « rôle principal seul » passés à tous les rôles ; bloc « À traiter » au tableau de bord. 443 tests PHP, 75 JS ; parcours Edge (16 vérifications). 165 règles : 132 faites, 24 partielles, 9 à faire. devBook §58 |
| 20/10/2026 | — | (ce commit) | Décision de l'utilisateur (Q104) : plus de code SMS pour viser une demande urgente (écart à RG-M01-07, à valider par Thierno) ; un ODM urgent ou très urgent prévient ses valideurs par SMS à chaque étape. 439 tests PHP, 75 JS ; parcours Edge (9 vérifications). 165 règles : 131 faites, 25 partielles, 9 à faire. devBook §59 |
| 22/10/2026 | — | (ce commit) | Signalement de l'utilisateur : demandes de rôles en attente de double validation signalées (notification aux autres administrateurs, compteur, « À traiter », fiche ; Q107) ; justifications de BP à viser listées dans « Validations » et « À traiter » (Comptabilité et Chef comptable, pas le DAF, Q106). 444 tests PHP, 75 JS ; parcours Edge (11 vérifications). devBook §60 |
| 22/10/2026 | — | (ce commit) | Signalement de l'utilisateur : 12 comptes importés sans le mot de passe initial, remis ; mot de passe initial à changer à la première connexion (écran imposé, interrupteur dans Sécurité, commande `utilisateurs:mot-de-passe-initial`, Q108). 448 tests PHP, 75 JS ; parcours Edge (7 vérifications). devBook §61 |
| 22/10/2026 | — | (ce commit) | Demande de l'utilisateur : liste des PV d'arrêté avec actions par ligne (Signer avec confirmation, Voir, Imprimer) et signatures manquantes. 448 tests PHP ; parcours Edge (10 vérifications). devBook §62 |
| 22/10/2026 | — | (ce commit) | Revue M08-M10 à la demande de l'utilisateur : montant émis plafonné (montant approuvé, plafond), caisse OM par virement seulement, alerte de remise en banque à la réception. 449 tests PHP ; parcours Edge complet du réapprovisionnement. devBook §63 |
| 22/10/2026 | — | (ce commit) | Demande de l'utilisateur : actions par ligne sur les listes des réapprovisionnements (visas et refus avec confirmation, exécution et réception sur la fiche) et des réconciliations OM (Valider, Voir, Relevé). 449 tests PHP ; parcours Edge. devBook §64 |
| 22/10/2026 | — | (ce commit) | Revue de la prolongation et de la clôture des ODM : BP recalé sur la prolongation et le retour réel, retour réel au plus tard aujourd'hui, clôture de toute la chaîne, message pour un retour antérieur à la prolongation (Q109 ouverte). 451 tests PHP ; parcours Edge. devBook §65 |
| 23/10/2026 | — | (ce commit) | Prolongation : analyse de la spec (« renouvellement » absent) ; reconduction à 30 jours par segment (Q110, ajout hors spec, à confirmer), OR à régulariser repris (Q111), retour réel dans un segment antérieur (Q109, défaut). 455 tests PHP, 76 JS ; parcours Edge. devBook §66 |
