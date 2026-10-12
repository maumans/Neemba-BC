/**
 * OR liés à un ODM (spec v2.2 §7.3) : n° de 8 chiffres commençant par 110, type VENTE ou GARANTIE.
 * Obligatoire pour une mission technique (RG-M12-02, MSG-M12-01).
 * Spec v2.3 : chaque OR peut porter un client du référentiel, proposé ensuite sur les parts (RG-M12-33).
 */
import { useState } from 'react';
import { X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ajouterOrOdm } from '@/utils/odm';
import ChoixClient from './ChoixClient';
import { msg } from '@/utils/messages';

export default function EtiquettesOrOdm({ valeurs = [], onChange, typesOr = {}, erreur, clients = [] }) {
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
                <ul className="space-y-1.5">
                    {valeurs.map((or) => (
                        <li key={or.numero} className="flex flex-wrap items-center gap-2">
                            <span className="inline-flex items-center rounded bg-marine-50 px-2 py-1 text-xs font-medium text-marine-800">
                                OR {or.numero} · {typesOr[or.type] ?? or.type}
                            </span>
                            <div className="w-56">
                                <ChoixClient compact valeur={or.client ?? null} suggestions={clients} libelle={`Client de l'OR ${or.numero}`}
                                    onChange={(client) => onChange(valeurs.map((v) => (v.numero === or.numero ? { ...v, client, client_id: client?.id ?? null } : v)))} />
                            </div>
                            <button type="button" className="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                                onClick={() => onChange(valeurs.filter((v) => v.numero !== or.numero))} aria-label={`Retirer l'OR ${or.numero}`}>
                                <X className="h-3.5 w-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
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
