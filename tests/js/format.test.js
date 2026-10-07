import { describe, expect, it } from 'vitest';
import {
    formaterDate,
    formaterDateHeure,
    formaterDuree,
    formaterDureeDepuis,
    formaterMontant,
    formaterNombre,
} from '@/utils/format';

/* Conventions d'affichage (SFD §1.4), mêmes cas que tests/Unit/FormatTest.php */
const NBSP = ' ';

describe('montants', () => {
    it('sépare les milliers par un espace insécable et ajoute GNF', () => {
        expect(formaterMontant(1500000)).toBe(`1${NBSP}500${NBSP}000${NBSP}GNF`);
        expect(formaterMontant(null)).toBe(`0${NBSP}GNF`);
        expect(formaterNombre(-150000)).toBe(`-150${NBSP}000`);
        expect(formaterNombre('2376.6')).toBe(`2${NBSP}377`);
    });
});

describe('dates', () => {
    it('affiche JJ/MM/AAAA et JJ/MM/AAAA HH:MM', () => {
        expect(formaterDate('2026-10-05')).toBe('05/10/2026');
        expect(formaterDateHeure('2026-10-05T14:30:59Z')).toBe('05/10/2026 14:30');
        expect(formaterDate(null)).toBe('—');
    });
});

describe('durées', () => {
    it('affiche « 2 h 15 min » sous 24 h et « 3 j 4 h » au-delà', () => {
        expect(formaterDuree(45)).toBe(`45${NBSP}min`);
        expect(formaterDuree(135)).toBe(`2${NBSP}h 15${NBSP}min`);
        expect(formaterDuree(120)).toBe(`2${NBSP}h`);
        expect(formaterDuree(3 * 1440 + 4 * 60 + 59)).toBe(`3${NBSP}j 4${NBSP}h`);
        expect(formaterDuree(35 * 1440)).toBe(`35${NBSP}j`);
        expect(formaterDureeDepuis('2026-08-01T08:00:00Z', '2026-08-04T12:00:00Z')).toBe(`3${NBSP}j 4${NBSP}h`);
    });
});
