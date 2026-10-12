/**
 * Prise en charge des frais d'un ODM (spec v2.3, RG-M12-30 à RG-M12-34).
 *
 * - RepartitionFrais : par participant, chaque ligne de frais (quantité × taux = montant) et ses parts ;
 *   saisie rapide (RG-M12-32) pour un participant ou tout l'ODM.
 * - RecapitulatifRepartition : part Neemba, part de chaque client et par OR, avertissements (RG-M12-34),
 *   pour les valideurs et la fiche.
 */
import { useState } from 'react';
import { AlertTriangle, Building2, Loader2, WandSparkles } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { formaterMontant, formaterNombre } from '@/utils/format';
import { appliquerRepartition, lignesAjustees, PART_NEEMBA, partsDeLigne, repartirMontant } from '@/utils/odm';
import ChoixClient from './ChoixClient';
import EditeurParts, { ResumeParts } from './EditeurParts';

const SELECT = 'h-9 w-full rounded-md border border-input bg-white px-2 text-sm';

/** « 5 j × 250 000 GNF », « 4 nuits × 500 000 GNF », « 5 j × 22 000 FCFA » */
function quantiteTaux(ligne) {
    if (ligne.a_la_cloture) return 'facture connue à la clôture';
    if (ligne.taux === null) return '';
    const unite = ligne.element === 'indemnite' ? 'j' : ligne.element === 'hebergement_exterieur' ? 'facture' : (ligne.quantite > 1 ? 'nuits' : 'nuit');
    const quantite = ligne.element === 'hebergement_exterieur' ? '1' : formaterNombre(ligne.quantite);

    return `${quantite} ${unite} × ${formaterNombre(ligne.taux)} ${ligne.devise}`;
}

/** RG-M12-32 : répartition à appliquer en une action */
function DialogueRepartition({ cible, ordres, clientsOdm, aRemplacer, onAppliquer, onFermer }) {
    const [modele, setModele] = useState('client');
    const [client, setClient] = useState(ordres.find((o) => o.client)?.client ?? clientsOdm[0] ?? null);
    const [or, setOr] = useState(ordres.find((o) => o.client)?.numero ?? ordres[0]?.numero ?? '');
    const [pourcentageClient, setPourcentageClient] = useState(50);

    const parts = () => {
        const partClient = (pourcentage) => ({ payeur: 'client', client, ordre_reparation: or || null, code_analytique: null, pourcentage, commentaire: '' });
        if (modele === 'neemba') return [{ ...PART_NEEMBA }];
        if (modele === 'client') return [partClient(100)];
        const p = Math.max(0, Math.min(100, Number(pourcentageClient) || 0));
        return [{ ...PART_NEEMBA, pourcentage: 100 - p }, partClient(p)];
    };
    const incomplet = modele !== 'neemba' && !client;

    return (
        <Dialog open onOpenChange={(o) => !o && onFermer()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Appliquer une répartition</DialogTitle>
                    <DialogDescription>À toutes les lignes de frais {cible.nom ? `de ${cible.nom}` : 'de l\'ordre de mission'}. Vous pourrez ensuite ajuster ligne par ligne.</DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                    <div role="radiogroup" aria-label="Répartition" className="grid gap-2 sm:grid-cols-3">
                        {[['neemba', 'Neemba 100 %'], ['client', 'Un client 100 %'], ['partage', 'Partagée']].map(([v, texte]) => (
                            <button key={v} type="button" role="radio" aria-checked={modele === v} onClick={() => setModele(v)}
                                className={cn('rounded-md border px-3 py-2 text-sm font-medium', modele === v ? 'border-neemba-400 bg-neemba-50 text-neemba-900' : 'border-gray-200 text-gray-600 hover:bg-gray-50')}>
                                {texte}
                            </button>
                        ))}
                    </div>
                    {modele !== 'neemba' && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label className="text-xs text-gray-600">Client</label>
                                <ChoixClient valeur={client} onChange={setClient} suggestions={[...ordres.map((o) => o.client).filter(Boolean), ...clientsOdm]
                                    .filter((c, i, l) => l.findIndex((x) => x.id === c.id) === i)} libelle="Client de la répartition" />
                            </div>
                            <div>
                                <label className="text-xs text-gray-600" htmlFor="repartition-or">OR</label>
                                <select id="repartition-or" className={SELECT} value={or} onChange={(e) => {
                                    setOr(e.target.value);
                                    const choisi = ordres.find((o) => o.numero === e.target.value);
                                    if (choisi?.client) setClient(choisi.client);
                                }}>
                                    <option value="">Sans OR</option>
                                    {ordres.map((o) => <option key={o.numero} value={o.numero}>OR {o.numero}{o.client ? ` · ${o.client.raison_sociale}` : ''}</option>)}
                                </select>
                            </div>
                            {modele === 'partage' && (
                                <div className="sm:col-span-2">
                                    <label className="text-xs text-gray-600" htmlFor="repartition-pourcentage">Part du client</label>
                                    <div className="flex items-center gap-2">
                                        <Input id="repartition-pourcentage" type="number" min="0" max="100" step="1" className="w-24" value={pourcentageClient}
                                            onChange={(e) => setPourcentageClient(e.target.value)} />
                                        <span className="text-sm text-gray-600">% client, {100 - (Number(pourcentageClient) || 0)} % Neemba</span>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                    {aRemplacer > 0 && (
                        <p className="flex items-start gap-1.5 rounded-md bg-amber-50 p-2 text-xs text-amber-900">
                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                            {aRemplacer} ligne{aRemplacer > 1 ? 's' : ''} déjà répartie{aRemplacer > 1 ? 's' : ''} autrement sera{aRemplacer > 1 ? 'ont' : ''} remplacée{aRemplacer > 1 ? 's' : ''}.
                        </p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onFermer}>Annuler</Button>
                    <Button disabled={incomplet} onClick={() => onAppliquer(parts())}>Appliquer</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function RepartitionFrais({
    participants = [], calcul = null, repartitions = {}, onChange, ordres = [], clientsOdm = [], codes = [], codeOdm = '', enCours = false, lectureSeule = false,
}) {
    const [dialogue, setDialogue] = useState(null);   // { user_id, nom } ou { user_id: null }

    if (participants.length === 0) {
        return <p className="py-2 text-sm text-gray-500">Ajoutez des participants : leurs frais apparaîtront ici, ligne par ligne.</p>;
    }

    return (
        <div className="space-y-4">
            {!lectureSeule && (
                <div className="flex justify-end">
                    <Button type="button" variant="outline" size="sm" className="h-8 text-xs" onClick={() => setDialogue({ user_id: null })}>
                        <WandSparkles className="mr-1 h-3.5 w-3.5" /> Appliquer à tout l'ODM…
                    </Button>
                </div>
            )}

            {participants.map((p) => {
                const calculP = calcul?.participants?.find((c) => c.user_id === p.user_id);
                const lignes = calculP?.lignes ?? [];

                return (
                    <section key={p.user_id} className="rounded-lg border border-gray-200" aria-label={`Prise en charge des frais de ${p.nom}`} data-testid={`repartition-${p.user_id}`}>
                        <header className="flex flex-wrap items-center justify-between gap-2 border-b bg-gray-50 px-3 py-2">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-gray-900">{p.nom}</p>
                                {calculP && (
                                    <p className="text-xs text-gray-500">
                                        {calculP.jours} j · {calculP.nuits} nuit{calculP.nuits > 1 ? 's' : ''}{calculP.nuit_rattrapage ? ' + 1 de rattrapage' : ''}{calculP.base_vie ? ' · base vie' : ''}
                                        {calculP.total !== null && <> · <span className="font-medium text-gray-700">{formaterMontant(calculP.total)}</span></>}
                                    </p>
                                )}
                            </div>
                            {!lectureSeule && lignes.length > 0 && (
                                <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => setDialogue({ user_id: p.user_id, nom: p.nom })}>
                                    <WandSparkles className="mr-1 h-3.5 w-3.5" /> Appliquer à ses lignes…
                                </Button>
                            )}
                        </header>

                        {lignes.length === 0 ? (
                            <p className="flex items-center gap-2 px-3 py-3 text-sm text-gray-500">
                                {enCours && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                                Renseignez les dates de la mission pour voir les frais.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {lignes.map((ligne) => {
                                    const parts = partsDeLigne(ligne, repartitions, p.user_id);
                                    const montants = repartirMontant(ligne.montant, parts.map((x) => x.pourcentage));

                                    return (
                                        <li key={ligne.element} className="space-y-1.5 px-3 py-2" data-testid={`ligne-${p.user_id}-${ligne.element}`}>
                                            <div className="flex flex-wrap items-baseline justify-between gap-x-4">
                                                <span className="text-sm text-gray-800">{ligne.libelle}</span>
                                                <span className="text-xs text-gray-500">{quantiteTaux(ligne)}</span>
                                                <span className="ml-auto whitespace-nowrap text-sm font-medium tabular-nums text-gray-900">
                                                    {ligne.montant !== null ? formaterMontant(ligne.montant) : ligne.a_la_cloture ? 'à la clôture' : 'au paiement'}
                                                </span>
                                            </div>
                                            {lectureSeule ? (
                                                <ResumeParts parts={ligne.parts.map((x) => ({ ...x }))} montants={ligne.parts.map((x) => x.montant)} codeOdm={codeOdm} />
                                            ) : (
                                                <EditeurParts ligne={ligne} parts={parts} ordres={ordres} clientsOdm={clientsOdm} codes={codes} codeOdm={codeOdm}
                                                    libelle={`${ligne.libelle}, ${p.nom}`}
                                                    onChange={(nouvelles) => onChange({ ...repartitions, [p.user_id]: { ...(repartitions[p.user_id] ?? {}), [ligne.element]: nouvelles } })} />
                                            )}
                                            {!lectureSeule && parts.length > 1 && montants.some((m) => m === null) && (
                                                <p className="text-xs text-gray-500">Montants des parts calculés au paiement, au taux du jour.</p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
                );
            })}

            {dialogue && (
                <DialogueRepartition cible={dialogue} ordres={ordres} clientsOdm={clientsOdm}
                    aRemplacer={lignesAjustees(repartitions, calcul, dialogue.user_id)}
                    onFermer={() => setDialogue(null)}
                    onAppliquer={(parts) => {
                        onChange(appliquerRepartition(repartitions, calcul, parts, dialogue.user_id));
                        setDialogue(null);
                    }} />
            )}
        </div>
    );
}

/** RG-M12-34 : récapitulatif Neemba / client, par client et par OR, avec les avertissements et leurs commentaires */
export function RecapitulatifRepartition({ recapitulatif, titre = 'Répartition des frais', className = '' }) {
    if (!recapitulatif || recapitulatif.total <= 0) return null;
    const pourcent = (m) => (recapitulatif.total > 0 ? Math.round((m / recapitulatif.total) * 100) : 0);

    return (
        <section className={cn('rounded-lg border bg-white p-4', className)} aria-label={titre} data-testid="recapitulatif-repartition">
            <h3 className="mb-2 text-sm font-semibold text-gray-900">{titre}</h3>
            <div className="mb-3 flex h-2 overflow-hidden rounded-full bg-gray-100" aria-hidden>
                <div className="bg-neemba-400" style={{ width: `${pourcent(recapitulatif.neemba)}%` }} />
                <div className="bg-purple-500" style={{ width: `${pourcent(recapitulatif.client)}%` }} />
            </div>
            <dl className="space-y-1 text-sm">
                <div className="flex justify-between gap-3">
                    <dt className="flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-neemba-400" aria-hidden /> Neemba</dt>
                    <dd className="tabular-nums">{formaterMontant(recapitulatif.neemba)} <span className="text-xs text-gray-500">({pourcent(recapitulatif.neemba)} %)</span></dd>
                </div>
                {recapitulatif.par_client.map((c) => (
                    <div key={c.client.id ?? 'x'}>
                        <div className="flex justify-between gap-3 text-purple-900">
                            <dt className="flex items-center gap-1.5"><Building2 className="h-3.5 w-3.5 text-purple-500" aria-hidden /> {c.client.raison_sociale}</dt>
                            <dd className="tabular-nums">{formaterMontant(c.montant)} <span className="text-xs text-purple-600">({pourcent(c.montant)} %)</span></dd>
                        </div>
                        {c.par_or.map((o) => (
                            <div key={o.or ?? 'sans'} className="flex justify-between gap-3 pl-5 text-xs text-gray-600">
                                <span>{o.or ? `OR ${o.or}` : 'Sans OR'}</span>
                                <span className="tabular-nums">{formaterMontant(o.montant)}</span>
                            </div>
                        ))}
                    </div>
                ))}
            </dl>
            {recapitulatif.avertissements?.length > 0 && (
                <ul className="mt-3 space-y-1 rounded-md bg-amber-50 p-2 text-xs text-amber-900">
                    {recapitulatif.avertissements.map((a, i) => (
                        <li key={i} className="flex gap-1.5">
                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" aria-hidden />
                            <span>{a.element} de {a.participant} : {a.message}{a.commentaire ? ` Commentaire : « ${a.commentaire} »` : ''}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
