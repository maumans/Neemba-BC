/**
 * Catalogue des messages de la SFD (MSG-BC-xxx…), partagé avec le serveur : lang/fr.json.
 *
 * Les variables du texte (:plafond, :numero…) sont remplacées par les valeurs fournies,
 * formatées selon §1.4 : nombres avec séparateur de milliers, « date » en JJ/MM/AAAA.
 *
 *   msg('MSG-BC-012', { plafond: 20000000, caisse: 'caisse principale Conakry' })
 */
import catalogue from '../../../lang/fr.json';
import { formaterDate, formaterNombre } from './format';

function formaterValeur(nom, valeur) {
    if (typeof valeur === 'number') return formaterNombre(valeur);
    if (nom === 'date') return formaterDate(valeur);
    return String(valeur ?? '');
}

/** Texte du message `cle`, variables remplacées (la clé elle-même si elle est inconnue) */
export function msg(cle, valeurs = {}) {
    let texte = catalogue[cle] ?? cle;

    /* Les noms les plus longs d'abord, comme Laravel (:montant avant :mont…) */
    Object.keys(valeurs)
        .sort((a, b) => b.length - a.length)
        .forEach((nom) => {
            texte = texte.split(`:${nom}`).join(formaterValeur(nom, valeurs[nom]));
        });

    return texte;
}

/** Texte d'une erreur renvoyée par le serveur au format { message_cle, valeurs } (SFD §5.7) */
export function msgErreur(erreur) {
    return erreur?.message_cle ? msg(erreur.message_cle, erreur.valeurs ?? {}) : (erreur?.message ?? '');
}
