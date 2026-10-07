/**
 * Assistant « Nouveau bon de caisse » (SFD M03, E-03.2 à E-03.7) : règles d'affichage côté écran.
 *
 * Le serveur reste juge (ReglesSaisie, ControlesBon) : ces fonctions ne font qu'anticiper
 * ce qu'il dira, pour guider la saisie (champ grisé, champ masqué, message immédiat).
 */

/** Les 5 étapes (E-03.2) */
export const ETAPES = [
    { numero: 1, libelle: 'Identification' },
    { numero: 2, libelle: 'Bénéficiaire' },
    { numero: 3, libelle: 'Dépense' },
    { numero: 4, libelle: 'Pièces' },
    { numero: 5, libelle: 'Contrôle' },
];

/** Champs de chaque étape, dans l'ordre de l'écran (même liste que ReglesSaisie::CHAMPS_PAR_ETAPE) */
export const CHAMPS_PAR_ETAPE = {
    1: ['demandeur_id', 'type_bon', 'code_analytique', 'site', 'service', 'niveau_urgence', 'motif_urgence', 'justification_urgence'],
    2: ['type_beneficiaire', 'beneficiaire_id', 'beneficiaire', 'telephone_beneficiaire'],
    3: ['motif', 'categorie_depense', 'montant', 'mode_paiement', 'vehicule', 'references_or', 'lie_mission', 'date_retour_mission'],
    4: ['pieces'],
};

/** Formats acceptés et limites des pièces (RG-BC-17) */
export const FORMATS_PIECES = ['application/pdf', 'image/jpeg', 'image/png'];
export const EXTENSIONS_PIECES = ['pdf', 'jpg', 'jpeg', 'png'];
export const TAILLE_MAX_FICHIER = 10 * 1024 * 1024;
export const POIDS_MAX_BON = 50 * 1024 * 1024;
export const NOMBRE_MAX_PIECES = 20;

/** Étape d'un champ (« references_or.0 » → 3 ; « pieces » → 4) */
export function etapeDuChamp(champ) {
    const racine = String(champ).split('.')[0];
    const trouvee = Object.entries(CHAMPS_PAR_ETAPE).find(([, champs]) => champs.includes(racine));

    return trouvee ? Number(trouvee[0]) : null;
}

/** Erreurs du serveur regroupées par champ racine, premier message seulement ({ 'references_or.1': [...] } → { references_or: '…' }) */
export function erreursParChamp(erreurs = {}) {
    return Object.entries(erreurs).reduce((resultat, [cle, messages]) => {
        const racine = cle.split('.')[0];
        if (!resultat[racine]) {
            resultat[racine] = Array.isArray(messages) ? messages[0] : messages;
        }
        return resultat;
    }, {});
}

/** Premier champ en erreur dans l'ordre de l'écran (curseur placé dessus après « Suivant ») */
export function premierChampEnErreur(erreurs = {}, etape) {
    const enErreur = Object.keys(erreursParChamp(erreurs));
    const ordre = CHAMPS_PAR_ETAPE[etape] ?? [];

    return ordre.find((champ) => enErreur.includes(champ)) ?? enErreur[0] ?? null;
}

/** RG-BC-11 : espèces possibles tant que le montant ne dépasse pas le plafond de retrait (pas de plafond = pas de limite) */
export function especesAutorisees(montant, plafond) {
    if (plafond === null || plafond === undefined || !montant) return true;

    return Number(montant) <= Number(plafond);
}

/** RG-BC-09 : visa du Directeur Pays au-delà du seuil (strictement) */
export function visaDpRequis(montant, seuil) {
    return Boolean(montant) && Number(montant) > Number(seuil);
}

/** Champs véhicule / OR affichés selon la catégorie (§5.4.3, ANO-14) */
export function champsDeLaCategorie(categorie) {
    return {
        vehicule: Boolean(categorie?.vehicule_obligatoire || categorie?.vehicule_affiche),
        vehiculeObligatoire: Boolean(categorie?.vehicule_obligatoire),
        or: Boolean(categorie?.or_affiche),
    };
}

/** RG-BC-04 : codes du service s'il en a, sinon tous les codes actifs */
export function codesDuService(codes = [], serviceId = null) {
    const duService = serviceId ? codes.filter((code) => code.service_id === serviceId) : [];

    return duService.length > 0 ? duService : codes;
}

/** Un numéro d'OR : 8 chiffres (RG-BC-13) */
export function estNumeroOr(valeur) {
    return /^\d{8}$/.test(String(valeur ?? '').trim());
}

/**
 * Ajout d'un OR à la liste des étiquettes : { liste, erreur }.
 * Erreur = clé du message (MSG-BC-014) ; un OR déjà présent n'est pas ajouté deux fois.
 */
export function ajouterOr(liste = [], saisie = '') {
    const numero = String(saisie).replace(/\s/g, '');
    if (numero === '') return { liste, erreur: null };
    if (!estNumeroOr(numero)) return { liste, erreur: 'MSG-BC-014' };
    if (liste.includes(numero)) return { liste, erreur: null };

    return { liste: [...liste, numero], erreur: null };
}

/** Type proposé pour une nouvelle pièce : « Ticket carburant » pour la catégorie Carburant (E-03.6) */
export function typePieceParDefaut(categorie) {
    return categorie === 'carburant' ? 'recu_carburant' : '';
}

/**
 * Contrôle d'un fichier avant envoi (RG-BC-17) : clé du message d'erreur, ou null.
 * Le serveur refait le contrôle sur le contenu réel du fichier.
 */
export function erreurFichier(fichier, pieces = []) {
    const extension = String(fichier?.name ?? '').split('.').pop().toLowerCase();
    if (!EXTENSIONS_PIECES.includes(extension) || (fichier.type && !FORMATS_PIECES.includes(fichier.type))) {
        return 'MSG-BC-021';
    }
    if (fichier.size > TAILLE_MAX_FICHIER) return 'MSG-BC-021';

    const poids = pieces.reduce((total, piece) => total + Number(piece.taille ?? 0), 0);
    if (pieces.length >= NOMBRE_MAX_PIECES || poids + fichier.size > POIDS_MAX_BON) return 'MSG-APP-003';

    return null;
}

/** « Caisse principale Conakry » → « caisse principale Conakry » (dans une phrase) */
export function libelleDansUnePhrase(libelle = '') {
    return libelle ? libelle.charAt(0).toLowerCase() + libelle.slice(1) : '';
}

/** Compteur du motif (E-03.5) : « n/10 min » tant que le minimum n'est pas atteint, puis « n/200 » */
export function compteurMotif(motif = '') {
    const longueur = motif.trim().length;

    return longueur < 10 ? `${longueur}/10 min` : `${longueur}/200`;
}

/** Date au format AAAA-MM-JJ, n jours avant la date du jour fournie (borne de la date de retour de mission) */
export function dateMoinsJours(dateDuJour, jours) {
    const date = new Date(`${dateDuJour}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() - jours);

    return date.toISOString().slice(0, 10);
}

/** Clé d'idempotence de la soumission (RG-BC-27) : la même pour les clics répétés d'une même tentative */
export function nouvelleCleIdempotence() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();

    return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/* ------------------------------------------------------------------
 * Lecture des tickets carburant (US-BC-09)
 * ------------------------------------------------------------------ */

/** Champs du panneau « Lecture du ticket », dans l'ordre de l'écran (même liste que LectureTicket::CHAMPS) */
export const CHAMPS_LECTURE = ['station', 'date', 'litres', 'montant', 'montant_lettres', 'immatriculation'];

/** Pastille de confiance : vert ≥ 85 %, orange 60–84 %, rouge < 60 % ; null si la valeur n'a pas été lue */
export function couleurConfiance(confiance) {
    if (confiance === null || confiance === undefined) return null;
    if (confiance >= 85) return 'vert';

    return confiance >= 60 ? 'orange' : 'rouge';
}

/** Prix au litre arrondi (null sans litres) */
export function prixAuLitre(montant, litres) {
    const l = Number(String(litres ?? '').replace(',', '.'));
    if (!montant || !l || l <= 0) return null;

    return Math.round(Number(montant) / l);
}

/** RG-BC-22 : prix au litre à plus de 10 % du prix de référence */
export function prixAtypique(prix, reference) {
    if (!prix || !reference) return false;

    return Math.abs(prix - reference) / reference > 0.1;
}

/** « Valider la lecture » n'est actif qu'une fois chaque champ confirmé ou corrigé (RG-BC-21) */
export function lectureConfirmable(confirmes = []) {
    return CHAMPS_LECTURE.every((champ) => confirmes.includes(champ));
}
