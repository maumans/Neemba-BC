/**
 * Étape 2 « Bénéficiaire » (E-03.4, US-BC-04) : employé choisi dans la liste des utilisateurs actifs
 * (nom et téléphone repris du référentiel, RG-BC-06) ou tiers externe (nom libre, RG-BC-07).
 */
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Loader2, Search, X } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import TelephoneInput from '@/Components/TelephoneInput';
import Champ from './Champ';

/** Recherche d'un employé dès 2 caractères, 300 ms après la frappe */
function RechercheEmploye({ choisi, onChoisir, erreur }) {
    const [recherche, setRecherche] = useState('');
    const [resultats, setResultats] = useState([]);
    const [chargement, setChargement] = useState(false);
    const [modeRecherche, setModeRecherche] = useState(!choisi);
    const requete = useRef(0);

    useEffect(() => {
        if (recherche.trim().length < 2) {
            setResultats([]);
            return undefined;
        }
        const numero = ++requete.current;
        const minuterie = setTimeout(async () => {
            setChargement(true);
            try {
                const { data } = await axios.get(route('api.referentiels.beneficiaires'), { params: { q: recherche.trim() } });
                if (numero === requete.current) setResultats(data.resultats ?? []);
            } finally {
                if (numero === requete.current) setChargement(false);
            }
        }, 300);

        return () => clearTimeout(minuterie);
    }, [recherche]);

    if (!modeRecherche && choisi) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                <span className="truncate text-sm font-medium">{choisi.libelle}</span>
                <button type="button" onClick={() => setModeRecherche(true)} className="shrink-0 text-sm text-neemba-700 hover:underline">
                    Changer
                </button>
            </div>
        );
    }

    return (
        <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input
                id="champ-beneficiaire_id"
                value={recherche}
                onChange={(e) => setRecherche(e.target.value)}
                placeholder="Nom, prénom ou matricule (2 caractères minimum)"
                className="pl-9 pr-9"
                autoComplete="off"
                aria-invalid={Boolean(erreur)}
            />
            {chargement && <Loader2 className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-gray-400" />}
            {!chargement && choisi && (
                <button
                    type="button"
                    onClick={() => setModeRecherche(false)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                    aria-label="Garder le bénéficiaire choisi"
                >
                    <X className="h-4 w-4" />
                </button>
            )}
            {recherche.trim().length >= 2 && !chargement && (
                <ul className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-white py-1 shadow-lg">
                    {resultats.length === 0 && <li className="px-3 py-2 text-sm text-gray-500">Aucun employé trouvé.</li>}
                    {resultats.map((employe) => (
                        <li key={employe.id}>
                            <button
                                type="button"
                                onClick={() => {
                                    onChoisir(employe);
                                    setRecherche('');
                                    setModeRecherche(false);
                                }}
                                className="w-full px-3 py-2 text-left text-sm hover:bg-neemba-50"
                            >
                                {employe.libelle}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function EtapeBeneficiaire({ donnees, changer, erreurs, typesBeneficiaire, employeChoisi, choisirEmploye, demandeur }) {
    const employe = donnees.type_beneficiaire === 'employe';

    const changerType = (type) => {
        changer('type_beneficiaire', type);
        if (type === 'employe') {
            /* RG-BC-02 : par défaut, le bénéficiaire est le demandeur */
            choisirEmploye(employeChoisi ?? demandeur);
        } else if (employe) {
            changer('beneficiaire_id', null);
            changer('beneficiaire', '');
            changer('telephone_beneficiaire', '');
        }
    };

    return (
        <div className="space-y-5">
            <Champ champ="type_beneficiaire" libelle="Type de bénéficiaire" obligatoire erreur={erreurs.type_beneficiaire} className="sm:max-w-sm">
                <Select value={donnees.type_beneficiaire} onValueChange={changerType}>
                    <SelectTrigger id="champ-type_beneficiaire">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.entries(typesBeneficiaire).map(([valeur, libelle]) => (
                            <SelectItem key={valeur} value={valeur}>{libelle}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Champ>

            {employe ? (
                <>
                    <Champ champ="beneficiaire_id" libelle="Bénéficiaire" obligatoire erreur={erreurs.beneficiaire_id}>
                        <RechercheEmploye choisi={employeChoisi} onChoisir={choisirEmploye} erreur={erreurs.beneficiaire_id} />
                    </Champ>
                    <Champ
                        champ="telephone_beneficiaire"
                        libelle="Téléphone"
                        erreur={erreurs.telephone_beneficiaire}
                        aide="Repris du profil de l'employé, non modifiable."
                        className="sm:max-w-sm"
                    >
                        <TelephoneInput id="champ-telephone_beneficiaire" value={donnees.telephone_beneficiaire ?? ''} onChange={() => {}} readOnly disabled placeholder="Aucun téléphone au profil" />
                    </Champ>
                </>
            ) : (
                <>
                    <Champ champ="beneficiaire" libelle="Nom du bénéficiaire" obligatoire erreur={erreurs.beneficiaire} aide="3 à 120 caractères.">
                        <Input
                            id="champ-beneficiaire"
                            value={donnees.beneficiaire ?? ''}
                            maxLength={120}
                            onChange={(e) => changer('beneficiaire', e.target.value)}
                            placeholder="Ex. GARAGE KABA"
                        />
                    </Champ>
                    <Champ
                        champ="telephone_beneficiaire"
                        libelle="Téléphone"
                        erreur={erreurs.telephone_beneficiaire}
                        aide="Obligatoire pour un paiement en espèces : le bénéficiaire recevra le code de retrait. Format : 6XX XX XX XX."
                        className="sm:max-w-sm"
                    >
                        <TelephoneInput
                            id="champ-telephone_beneficiaire"
                            value={donnees.telephone_beneficiaire ?? ''}
                            onChange={(valeur) => changer('telephone_beneficiaire', valeur)}
                            placeholder="6XX XX XX XX"
                        />
                    </Champ>
                </>
            )}
        </div>
    );
}
