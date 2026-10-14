import { describe, expect, it } from 'vitest';
import { calculsJustification } from '@/utils/justification';

/* Lot E (RG-M07-03) : calculs de l'Annexe 3, identiques à JustifierBp::calculs */
describe('calculsJustification', () => {
    it('porte l\'écart non retourné dans le restant à justifier', () => {
        expect(calculsJustification(1000000, [{ montant: '600000' }, { montant: 200000 }], '')).toEqual({
            justifie: 800000, a_retourner: 0, restant: 200000, trop_justifie: 0,
        });
    });

    it('limite le retour en caisse à l\'écart', () => {
        expect(calculsJustification(1000000, [{ montant: 800000 }], 500000)).toEqual({
            justifie: 800000, a_retourner: 200000, restant: 0, trop_justifie: 0,
        });
    });

    it('calcule le trop justifié, versé par un BD de complément', () => {
        expect(calculsJustification(500000, [{ montant: 650000 }, { montant: '' }], 100000)).toEqual({
            justifie: 650000, a_retourner: 0, restant: 0, trop_justifie: 150000,
        });
    });
});
