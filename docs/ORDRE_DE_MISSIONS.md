# Module M12 — Ordres de mission (ODM) : comprendre et suivre

> **Sources** :
> - `docs/Spec_Fonctionnelle_v2.2_Neemba_P2.docx` (Thierno DIALLO, 07/10/2026) : §7.3 M12, §6.6 barèmes, §9 points ouverts, §10 planning, annexes A et B ;
> - comité de suivi du 06/10/2026.
>
> **Branche** : `m12-odm`. Fichier mis à jour à chaque lot : cases à cocher et journal en fin de document.

---

## 1. À quoi sert le module

Quand un salarié de Neemba part en mission (dépannage d'une machine chez un client à Kouroussa, intervention sur une mine à Siguiri, déplacement à l'étranger…), on établit aujourd'hui un **ordre de mission** sur papier :

- la **fiche d'indemnités** calcule ce que Neemba verse au salarié ;
- l'**ordre de mission** autorise le déplacement.

Exemples reçus : fiches N°282 et N°285/AT/26. Quand la mission dure plus longtemps que prévu, on la prolonge par échange d'e-mails (fil « Neemba Service_Mission »).

Le module M12 remplace ce circuit papier et ces e-mails :

1. le demandeur saisit l'ODM dans la plateforme : les participants, où, pourquoi, quand ;
2. la plateforme **calcule les indemnités** de chaque participant, sans erreur possible ;
3. l'ODM est **visé** par le chef d'atelier ou chef d'équipe, puis le DAF, puis le Directeur Pays ;
4. une fois l'ODM validé, la plateforme **génère les bons de caisse** qui servent à payer les participants ;
5. chaque prolongation, retour anticipé ou clôture est un événement tracé et notifié.

**Le principe à retenir** : l'ODM est créé et validé **d'abord**. Les bons de caisse sont **ensuite** générés à partir de l'ODM validé. Chacun suit alors son propre circuit complet, comme n'importe quel bon : chef de service → CDG → Finance → Directeur Pays au-delà de 1,5 M → caissier.

```
 ODM : autoriser la mission et calculer          Bons de caisse : payer
 ───────────────────────────────────────         ────────────────────────────────────────────
 Saisie → Chef d'atelier → DAF → DP → Validé ─┬─► BD participant 1 → chef serv. → CDG → Finance → (DP) → caisse
                                              ├─► BD participant 2 → …
                                              └─► BP « frais réels » (facultatif) → … → régularisation au retour
```

---

## 2. Vocabulaire

| Terme | Signification |
|-------|---------------|
| **ODM** | Ordre de mission. Il porte un numéro du type **N°285/AT/26** : séquence 285, préfixe du service AT, année 2026 |
| **ODM intérieur / extérieur** | Mission en Guinée (indemnités en GNF) / à l'étranger (indemnités en FCFA, converties en GNF au taux du jour) |
| **Nature technique / non technique** | Une mission technique (dépannage, intervention) doit être rattachée à au moins un **OR** |
| **OR** | Ordre de réparation de l'atelier : 8 chiffres commençant par 110 (ex. 11022219), de type VENTE (facturé au client) ou GARANTIE |
| **Participant** | Salarié qui part en mission. Un ODM en compte de 1 à 10 ; chacun a son propre calcul |
| **Statut cadre / non-cadre** | Renseigné par les RH. Indispensable pour un ODM extérieur, dont le barème dépend du statut |
| **Base vie** | Logement fourni sur le site, par exemple une mine. Un participant logé sur base vie n'a pas d'indemnité d'hébergement |
| **Segment** | Une période de mission. L'ODM initial est le segment 1 ; chaque prolongation crée le segment suivant, lié au précédent |
| **Mission** | La chaîne complète des segments : l'ODM initial et toutes ses prolongations |
| **Nuitée de rattrapage** | Nuit d'hébergement ajoutée lors d'une prolongation pour compenser la nuit non payée du segment précédent (section 4.3) |
| **Prise en charge client** | Mission dont les frais sont refacturés au client. L'ODM est marqué « à refacturer », avec ses OR |
| **Taux du jour** | Taux FCFA → GNF saisi chaque jour par la Trésorerie, d'après le taux communiqué par la banque |
| **Frais Orange Money (OM)** | Frais d'envoi par OM, calculés par paliers sur le total versé : 1 % de 100 001 à 5 000 000 GNF, 0,8 % de 5 000 001 à 15 000 000 GNF. Ils sont **ajoutés** au montant versé |
| **BD / BP** | Bon de caisse définitif (dépense justifiée) / provisoire (avance à régulariser après la mission) |

---

## 3. Le parcours de bout en bout

### 3.1 Statuts d'un ODM (RG-M12-21)

```
            ┌──────────── rejet (motif obligatoire) ────────────┐
            ▼                                                    │
 Brouillon ──soumission──► En validation ──► chef d'atelier ──► DAF ──► DP ──► Validé ──génération──► Bons générés ──► Payé ──retour──► Clôturé
     │                                                                            │
     └─── annulation (demandeur, tant qu'aucun bon n'est généré) ───► Annulé ◄─── (DAF ensuite, si aucun bon payé)
```

| Étape | Qui | Ce qui se passe |
|-------|-----|-----------------|
| **Brouillon** | Demandeur | Saisie libre, enregistrable à tout moment. Pas encore de numéro |
| **Soumission** | Demandeur | Contrôles bloquants : champs obligatoires, dates, OR d'une mission technique, statut cadre pour l'extérieur, **chevauchement**. Le numéro N°…/…/AA est attribué. La liste de diffusion du service est notifiée |
| **Visa chef d'atelier / chef d'équipe** | Chef de l'équipe qui part (ou son suppléant désigné) | Vise ou rejette avec motif |
| **Visa DAF** | DAF, DAF adjoint ou chef comptable | Idem |
| **Visa DP** | Directeur Pays (ou DP adjoint) | Idem. **Tous** les ODM passent par le DP. Pas de CDG ni de RH sur l'ODM |
| **Validé** | — | **Le calcul est figé** : barèmes, paramètres et taux enregistrés avec l'ODM (RG-M12-25). Message « ODM [n°] validé. Vous pouvez générer le ou les bons de caisse. » |
| **Bons générés** | Demandeur | Un BD par participant (par défaut), et un BP facultatif pour les frais réels. Chaque bon part dans son circuit |
| **Payé** | Caissier | Tous les bons de l'ODM sont payés |
| **Prolongation** | Demandeur | Depuis un ODM validé : nouveau segment, même circuit |
| **Clôturé** | Demandeur | Date de retour réelle saisie. Retour anticipé : trop-perçu à reverser ou à retenir sur salaire |

### 3.2 Les contrôles importants

- **Chevauchement** (RG-M12-16) : un participant ne peut pas figurer sur deux ODM dont les périodes se chevauchent, pour éviter qu'il soit payé deux fois. La soumission est bloquée. Le DAF peut accorder une **dérogation motivée**, qui est tracée.
- **Mission technique** (RG-M12-02) : au moins un OR, sinon blocage.
- **ODM extérieur** (RG-M02-04) : chaque participant doit avoir son statut cadre / non-cadre, sinon blocage (« contactez les RH »).
- **Départ dans le passé** (RG-M12-06) : admis pour régulariser une mission, avec un motif obligatoire.
- **Paiement d'un ODM extérieur** (RG-M12-10) : sans taux du jour saisi par la Trésorerie, le paiement est bloqué.

---

## 4. Les calculs, pas à pas

### 4.1 Règles de calcul (RG-M12-07)

| Élément | ODM intérieur | ODM extérieur |
|---------|---------------|---------------|
| **Jours** | retour − départ **+ 1** : le jour du départ et celui du retour comptent | Idem |
| **Nuits** (segment initial) | jours **− 1** | Sans objet si l'hébergement est pris en charge par la filiale d'accueil |
| **Nuits** (prolongation) | jours du segment − 1 **+ 1 nuitée de rattrapage** = jours du segment | Idem |
| **Indemnité** | jours × **250 000 GNF**, affichée en 2 lignes de 125 000 (« Indemnité de repas », « Indemnité de déplacement ») | jours × **22 000 FCFA** (non-cadre) ou **34 000 FCFA** (cadre) × taux du jour → GNF |
| **Hébergement** | nuits × **500 000 GNF** ; **0** si logé sur base vie | Selon le mode : pris en charge par la filiale (0) ; facture payée avant le départ ; facture payée au retour (justificatif) |
| **Frais OM** | Calculés sur chaque bon au paiement si le caissier retient OM ; affichés pour information sur la fiche | Idem |

Toutes ces valeurs sont des **paramètres** modifiables dans la plateforme, jamais écrites en dur. Un ODM est calculé avec les valeurs en vigueur à sa soumission, puis figé à sa validation.

### 4.2 Exemple B.1 — une personne, Conakry → Kouroussa, du 22/09 au 26/09

| Poste | Calcul | Montant (GNF) |
|-------|--------|--------------:|
| Jours | 26/09 − 22/09 + 1 | 5 |
| Nuits | 5 − 1 | 4 |
| Indemnité (2 × 125 000) | 5 × 250 000 | 1 250 000 |
| Hébergement | 4 × 500 000 | 2 000 000 |
| **Total** | | **3 250 000** |
| Frais OM, si le caissier paie par OM | 1 % (palier 100 001 – 5 000 000) | 32 500 |
| **Montant versé** | | **3 282 500** |

La dépense (3 250 000 GNF, hors frais) dépasse 1 500 000 GNF : le **bon** généré aura aussi le visa du DP, en plus du visa DP déjà donné sur l'ODM.

### 4.3 Exemple B.2 — deux participants, même mission

| Mode de génération des bons | Calcul | Montant versé |
|-----------------------------|--------|--------------:|
| **Un bon par participant** (défaut) | 2 × (3 250 000 + 32 500) | 6 565 000 |
| Un bon groupé, versé à un seul participant | 6 500 000 + 0,8 % (palier 5 M – 15 M) = 6 500 000 + 52 000 | 6 552 000 |

Le bon groupé économise 13 000 GNF de frais, mais verse tout l'argent sur le compte d'une seule personne : c'est un risque d'audit. Le DAF doit trancher (PO-03) ; en attendant, c'est **un bon par participant**.

### 4.4 Exemple B.3 — prolongation et nuitée de rattrapage

Segment 1 : du 22/09 au 26/09 (exemple B.1), soit 5 jours et 4 nuits payées. Prolongation : du 27/09 au 03/10.

| Poste | Calcul | Montant (GNF) |
|-------|--------|--------------:|
| Jours | 03/10 − 27/09 + 1 | 7 |
| Nuits du segment | 7 − 1 | 6 |
| **Nuitée de rattrapage — segment 1** | + 1 | 1 |
| Indemnité | 7 × 250 000 | 1 750 000 |
| Hébergement | 6 × 500 000 | 3 000 000 |
| Nuitée de rattrapage | 1 × 500 000 | 500 000 |
| **Total** | | **5 250 000** |
| Frais OM | 0,8 % (palier 5 M – 15 M) | 42 000 |
| **Montant versé** | | **5 292 000** |

**Pourquoi une nuitée de rattrapage ?** Sur toute la mission, nuits = jours − 1 : le dernier jour, on rentre, pas de nuit d'hôtel. Quand le segment 1 a été émis, il était le dernier : il a compté 4 nuits pour 5 jours. Avec la prolongation, il devient un segment intermédiaire. Le soir du 26/09, le salarié dort sur place, alors que cette nuit n'avait pas été payée. La prolongation la paie, sur une ligne distincte.

**Contrôle de la mission** : 5 + 7 = 12 jours ; 4 + 7 = 11 nuits = 12 − 1. ✔

### 4.5 Exemple B.4 — cas réel, mission KOUROUMA (29/07 → 25/09/2026)

| Segment | Période | Jours | Nuits payées | Nuits selon la règle | Écart |
|---------|---------|------:|-------------:|---------------------:|------:|
| 1 | à partir du 29/07 | 9 | 8 | 8 | — |
| 2 | 07/08 – 20/08 | 14 | 14 | 14 | — |
| 3 | 21/08 – 26/08 | 6 | 5 → corrigé 6 | 6 | — |
| 4 | 27/08 – 20/09 | 25 | 25 | 25 | — |
| 5 | 21/09 – 25/09 | 5 | 4 | 5 | − 1 |
| **Total** | | **59** | **57** | **58** | **− 1** |

La règle donne 58 nuits (59 − 1). Le fil d'e-mails de Neemba n'en a payé que 57 : le dernier segment a appliqué « jours − 1 » sans la nuitée de rattrapage. La vue « mission » signale ce type d'incohérence au DAF (MSG-M12-07). **Le calcul n'est pas adapté** pour reproduire l'écart ; la question est posée à Neemba (PO-21).

### 4.6 ODM extérieur

Indemnité = jours × barème FCFA (22 000 non-cadre / 34 000 cadre) × taux FCFA → GNF.

- À la génération du bon, le montant est **estimé** avec le dernier taux saisi : c'est cette estimation qui détermine le circuit, par exemple le visa du DP.
- Au paiement, il est **recalculé** avec le taux saisi par la Trésorerie **le jour même** ; sans taux du jour, le paiement est bloqué.
- Le barème est unique, y compris hors zone CFA.
- Le billet d'avion reste hors circuit ODM (bon de commande Wanda) : seule sa référence est notée sur l'ODM.

### 4.7 Retour anticipé (RG-M12-20)

Si la mission s'achève plus tôt que prévu, le demandeur saisit la date de retour réelle. Pour chaque participant, la plateforme recalcule et affiche le **trop-perçu**, c'est-à-dire ce qui a été versé en trop (MSG-M12-09). Il est soit **reversé en caisse** (une entrée d'argent), soit **retenu sur salaire** (les RH sont prévenus).

Un retour tardif n'est pas accepté tel quel : il faut une **prolongation**.

---

## 5. Les règles de la spécification (RG-M12)

Statut :
- **C** : confirmé, à coder tel quel ;
- **D** : valeur par défaut, à coder en paramètre ;
- **O** : ouvert, variantes activables par paramètre.

| Règle | Contenu | Statut | Lot | Fait |
|-------|---------|:------:|:---:|:----:|
| RG-M12-01 | Type intérieur / extérieur obligatoire | C | M12-2 | ☑ |
| RG-M12-02 | Nature technique : au moins un OR ; case pré-cochée pour Technique et Aftermarket | D | M12-2 | ☑ |
| RG-M12-03 | Numéro à la soumission N°[séq]/[préfixe]/[AA], séquence par préfixe et par année | D | M12-1 | ☑ |
| RG-M12-04 | 1 à 10 participants (paramètre), salariés ; statut cadre, service et n° OM repris | C | M12-2 | ☑ |
| RG-M12-05 | Obligatoires : service, au moins une destination, but (10 caractères), dates, prise en charge | C | M12-2 | ☑ |
| RG-M12-06 | Retour ≥ départ ; départ passé admis avec motif | C | M12-2 | ☑ |
| RG-M12-07 | Calcul par participant, barèmes en vigueur à la soumission | C | M12-1 | ☑ |
| RG-M12-08 | Libellés des 2 lignes de 125 000 paramétrables (Repas / Déplacement) | D | M12-0 | ☑ |
| RG-M12-09 | Base vie : hébergement à 0 pour le participant | C | M12-1 | ☑ |
| RG-M12-10 | Extérieur : estimation au dernier taux, recalcul au taux du jour au paiement, blocage sans taux | C | M12-4 | ☑ |
| RG-M12-11 | Circuit chef d'atelier → DAF → DP ; pas de CDG ni de RH ; étape RH en paramètre inactif | C | M12-3 | ☑ |
| RG-M12-12 | Rejet motivé ; même numéro ; modifiable et resoumis | C | M12-3 | ☑ |
| RG-M12-13 | Génération depuis un ODM validé : BD, plus BP facultatif ; circuit complet ; OM souhaité | C | M12-4 | ☑ |
| RG-M12-14 | Un bon par participant (défaut) ou bon groupé | O | M12-4 | ☑ |
| RG-M12-15 | Prise en charge client : variante A (bons et « à refacturer », défaut) ou B (aucun bon) | O | M12-4 | ☑ |
| RG-M12-16 | Chevauchement bloqué ; dérogation du DAF motivée | C | M12-2 | ☑ |
| RG-M12-17 | Prolongation : segment lié, départ = retour + 1, participants repris (pas d'ajout) | C | M12-5 | ☐ |
| RG-M12-18 | Nuitée de rattrapage pour chaque participant non logé sur base vie | C | M12-5 | ☐ |
| RG-M12-19 | Vue mission : cumuls et contrôle nuits = jours − 1, signalé au DAF | C | M12-5 | ☐ |
| RG-M12-20 | Clôture : retour réel ; anticipé → trop-perçu ; tardif → prolongation | D | M12-6 | ☐ |
| RG-M12-21 | 9 statuts | C | M12-1 | ☑ |
| RG-M12-22 | Annulation : demandeur avant génération, DAF ensuite ; un bon payé interdit l'annulation | D | M12-6 | ☐ |
| RG-M12-23 | PDF fiche d'indemnités et ordre de mission, visas horodatés | C | M12-7 | ☐ |
| RG-M12-24 | Notifications à la liste de diffusion du service émetteur | C | M12-2 | ☑ |
| RG-M12-25 | Calcul figé à la validation finale | C | M12-3 | ☑ |
| RG-M12-26 | Hébergement extérieur, 3 modes | C | M12-6 | ☐ |
| RG-M12-27 | Référence billet / bon de commande Wanda (facultatif) | C | M12-6 | ☐ |
| RG-M12-28 | Rappel avant la fin d'un segment (2 jours ouvrés) | C | M12-5 | ☐ |
| RG-M12-29 | Aucun justificatif exigé au retour pour les indemnités forfaitaires | C | M12-4 | ☑ |

Règles d'autres modules utilisées par M12 :
- **RG-M03-09** : frais OM sur tous les bons ;
- **RG-M03-15** : un BD généré depuis un ODM n'exige pas de justificatif ;
- **RG-M03-22** : champs repris de l'ODM et verrouillés sur le bon ;
- **RG-M04-02** : circuit complet des bons générés ;
- **RG-M06-07** : mode de paiement décidé par le caissier, frais OM au paiement ;
- **RG-M07-02** : échéance d'un BP de mission à 3 jours ouvrés après le retour ;
- **RG-M02-04** : statut cadre ;
- **RG-M01-04** : on ne vise pas un ODM dont on est demandeur ou participant.

---

## 6. Messages (texte exact de la spécification)

| ID | Type | Texte |
|----|------|-------|
| MSG-M12-01 | Blocage | Mission technique : rattachez au moins un OR. |
| MSG-M12-02 | Blocage | La date de retour doit être postérieure ou égale à la date de départ. |
| MSG-M12-03 | Blocage | [Participant] est déjà en mission du [date] au [date] (ODM [n°]). |
| MSG-M12-04 | Blocage | [Participant] n'a pas de statut cadre / non-cadre : contactez les RH. |
| MSG-M12-05 | Blocage | Aucun taux de change FCFA → GNF saisi aujourd'hui : paiement impossible. Contactez la Trésorerie. |
| MSG-M12-06 | Info | Prolongation : une nuitée de rattrapage du segment [n°] est ajoutée pour [n] participant(s). |
| MSG-M12-07 | Alerte | Incohérence sur la mission [n°] : [x] nuits payées pour [y] jours. |
| MSG-M12-08 | Succès | ODM [n°] validé. Vous pouvez générer le ou les bons de caisse. |
| MSG-M12-09 | Info | Retour anticipé : trop-perçu de [montant] GNF pour [participant], à régulariser. |

---

## 7. Ce qui existe déjà dans l'application

| Élément | État | Ce qu'on en fait |
|---------|------|------------------|
| Table `ordres_mission`, modèle `OrdreMission` | Stub : un collaborateur, un bon (`bon_caisse_id`), jamais utilisé | **Remplacés** par le vrai modèle (M12-1) |
| `bons_caisse.odm_id` (lot 3) | Clé étrangère vers `ordres_mission`, vide | **Conservé** : lien du bon vers son ODM |
| `BonCaisse::ordreMission()` | HasOne via `bon_caisse_id` | Devient un BelongsTo via `odm_id` |
| Catégorie de dépense « mission » | Existe, non proposée dans l'assistant | Catégorie des bons générés |
| BP lié à une mission (`lie_mission`, `date_retour_mission`) | Lot 3 | BP « frais réels » généré depuis l'ODM |
| Création et soumission des bons (`EnregistrementBon`, `SoumettreBon`, circuit, visa DP au-delà de 1,5 M) | Lots 3 à 5 | Les bons générés passent par ce même chemin |
| Délégations (validation, au titre de) | Lot 5 | Suppléant du chef d'atelier, du DAF, du DP |
| Statut cadre, N+1, rôles déclarés (DAF adjoint, chef comptable…) | Référentiels | Participants et circuit |
| Import des référentiels | `php artisan referentiels:importer` | Attribuera le rôle « chef d'atelier » (onglet 5) |
| PDF (dompdf), montant en lettres, formats, messages | Socle | Fiche d'indemnités et ordre de mission |
| Taux de change, frais OM par paliers, jours ouvrés, rôles chef d'atelier / DP adjoint / logistique, n° Orange Money des salariés | ✅ Ajoutés par M12-0 (devBook §26) | Utilisés par les lots suivants |

---

## 8. Plan d'implémentation et suivi

Pour chaque lot :
- tests automatiques (PHP et JS) et build ;
- un parcours dans le navigateur sur une copie jetable de la base ;
- une section du devBook ;
- commits locaux ;
- cases cochées ici.

### M12-0 — Prérequis transverses (≈ 2 j)

- [x] Rôles `chef_atelier`, `dp_adjoint` (actifs) et `logistique` (déclaré) dans `User::ROLES` et à l'écran ; rôles complémentaires sur la fiche utilisateur, avec double validation (Q31)
- [x] Visa DAF de l'ODM ouvert à `daf`, `daf_adjoint` et `chef_comptable` (Q27) : réalisé avec le circuit (M12-3)
- [x] `users.numero_om` : format guinéen (RG-M02-05), saisi sur la fiche utilisateur ; repris sur les participants en M12-1
- [x] `App\Support\JoursOuvres` : week-ends et paramètre `jours_feries` (Q29) ; échéance des BP en jours ouvrés
- [x] `FraisOrangeMoney` :
  - [x] paliers paramétrés et arrondi à l'unité supérieure ;
  - [x] saisie manuelle hors paliers, par le caissier (Q30) ;
  - [x] branché au paiement (montant versé = total + frais, débité de la caisse OM).
- [x] Taux de change FCFA → GNF : table `taux_change`, écran Trésorerie « Taux du jour », `duJour()` et `dernier()` (Q32)
- [x] Paramètres ODM (groupe « Ordres de mission ») :
  - [x] barèmes, libellés, hébergement ;
  - [x] nombre maximal de participants ;
  - [x] BP oui / non, mode de génération, prise en charge client (paramètres à choix) ;
  - [x] étape RH, délai du rappel.
- [x] Import des référentiels : rôle `chef_atelier` attribué depuis l'onglet 5 (compte rattaché au site et au service)

### M12-1 — Modèle de données et calcul (≈ 2 j)

- [x] Migration : `ordres_mission` remplacée ; `odm_participants`, `odm_ordres_reparation`, `odm_etapes`, `odm_historique`, `compteurs_odm`
- [x] Ajouts : `services.prefixe_odm` et `services.diffusion_odm` ; `bons_caisse.odm_participant_id` et `bons_caisse.genere_par_odm`
- [x] Modèles `OrdreMission`, `ParticipantOdm`, `OrdreReparationOdm`, `EtapeOdm` ; relation `BonCaisse::ordreMission()`
- [x] `CalculOdm` : jours, nuits, rattrapage, indemnités intérieures et extérieures, hébergement, totaux, contrôle de chaîne
- [x] Tests unitaires sur les annexes B.1, B.2, B.3 et B.4
- [x] `NumeroteurOdm` : N°[séq]/[préfixe]/[AA], compteur verrouillé, numéro de départ repris des carnets

### M12-2 — Saisie et soumission (≈ 3 j)

- [x] API `/api/v1/odm` : brouillon, enregistrement, calcul en direct, participants, OR, contrôles, soumission, annulation
- [x] `ReglesOdm` : champs obligatoires, dates (MSG-M12-02), départ passé, OR d'une mission technique (MSG-M12-01), statut cadre pour l'extérieur (MSG-M12-04)
- [x] `ChevauchementOdm` (MSG-M12-03) et dérogation du DAF
- [x] `SoumettreOdm` : numéro, circuit, notification à la liste de diffusion
- [x] Écrans : liste des ODM et formulaire avec panneau « Calcul » ; menu « Ordres de mission »
- [x] Paramétrage des services : préfixe, liste de diffusion, reprise du carnet papier (Q35)

### M12-3 — Circuit de validation (≈ 2 j)

- [x] `CircuitOdm` : chef d'atelier du service, puis DAF, puis DP ; RH en option
- [x] Incompatibilités : ni demandeur ni participant ; renvoi au suppléant ou au niveau supérieur (Q37)
- [x] Visa, rejet motivé, resoumission (version + 1), « au titre de » (délégation « Visa des ordres de mission », Q36)
- [x] Délais, relances et escalade sur les étapes d'ODM (`odm:relancer-visas`)
- [x] Calcul figé à la validation (RG-M12-25), MSG-M12-08
- [x] Écrans : bloc « Ordres de mission à viser » (Q39) et fiche ODM (onglets Détails, Calcul, Validations, Historique) ; onglets Bons et Mission avec M12-4 et M12-5

### M12-4 — Génération des bons et paiement (≈ 3 j)

- [x] `GenererBonsOdm` :
  - [x] un BD par participant, ou groupé ;
  - [x] champs verrouillés et catégorie « mission » ;
  - [x] pas de justificatif exigé ;
  - [x] soumission automatique.
- [x] BP « frais réels » facultatif, échéance de 3 jours ouvrés après le retour
- [x] Prise en charge client : variantes A et B ; ODM « à refacturer »
- [x] Extérieur : estimation au dernier taux ; recalcul au paiement ; blocage sans taux du jour (MSG-M12-05)
- [x] Statuts « Bons générés » puis « Payé »

### M12-5 — Prolongations et vue mission (≈ 2 j)

- [ ] `ProlongerOdm` : segment lié, participants repris, nuitée de rattrapage (MSG-M12-06), libellé « Prolongation n de N°xxx »
- [ ] Vue mission : cumuls et contrôle nuits = jours − 1 (MSG-M12-07, alerte au DAF)
- [ ] Commande de rappel des missions longues (2 jours ouvrés avant la fin)

### M12-6 — Clôture, annulation, extérieur (≈ 2 j)

- [ ] `CloturerOdm` : retour réel ; trop-perçu (MSG-M12-09) reversé en caisse ou retenu (RH) ; retour tardif → prolongation
- [ ] `AnnulerOdm` : demandeur, puis DAF ; annulation des bons non payés
- [ ] Hébergement extérieur (3 modes) et référence billet Wanda

### M12-7 — Impression et tableaux de bord (≈ 2 j)

- [ ] PDF fiche d'indemnités et ordre de mission (autorisation de circuler), visas horodatés
- [ ] Tableau de bord du DAF : missions en cours (durée, coût), chevauchements, « à refacturer » ; export Excel
- [ ] Taux appliqué visible par la Trésorerie

### M12-8 — Recette (≈ 1,5 j)

- [ ] Tests des scénarios SC-20 à SC-29 et SC-37
- [ ] Parcours complet dans le navigateur
- [ ] devBook, `docs/questions.md`, ce fichier entièrement coché

**Total estimé : environ 19,5 jours**, dans la fenêtre « évolutions + module ODM » du 12/10 au 20/11 fixée en comité.

---

## 9. Points ouverts et décisions provisoires

### 9.1 Points ouverts de la spécification

Ils sont codés en **paramètres**, avec leur valeur par défaut : la réponse de Neemba ne changera qu'un réglage.

| Réf. | Sujet | Défaut développé | Attendu de |
|------|-------|------------------|------------|
| PO-02 | Libellés des deux lignes de 125 000 | Repas / Déplacement | Neemba |
| PO-03 | Un bon par participant ou bon groupé | Un bon par participant | DAF (avant S6) |
| PO-04 | ODM à la charge du client | Variante A : bons, plus « à refacturer » | Neemba (avant S6) |
| PO-05 | Prolongation = ODM lié à l'initial | Retenu | Neemba |
| PO-06 | Définition d'une mission technique | Case pré-cochée pour Technique et Aftermarket | Neemba |
| PO-07 | Hébergement à l'étranger : plafond de la facture | Facture réelle, sans plafond | Neemba |
| PO-08 | Taux de change du jour | Saisi par la Trésorerie | Neemba |
| PO-09 | Procédure ODM du groupe (en rédaction) | Règles de la spécification | Neemba |
| PO-21 | Mission KOUROUMA : 57 nuits payées pour 59 jours | Règle de la spec (58) ; calcul non adapté | Neemba |

### 9.2 Décisions provisoires du développement

Ces questions ne sont pas tranchées par la spécification. Elles seront reportées dans `docs/questions.md` et sont révisables par Thierno.

| # | Sujet | Défaut retenu |
|---|-------|---------------|
| Q22 | La fiche ODM n'a pas de **code analytique**, mais les bons générés en exigent un | Champ obligatoire sur l'ODM, filtré par service émetteur et repris sur chaque bon |
| Q23 | **Numéro d'une prolongation** | Numéro propre dans la séquence du préfixe, plus le libellé « Prolongation n de N°xxx » |
| Q24 | Hébergement extérieur « facture payée **avant le départ** / **au retour** » | Avant : montant de la facture saisi sur l'ODM, ajouté au bon du participant avec la facture ou la proforma. Au retour : BD complémentaire à la clôture, justificatif obligatoire |
| Q25 | ODM pris en compte pour le **chevauchement** | Tous, sauf les brouillons et les ODM annulés |
| Q26 | **Clôture** si un bon n'est pas encore payé | Clôture possible ; le bon non payé est annulé et régénéré au réel ; trop-perçu calculé sur les bons payés |
| Q27 | Qui donne le **visa DAF** de l'ODM | DAF, DAF adjoint ou chef comptable, comme pour la Finance des bons |
| Q28 | **Préfixes ODM** par service | Paramètre par service (défaut « AT » pour Technique) ; numéro de départ repris des carnets papier (ex. après 285 pour AT/26) |

### 9.3 À obtenir

- [ ] Les **fiches ODM N°282 et N°285/AT/26** (reçues par Addvalis le 05/10), pour reproduire la mise en page du PDF
- [ ] Le **fil « Neemba Service_Mission »**, modèle de prolongation (reçu le 06/10)
- [ ] La **liste de diffusion** de chaque service (chef d'atelier, logistique, assistante…)
- [ ] Le **dernier numéro** de chaque carnet d'ODM, pour reprendre la séquence
- [ ] Le **chef d'atelier / chef d'équipe** de chaque service (onglet 5 du classeur des référentiels, à confirmer)

---

## 10. Scénarios de test (annexe A de la spécification)

| Scénario | Sujet | Lot | Test automatique | Fait |
|----------|-------|:---:|------------------|:----:|
| SC-20 | ODM intérieur, une personne (exemple B.1) | M12-2 / M12-3 | `SaisieOdmTest`, `CircuitOdmTest` | ☑ |
| SC-21 | ODM à plusieurs personnes | M12-2 | `SaisieOdmTest` | ☑ |
| SC-22 | ODM technique avec OR liés | M12-2 | `SaisieOdmTest` | ☑ |
| SC-23 | Génération des bons (individuels / groupé, exemple B.2) | M12-4 | `GenerationBonsOdmTest` | ☑ |
| SC-24 | Prolongation d'une mission longue, nuitée de rattrapage (exemple B.3) | M12-5 | | ☐ |
| SC-25 | Ajustement au réel et clôture | M12-6 | | ☐ |
| SC-26 | Chevauchement de périodes (anti-doublon) | M12-2 | `SaisieOdmTest` | ☑ |
| SC-27 | Mission sur base vie | M12-1 / M12-2 | `CalculOdmTest, SaisieOdmTest` | ☑ |
| SC-28 | ODM à la charge du client | M12-4 / M12-7 | `GenerationBonsOdmTest` (reporting : M12-7) | ☐ |
| SC-29 | ODM extérieur | M12-4 / M12-6 | `CalculOdmTest`, `SaisieOdmTest`, `GenerationBonsOdmTest` (hébergement au retour : M12-6) | ☐ |
| SC-37 | ODM générant un BP (avance pour frais réels) | M12-4 | `GenerationBonsOdmTest` | ☑ |

---

## 11. Écarts de la spécification v2.2 hors du module M12

La v2.2 (07/10/2026) **remplace** la SFD v1.3, dont le module M03 a été livré (lots 3 à 5). Plusieurs règles du bon de caisse changent. Elles ne sont **pas** traitées par le chantier M12 et demandent un plan dédié :

| Sujet | Livré (v1.3) | v2.2 |
|-------|--------------|------|
| Numérotation des bons | `BC-AAAA-NNNN` | `BC-[caisse]-[AAAA]-[00001]`, séquence par caisse (RG-M03-18) |
| Format des OR | 8 chiffres | Commence par 110, avec un type VENTE / GARANTIE (RG-M03-13) |
| Mode de paiement | Choisi par le demandeur | Souhaité par le demandeur, **décidé par le caissier** (RG-M06-07) |
| Bénéficiaires | Employé ou tiers en texte libre | Salariés, **visiteurs du groupe**, **tiers validés** par la comptabilité ou le CDG (RG-M03-03, RG-M02-06) |
| Pièce déjà utilisée | Confirmée et justifiée | **Bloquante** (RG-M03-26) |
| Qualité des scans | Bloquante sous 150 dpi | Alerte non bloquante sous 300 dpi (RG-M03-16) |
| Brouillon abandonné | Annulé à 30 jours | Supprimé à 30 jours (RG-M03-19) |
| Doublon probable | Non traité | Même bénéficiaire et même montant sur 7 jours : alerte (RG-M03-20) |

Autres écarts :
- délais de validation en heures ouvrées ;
- rôle DP adjoint ;
- écran de justification des BP (annexe 3) et blocage des BP en retard ;
- réapprovisionnement, encaissement client et inventaire (M08, M10) ;
- export comptable (M16).

Le lot M12-0 ne réalise que ce dont M12 a besoin : frais OM, taux de change, jours ouvrés, rôles.

---

## 12. Journal de suivi

| Date | Lot | Commit | Remarques |
|------|-----|--------|-----------|
| 08/10/2026 | — | — | Analyse de la spec v2.2 et du comité du 06/10 ; plan approuvé ; création de ce fichier |
| 08/10/2026 | M12-0 | 6449cf2, ae3bdbf | Frais OM au paiement, taux du jour, jours ouvrés, paramètres ODM, rôles et n° OM ; 193 tests PHP ; parcours navigateur (19 vérifications). Corrections : OTP sans service SMS, matricule facultatif. Visa DAF de l'ODM reporté à M12-3 |
| 08/10/2026 | M12-1 | 88cfbcd, b569a0b | Tables des ODM, modèles, CalculOdm (annexes B.1 à B.4 vérifiées), NumeroteurOdm ; 211 tests PHP. La numérotation sera attribuée à la soumission (M12-2) : RG-M12-03 cochée avec M12-2 |
| 08/10/2026 | M12-2 | 61b52eb, e051c9f | Formulaire (brouillon automatique, calcul du serveur), soumission, chevauchement et dérogation du DAF, liste, fiche, paramétrage des services ; 227 tests PHP, 66 JS ; parcours navigateur (23 vérifications). Décisions Q33 à Q35 |
| 08/10/2026 | M12-3 | d5d14f9, 2356246 | Circuit chef d'atelier → DAF → DP (RH en option), suppléants « au titre de », étapes sautées (RG-M01-04), rejet et resoumission, calcul figé, relances et escalade ; 237 tests PHP ; parcours navigateur (16 vérifications). Décisions Q36 à Q39 |
| 08/10/2026 | M12-4 | fe25dde, 79c6f97 | Génération des bons (par participant ou groupé, BP d'avance), champs verrouillés, prise en charge client, ODM extérieur recalculé au taux du jour, ODM « Payé » ; 247 tests PHP ; parcours navigateur (13 vérifications). Décisions Q40 à Q43. Incident : la migration 000004 a été appliquée à la base de dev par un `migrate --env=testing` lancé par erreur (sans `.env.testing`, Laravel lit `.env`) ; ajout de colonnes sans effet sur les données, aller-retour vérifié ensuite sur une copie |
