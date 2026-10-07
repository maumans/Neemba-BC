/**
 * Champ de l'assistant : libellé, astérisque si obligatoire, aide et message d'erreur.
 * `data-champ` permet de placer le curseur sur le premier champ en erreur après « Suivant ».
 */
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

export default function Champ({ champ, id, libelle, obligatoire = false, erreur, aide, className, children }) {
    return (
        <div data-champ={champ} className={className}>
            {libelle && (
                <Label htmlFor={id ?? `champ-${champ}`}>
                    {libelle}
                    {obligatoire && <span className="text-red-600"> *</span>}
                </Label>
            )}
            <div className={cn(libelle && 'mt-1', erreur && '[&_input]:border-red-500 [&_textarea]:border-red-500 [&_button[role=combobox]]:border-red-500')}>
                {children}
            </div>
            {aide && !erreur && <p className="mt-1 text-xs text-gray-500">{aide}</p>}
            {erreur && (
                <p className="mt-1 text-sm text-red-600" role="alert">
                    {erreur}
                </p>
            )}
        </div>
    );
}

/** Curseur sur le premier élément saisissable du champ (E-03.2 : « Suivant » en erreur) */
export function placerCurseur(champ) {
    const conteneur = typeof document !== 'undefined' ? document.querySelector(`[data-champ="${champ}"]`) : null;
    if (!conteneur) return;

    conteneur.scrollIntoView({ block: 'center', behavior: 'smooth' });
    conteneur.querySelector('input:not([type=hidden]):not([disabled]), textarea, button:not([disabled]), select')?.focus({ preventScroll: true });
}
