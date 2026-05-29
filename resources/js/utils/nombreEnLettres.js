/**
 * Convertit un nombre en toutes lettres en français
 * Supporte les montants jusqu'à 999 999 999 999
 * 
 * @param {number|string} nombre - Le nombre à convertir
 * @param {string} devise - La devise (par défaut 'francs guinéens')
 * @returns {string} Le nombre en toutes lettres
 */

const UNITES = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
    'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

const DIZAINES = ['', 'dix', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];

function convertirCentaine(n) {
    if (n === 0) return '';

    let resultat = '';

    const centaines = Math.floor(n / 100);
    const reste = n % 100;

    if (centaines > 0) {
        if (centaines === 1) {
            resultat = 'cent';
        } else {
            resultat = UNITES[centaines] + ' cent';
        }
        if (reste === 0 && centaines > 1) {
            resultat += 's';
        }
    }

    if (reste > 0) {
        if (resultat) resultat += ' ';

        if (reste < 20) {
            resultat += UNITES[reste];
        } else {
            const dizaine = Math.floor(reste / 10);
            const unite = reste % 10;

            if (dizaine === 7 || dizaine === 9) {
                /* 70-79 : soixante-dix... / 90-99 : quatre-vingt-dix... */
                const base = dizaine === 7 ? 'soixante' : 'quatre-vingt';
                const sousNombre = reste - (dizaine === 7 ? 60 : 80);
                if (sousNombre === 1 && dizaine === 7) {
                    resultat += base + ' et onze';
                } else {
                    resultat += base + '-' + UNITES[sousNombre];
                }
            } else {
                resultat += DIZAINES[dizaine];
                if (unite === 1 && dizaine !== 8) {
                    resultat += ' et un';
                } else if (unite > 0) {
                    resultat += '-' + UNITES[unite];
                } else if (dizaine === 8) {
                    resultat += 's';
                }
            }
        }
    }

    return resultat;
}

export function nombreEnLettres(nombre, devise = 'francs guinéens') {
    if (nombre === null || nombre === undefined || nombre === '') return '';

    const n = Math.floor(Math.abs(parseFloat(nombre)));

    if (isNaN(n)) return '';
    if (n === 0) return 'zéro ' + devise;

    const parties = [];

    /* Milliards */
    const milliards = Math.floor(n / 1000000000);
    if (milliards > 0) {
        if (milliards === 1) {
            parties.push('un milliard');
        } else {
            parties.push(convertirCentaine(milliards) + ' milliards');
        }
    }

    /* Millions */
    const millions = Math.floor((n % 1000000000) / 1000000);
    if (millions > 0) {
        if (millions === 1) {
            parties.push('un million');
        } else {
            parties.push(convertirCentaine(millions) + ' millions');
        }
    }

    /* Milliers */
    const milliers = Math.floor((n % 1000000) / 1000);
    if (milliers > 0) {
        if (milliers === 1) {
            parties.push('mille');
        } else {
            parties.push(convertirCentaine(milliers) + ' mille');
        }
    }

    /* Centaines */
    const centaines = n % 1000;
    if (centaines > 0) {
        parties.push(convertirCentaine(centaines));
    }

    let texte = parties.join(' ').replace(/\s+/g, ' ').trim();

    /* Première lettre en majuscule */
    texte = texte.charAt(0).toUpperCase() + texte.slice(1);

    return texte + ' ' + devise;
}

/**
 * Formate un nombre avec séparateur de milliers (format français)
 * @param {number|string} nombre
 * @returns {string}
 */
export function formaterNombre(nombre) {
    if (nombre === null || nombre === undefined || nombre === '') return '0';
    const n = parseFloat(nombre);
    if (isNaN(n)) return '0';
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(n);
}

/**
 * Formate un montant avec devise GNF
 * @param {number|string} montant
 * @returns {string}
 */
export function formaterMontant(montant) {
    if (!montant && montant !== 0) return '0 GNF';
    return formaterNombre(montant) + ' GNF';
}
