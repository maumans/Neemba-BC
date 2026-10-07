import { describe, expect, it } from 'vitest';
import { nombreEnLettres } from '@/utils/nombreEnLettres';
import fixture from '../fixtures/montants_lettres.json';

/* RG-BC-08, ANO-01, TC-BC-007 — mêmes cas que tests/Unit/MontantEnLettresTest.php */
describe('nombreEnLettres', () => {
    it.each(fixture.cas)('%i → %s', (montant, attendu) => {
        expect(nombreEnLettres(montant)).toBe(attendu);
    });

    it('accepte une chaîne et ignore une saisie vide', () => {
        expect(nombreEnLettres('1200000')).toBe('un million deux cent mille francs guinéens');
        expect(nombreEnLettres('')).toBe('');
        expect(nombreEnLettres('abc')).toBe('');
    });
});
