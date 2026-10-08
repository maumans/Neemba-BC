/**
 * Ajout d'un participant (RG-M12-04) : salariés actifs, recherche dès 2 caractères, 300 ms après la frappe.
 * Service, statut cadre et n° Orange Money sont repris du référentiel.
 */
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Loader2, UserPlus } from 'lucide-react';
import { Input } from '@/Components/ui/input';

export default function RechercheParticipant({ onChoisir, dejaChoisis = [], desactive = false }) {
    const [recherche, setRecherche] = useState('');
    const [resultats, setResultats] = useState([]);
    const [chargement, setChargement] = useState(false);
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
                const { data } = await axios.get(route('api.odm.employes'), { params: { q: recherche.trim() } });
                if (numero === requete.current) setResultats(data.resultats ?? []);
            } finally {
                if (numero === requete.current) setChargement(false);
            }
        }, 300);

        return () => clearTimeout(minuterie);
    }, [recherche]);

    return (
        <div className="relative">
            <UserPlus className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input
                id="champ-participants"
                value={recherche}
                onChange={(e) => setRecherche(e.target.value)}
                placeholder="Ajouter un participant : nom, prénom ou matricule"
                className="pl-9 pr-9"
                autoComplete="off"
                disabled={desactive}
            />
            {chargement && <Loader2 className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-gray-400" />}
            {recherche.trim().length >= 2 && !chargement && (
                <ul className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-white py-1 shadow-lg">
                    {resultats.length === 0 && <li className="px-3 py-2 text-sm text-gray-500">Aucun salarié trouvé.</li>}
                    {resultats.map((employe) => {
                        const deja = dejaChoisis.includes(employe.user_id);
                        return (
                            <li key={employe.user_id}>
                                <button
                                    type="button"
                                    disabled={deja}
                                    onClick={() => {
                                        onChoisir(employe);
                                        setRecherche('');
                                    }}
                                    className="w-full px-3 py-2 text-left text-sm hover:bg-neemba-50 disabled:cursor-default disabled:text-gray-400 disabled:hover:bg-white"
                                >
                                    {employe.libelle}
                                    {deja && ' (déjà ajouté)'}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
