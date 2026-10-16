/**
 * Entité de travail (spec v2.4, RG-M01-08) : affiché seulement quand l'utilisateur a des droits dans plusieurs entités
 * actives. La vue consolidée (toutes les entités) est proposée aux rôles habilités (paramètre « roles_vue_consolidee »).
 * Le choix est gardé en session ; listes, tableaux de bord, rapports et exports suivent l'entité choisie.
 */
import { router, usePage } from '@inertiajs/react';
import { Building } from 'lucide-react';

export default function SelecteurEntite() {
    const entite = usePage().props.auth?.entite;
    if (!entite || (entite.accessibles.length < 2 && !entite.peut_consolider)) return null;

    const valeur = entite.consolide ? 'consolide' : String(entite.courante?.id ?? '');
    const choisir = (e) => router.post(route('entite.choisir'), { entite: e.target.value });

    return (
        <label className="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600">
            <Building className="h-3.5 w-3.5 text-neemba-600" aria-hidden="true" />
            <span className="sr-only">Entité de travail</span>
            <select aria-label="Entité de travail" value={valeur} onChange={choisir} className="border-0 bg-transparent py-0 pl-0 pr-6 text-xs font-medium text-gray-800 focus:ring-0">
                {entite.accessibles.map((e) => <option key={e.id} value={e.id}>{e.nom}</option>)}
                {entite.peut_consolider && <option value="consolide">Vue consolidée (toutes)</option>}
            </select>
        </label>
    );
}
