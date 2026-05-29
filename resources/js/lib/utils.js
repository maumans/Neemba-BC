/**
 * Utilitaires partagés pour les composants UI
 * Combinaison de clsx et tailwind-merge pour gérer les classes CSS
 */
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Fusionne les classes CSS avec résolution des conflits Tailwind
 * @param  {...any} inputs - Classes CSS à fusionner
 * @returns {string} Classes CSS fusionnées
 */
export function cn(...inputs) {
    return twMerge(clsx(inputs));
}
