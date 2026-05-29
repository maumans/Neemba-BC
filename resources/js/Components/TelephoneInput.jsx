/**
 * Champ de saisie de téléphone avec formatage automatique
 *
 * Formate automatiquement les numéros guinéens : +224 6XX XX XX XX
 * Accepte aussi les numéros internationaux.
 */
import { useCallback } from 'react';
import { Input } from '@/Components/ui/input';

/**
 * Formater un numéro de téléphone guinéen
 * Ex: 622123456 → 622 12 34 56
 * Ex: +224622123456 → +224 622 12 34 56
 */
function formaterTelephone(valeur) {
    if (!valeur) return '';
    const chiffres = valeur.replace(/[^0-9+]/g, '');

    /* Numéro guinéen avec indicatif */
    if (chiffres.startsWith('+224') || chiffres.startsWith('00224')) {
        const prefixe = chiffres.startsWith('+224') ? '+224' : '00224';
        const local = chiffres.slice(prefixe.length);
        return prefixe + ' ' + formaterLocal(local);
    }

    /* Numéro local guinéen (6xx ou 3xx) */
    if (/^[36]\d{8}$/.test(chiffres) || /^[36]\d{0,8}$/.test(chiffres)) {
        return formaterLocal(chiffres);
    }

    /* Autre format international */
    if (chiffres.startsWith('+')) {
        return chiffres;
    }

    return formaterLocal(chiffres);
}

function formaterLocal(chiffres) {
    if (!chiffres) return '';
    /* Format : XXX XX XX XX */
    const parties = [];
    if (chiffres.length >= 3) parties.push(chiffres.slice(0, 3));
    else return chiffres;

    if (chiffres.length >= 5) parties.push(chiffres.slice(3, 5));
    else if (chiffres.length > 3) parties.push(chiffres.slice(3));

    if (chiffres.length >= 7) parties.push(chiffres.slice(5, 7));
    else if (chiffres.length > 5) parties.push(chiffres.slice(5));

    if (chiffres.length >= 9) parties.push(chiffres.slice(7, 9));
    else if (chiffres.length > 7) parties.push(chiffres.slice(7));

    return parties.join(' ');
}

/**
 * @param {Object} props
 * @param {string} props.value - Valeur brute du téléphone
 * @param {function} props.onChange - Callback avec la valeur formatée
 * @param {string} [props.className]
 * @param {string} [props.id]
 * @param {string} [props.placeholder]
 */
export default function TelephoneInput({
    value,
    onChange,
    className = '',
    id,
    placeholder = '+224 6XX XX XX XX',
    ...rest
}) {
    const handleChange = useCallback((e) => {
        const brut = e.target.value.replace(/[^0-9+\s]/g, '');
        onChange(brut);
    }, [onChange]);

    return (
        <Input
            id={id}
            type="tel"
            value={formaterTelephone(value || '')}
            onChange={handleChange}
            placeholder={placeholder}
            className={className}
            autoComplete="tel"
            {...rest}
        />
    );
}
