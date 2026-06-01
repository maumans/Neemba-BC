# NEEMBA CATERPILLAR × ADDVALIS
## Plan de test — Pilote en conditions réelles · Conakry

| Référence | Détail |
|------------|---------|
| Site pilote | Caisse principale de Conakry |
| Durée du test | 5 à 7 jours ouvrés |
| Volume cible | 10 à 15 bons de caisse réels minimum |
| Période | À compléter après réunion de clôture |
| Référent Neemba | À désigner en séance |
| Référent Addvalis | Thierno Diallo — Lead consultant |
| Critère de clôture | Fiche GO / NO GO signée à J+10 maximum |

---

# Objectif du test

Ce plan de test vise à valider la plateforme en conditions réelles avec les utilisateurs Neemba, sur des bons de caisse issus de l'activité courante de Conakry.

Il couvre 10 scénarios représentatifs des cas métiers identifiés lors de l’audit.

Les résultats mesurés alimenteront directement la fiche GO / NO GO et l’offre Phase 2 (déploiement 7 sites).

---

# Utilisateurs pilotes — à désigner en séance

| Profil | Nombre | Responsabilité dans le test | Nom désigné |
|----------|----------|----------|----------|
| Demandeur | 2 à 3 | Saisir les bons, joindre les pièces, suivre le statut | Souadou BARRY |
| Chef de service | 1 | Valider les bons de son équipe à l’étape 1 | Thierry Antoine GOMIS |
| CDG (Contrôleur de gestion) | 1 | Valider, corriger les codes analytiques, rejeter si nécessaire | Maïmouna Sonna BARRY |
| DAF | 1 | Valider les bons, supervision globale | Diakite Mohamed |
| DG Pays | 1 | Valider les bons ≥ 1 500 000 GNF, supervision globale | M Lo |
| Caissier | 1 | Émettre les OTP, procéder aux paiements, gérer le solde | Youssouf TOURE |

---

# Scénarios de test

Chaque scénario est exécuté par les utilisateurs réels dans l'environnement de production paramétré.

Addvalis assure un accompagnement à distance (disponible par téléphone/WhatsApp).

---

## Scénario S01 — Bon de dépense/caisse — Normal

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Demandeur |
| Description | Saisie d'un bon de dépense standard avec pièce jointe scannée. Validation par le chef de service, le CDG, puis paiement par le caissier avec OTP. |
| Résultat attendu | Validation en < 2 jours. Bon archivé automatiquement. Rapport J+0 inclut le bon. |
| KPI mesuré | Délai validation, archivage |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S02 — Bon de dépense — Urgent

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Demandeur |
| Description | Saisie d'un bon urgent avec sélection du motif dans la liste déroulante. Le système envoie un email + SMS au validateur. Le demandeur doit remplir la justification obligatoire. |
| Résultat attendu | Notification email + SMS envoyée au CDG. Motif d'urgence tracé. Délai de validation réduit. |
| KPI mesuré | Notification < 2h, traçabilité motif |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S03 — Code analytique — Correction par CDG

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Demandeur + CDG |
| Description | Le demandeur saisit un code analytique erroné. Le CDG corrige le code sans renvoyer le bon au demandeur. |
| Résultat attendu | Historique de modification tracé. |
| KPI mesuré | Fonctionnalité CDG, traçabilité |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S04 — Rejet avec notification

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | CDG / DAF |
| Description | Bon incomplet rejeté avec motif. Notification automatique au demandeur. |
| Résultat attendu | Notification reçue en < 2h. Motif visible dans l'historique. |
| KPI mesuré | Délai notification rejet < 2h |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S05 — Bon provisoire — Régularisation

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Demandeur + Caissier |
| Description | Émission d'un bon provisoire puis régularisation à J+2 avec justificatif. |
| Résultat attendu | Rappel automatique à J+1. Bon clôturé et archivé. |
| KPI mesuré | Taux régularisation dans délai |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S06 — OTP — Paiement sécurisé

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Caissier |
| Description | Génération OTP pour paiement d'un bon validé. |
| Résultat attendu | OTP valide 5 min. Paiement enregistré. Solde mis à jour en temps réel. |
| KPI mesuré | Sécurité paiement, solde caisse |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S07 — Validation DAF — Bon ≥ 5 000 000 GNF

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | DAF |
| Description | Déclenchement automatique d'une validation DAF. |
| Résultat attendu | Sans validation DAF, le bon reste bloqué. |
| KPI mesuré | Workflow 4 niveaux |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S08 — Validation DG Pays — Bon ≥ 1 500 000 GNF

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | DG Pays |
| Description | Déclenchement automatique d'une validation DG Pays. |
| Résultat attendu | Validation supplémentaire obligatoire. |
| KPI mesuré | Workflow 4 niveaux, seuil DAF |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S09 — Rapport journalier automatique

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Caissier / Finance |
| Description | Génération automatique du rapport journalier avant 8h. |
| Résultat attendu | Rapport Excel et PDF disponible avant 8h. |
| KPI mesuré | Rapport J+0 automatisé à 100% |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S10 — Délégation de signature

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Admin / CDG |
| Description | Activation du back-up en cas d'absence du CDG. |
| Résultat attendu | Délégation effective sans intervention manuelle. |
| KPI mesuré | Délégation opérationnelle |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

## Scénario S11 — Blocage solde négatif

| Champ | Détail |
|---------|---------|
| Profil(s) impliqué(s) | Caissier |
| Description | Tentative de paiement supérieure au solde disponible. |
| Résultat attendu | Paiement bloqué et alerte envoyée. |
| KPI mesuré | Blocage solde, alerte automatique |
| Résultat obtenu | |
| Anomalie / remarque | |
| Statut | ☐ Conforme ☐ Partiellement ☐ Non conforme |

---

# Grille de mesure KPI — à compléter à J+10

| KPI | Baseline | Cible | Seuil GO | Résultat mesuré | Verdict |
|---------|---------|---------|---------|---------|---------|
| Délai validation bout-en-bout | 4–6 jours | < 2 jours | ≥ 80% amélioration | | |
| Taux dossiers complets à soumission | ~65% | ≥ 95% | +30 pts min. | | |
| Génération rapport journalier | J+1/J+2 manuel | J+0 avant 8h | 100% auto. | | |
| Archivage & traçabilité | 0% papier | 100% centralisé | 0 pièce manquante | | |
| Délai notification rejet | > 24h verbal | < 2h auto | < 2h garanti | | |
| Taux régularisation provisoires | ~60% | ≥ 90% | +30 pts min. | | |
| Calcul indemnités ODM | Non mesuré | 100% sans erreur | 0 erreur | | |

---

# Journal de suivi quotidien

| Jour | Date | Bons traités | Anomalies | Actions correctives | Visa référent |
|---------|---------|---------|---------|---------|---------|
| J+1 | | | | | |
| J+2 | | | | | |
| J+3 | | | | | |
| J+4 | | | | | |
| J+5 | | | | | |
| J+6 | | | | | |
| J+7 | | | | | |

---

# Règles pendant le test

## Accompagnement Addvalis

1. Disponible par téléphone / WhatsApp pendant toute la durée du test.
2. Point quotidien de 15 minutes avec le référent Neemba.
3. Toute anomalie bloquante traitée sous 4h ouvrées.

## Gel de périmètre

1. Aucune nouvelle fonctionnalité ajoutée pendant le test.
2. Seules les corrections de bugs bloquants sont autorisées.

## Clôture du test

1. À J+5 maximum, Addvalis produit le rapport KPI avant/après et la fiche GO / NO GO.
2. La fiche GO / NO GO est signée conjointement avant transmission de l'offre Phase 2.

---

**Document confidentiel — Diffusion restreinte Neemba Caterpillar & Addvalis | Avril 2026**