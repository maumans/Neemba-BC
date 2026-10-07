import { describe, expect, it } from 'vitest';
import { msg, msgErreur } from '@/utils/messages';

const NBSP = ' ';

describe('msg', () => {
    it('renvoie le texte exact du catalogue', () => {
        expect(msg('MSG-BC-001')).toBe('Ce champ est obligatoire.');
    });

    it('formate les nombres et les dates des variables', () => {
        expect(msg('MSG-BC-024', { total: 2376000, montant: 2500000, ecart: 124000 }))
            .toBe(`Le total des tickets (2${NBSP}376${NBSP}000 GNF) diffère du montant du bon (2${NBSP}500${NBSP}000 GNF) de 124${NBSP}000 GNF.`);
        expect(msg('MSG-BC-019', { numero: 'BC-2026-0011', date: '2026-10-05' }))
            .toContain('au bon BC-2026-0011 du 05/10/2026.');
    });

    it('renvoie la clé si le message est inconnu', () => {
        expect(msg('MSG-XX-999')).toBe('MSG-XX-999');
    });
});

describe('msgErreur', () => {
    it('reconstruit le texte d\'un refus serveur (SFD §5.7)', () => {
        expect(msgErreur({ message_cle: 'MSG-BC-012', valeurs: { plafond: 20000000, caisse: 'caisse principale Conakry' } }))
            .toBe(`Au-delà de 20${NBSP}000${NBSP}000 GNF, ce bon ne peut pas être payé en espèces sur la caisse principale Conakry. Choisissez Orange Money, chèque ou virement.`);
    });
});
