import { describe, expect, it } from 'vitest';
import {
    ajouterOr,
    champsDeLaCategorie,
    codesDuService,
    compteurMotif,
    dateMoinsJours,
    erreurFichier,
    erreursParChamp,
    especesAutorisees,
    etapeDuChamp,
    libelleDansUnePhrase,
    premierChampEnErreur,
    typePieceParDefaut,
    visaDpRequis,
} from '@/utils/assistant';

const Mo = 1024 * 1024;

describe('assistant — étapes et erreurs', () => {
    it('retrouve l\'étape d\'un champ', () => {
        expect(etapeDuChamp('code_analytique')).toBe(1);
        expect(etapeDuChamp('telephone_beneficiaire')).toBe(2);
        expect(etapeDuChamp('references_or.0')).toBe(3);
        expect(etapeDuChamp('pieces')).toBe(4);
        expect(etapeDuChamp('inconnu')).toBeNull();
    });

    it('regroupe les erreurs par champ et garde le premier message', () => {
        expect(erreursParChamp({ 'references_or.1': ['Un numéro d\'OR comporte 8 chiffres (ex. 11022219).'], motif: ['A', 'B'] }))
            .toEqual({ references_or: 'Un numéro d\'OR comporte 8 chiffres (ex. 11022219).', motif: 'A' });
    });

    it('place le curseur sur le premier champ en erreur dans l\'ordre de l\'écran', () => {
        expect(premierChampEnErreur({ montant: ['x'], motif: ['y'] }, 3)).toBe('motif');
        expect(premierChampEnErreur({}, 3)).toBeNull();
    });
});

describe('assistant — dépense', () => {
    it('RG-BC-11 : espèces refusées au-delà du plafond, sans limite s\'il n\'y a pas de plafond', () => {
        expect(especesAutorisees(20000000, 20000000)).toBe(true);
        expect(especesAutorisees(23500000, 20000000)).toBe(false);
        expect(especesAutorisees(1200000, 1000000)).toBe(false);
        expect(especesAutorisees(99000000, null)).toBe(true);
        expect(especesAutorisees('', 1000000)).toBe(true);
    });

    it('RG-BC-09 : visa du Directeur Pays strictement au-delà du seuil', () => {
        expect(visaDpRequis(1500000, 1500000)).toBe(false);
        expect(visaDpRequis(1500001, 1500000)).toBe(true);
        expect(visaDpRequis('', 1500000)).toBe(false);
    });

    it('§5.4.3 : la catégorie affiche les champs véhicule et OR', () => {
        expect(champsDeLaCategorie({ vehicule_obligatoire: true, vehicule_affiche: true, or_affiche: true }))
            .toEqual({ vehicule: true, vehiculeObligatoire: true, or: true });
        expect(champsDeLaCategorie({ vehicule_obligatoire: false, vehicule_affiche: true, or_affiche: false }))
            .toEqual({ vehicule: true, vehiculeObligatoire: false, or: false });
        expect(champsDeLaCategorie(null)).toEqual({ vehicule: false, vehiculeObligatoire: false, or: false });
    });

    it('RG-BC-04 : codes du service, sinon tous les codes', () => {
        const codes = [{ code: 'ADAZZZ', service_id: 1 }, { code: 'LOCZZZ', service_id: 2 }, { code: 'GENZZZ', service_id: null }];
        expect(codesDuService(codes, 1).map((c) => c.code)).toEqual(['ADAZZZ']);
        expect(codesDuService(codes, 9)).toHaveLength(3);
        expect(codesDuService(codes, null)).toHaveLength(3);
    });

    it('RG-BC-13 : un OR fait 8 chiffres et n\'est ajouté qu\'une fois', () => {
        expect(ajouterOr([], '1102221')).toEqual({ liste: [], erreur: 'MSG-BC-014' });
        expect(ajouterOr([], '1102 2204')).toEqual({ liste: ['11022204'], erreur: null });
        expect(ajouterOr(['11022204'], '11022204')).toEqual({ liste: ['11022204'], erreur: null });
        expect(ajouterOr(['11022204'], '')).toEqual({ liste: ['11022204'], erreur: null });
    });

    it('compte les caractères du motif (n/10 min puis n/200)', () => {
        expect(compteurMotif('Achat')).toBe('5/10 min');
        expect(compteurMotif('Achat de gasoil')).toBe('15/200');
    });

    it('calcule la borne de la date de retour de mission (aujourd\'hui − 30 j)', () => {
        expect(dateMoinsJours('2026-10-07', 30)).toBe('2026-09-07');
        expect(dateMoinsJours('2026-03-01', 1)).toBe('2026-02-28');
    });

    it('met le libellé d\'une caisse en minuscule dans une phrase', () => {
        expect(libelleDansUnePhrase('Caisse principale Conakry')).toBe('caisse principale Conakry');
    });
});

describe('assistant — pièces', () => {
    it('propose « Ticket carburant » pour la catégorie Carburant', () => {
        expect(typePieceParDefaut('carburant')).toBe('recu_carburant');
        expect(typePieceParDefaut('fournitures')).toBe('');
    });

    it('RG-BC-17 : formats PDF, JPG, PNG ; 10 Mo par fichier ; 20 fichiers et 50 Mo par bon', () => {
        expect(erreurFichier({ name: 'facture.pdf', type: 'application/pdf', size: 2 * Mo })).toBeNull();
        expect(erreurFichier({ name: 'ticket.JPG', type: 'image/jpeg', size: Mo })).toBeNull();
        expect(erreurFichier({ name: 'note.docx', type: 'application/msword', size: Mo })).toBe('MSG-BC-021');
        expect(erreurFichier({ name: 'scan.pdf', type: 'application/pdf', size: 11 * Mo })).toBe('MSG-BC-021');

        const vingt = Array.from({ length: 20 }, () => ({ taille: Mo }));
        expect(erreurFichier({ name: 'recu.png', type: 'image/png', size: Mo }, vingt)).toBe('MSG-APP-003');

        const lourdes = Array.from({ length: 5 }, () => ({ taille: 9.5 * Mo }));
        expect(erreurFichier({ name: 'recu.png', type: 'image/png', size: 3 * Mo }, lourdes)).toBe('MSG-APP-003');
    });
});
