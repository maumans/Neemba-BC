/**
 * Conventions d'affichage communes (SFD §1.4).
 *
 * - Montants : séparateur de milliers espace insécable, sans décimale, suivi de « GNF » (1 500 000 GNF)
 * - Dates : JJ/MM/AAAA ; date-heure JJ/MM/AAAA HH:MM (fuseau Africa/Conakry)
 * - Durées : « 2 h 15 min » sous 24 h ; « 3 j 4 h » au-delà
 *
 * Même règles côté serveur : App\Support\Format.
 */

export const ESPACE_INSECABLE = ' ';
export const FUSEAU_HORAIRE = 'Africa/Conakry';

/** Nombre entier avec séparateur de milliers : 1 500 000 */
export function formaterNombre(valeur) {
    if (valeur === null || valeur === undefined || valeur === '') return '0';
    const n = Number.parseFloat(valeur);
    if (Number.isNaN(n)) return '0';

    const arrondi = Math.round(n);
    const signe = arrondi < 0 ? '-' : '';
    return signe + String(Math.abs(arrondi)).replace(/\B(?=(\d{3})+(?!\d))/g, ESPACE_INSECABLE);
}

/** Montant en GNF : 1 500 000 GNF */
export function formaterMontant(valeur) {
    return formaterNombre(valeur) + ESPACE_INSECABLE + 'GNF';
}

function enDate(valeur) {
    if (!valeur) return null;
    const date = valeur instanceof Date ? valeur : new Date(valeur);
    return Number.isNaN(date.getTime()) ? null : date;
}

function partiesDate(date, avecHeure) {
    const options = { timeZone: FUSEAU_HORAIRE, day: '2-digit', month: '2-digit', year: 'numeric' };
    if (avecHeure) Object.assign(options, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

    return Object.fromEntries(
        new Intl.DateTimeFormat('fr-FR', options).formatToParts(date).map(({ type, value }) => [type, value]),
    );
}

/** Date : 05/10/2026 (« — » si absente) */
export function formaterDate(valeur) {
    const date = enDate(valeur);
    if (!date) return '—';
    const p = partiesDate(date, false);
    return `${p.day}/${p.month}/${p.year}`;
}

/** Date et heure : 05/10/2026 14:30 (« — » si absente) */
export function formaterDateHeure(valeur) {
    const date = enDate(valeur);
    if (!date) return '—';
    const p = partiesDate(date, true);
    return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`;
}

/** Durée en minutes : « 45 min », « 2 h 15 min », « 3 j 4 h » */
export function formaterDuree(minutes) {
    if (minutes === null || minutes === undefined || Number.isNaN(Number(minutes))) return '—';
    const total = Math.max(0, Math.floor(Number(minutes)));

    if (total < 60) return `${total}${ESPACE_INSECABLE}min`;

    if (total < 24 * 60) {
        const heures = Math.floor(total / 60);
        const reste = total % 60;
        return reste
            ? `${heures}${ESPACE_INSECABLE}h ${reste}${ESPACE_INSECABLE}min`
            : `${heures}${ESPACE_INSECABLE}h`;
    }

    const jours = Math.floor(total / (24 * 60));
    const heures = Math.floor((total % (24 * 60)) / 60);
    return heures
        ? `${jours}${ESPACE_INSECABLE}j ${heures}${ESPACE_INSECABLE}h`
        : `${jours}${ESPACE_INSECABLE}j`;
}

/** Durée écoulée entre deux dates (par défaut jusqu'à maintenant), formatée */
export function formaterDureeDepuis(debut, fin = new Date()) {
    const dateDebut = enDate(debut);
    const dateFin = enDate(fin);
    if (!dateDebut || !dateFin) return '—';
    return formaterDuree((dateFin.getTime() - dateDebut.getTime()) / 60000);
}
