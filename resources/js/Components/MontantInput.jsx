/**
 * Champ de saisie de montant avec formatage visuel inline EN TEMPS RÉEL
 *
 * Affiche le montant formaté (ex: 5 000 000) directement dans l'input
 * pendant la saisie, tout en stockant la valeur numérique brute.
 */
import { useCallback } from 'react';
import { Input } from '@/Components/ui/input';
import { formaterNombre } from '@/utils/nombreEnLettres';

/**
 * Extraire uniquement les chiffres d'une chaîne
 */
function extraireChiffres(valeur) {
    return valeur.replace(/[^0-9]/g, '');
}

/**
 * @param {Object} props
 * @param {string|number} props.value - Valeur numérique brute
 * @param {function} props.onChange - Callback avec la valeur numérique brute (string)
 * @param {string} [props.devise='GNF'] - Devise affichée en suffixe
 * @param {number} [props.max] - Valeur maximum
 * @param {string} [props.className]
 * @param {string} [props.id]
 * @param {string} [props.placeholder]
 * @param {boolean} [props.required]
 * @param {boolean} [props.disabled]
 */
export default function MontantInput({
    value,
    onChange,
    devise = 'GNF',
    max,
    className = '',
    id,
    placeholder = '0',
    required = false,
    disabled = false,
    ...rest
}) {
    const valeurBrute = value ? String(value) : '';
    const valeurFormatee = valeurBrute ? formaterNombre(valeurBrute) : '';

    const handleChange = useCallback((e) => {
        const chiffres = extraireChiffres(e.target.value);
        if (max && chiffres && parseInt(chiffres) > max) return;
        onChange(chiffres);
    }, [onChange, max]);

    return (
        <div className="relative">
            <Input
                id={id}
                type="text"
                inputMode="numeric"
                value={valeurFormatee}
                onChange={handleChange}
                placeholder={placeholder}
                className={`${className} pr-14`}
                required={required}
                disabled={disabled}
                autoComplete="off"
                {...rest}
            />
            {devise && (
                <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">
                    {devise}
                </span>
            )}
        </div>
    );
}
