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
    appliquerPriseEnChargeGlobale,
    changerPriseEnCharge,
    etatPriseEnCharge,
    priseDeLigne,
    toutesLesLignes,
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
        expect(donnees.ordres_reparation).toEqual([{ numero: '11022219', type: 'vente' }]);
        expect(donneesOdm({ ...form, type: 'exterieur' }).participants[0].base_vie).toBe(false);
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

describe('Ordres de mission — prise en charge par ligne (Q49)', () => {
    const calcul = {
        participants: [
            { user_id: 3, lignes: [{ cle: 'indemnite_1' }, { cle: 'indemnite_2' }, { cle: 'hebergement' }, { cle: 'rattrapage', sans_objet: true }] },
            { user_id: 4, lignes: [{ cle: 'indemnite_1' }, { cle: 'indemnite_2' }, { cle: 'hebergement', sans_objet: true }, { cle: 'rattrapage', sans_objet: true }] },
        ],
    };
    const form = {
        prise_en_charge: 'neemba', mode_client: 'avance',
        participants: [{ user_id: 3, prises_en_charge: toutesLesLignes('neemba') }, { user_id: 4, prises_en_charge: toutesLesLignes('neemba') }],
    };

    it('traduit le choix de l\'en-tête en valeur de ligne', () => {
        expect(priseDeLigne('neemba', 'direct')).toBe('neemba');
        expect(priseDeLigne('client', 'avance')).toBe('client_avance');
        expect(priseDeLigne('client', 'direct')).toBe('client_direct');
        expect(priseDeLigne('mixte', 'direct')).toBe('neemba');
    });

    it('applique le choix de l\'en-tête à toutes les lignes de tous les participants', () => {
        const apres = appliquerPriseEnChargeGlobale(form, 'client', 'direct');
        expect(apres.prise_en_charge).toBe('client');
        expect(apres.participants.every((p) => Object.values(p.prises_en_charge).every((v) => v === 'client_direct'))).toBe(true);
        expect(form.participants[0].prises_en_charge.hebergement).toBe('neemba');   // pas de mutation
    });

    it('une ligne ajustée rend l\'en-tête « mixte » ; les lignes sans objet ne comptent pas', () => {
        const mixte = changerPriseEnCharge(form, 3, 'hebergement', 'client_avance', calcul);
        expect(mixte.prise_en_charge).toBe('mixte');
        expect(mixte.participants[0].prises_en_charge.hebergement).toBe('client_avance');

        /* L'hébergement de 4 est sans objet (base vie) : le mettre au client ne change rien à l'en-tête */
        expect(changerPriseEnCharge(form, 4, 'hebergement', 'client_direct', calcul).prise_en_charge).toBe('neemba');
    });

    it('toutes les lignes d\'un participant, puis de tous : l\'en-tête suit', () => {
        let etat = changerPriseEnCharge(form, 3, null, 'client_direct', calcul);
        expect(etat.prise_en_charge).toBe('mixte');
        etat = changerPriseEnCharge(etat, 4, null, 'client_direct', calcul);
        expect(etatPriseEnCharge(etat.participants, calcul, etat)).toEqual({ prise_en_charge: 'client', mode_client: 'direct' });
    });

    it('un nouveau participant reçoit le choix de l\'en-tête et l\'envoie au serveur', () => {
        const { liste } = ajouterParticipant([], { user_id: 7, nom: 'CAMARA Ibrahima' }, 10, 'client_avance');
        expect(liste[0].prises_en_charge.indemnite_1).toBe('client_avance');
        const donnees = donneesOdm({ ...form, type: 'interieur', mode_client: 'direct', participants: liste });
        expect(donnees.mode_client).toBe('direct');
        expect(donnees.participants[0].prises_en_charge.hebergement).toBe('client_avance');
    });
});
