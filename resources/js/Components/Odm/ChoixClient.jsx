/**
 * Choix d'un client dans le référentiel (spec v2.3, §6.5 : pas de saisie libre sur l'ODM).
 * Liste déroulante avec recherche (code ou raison sociale) ; les « suggestions » (client de l'OR, clients de l'ODM)
 * sont proposées en premier, avant toute frappe.
 *
 * - ChoixClient : un client (OR, part de prise en charge) ;
 * - SelectionClients : plusieurs clients (en-tête de l'ODM), en étiquettes.
 */
import { useEffect, useId, useRef, useState } from 'react';
import axios from 'axios';
import { Building2, ChevronDown, Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';

function useRechercheClients(ouvert, recherche) {
    const [resultats, setResultats] = useState([]);
    const [chargement, setChargement] = useState(false);
    const requete = useRef(0);

    useEffect(() => {
        if (!ouvert) return undefined;
        const numero = ++requete.current;
        const minuterie = setTimeout(async () => {
            setChargement(true);
            try {
                const { data } = await axios.get(route('api.referentiels.clients'), { params: { q: recherche.trim() || undefined } });
                if (numero === requete.current) setResultats(data ?? []);
            } catch {
                if (numero === requete.current) setResultats([]);
            } finally {
                if (numero === requete.current) setChargement(false);
            }
        }, recherche ? 250 : 0);

        return () => clearTimeout(minuterie);
    }, [ouvert, recherche]);

    return { resultats, chargement };
}

/** Liste déroulante commune */
function Liste({ ouvert, recherche, setRecherche, suggestions, exclus = [], onChoisir, idListe }) {
    const { resultats, chargement } = useRechercheClients(ouvert, recherche);
    if (!ouvert) return null;
    const filtre = (c) => !exclus.includes(c.id);
    const proposes = recherche.trim() ? [] : suggestions.filter(filtre);
    const autres = resultats.filter(filtre).filter((c) => !proposes.some((p) => p.id === c.id));

    return (
        <div className="absolute z-30 mt-1 w-full min-w-[16rem] rounded-md border bg-white shadow-lg">
            <div className="border-b p-1.5">
                <input autoFocus value={recherche} onChange={(e) => setRecherche(e.target.value)} placeholder="Code ou raison sociale"
                    className="h-8 w-full rounded border border-input px-2 text-sm focus:outline-none focus:ring-2 focus:ring-neemba-400" aria-label="Rechercher un client" />
            </div>
            <ul id={idListe} role="listbox" className="max-h-60 overflow-y-auto py-1 text-sm">
                {proposes.length > 0 && <li className="px-3 pb-0.5 pt-1 text-[11px] font-medium uppercase tracking-wide text-gray-400">Proposés</li>}
                {proposes.map((c) => <Option key={`p${c.id}`} client={c} onChoisir={onChoisir} />)}
                {proposes.length > 0 && autres.length > 0 && <li className="mx-2 my-1 border-t" aria-hidden />}
                {autres.map((c) => <Option key={c.id} client={c} onChoisir={onChoisir} />)}
                {chargement && <li className="flex items-center gap-2 px-3 py-2 text-gray-400"><Loader2 className="h-3.5 w-3.5 animate-spin" /> Recherche…</li>}
                {!chargement && proposes.length === 0 && autres.length === 0 && (
                    <li className="px-3 py-2 text-gray-500">Aucun client trouvé. Demandez à la Comptabilité de l'ajouter au référentiel.</li>
                )}
            </ul>
        </div>
    );
}

function Option({ client, onChoisir }) {
    return (
        <li role="option" aria-selected="false">
            <button type="button" onClick={() => onChoisir(client)} className="flex w-full items-baseline gap-2 px-3 py-1.5 text-left hover:bg-neemba-50">
                <span className="font-mono text-xs text-gray-500">{client.code}</span>
                <span className="truncate">{client.raison_sociale}</span>
            </button>
        </li>
    );
}

/** Ferme la liste au clic à l'extérieur ou à Échap */
function useFermeture(ref, ouvert, fermer) {
    useEffect(() => {
        if (!ouvert) return undefined;
        const clic = (e) => ref.current && !ref.current.contains(e.target) && fermer();
        const touche = (e) => e.key === 'Escape' && fermer();
        document.addEventListener('mousedown', clic);
        document.addEventListener('keydown', touche);
        return () => {
            document.removeEventListener('mousedown', clic);
            document.removeEventListener('keydown', touche);
        };
    }, [ref, ouvert, fermer]);
}

/**
 * @param {{ valeur: ?{id:number, code:string, raison_sociale:string}, onChange: (client|null) => void, suggestions?: Array,
 *           id?: string, libelle?: string, compact?: boolean, desactive?: boolean, effacable?: boolean, invalide?: boolean }} props
 */
export default function ChoixClient({ valeur = null, onChange, suggestions = [], id, libelle = 'Client', compact = false, desactive = false, effacable = true, invalide = false }) {
    const [ouvert, setOuvert] = useState(false);
    const [recherche, setRecherche] = useState('');
    const conteneur = useRef(null);
    const idListe = useId();
    useFermeture(conteneur, ouvert, () => setOuvert(false));

    return (
        <div ref={conteneur} className="relative">
            <button type="button" id={id} disabled={desactive} aria-haspopup="listbox" aria-expanded={ouvert} aria-controls={idListe} aria-label={libelle}
                onClick={() => { setOuvert((o) => !o); setRecherche(''); }}
                className={cn(
                    'flex w-full items-center gap-1.5 rounded-md border bg-white text-left focus:outline-none focus:ring-2 focus:ring-neemba-400 disabled:cursor-not-allowed disabled:opacity-60',
                    compact ? 'h-8 px-2 text-xs' : 'h-9 px-3 text-sm',
                    invalide ? 'border-red-500' : 'border-input',
                )}>
                <Building2 className="h-3.5 w-3.5 flex-shrink-0 text-gray-400" aria-hidden />
                <span className={cn('min-w-0 flex-1 truncate', !valeur && 'text-gray-400')}>
                    {valeur ? valeur.raison_sociale : 'Choisir un client…'}
                </span>
                {valeur && effacable && !desactive ? (
                    <span role="button" tabIndex={0} aria-label="Retirer le client" className="rounded p-0.5 text-gray-400 hover:text-gray-700"
                        onClick={(e) => { e.stopPropagation(); onChange(null); }}
                        onKeyDown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); onChange(null); } }}>
                        <X className="h-3 w-3" />
                    </span>
                ) : <ChevronDown className="h-3.5 w-3.5 text-gray-400" aria-hidden />}
            </button>
            <Liste ouvert={ouvert} recherche={recherche} setRecherche={setRecherche} suggestions={suggestions} idListe={idListe}
                onChoisir={(c) => { onChange(c); setOuvert(false); }} />
        </div>
    );
}

/** Clients de la mission, en étiquettes */
export function SelectionClients({ valeurs = [], onChange, id = 'champ-clients' }) {
    const [ouvert, setOuvert] = useState(false);
    const [recherche, setRecherche] = useState('');
    const conteneur = useRef(null);
    const idListe = useId();
    useFermeture(conteneur, ouvert, () => setOuvert(false));

    return (
        <div ref={conteneur} className="relative">
            <div className="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input bg-white px-2 py-1">
                {valeurs.map((c) => (
                    <span key={c.id} className="inline-flex items-center gap-1 rounded bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-900">
                        {c.raison_sociale}
                        <button type="button" onClick={() => onChange(valeurs.filter((v) => v.id !== c.id))} aria-label={`Retirer ${c.raison_sociale}`}>
                            <X className="h-3 w-3" />
                        </button>
                    </span>
                ))}
                <button type="button" id={id} aria-haspopup="listbox" aria-expanded={ouvert} aria-controls={idListe}
                    onClick={() => { setOuvert((o) => !o); setRecherche(''); }}
                    className="flex-1 py-0.5 text-left text-sm text-gray-400 hover:text-gray-600">
                    {valeurs.length ? '+ Ajouter un client' : 'Choisir dans le référentiel des clients…'}
                </button>
            </div>
            <Liste ouvert={ouvert} recherche={recherche} setRecherche={setRecherche} suggestions={[]} exclus={valeurs.map((c) => c.id)} idListe={idListe}
                onChoisir={(c) => { onChange([...valeurs, c]); setOuvert(false); }} />
        </div>
    );
}
