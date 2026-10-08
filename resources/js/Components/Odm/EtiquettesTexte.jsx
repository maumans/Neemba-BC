/**
 * Liste de textes saisis en étiquettes (destinations, clients d'un ODM) : Entrée ou virgule pour ajouter.
 */
import { useState } from 'react';
import { X } from 'lucide-react';
import { ajouterEtiquette } from '@/utils/odm';

export default function EtiquettesTexte({ id, valeurs = [], onChange, placeholder, desactive = false, invalide = false }) {
    const [saisie, setSaisie] = useState('');

    const valider = () => {
        const liste = ajouterEtiquette(valeurs, saisie);
        if (liste !== valeurs) onChange(liste);
        setSaisie('');
    };

    return (
        <div className={`flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border px-2 py-1 ${invalide ? 'border-red-500' : 'border-input'}`}>
            {valeurs.map((valeur) => (
                <span key={valeur} className="inline-flex items-center gap-1 rounded bg-marine-50 px-2 py-0.5 text-xs font-medium text-marine-800">
                    {valeur}
                    {!desactive && (
                        <button type="button" onClick={() => onChange(valeurs.filter((v) => v !== valeur))} aria-label={`Retirer ${valeur}`}>
                            <X className="h-3 w-3" />
                        </button>
                    )}
                </span>
            ))}
            {!desactive && (
                <input
                    id={id}
                    value={saisie}
                    onChange={(e) => setSaisie(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
                            e.preventDefault();
                            valider();
                        }
                    }}
                    onBlur={valider}
                    placeholder={valeurs.length ? '' : placeholder}
                    className="min-w-[10rem] flex-1 border-0 p-1 text-sm focus:outline-none focus:ring-0"
                    aria-invalid={invalide}
                />
            )}
        </div>
    );
}
