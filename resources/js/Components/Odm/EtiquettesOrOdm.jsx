/**
 * OR liés à un ODM (spec v2.2 §7.3) : n° de 8 chiffres commençant par 110, type VENTE ou GARANTIE.
 * Obligatoire pour une mission technique (RG-M12-02, MSG-M12-01).
 */
import { useState } from 'react';
import { X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ajouterOrOdm } from '@/utils/odm';
import { msg } from '@/utils/messages';

export default function EtiquettesOrOdm({ valeurs = [], onChange, typesOr = {}, erreur }) {
    const [saisie, setSaisie] = useState('');
    const [type, setType] = useState('vente');
    const [erreurSaisie, setErreurSaisie] = useState(null);

    const ajouter = () => {
        const { liste, erreur: cle } = ajouterOrOdm(valeurs, saisie, type);
        setErreurSaisie(cle ? msg(cle) : null);
        if (!cle) {
            onChange(liste);
            setSaisie('');
        }
    };

    return (
        <div className="space-y-2">
            {valeurs.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {valeurs.map((or) => (
                        <span key={or.numero} className="inline-flex items-center gap-1 rounded bg-marine-50 px-2 py-0.5 text-xs font-medium text-marine-800">
                            OR {or.numero} · {typesOr[or.type] ?? or.type}
                            <button type="button" onClick={() => onChange(valeurs.filter((v) => v.numero !== or.numero))} aria-label={`Retirer l'OR ${or.numero}`}>
                                <X className="h-3 w-3" />
                            </button>
                        </span>
                    ))}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    id="champ-ordres_reparation"
                    value={saisie}
                    inputMode="numeric"
                    maxLength={9}
                    onChange={(e) => {
                        setSaisie(e.target.value.replace(/[^\d\s]/g, ''));
                        setErreurSaisie(null);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            ajouter();
                        }
                    }}
                    placeholder="Ex. 11022219"
                    className="w-36"
                    aria-invalid={Boolean(erreurSaisie ?? erreur)}
                />
                <select
                    value={type}
                    onChange={(e) => setType(e.target.value)}
                    className="h-9 rounded-md border border-input bg-white px-2 text-sm"
                    aria-label="Type d'OR"
                >
                    {Object.entries(typesOr).map(([valeur, libelle]) => (
                        <option key={valeur} value={valeur}>{libelle}</option>
                    ))}
                </select>
                <Button type="button" variant="outline" size="sm" onClick={ajouter} disabled={saisie.trim() === ''}>
                    Ajouter l'OR
                </Button>
            </div>
            {(erreurSaisie ?? erreur) && <p className="text-sm text-red-600" role="alert">{erreurSaisie ?? erreur}</p>}
        </div>
    );
}
