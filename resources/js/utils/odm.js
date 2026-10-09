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
 * Prise en charge des frais, ligne par ligne (Q49)
 * Une ligne est à la charge de Neemba, du client avec avance de Neemba (dans le bon, refacturée)
 * ou du client qui la paie directement (hors bon). L'en-tête est le défaut appliqué à toutes les lignes.
 * ------------------------------------------------------------------ */

export const LIGNES_FRAIS = ['indemnite_1', 'indemnite_2', 'hebergement', 'rattrapage', 'indemnite', 'hebergement_retour'];

export const PRISES_LIGNE = {
    neemba: 'Neemba',
    client_avance: 'Client · avancé par Neemba',
    client_direct: 'Client · payé directement',
};

/** Valeur d'une ligne pour un choix d'en-tête : « client » + mode, ou Neemba */
export function priseDeLigne(priseEnCharge, modeClient = 'avance') {
    return priseEnCharge === 'client' ? `client_${modeClient === 'direct' ? 'direct' : 'avance'}` : 'neemba';
}

/** Toutes les lignes avec la même prise en charge */
export function toutesLesLignes(prise) {
    return Object.fromEntries(LIGNES_FRAIS.map((ligne) => [ligne, prise]));
}

/**
 * En-tête déduit des lignes qui ont un montant (celles du calcul serveur, hors « sans objet ») :
 * neemba, client (avec son mode) ou mixte. Sans calcul, toutes les lignes comptent.
 */
export function etatPriseEnCharge(participants = [], calcul = null, actuel = { prise_en_charge: 'neemba', mode_client: 'avance' }) {
    const valeurs = new Set();
    participants.forEach((p) => {
        const lignesCalcul = calcul?.participants?.find((c) => c.user_id === p.user_id)?.lignes;
        const cles = lignesCalcul ? lignesCalcul.filter((l) => !l.sans_objet).map((l) => l.cle) : LIGNES_FRAIS;
        cles.forEach((cle) => valeurs.add(p.prises_en_charge?.[cle] ?? priseDeLigne(actuel.prise_en_charge, actuel.mode_client)));
    });
    if (valeurs.size === 0) return { prise_en_charge: actuel.prise_en_charge, mode_client: actuel.mode_client };
    if (valeurs.size === 1) {
        const [seule] = [...valeurs];
        if (seule === 'neemba') return { prise_en_charge: 'neemba', mode_client: actuel.mode_client };
        return { prise_en_charge: 'client', mode_client: seule === 'client_direct' ? 'direct' : 'avance' };
    }

    return { prise_en_charge: 'mixte', mode_client: actuel.mode_client };
}

/** Choix de l'en-tête appliqué à toutes les lignes de tous les participants */
export function appliquerPriseEnChargeGlobale(form, priseEnCharge, modeClient = 'avance') {
    const prise = priseDeLigne(priseEnCharge, modeClient);

    return {
        ...form,
        prise_en_charge: priseEnCharge,
        mode_client: modeClient,
        participants: (form.participants ?? []).map((p) => ({ ...p, prises_en_charge: toutesLesLignes(prise) })),
    };
}

/** Une ligne d'un participant, ou toutes ses lignes (ligne = null) ; l'en-tête est recalculé */
export function changerPriseEnCharge(form, userId, ligne, prise, calcul = null) {
    const participants = (form.participants ?? []).map((p) => {
        if (p.user_id !== userId) return p;
        const prises = ligne === null
            ? toutesLesLignes(prise)
            : { ...toutesLesLignes(priseDeLigne(form.prise_en_charge, form.mode_client)), ...(p.prises_en_charge ?? {}), [ligne]: prise };
        return { ...p, prises_en_charge: prises };
    });

    return { ...form, participants, ...etatPriseEnCharge(participants, calcul, form) };
}

/** Participant ajouté depuis la recherche (une seule fois, dans la limite du paramètre) */
export function ajouterParticipant(liste = [], employe, maximum = 10, prise = 'neemba') {
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
            prises_en_charge: toutesLesLignes(prise),
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
        clients: form.clients ?? [],
        destinations: form.destinations ?? [],
        vehicule: form.vehicule ?? '',
        date_depart: form.date_depart || null,
        date_retour_prevue: form.date_retour_prevue || null,
        motif_depart_passe: form.motif_depart_passe ?? '',
        prise_en_charge: form.prise_en_charge,
        mode_client: form.mode_client === 'direct' ? 'direct' : 'avance',
        hebergement_exterieur: form.type === 'exterieur' ? (form.hebergement_exterieur || null) : null,
        reference_billet: form.reference_billet ?? '',
        participants: (form.participants ?? []).map((p) => ({
            user_id: p.user_id,
            base_vie: form.type === 'interieur' ? Boolean(p.base_vie) : false,
            hebergement_facture: p.hebergement_facture === '' || p.hebergement_facture === undefined ? null : p.hebergement_facture,
            ...(p.prises_en_charge ? { prises_en_charge: p.prises_en_charge } : {}),
        })),
        ordres_reparation: (form.ordres_reparation ?? []).map((or) => ({ numero: or.numero, type: or.type })),
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
