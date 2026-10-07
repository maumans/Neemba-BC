import { describe, expect, it } from 'vitest';
import { infosStatut, STATUTS } from '@/utils/statuts';

/* Pastilles de statut (SFD §1.4) */
describe('infosStatut', () => {
    it('applique les couleurs de la SFD', () => {
        expect(infosStatut('BROUILLON').variante).toBe('statut_gris');
        expect(infosStatut('EN_ATTENTE_CDG')).toEqual({ libelle: 'En validation · CDG', variante: 'statut_bleu' });
        expect(infosStatut('APPROUVE').variante).toBe('statut_vert_clair');
        expect(infosStatut('PAYE').variante).toBe('statut_vert');
        expect(infosStatut('REJETE').variante).toBe('statut_rouge');
        expect(infosStatut('EN_ATTENTE_REGULARISATION').variante).toBe('statut_orange');
        expect(infosStatut('ANNULE')).toEqual({ libelle: 'Annulé', variante: 'statut_annule' });
    });

    it('couvre tous les statuts du serveur', () => {
        const serveur = ['BROUILLON', 'EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP',
            'APPROUVE', 'PAYE', 'REJETE', 'EN_ATTENTE_REGULARISATION', 'REGULARISE', 'ARCHIVE', 'ANNULE'];
        expect(Object.keys(STATUTS).sort()).toEqual([...serveur].sort());
    });

    it('affiche un statut inconnu tel quel', () => {
        expect(infosStatut('XYZ')).toEqual({ libelle: 'XYZ', variante: 'statut_gris' });
    });
});
