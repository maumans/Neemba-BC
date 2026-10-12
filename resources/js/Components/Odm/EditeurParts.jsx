/**
 * Parts de prise en charge d'une ligne de frais (spec v2.3, RG-M12-31 à RG-M12-33).
 *
 * Une part : payeur (Neemba ou Client), client du référentiel, OR facultatif, code analytique (vide : celui de l'ODM),
 * pourcentage ou montant. Le pourcentage fait foi (décision Q52) : un montant saisi est converti en pourcentage.
 * Le total doit faire 100 % ; une part client sans OR, ou dont le client diffère de celui de son OR, demande un
 * commentaire, visible des valideurs.
 *
 * Affichage compact quand la ligne est entièrement à la charge de Neemba (le cas par défaut).
 */
import { useState } from 'react';
import { AlertTriangle, Building2, Plus, SquareSplitHorizontal, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import MontantInput from '@/Components/MontantInput';
import { cn } from '@/lib/utils';
import { formaterMontant } from '@/utils/format';
import {
    avertissementPart, choisirOrDeLaPart, estNeembaSeul, nouvellePartClient, PART_NEEMBA, pourcentageDe, repartirMontant, resteARepartir,
} from '@/utils/odm';
import ChoixClient from './ChoixClient';

const SELECT = 'h-8 rounded-md border border-input bg-white px-2 text-xs focus:outline-none focus:ring-2 focus:ring-neemba-400';

function Payeur({ valeur, onChange, libelle }) {
    return (
        <div role="radiogroup" aria-label={`Payeur : ${libelle}`} className="inline-flex rounded-md border border-gray-200 bg-gray-100 p-0.5">
            {[['neemba', 'Neemba'], ['client', 'Client']].map(([v, texte]) => (
                <button key={v} type="button" role="radio" aria-checked={valeur === v} onClick={() => onChange(v)}
                    className={cn('inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium',
                        valeur === v && v === 'neemba' && 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300',
                        valeur === v && v === 'client' && 'bg-purple-600 text-white shadow-sm',
                        valeur !== v && 'text-gray-600 hover:text-gray-900')}>
                    {v === 'client' && <Building2 className="h-3 w-3" aria-hidden />}{texte}
                </button>
            ))}
        </div>
    );
}

/** Lecture seule : une pastille par part */
export function ResumeParts({ parts, montants, codeOdm }) {
    return (
        <ul className="space-y-1">
            {parts.map((p, i) => (
                <li key={i} className="text-xs">
                    <span className={cn('inline-flex flex-wrap items-center gap-x-1.5 rounded px-1.5 py-0.5',
                        p.payeur === 'client' ? 'bg-purple-50 text-purple-900' : 'bg-gray-100 text-gray-700')}>
                        <strong>{p.payeur === 'client' ? (p.client?.raison_sociale ?? 'Client non renseigné') : 'Neemba'}</strong>
                        <span>{Number(p.pourcentage).toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %</span>
                        {montants[i] !== null && montants[i] !== undefined && <span className="tabular-nums">· {formaterMontant(montants[i])}</span>}
                        {p.ordre_reparation && <span>· OR {p.ordre_reparation}</span>}
                        {p.code_analytique && p.code_analytique !== codeOdm && <span>· {p.code_analytique}</span>}
                    </span>
                    {p.avertissement && (
                        <span className="ml-1 inline-flex items-center gap-1 text-amber-700">
                            <AlertTriangle className="h-3 w-3" aria-hidden /> {p.avertissement}{p.commentaire ? ` « ${p.commentaire} »` : ''}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * @param {{ ligne: object, parts: object[], onChange: (parts: object[]) => void, ordres?: object[], clientsOdm?: object[],
 *           codes?: {code: string, libelle: string}[], codeOdm?: string, libelle: string }} props
 */
export default function EditeurParts({ ligne, parts, onChange, ordres = [], clientsOdm = [], codes = [], codeOdm = '', libelle }) {
    const [ouvert, setOuvert] = useState(false);
    const montants = repartirMontant(ligne.montant, parts.map((p) => p.pourcentage));
    const reste = resteARepartir(parts);
    const compact = estNeembaSeul(parts) && !ouvert;

    const changerPart = (indice, valeurs) => onChange(parts.map((p, i) => (i === indice ? { ...p, ...valeurs } : p)));
    const changerPayeur = (indice, payeur) => {
        const part = parts[indice];
        if (payeur === 'neemba') return changerPart(indice, { payeur, client: null, commentaire: '' });
        const or = ordres.find((o) => o.numero === part.ordre_reparation) ?? ordres.find((o) => o.client) ?? null;
        changerPart(indice, { payeur, client: part.client ?? or?.client ?? clientsOdm[0] ?? null, ordre_reparation: part.ordre_reparation ?? or?.numero ?? null });
    };

    if (compact) {
        return (
            <div className="flex items-center gap-2">
                <span className="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">Neemba 100 %</span>
                <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs text-purple-800 hover:bg-purple-50" onClick={() => setOuvert(true)}
                    aria-label={`Répartir : ${libelle}`}>
                    <SquareSplitHorizontal className="mr-1 h-3.5 w-3.5" /> Répartir
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            {parts.map((part, i) => {
                const avertissement = avertissementPart(part, ordres);
                const suggestions = [ordres.find((o) => o.numero === part.ordre_reparation)?.client, ...clientsOdm].filter(Boolean)
                    .filter((c, j, liste) => liste.findIndex((x) => x.id === c.id) === j);

                return (
                    <div key={i} className={cn('space-y-2 rounded-md border p-2', part.payeur === 'client' ? 'border-purple-200 bg-purple-50/40' : 'border-gray-200')}
                        data-testid="part">
                        <div className="flex flex-wrap items-center gap-2">
                            <Payeur valeur={part.payeur} libelle={`${libelle}, part ${i + 1}`} onChange={(v) => changerPayeur(i, v)} />
                            {part.payeur === 'client' && (
                                <div className="w-52">
                                    <ChoixClient compact valeur={part.client} suggestions={suggestions} libelle={`Client : ${libelle}, part ${i + 1}`}
                                        invalide={!part.client} effacable={false} onChange={(client) => changerPart(i, { client })} />
                                </div>
                            )}
                            <select className={SELECT} value={part.ordre_reparation ?? ''} aria-label={`OR : ${libelle}, part ${i + 1}`}
                                onChange={(e) => onChange(parts.map((p, j) => (j === i ? choisirOrDeLaPart(p, e.target.value, ordres) : p)))}>
                                <option value="">Sans OR</option>
                                {ordres.map((o) => <option key={o.numero} value={o.numero}>OR {o.numero}{o.client ? ` · ${o.client.raison_sociale}` : ''}</option>)}
                            </select>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <select className={cn(SELECT, 'max-w-[14rem]')} value={part.code_analytique ?? ''} aria-label={`Code analytique : ${libelle}, part ${i + 1}`}
                                onChange={(e) => changerPart(i, { code_analytique: e.target.value || null })}>
                                <option value="">{codeOdm ? `Code de l'ODM (${codeOdm})` : "Code de l'ODM"}</option>
                                {codes.filter((c) => c.code !== codeOdm).map((c) => <option key={c.code} value={c.code}>{c.code} — {c.libelle}</option>)}
                            </select>
                            <div className="flex items-center gap-1">
                                <Input type="number" min="0" max="100" step="0.01" className="h-8 w-20 text-right text-xs" value={part.pourcentage}
                                    aria-label={`Pourcentage : ${libelle}, part ${i + 1}`}
                                    onChange={(e) => changerPart(i, { pourcentage: e.target.value === '' ? 0 : Number(e.target.value) })} />
                                <span className="text-xs text-gray-500">%</span>
                            </div>
                            {ligne.montant !== null && (
                                <div className="flex items-center gap-1">
                                    <span className="text-xs text-gray-400">soit</span>
                                    <MontantInput className="h-8 w-36 text-xs" value={montants[i] ?? ''} aria-label={`Montant : ${libelle}, part ${i + 1}`}
                                        onChange={(v) => changerPart(i, { pourcentage: pourcentageDe(Number(v || 0), ligne.montant) })} />
                                </div>
                            )}
                            {parts.length > 1 && (
                                <Button type="button" variant="ghost" size="icon" className="ml-auto h-8 w-8 text-gray-400 hover:text-red-600"
                                    aria-label={`Supprimer la part ${i + 1} : ${libelle}`} onClick={() => onChange(parts.filter((_, j) => j !== i))}>
                                    <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                            )}
                        </div>
                        {avertissement && (
                            <div className="space-y-1 rounded bg-amber-50 p-2 text-xs text-amber-900">
                                <p className="flex items-center gap-1"><AlertTriangle className="h-3.5 w-3.5" aria-hidden /> {avertissement} Un commentaire est obligatoire ; il sera lu par les valideurs.</p>
                                <Input className={cn('h-8 bg-white text-xs', !part.commentaire?.trim() && 'border-amber-400')} value={part.commentaire ?? ''} maxLength={500}
                                    placeholder="Ex. : refacturation sur le contrat cadre, sans OR" aria-label={`Commentaire : ${libelle}, part ${i + 1}`}
                                    onChange={(e) => changerPart(i, { commentaire: e.target.value })} />
                            </div>
                        )}
                    </div>
                );
            })}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex gap-1">
                    <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onChange([...parts, nouvellePartClient(parts, ordres, clientsOdm)])}
                        disabled={parts.length >= 10}>
                        <Plus className="mr-1 h-3 w-3" /> Ajouter une part
                    </Button>
                    <Button type="button" variant="ghost" size="sm" className="h-7 text-xs" onClick={() => { onChange([{ ...PART_NEEMBA }]); setOuvert(false); }}>
                        Tout Neemba
                    </Button>
                </div>
                <span className={cn('text-xs font-medium', Math.abs(reste) < 0.001 ? 'text-green-700' : 'text-red-600')} data-testid="reste">
                    {Math.abs(reste) < 0.001 ? 'Répartie à 100 %' : `Reste à répartir : ${reste.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} %`}
                </span>
            </div>
        </div>
    );
}
