/**
 * Calculs de la justification d'un BP (Annexe 3, RG-M07-03) — mêmes règles que App\Services\BonCaisse\JustifierBp::calculs.
 *
 * - justifié : somme des lignes ;
 * - à retourner en caisse : montant que la personne déclare rendre, au plus l'écart entre l'avance et le justifié ;
 * - restant à justifier : écart non couvert par les lignes ni par le retour (doit être nul pour soumettre) ;
 * - trop justifié : dépassement de l'avance, versé par un BD de complément (RG-M07-08).
 */
export function calculsJustification(avance, lignes, aRetourner) {
    const justifie = lignes.reduce((total, ligne) => total + (Math.round(Number(ligne.montant)) || 0), 0);
    const ecart = Math.max(0, Number(avance) - justifie);
    const retour = Math.min(Math.max(0, Number(aRetourner) || 0), ecart);

    return {
        justifie,
        a_retourner: retour,
        restant: ecart - retour,
        trop_justifie: Math.max(0, justifie - Number(avance)),
    };
}
