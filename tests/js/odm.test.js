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
