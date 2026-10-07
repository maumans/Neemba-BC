/**
 * Montant en lettres (RG-BC-08, ANO-01).
 *
 * Aperçu à l'écran pendant la frappe : la valeur enregistrée est toujours recalculée
 * par le serveur (App\Support\MontantEnLettres), avec les mêmes règles :
 * - trait d'union sous cent (« quatre-vingt-deux »), « et » pour 21, 31… 71 ;
 * - « cent » et « vingt » prennent un s quand ils terminent le nombre ou précèdent
 *   million(s) / milliard(s), jamais devant « mille » (« deux cent mille », « deux cents millions ») ;
 * - « de » quand le montant se termine par million(s) / milliard(s) (« un million de francs guinéens »).
 * Cas de référence : tests/fixtures/montants_lettres.json.
 */

const UNITES = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
    'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

const DIZAINES = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

/**
 * Nombre de 1 à 999 en lettres.
 * @param {number} n
 * @param {boolean} pluriel - false devant « mille » (cent et vingt restent invariables)
 */
function centaine(n, pluriel) {
    const centaines = Math.floor(n / 100);
    const reste = n % 100;
    const mots = [];

    if (centaines > 0) {
        const cent = centaines === 1 ? 'cent' : `${UNITES[centaines]} cent`;
        mots.push(reste === 0 && centaines > 1 && pluriel ? `${cent}s` : cent);
    }

    if (reste > 0) {
        mots.push(dizaine(reste, pluriel));
    }

    return mots.join(' ');
}

/** Nombre de 1 à 99 en lettres */
function dizaine(n, pluriel) {
    if (n < 20) return UNITES[n];

    const rang = Math.floor(n / 10);
    const unite = n % 10;

    /* 70-79 et 90-99 : soixante-dix…, quatre-vingt-dix… */
    if (rang === 7 || rang === 9) {
        const base = DIZAINES[rang];
        const sousNombre = n - (rang === 7 ? 60 : 80);
        return sousNombre === 11 && rang === 7 ? `${base} et onze` : `${base}-${UNITES[sousNombre]}`;
    }

    if (unite === 0) {
        return rang === 8 && pluriel ? 'quatre-vingts' : DIZAINES[rang];
    }

    return unite === 1 && rang !== 8
        ? `${DIZAINES[rang]} et un`
        : `${DIZAINES[rang]}-${UNITES[unite]}`;
}

/**
 * Nombre entier positif en lettres (sans devise).
 * @param {number} n
 */
export function nombreEnMots(n) {
    if (n === 0) return 'zéro';

    const milliards = Math.floor(n / 1_000_000_000);
    const millions = Math.floor((n % 1_000_000_000) / 1_000_000);
    const milliers = Math.floor((n % 1_000_000) / 1_000);
    const unites = n % 1_000;
    const mots = [];

    if (milliards > 0) mots.push(milliards === 1 ? 'un milliard' : `${centaine(milliards, true)} milliards`);
    if (millions > 0) mots.push(millions === 1 ? 'un million' : `${centaine(millions, true)} millions`);
    if (milliers > 0) mots.push(milliers === 1 ? 'mille' : `${centaine(milliers, false)} mille`);
    if (unites > 0) mots.push(centaine(unites, true));

    return mots.join(' ');
}

/**
 * Montant en GNF en lettres : « un million deux cent mille francs guinéens ».
 * Renvoie '' si la valeur n'est pas un nombre.
 * @param {number|string} montant
 */
export function nombreEnLettres(montant) {
    if (montant === null || montant === undefined || montant === '') return '';
    const n = Math.floor(Math.abs(Number.parseFloat(montant)));
    if (Number.isNaN(n)) return '';

    const mots = nombreEnMots(n);
    const de = n >= 1_000_000 && n % 1_000_000 === 0 ? ' de' : '';
    const devise = n < 2 ? 'franc guinéen' : 'francs guinéens';

    return `${mots}${de} ${devise}`;
}

/* Formatage des nombres et montants : utilitaire commun (SFD §1.4) */
export { formaterNombre, formaterMontant } from './format';
