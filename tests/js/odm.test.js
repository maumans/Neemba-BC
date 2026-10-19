import { describe, expect, it } from 'vitest';
import {
    ajouterEtiquette,
    ajouterOrOdm,
    ajouterParticipant,
    codesDuServiceOdm,
    departPasse,
    donneesOdm,
    erreursOdmParChamp,
    estNumeroOrOdm,
    infosStatutOdm,
    joursDeMission,
    natureTechniqueParDefaut,
    retourAvantDepart,
    appliquerRepartition,
    avertissementPart,
    choisirOrDeLaPart,
    clientsAvecCeuxDesOr,
    estNeembaSeul,
    lignesAjustees,
    nouvellePartClient,
    PART_NEEMBA,
    partsDeLigne,
    pourcentageDe,
    repartirMontant,
    resteARepartir,
} from '../../resources/js/utils/odm';

describe('Ordres de mission — saisie (M12-2)', () => {
    it('vérifie le format des OR : 8 chiffres commençant par 110 (MSG-M03-07)', () => {
        expect(estNumeroOrOdm('11022219')).toBe(true);
        expect(estNumeroOrOdm('110 22219')).toBe(true);
        expect(estNumeroOrOdm('12022219')).toBe(false);
        expect(estNumeroOrOdm('1102221')).toBe(false);
        expect(ajouterOrOdm([], '12345678').erreur).toBe('MSG-M03-07');
        expect(ajouterOrOdm([], '11022219', 'garantie').liste).toEqual([{ numero: '11022219', type: 'garantie' }]);
        expect(ajouterOrOdm([{ numero: '11022219', type: 'vente' }], '11022219').liste).toHaveLength(1);
    });

    it('ajoute destinations et clients en étiquettes, sans doublon', () => {
        let liste = ajouterEtiquette([], '  Kouroussa ');
        liste = ajouterEtiquette(liste, 'kouroussa');
        liste = ajouterEtiquette(liste, 'Siguiri');
        expect(liste).toEqual(['Kouroussa', 'Siguiri']);
        expect(ajouterEtiquette(liste, '   ')).toEqual(liste);
    });

    it('contrôle les dates (RG-M12-06) et compte les jours comme le serveur', () => {
        expect(departPasse('2026-10-07', '2026-10-08')).toBe(true);
        expect(departPasse('2026-10-08', '2026-10-08')).toBe(false);
        expect(retourAvantDepart('2026-09-26', '2026-09-22')).toBe(true);
        expect(retourAvantDepart('2026-09-22', '2026-09-22')).toBe(false);
        expect(joursDeMission('2026-09-22', '2026-09-26')).toBe(5);      // annexe B.1
        expect(joursDeMission('2026-09-27', '2026-10-03')).toBe(7);      // annexe B.3
        expect(joursDeMission('2026-09-26', '2026-09-22')).toBe(0);
    });

    it('pré-coche la nature technique pour Technique et Aftermarket (PO-06) et filtre les codes du service', () => {
        const services = [{ id: 6, nom: 'Technique', technique: true }, { id: 9, nom: 'Logistique', technique: false }];
        expect(natureTechniqueParDefaut(services, 'Technique')).toBe(true);
        expect(natureTechniqueParDefaut(services, 'Logistique')).toBe(false);
        const codes = [{ code: 'TEC', service_id: 6 }, { code: 'LOG', service_id: 9 }];
        expect(codesDuServiceOdm(codes, services, 'Technique')).toEqual([{ code: 'TEC', service_id: 6 }]);
        expect(codesDuServiceOdm(codes, services, 'Inconnu')).toEqual(codes);
    });

    it('ajoute un participant une seule fois, dans la limite du paramètre (RG-M12-04)', () => {
        const employe = { user_id: 3, nom: 'BAH Thierno', service: 'Technique', statut_cadre: 'non_cadre', numero_om: '622334455' };
        let { liste } = ajouterParticipant([], employe, 2);
        ({ liste } = ajouterParticipant(liste, employe, 2));
        expect(liste).toHaveLength(1);
        expect(liste[0]).toMatchObject({ user_id: 3, base_vie: false, numero_om: '622334455' });
        ({ liste } = ajouterParticipant(liste, { user_id: 4, nom: 'BARRY Yacouba' }, 2));
        expect(ajouterParticipant(liste, { user_id: 5, nom: 'DIALLO Amadou' }, 2).erreur).toBe('MSG-APP-014');
    });

    it('prépare les données du brouillon : base vie seulement à l\'intérieur, pas de mode d\'hébergement', () => {
        const form = {
            type: 'interieur', technique: 1, service: 'Technique', participants: [{ user_id: 3, base_vie: true, hebergement_facture: '' }],
            hebergement_exterieur: 'filiale', ordres_reparation: [{ numero: '11022219', type: 'vente', autre: 'x' }],
        };
        const donnees = donneesOdm(form);
        expect(donnees.technique).toBe(true);
        expect(donnees.hebergement_exterieur).toBeNull();
        expect(donnees.participants).toEqual([{ user_id: 3, base_vie: true, hebergement_facture: null }]);
        expect(donnees.ordres_reparation).toEqual([{ numero: '11022219', type: 'vente', client_id: null }]);
        expect(donneesOdm({ ...form, type: 'exterieur' }).participants[0].base_vie).toBe(false);
    });

    it("envoie l'urgence, l'OR à régulariser et le besoin carburant (spec v2.4)", () => {
        const base = { type: 'interieur', technique: true, participants: [], ordres_reparation: [] };
        const donnees = donneesOdm({ ...base, niveau_urgence: 'urgente', motif_urgence: 'Panne machine', or_a_regulariser: true,
            immatriculation: 'RC 1234 A', itineraire: 'Conakry – Kindia' });
        expect([donnees.niveau_urgence, donnees.motif_urgence, donnees.or_a_regulariser]).toEqual(['urgente', 'Panne machine', true]);
        expect([donnees.immatriculation, donnees.itineraire]).toEqual(['RC 1234 A', 'Conakry – Kindia']);
        expect(donneesOdm({ ...base, motif_urgence: 'Panne machine' })).toMatchObject({ niveau_urgence: 'normale', motif_urgence: null });
        expect(donneesOdm({ ...base, or_a_regulariser: true, ordres_reparation: [{ numero: '11022219', type: 'vente' }] }).or_a_regulariser).toBe(false);
        expect(donneesOdm({ ...base, technique: false, or_a_regulariser: true }).or_a_regulariser).toBe(false);
    });

    it('regroupe les erreurs de soumission par champ et donne la pastille du statut', () => {
        const erreurs = [
            { champ: 'participants', message: 'A' }, { champ: 'but', message: 'B' }, { champ: 'participants', message: 'C' }, { message: 'D' },
        ];
        expect(erreursOdmParChamp(erreurs)).toEqual({ participants: ['A', 'C'], but: ['B'], general: ['D'] });
        expect(infosStatutOdm('BONS_GENERES').libelle).toBe('Bons générés');
        expect(infosStatutOdm('INCONNU').variante).toBe('statut_gris');
    });
});

describe('Ordres de mission — lignes de frais et parts (spec v2.3)', () => {
    const smd = { id: 7, code: 'SMD', raison_sociale: 'Société Minière de Dinguiraye' };
    const cbg = { id: 9, code: 'CBG', raison_sociale: 'Compagnie des Bauxites de Guinée' };
    const ordres = [{ numero: '11022219', type: 'vente', client: smd }, { numero: '11022220', type: 'garantie', client: null }];
    const calcul = {
        participants: [
            { user_id: 3, lignes: [{ element: 'indemnite', montant: 1250000, parts: [] }, { element: 'hebergement', montant: 2000000, parts: [] }] },
            { user_id: 4, lignes: [{ element: 'indemnite', montant: 1250000, parts: [{ payeur: 'client', client: smd, ordre_reparation: '11022219', pourcentage: 100 }] }] },
        ],
    };

    it('part par défaut : Neemba 100 %, affichée en compact', () => {
        expect(partsDeLigne(calcul.participants[0].lignes[0], {}, 3)).toEqual([PART_NEEMBA]);
        expect(estNeembaSeul([PART_NEEMBA])).toBe(true);
        expect(estNeembaSeul([{ ...PART_NEEMBA, pourcentage: 60 }, { ...PART_NEEMBA, payeur: 'client', pourcentage: 40 }])).toBe(false);
        /* Parts du serveur, puis parts modifiées à l'écran */
        expect(partsDeLigne(calcul.participants[1].lignes[0], {}, 4)[0].client).toEqual(smd);
        expect(partsDeLigne(calcul.participants[1].lignes[0], { 4: { indemnite: [PART_NEEMBA] } }, 4)).toEqual([PART_NEEMBA]);
    });

    it('montants des parts comme le serveur, reste à répartir et saisie en GNF', () => {
        expect(repartirMontant(1250000, [60, 40])).toEqual([750000, 500000]);
        expect(repartirMontant(1000000, [33.3333, 33.3333, 33.3334]).reduce((a, b) => a + b, 0)).toBe(1000000);
        expect(repartirMontant(null, [100])).toEqual([null]);
        expect(resteARepartir([{ pourcentage: 60 }, { pourcentage: 30 }])).toBe(10);
        expect(resteARepartir([{ pourcentage: 60 }, { pourcentage: 40 }])).toBe(0);
        expect(pourcentageDe(400000, 1250000)).toBe(32);
    });

    it('client de l\'OR proposé, avertissements RG-M12-33', () => {
        const part = { payeur: 'client', client: null, ordre_reparation: null, pourcentage: 40 };
        expect(avertissementPart(part, ordres)).toBe('Part client sans OR.');
        const avecOr = choisirOrDeLaPart(part, '11022219', ordres);
        expect(avecOr.client).toEqual(smd);                       // proposé par défaut
        expect(avertissementPart(avecOr, ordres)).toBeNull();
        expect(avertissementPart({ ...avecOr, client: cbg }, ordres)).toBe("Client différent de celui de l'OR 11022219.");
        expect(avertissementPart({ ...PART_NEEMBA }, ordres)).toBeNull();
        /* Nouvelle part client : le reste, le client et l'OR proposés */
        expect(nouvellePartClient([{ pourcentage: 70 }], ordres, [cbg])).toMatchObject({ payeur: 'client', client: smd, ordre_reparation: '11022219', pourcentage: 30 });
    });

    it('saisie rapide sur un participant ou tout l\'ODM (RG-M12-32), lignes déjà ajustées comptées', () => {
        const modele = [{ ...PART_NEEMBA, pourcentage: 50 }, { payeur: 'client', client: smd, ordre_reparation: '11022219', pourcentage: 50 }];
        const pourUn = appliquerRepartition({}, calcul, modele, 3);
        expect(Object.keys(pourUn)).toEqual(['3']);
        expect(pourUn[3].hebergement[1].client).toEqual(smd);
        const pourTous = appliquerRepartition({}, calcul, modele, null);
        expect(Object.keys(pourTous)).toEqual(['3', '4']);
        expect(lignesAjustees({}, calcul, null)).toBe(1);         // la ligne déjà au client
        expect(lignesAjustees(pourTous, calcul, 3)).toBe(2);
    });

    it('envoie les parts modifiées au serveur, clients par identifiant ; un client d\'OR rejoint ceux de l\'ODM', () => {
        const donnees = donneesOdm({
            type: 'interieur', participants: [{ user_id: 3, base_vie: false }], clients: [cbg], ordres_reparation: ordres,
            repartitions: { 3: { hebergement: [{ payeur: 'client', client: smd, ordre_reparation: '11022219', code_analytique: null, pourcentage: 100, commentaire: ' ' }] } },
        });
        expect(donnees.clients).toEqual([9]);
        expect(donnees.ordres_reparation[0]).toEqual({ numero: '11022219', type: 'vente', client_id: 7 });
        expect(donnees.participants[0].lignes.hebergement.parts[0]).toEqual({
            payeur: 'client', client_id: 7, ordre_reparation: '11022219', code_analytique: null, pourcentage: 100, commentaire: null,
        });
        expect(clientsAvecCeuxDesOr([cbg], ordres).map((c) => c.id)).toEqual([9, 7]);
    });
});
