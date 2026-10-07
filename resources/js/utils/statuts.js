/**
 * Statuts d'un bon de caisse : libellé et couleur de la pastille (SFD §1.4).
 *
 * Brouillon gris · En validation bleu (avec l'étape) · Approuvé vert clair · Payé vert · Rejeté rouge ·
 * En attente de régularisation orange · Régularisé vert · Annulé gris barré · Archivé gris.
 * Les variantes sont définies dans Components/ui/badge.jsx.
 */
export const STATUTS = {
    BROUILLON: { libelle: 'Brouillon', variante: 'statut_gris' },
    EN_ATTENTE_CHEF_SERVICE: { libelle: 'En validation · Chef de service', variante: 'statut_bleu' },
    EN_ATTENTE_CDG: { libelle: 'En validation · CDG', variante: 'statut_bleu' },
    EN_ATTENTE_DAF: { libelle: 'En validation · Finance', variante: 'statut_bleu' },
    EN_ATTENTE_DP: { libelle: 'En validation · Directeur Pays', variante: 'statut_bleu' },
    APPROUVE: { libelle: 'Approuvé', variante: 'statut_vert_clair' },
    PAYE: { libelle: 'Payé', variante: 'statut_vert' },
    REJETE: { libelle: 'Rejeté', variante: 'statut_rouge' },
    EN_ATTENTE_REGULARISATION: { libelle: 'En attente de régularisation', variante: 'statut_orange' },
    REGULARISE: { libelle: 'Régularisé', variante: 'statut_vert' },
    ANNULE: { libelle: 'Annulé', variante: 'statut_annule' },
    ARCHIVE: { libelle: 'Archivé', variante: 'statut_gris' },
};

/** Statuts du circuit de validation (affichés « En validation ») */
export const STATUTS_EN_VALIDATION = ['EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP'];

/** Libellé et variante de pastille d'un statut (statut inconnu : affiché tel quel, en gris) */
export function infosStatut(statut) {
    return STATUTS[statut] ?? { libelle: statut ?? '—', variante: 'statut_gris' };
}
