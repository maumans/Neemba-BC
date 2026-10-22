/**
 * Demandes de réapprovisionnement (spec v2.4, RG-M08-04 à RG-M08-09) : gérant de la caisse → trésorerie → Finance →
 * Directeur Pays (ou deux membres du CODIR en son absence) → exécution par la trésorerie → réception par la caisse.
 */
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Check, Eye, PackageCheck, Plus, RefreshCcw, Send, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

/* Visas donnés depuis la liste, après confirmation */
const VISAS = {
    valider_tresorerie: 'Valider (trésorerie)',
    viser_finance: 'Viser (Finance)',
    approuver_dp: 'Approuver (Directeur Pays)',
    approuver_codir: 'Approuver (membre du CODIR)',
};
/* Actions qui demandent des pièces ou des montants : sur la fiche */
const SUR_LA_FICHE = {
    executer: { libelle: 'Exécuter', icone: Send },
    confirmer_reception: { libelle: 'Réceptionner', icone: PackageCheck },
};

const COULEURS = {
    recue: 'bg-green-100 text-green-800',
    refusee: 'bg-red-100 text-red-800',
    en_cours: 'bg-blue-100 text-blue-800',
    approuvee: 'bg-blue-100 text-blue-800',
};

export default function Index({ demandes, statuts = {}, filtres = {}, peutDemander = false }) {
    const filtrer = (statut) => router.get(route('reapprovisionnements.index'), statut ? { statut } : {}, { preserveState: true, replace: true });
    /* { demande, action } en cours de confirmation */
    const [choix, setChoix] = useState(null);
    const [motif, setMotif] = useState('');
    const [erreurs, setErreurs] = useState({});
    const [enCours, setEnCours] = useState(false);
    const ouvrir = (demande, action) => { setChoix({ demande, action }); setMotif(''); setErreurs({}); };
    const confirmer = () => router.post(route('reapprovisionnements.agir', choix.demande.id),
        { action: choix.action, ...(choix.action === 'refuser' ? { motif } : {}) }, {
            preserveScroll: true,
            onStart: () => setEnCours(true),
            onSuccess: () => setChoix(null),
            onError: (e) => setErreurs(e),
            onFinish: () => setEnCours(false),
        });

    return (
        <AuthenticatedLayout header="Réapprovisionnements">
            <Head title="Réapprovisionnements" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <RefreshCcw className="h-5 w-5 text-neemba-600" /> Réapprovisionnement des caisses
                            </CardTitle>
                            <CardDescription>
                                Demandé par le gérant de la caisse, validé par la trésorerie, visé par la Finance, approuvé par le Directeur Pays.
                                La caisse n'est créditée qu'à la confirmation de réception.
                            </CardDescription>
                        </div>
                        {peutDemander && (
                            <Link href={route('reapprovisionnements.create')}>
                                <Button><Plus className="mr-1 h-4 w-4" /> Nouvelle demande</Button>
                            </Link>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <select aria-label="Statut" value={filtres.statut ?? ''} onChange={(e) => filtrer(e.target.value)}
                            className="h-9 max-w-full rounded-md border border-input bg-white px-2 text-sm">
                            <option value="">Tous les statuts</option>
                            {Object.entries(statuts).map(([cle, libelle]) => <option key={cle} value={cle}>{libelle}</option>)}
                        </select>
                        <div className="overflow-x-auto rounded-md border bg-white">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                        <th className="px-3 py-2">Demande</th>
                                        <th className="px-3 py-2">Caisse</th>
                                        <th className="px-3 py-2">Le</th>
                                        <th className="px-3 py-2 text-right">Montant</th>
                                        <th className="px-3 py-2">Statut</th>
                                        <th className="px-3 py-2 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {demandes.data.map((d) => (
                                        <tr key={d.id} className="border-b last:border-0">
                                            <td className="whitespace-nowrap px-3 py-2">
                                                <Link href={route('reapprovisionnements.show', d.id)} className="font-medium text-neemba-700 hover:underline">{d.numero}</Link>
                                                {d.a_traiter && <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">À traiter</span>}
                                            </td>
                                            <td className="px-3 py-2">{d.caisse}</td>
                                            <td className="whitespace-nowrap px-3 py-2">{d.date}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{d.montant}</td>
                                            <td className="px-3 py-2">
                                                <span className={`rounded px-1.5 py-0.5 text-xs ${COULEURS[d.statut] ?? 'bg-amber-50 text-amber-900'}`}>{d.statut_libelle}</span>
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2">
                                                <div className="flex justify-end gap-1">
                                                    {(d.actions ?? []).filter((a) => VISAS[a]).map((a) => (
                                                        <Button key={a} size="sm" className="h-8" data-testid={`action-${d.id}-${a}`} onClick={() => ouvrir(d, a)}>
                                                            <Check className="mr-1 h-3.5 w-3.5" /> {VISAS[a]}
                                                        </Button>
                                                    ))}
                                                    {(d.actions ?? []).filter((a) => SUR_LA_FICHE[a]).map((a) => {
                                                        const Icone = SUR_LA_FICHE[a].icone;
                                                        return (
                                                            <Link key={a} href={`${route('reapprovisionnements.show', d.id)}#agir`}>
                                                                <Button size="sm" className="h-8" data-testid={`action-${d.id}-${a}`}><Icone className="mr-1 h-3.5 w-3.5" /> {SUR_LA_FICHE[a].libelle}</Button>
                                                            </Link>
                                                        );
                                                    })}
                                                    {(d.actions ?? []).includes('refuser') && (
                                                        <Button size="sm" variant="outline" className="h-8 border-red-300 text-red-700 hover:bg-red-50" data-testid={`action-${d.id}-refuser`}
                                                            onClick={() => ouvrir(d, 'refuser')}>
                                                            <X className="mr-1 h-3.5 w-3.5" /> Refuser
                                                        </Button>
                                                    )}
                                                    <Link href={route('reapprovisionnements.show', d.id)}>
                                                        <Button size="sm" variant="outline" className="h-8" title="Voir la demande" aria-label={`Voir ${d.numero}`}><Eye className="h-3.5 w-3.5" /></Button>
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {demandes.data.length === 0 && (
                                        <tr><td colSpan={6} className="px-3 py-8 text-center text-gray-400">Aucune demande de réapprovisionnement.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {demandes.last_page > 1 && (
                            <div className="flex flex-wrap gap-1">
                                {demandes.links.map((lien, i) => (
                                    <Link key={i} href={lien.url ?? '#'} preserveScroll
                                        className={`rounded px-2 py-1 text-xs ${lien.active ? 'bg-neemba-500 text-white' : 'border bg-white'} ${lien.url ? '' : 'pointer-events-none opacity-40'}`}
                                        dangerouslySetInnerHTML={{ __html: lien.label }} />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={choix !== null} onOpenChange={(ouvert) => !ouvert && !enCours && setChoix(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{choix?.action === 'refuser' ? 'Refuser' : VISAS[choix?.action]} — {choix?.demande.numero}</DialogTitle>
                        <DialogDescription>{choix?.demande.caisse} : {choix?.demande.montant}.</DialogDescription>
                    </DialogHeader>
                    {choix?.action === 'valider_tresorerie' && (
                        <p className="text-sm text-gray-600">Vous validez le montant demandé. Pour le réduire, ouvrez la demande.</p>
                    )}
                    {choix?.action === 'refuser' ? (
                        <div>
                            <Label htmlFor="liste-motif-refus">Motif du refus</Label>
                            <Textarea id="liste-motif-refus" className="mt-1" rows={3} maxLength={500} value={motif} onChange={(e) => setMotif(e.target.value)} />
                            <p className="mt-0.5 text-[11px] text-gray-500">10 caractères au moins ; le gérant de la caisse le reçoit.</p>
                            {(erreurs.motif || erreurs.general) && <p className="text-xs text-red-600" role="alert">{erreurs.motif || erreurs.general}</p>}
                        </div>
                    ) : (
                        erreurs.general && <p className="text-xs text-red-600" role="alert">{erreurs.general}</p>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setChoix(null)} disabled={enCours}>Annuler</Button>
                        {choix?.action === 'refuser' ? (
                            <Button variant="destructive" onClick={confirmer} disabled={enCours}>Refuser la demande</Button>
                        ) : (
                            <Button onClick={confirmer} disabled={enCours}><Check className="mr-1 h-4 w-4" /> Confirmer</Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
