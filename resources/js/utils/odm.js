/**
 * Formulaire « Ordre de mission » (M12, spec v2.2 §7.3) : règles de saisie côté écran.
 * Le calcul des indemnités n'est jamais refait ici : il vient du serveur, qui fait foi.
 */

/** Statuts d'un ODM (RG-M12-21) et couleur de la pastille (variantes de Components/ui/badge.jsx) */
export const STATUTS_ODM = {
    BROUILLON: { libelle: 'Brouillon', variante: 'statut_gris' },
    SOUMIS: { libelle: 'Soumis', variante: 'statut_bleu' },
    EN_VALIDATION: { libelle: 'En validation', variante: 'statut_bleu' },
    REJETE: { libelle: 'Rejeté', variante: 'statut_rouge' },
    VALIDE: { libelle: 'Validé', variante: 'statut_vert_clair' },
    BONS_GENERES: { libelle: 'Bons générés', variante: 'statut_orange' },
    PAYE: { libelle: 'Payé', variante: 'statut_vert' },
    CLOTURE: { libelle: 'Clôturé', variante: 'statut_gris' },
    ANNULE: { libelle: 'Annulé', variante: 'statut_annule' },
};

export function infosStatutOdm(statut) {
    return STATUTS_ODM[statut] ?? { libelle: statut ?? '—', variante: 'statut_gris' };
}

/** Spec v2.2 (MSG-M03-07) : n° d'OR de 8 chiffres commençant par 110 */
export function estNumeroOrOdm(valeur) {
    return /^110\d{5}$/.test(String(valeur ?? '').replace(/\s/g, ''));
}

/** Ajout d'un OR typé : erreur MSG-M03-07 si le format est faux ; un OR déjà présent n'est pas répété */
export function ajouterOrOdm(liste = [], saisie = '', type = 'vente') {
    const numero = String(saisie ?? '').replace(/\s/g, '');
    if (numero === '') return { liste, erreur: null };
    if (!estNumeroOrOdm(numero)) return { liste, erreur: 'MSG-M03-07' };
    if (liste.some((or) => or.numero === numero)) return { liste, erreur: null };

    return { liste: [...liste, { numero, type }], erreur: null };
}

/** Destination ou client ajouté en étiquette : texte nettoyé, sans doublon (casse ignorée), 120 caractères au plus */
export function ajouterEtiquette(liste = [], texte = '') {
    const valeur = String(texte ?? '').trim().replace(/\s+/g, ' ').slice(0, 120);
    if (valeur === '' || liste.some((v) => v.toLowerCase() === valeur.toLowerCase())) return liste;

    return [...liste, valeur];
}

/** RG-M12-06 : départ passé, motif exigé */
export function departPasse(dateDepart, dateDuJour) {
    return Boolean(dateDepart && dateDuJour && dateDepart < dateDuJour);
}

/** RG-M12-06 (MSG-M12-02) : retour antérieur au départ */
export function retourAvantDepart(dateDepart, dateRetour) {
    return Boolean(dateDepart && dateRetour && dateRetour < dateDepart);
}

/** Jours de mission pour l'affichage immédiat (le serveur recalcule) : retour − départ + 1 */
export function joursDeMission(dateDepart, dateRetour) {
    if (!dateDepart || !dateRetour || dateRetour < dateDepart) return 0;
    const jour = 24 * 60 * 60 * 1000;

    return Math.round((Date.parse(`${dateRetour}T00:00:00Z`) - Date.parse(`${dateDepart}T00:00:00Z`)) / jour) + 1;
}

/** RG-M12-02 (PO-06) : nature technique pré-cochée pour les services Technique et Aftermarket */
export function natureTechniqueParDefaut(services = [], nomService = '') {
    return Boolean(services.find((s) => s.nom === nomService)?.technique);
}

/** Codes analytiques du service émetteur (décision Q22) */
export function codesDuServiceOdm(codes = [], services = [], nomService = '') {
    const service = services.find((s) => s.nom === nomService);
    if (!service) return codes;
    const filtres = codes.filter((c) => c.service_id === service.id);

    return filtres.length ? filtres : codes;
}

/* ------------------------------------------------------------------
 * Spec v2.3 : lignes de frais (RG-M12-30) et parts de prise en charge (RG-M12-31 à RG-M12-33)
 * Une part : payeur (neemba | client), client du référentiel, OR (numéro), code analytique (vide : celui de l'ODM),
 * pourcentage (il fait foi, décision Q52), commentaire. Le serveur fait foi ; l'écran recalcule les montants pour l'affichage.
 * ------------------------------------------------------------------ */

export const PART_NEEMBA = { payeur: 'neemba', client: null, ordre_reparation: null, code_analytique: null, pourcentage: 100, commentaire: '' };

/** Part reçue du serveur, au format de l'éditeur */
export function partEditable(part) {
    return {
        payeur: part.payeur === 'client' ? 'client' : 'neemba',
        client: part.client ?? null,
        ordre_reparation: part.ordre_reparation ?? null,
        code_analytique: part.code_analytique ?? null,
        pourcentage: Number(part.pourcentage ?? 0),
        commentaire: part.commentaire ?? '',
    };
}

/** Parts d'une ligne : celles modifiées à l'écran, sinon celles du serveur, sinon Neemba 100 % */
export function partsDeLigne(ligne, repartitions = {}, userId = null) {
    const locales = repartitions?.[userId]?.[ligne.element];
    if (locales) return locales;
    if (ligne.parts?.length) return ligne.parts.map(partEditable);

    return [{ ...PART_NEEMBA }];
}

/** Une seule part, Neemba à 100 % : affichage compact */
export function estNeembaSeul(parts = []) {
    return parts.length === 1 && parts[0].payeur === 'neemba' && Number(parts[0].pourcentage) === 100 && !parts[0].code_analytique;
}

/** Montant de chaque part (comme le serveur : la part la plus forte reçoit l'arrondi quand le total fait 100 %) */
export function repartirMontant(montant, pourcentages = []) {
    if (montant === null || montant === undefined) return pourcentages.map(() => null);
    const montants = pourcentages.map((p) => Math.round((montant * Number(p || 0)) / 100));
    const total = pourcentages.reduce((s, p) => s + Number(p || 0), 0);
    if (montants.length && Math.abs(total - 100) < 0.001) {
        const max = Math.max(...pourcentages.map(Number));
        const indice = pourcentages.findIndex((p) => Number(p) === max);
        montants[indice] += Math.round(montant) - montants.reduce((s, m) => s + m, 0);
    }

    return montants;
}

/** Pourcentage d'une part saisie en GNF (4 décimales) */
export function pourcentageDe(montantPart, montantLigne) {
    return montantLigne > 0 ? Math.round((Number(montantPart || 0) / montantLigne) * 1000000) / 10000 : 0;
}

/** Reste à répartir, en pourcentage (0 : la ligne est entièrement répartie) */
export function resteARepartir(parts = []) {
    return Math.round((100 - parts.reduce((s, p) => s + Number(p.pourcentage || 0), 0)) * 10000) / 10000;
}

/** RG-M12-33 : avertissement (commentaire obligatoire) — part client sans OR, ou client différent de celui de l'OR */
export function avertissementPart(part, ordres = []) {
    if (part.payeur !== 'client') return null;
    if (!part.ordre_reparation) return 'Part client sans OR.';
    const or = ordres.find((o) => o.numero === part.ordre_reparation);
    if (or?.client && part.client && or.client.id !== part.client.id) return `Client différent de celui de l'OR ${or.numero}.`;

    return null;
}

/** Changement d'OR sur une part client : le client de l'OR est proposé par défaut (RG-M12-33) */
export function choisirOrDeLaPart(part, numero, ordres = []) {
    const or = ordres.find((o) => o.numero === numero);
    const client = part.payeur === 'client' && !part.client && or?.client ? or.client : part.client;

    return { ...part, ordre_reparation: numero || null, client };
}

/** Nouvelle part client : le reste à répartir, le client et l'OR proposés */
export function nouvellePartClient(parts = [], ordres = [], clientsOdm = []) {
    const or = ordres.find((o) => o.client) ?? ordres[0] ?? null;
    const reste = resteARepartir(parts);

    return {
        payeur: 'client',
        client: or?.client ?? clientsOdm[0] ?? null,
        ordre_reparation: or?.numero ?? null,
        code_analytique: null,
        pourcentage: reste > 0 ? reste : 0,
        commentaire: '',
    };
}

/**
 * RG-M12-32 : une répartition appliquée d'un coup à toutes les lignes d'un participant (userId) ou de l'ODM (null).
 * Les lignes viennent du calcul du serveur.
 */
export function appliquerRepartition(repartitions = {}, calcul = null, modele = [{ ...PART_NEEMBA }], userId = null) {
    const resultat = { ...repartitions };
    (calcul?.participants ?? []).forEach((p) => {
        if (userId !== null && p.user_id !== userId) return;
        resultat[p.user_id] = { ...(resultat[p.user_id] ?? {}) };
        (p.lignes ?? []).forEach((ligne) => {
            resultat[p.user_id][ligne.element] = modele.map((part) => ({ ...part }));
        });
    });

    return resultat;
}

/** Lignes déjà réparties autrement que « Neemba 100 % » (confirmation avant d'appliquer une répartition) */
export function lignesAjustees(repartitions = {}, calcul = null, userId = null) {
    let nombre = 0;
    (calcul?.participants ?? []).forEach((p) => {
        if (userId !== null && p.user_id !== userId) return;
        (p.lignes ?? []).forEach((ligne) => {
            if (!estNeembaSeul(partsDeLigne(ligne, repartitions, p.user_id))) nombre += 1;
        });
    });

    return nombre;
}

/** Part envoyée au serveur */
export function partPourServeur(part) {
    return {
        payeur: part.payeur === 'client' ? 'client' : 'neemba',
        client_id: part.payeur === 'client' ? (part.client?.id ?? null) : null,
        ordre_reparation: part.ordre_reparation || null,
        code_analytique: part.code_analytique || null,
        pourcentage: Number(part.pourcentage || 0),
        commentaire: part.commentaire?.trim() ? part.commentaire.trim() : null,
    };
}

/** Spec v2.3 : un client choisi sur un OR rejoint les clients de la mission */
export function clientsAvecCeuxDesOr(clients = [], ordres = []) {
    const liste = [...clients];
    ordres.forEach((or) => {
        if (or.client && !liste.some((c) => c.id === or.client.id)) liste.push(or.client);
    });

    return liste;
}

/** Participant ajouté depuis la recherche (une seule fois, dans la limite du paramètre) */
export function ajouterParticipant(liste = [], employe, maximum = 10) {
    if (!employe || liste.some((p) => p.user_id === employe.user_id)) return { liste, erreur: null };
    if (liste.length >= maximum) return { liste, erreur: 'MSG-APP-014' };

    return {
        liste: [...liste, {
            user_id: employe.user_id,
            nom: employe.nom,
            matricule: employe.matricule ?? null,
            service: employe.service ?? null,
            statut_cadre: employe.statut_cadre ?? null,
            numero_om: employe.numero_om ?? null,
            base_vie: false,
            hebergement_facture: null,
        }],
        erreur: null,
    };
}

/** Données envoyées à l'enregistrement du brouillon */
export function donneesOdm(form) {
    return {
        type: form.type,
        technique: Boolean(form.technique),
        site: form.site || null,
        service: form.service || null,
        code_analytique: form.code_analytique || null,
        but: form.but ?? '',
        /* Spec v2.3, §6.5 : clients du référentiel, envoyés par identifiant */
        clients: (form.clients ?? []).map((c) => (typeof c === 'object' ? c.id : c)),
        destinations: form.destinations ?? [],
        vehicule: form.vehicule ?? '',
        /* Spec v2.4, RG-M12-42 : besoin carburant */
        immatriculation: form.immatriculation ?? '',
        itineraire: form.itineraire ?? '',
        /* Spec v2.4, Q76 : urgence de l'ODM (code SMS au visa) ; RG-M12-39 : OR à régulariser */
        niveau_urgence: form.niveau_urgence || 'normale',
        motif_urgence: form.niveau_urgence && form.niveau_urgence !== 'normale' ? (form.motif_urgence || null) : null,
        or_a_regulariser: Boolean(form.technique) && !(form.ordres_reparation ?? []).length && Boolean(form.or_a_regulariser),
        date_depart: form.date_depart || null,
        date_retour_prevue: form.date_retour_prevue || null,
        motif_depart_passe: form.motif_depart_passe ?? '',
        hebergement_exterieur: form.type === 'exterieur' ? (form.hebergement_exterieur || null) : null,
        reference_billet: form.reference_billet ?? '',
        participants: (form.participants ?? []).map((p) => ({
            user_id: p.user_id,
            base_vie: form.type === 'interieur' ? Boolean(p.base_vie) : false,
            hebergement_facture: p.hebergement_facture === '' || p.hebergement_facture === undefined ? null : p.hebergement_facture,
            /* RG-M12-31 : parts des lignes modifiées à l'écran */
            ...(form.repartitions?.[p.user_id] ? {
                lignes: Object.fromEntries(Object.entries(form.repartitions[p.user_id]).map(([element, parts]) => [element, { parts: parts.map(partPourServeur) }])),
            } : {}),
        })),
        ordres_reparation: (form.ordres_reparation ?? []).map((or) => ({ numero: or.numero, type: or.type, client_id: or.client?.id ?? or.client_id ?? null })),
    };
}

/** Erreurs de soumission (format §5.7) regroupées par champ : premier message de chaque champ */
export function erreursOdmParChamp(erreurs = []) {
    return erreurs.reduce((acc, e) => {
        const champ = e.champ ?? 'general';
        if (!acc[champ]) acc[champ] = [];
        acc[champ].push(e.message);
        return acc;
    }, {});
}

export const STATUTS_CADRE = { cadre: 'Cadre', non_cadre: 'Non-cadre' };
