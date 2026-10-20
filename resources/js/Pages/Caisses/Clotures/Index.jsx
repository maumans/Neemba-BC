/**
 * Clôture journalière par caisse (spec v2.4, RG-M09-01 à RG-M09-05) : journée en cours de chaque caisse, clôture par le
 * gérant (à défaut, automatique à 23 h 45), historique, rapport « REPORTING CAISSE » en PDF ou Excel.
 */
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileSpreadsheet, FileText, Lock, LockKeyhole } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

const SELECT = 'mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm';
const aujourdhui = () => new Date().toISOString().slice(0, 10);

export default function Index({ caisses = [], clotures, filtres = {} }) {
    const [aCloturer, setACloturer] = useState(null);
    const form = useForm({ caisse_id: '', observations: '' });
    const [periode, setPeriode] = useState({ caisse: filtres.caisse ?? caisses[0]?.id ?? '', du: aujourdhui(), au: aujourdhui() });

    const ouvrir = (caisse) => {
        form.setData({ caisse_id: caisse.id, observations: '' });
        setACloturer(caisse);
    };
    const cloturer = () => form.post(route('clotures-caisse.store'), { preserveScroll: true, onSuccess: () => setACloturer(null) });
    const filtrer = (caisse) => router.get(route('clotures-caisse.index'), caisse ? { caisse } : {}, { preserveState: true, replace: true });
    const lien = (format, caisse, du, au) => route('clotures-caisse.reporting', { format, caisse, du, au });

    return (
        <AuthenticatedLayout header="Clôtures de caisse">
            <Head title="Clôtures de caisse" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-lg"><LockKeyhole className="h-5 w-5 text-neemba-600" /> Journée en cours</CardTitle>
                        <CardDescription>
                            Le gérant clôture la journée de sa caisse ; à défaut, elle est clôturée à 23 h 45. Une journée clôturée n'accepte plus
                            aucune opération. Le rapport « REPORTING CAISSE » part avant 8 h aux destinataires de la caisse.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-2">
                        {caisses.map((c) => (
                            <div key={c.id} className="rounded-md border p-3 text-sm" data-testid={`journee-${c.id}`}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-medium">{c.libelle}</p>
                                    {c.close ? (
                                        <span className="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700"><Lock className="h-3.5 w-3.5" /> Clôturée</span>
                                    ) : c.peut_cloturer ? (
                                        <Button size="sm" onClick={() => ouvrir(c)}>Clôturer la journée</Button>
                                    ) : null}
                                </div>
                                <div className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs sm:grid-cols-4">
                                    <p><span className="text-gray-500">Initial</span><br /><span className="tabular-nums">{c.solde_initial}</span></p>
                                    <p><span className="text-gray-500">+ Encaissements</span><br /><span className="tabular-nums">{c.encaissements}</span></p>
                                    <p><span className="text-gray-500">− Décaissements</span><br /><span className="tabular-nums">{c.decaissements}</span></p>
                                    <p><span className="text-gray-500">Final</span><br /><strong className="tabular-nums">{c.solde_final}</strong></p>
                                </div>
                                <p className="mt-1 text-xs text-gray-500">
                                    {c.operations} opération(s)
                                    {c.ecart !== null && c.ecart !== 0 && <span className="ml-2 font-semibold text-red-700">Écart avec la veille : {c.ecart_format}</span>}
                                </p>
                            </div>
                        ))}
                        {caisses.length === 0 && <p className="text-sm text-gray-400">Aucune caisse.</p>}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Rapport « REPORTING CAISSE »</CardTitle>
                        <CardDescription>Une feuille par jour et l'onglet « Détail » (valideurs et délais par étape), sur 62 jours au plus.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-4">
                        <div className="sm:col-span-2">
                            <Label htmlFor="rep-caisse">Caisse</Label>
                            <select id="rep-caisse" className={SELECT} value={periode.caisse} onChange={(e) => setPeriode({ ...periode, caisse: e.target.value })}>
                                {caisses.map((c) => <option key={c.id} value={c.id}>{c.libelle}</option>)}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="rep-du">Du</Label>
                            <Input id="rep-du" type="date" className="mt-1" value={periode.du} onChange={(e) => setPeriode({ ...periode, du: e.target.value })} />
                        </div>
                        <div>
                            <Label htmlFor="rep-au">Au</Label>
                            <Input id="rep-au" type="date" className="mt-1" value={periode.au} onChange={(e) => setPeriode({ ...periode, au: e.target.value })} />
                        </div>
                        <div className="flex flex-wrap gap-2 sm:col-span-4">
                            <a href={lien('pdf', periode.caisse, periode.du, periode.au)} target="_blank" rel="noreferrer">
                                <Button variant="outline" size="sm" disabled={!periode.caisse}><FileText className="mr-1 h-4 w-4" /> PDF</Button>
                            </a>
                            <a href={lien('excel', periode.caisse, periode.du, periode.au)}>
                                <Button variant="outline" size="sm" disabled={!periode.caisse}><FileSpreadsheet className="mr-1 h-4 w-4" /> Excel</Button>
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="text-base">Journées clôturées</CardTitle></CardHeader>
                    <CardContent className="space-y-3">
                        {caisses.length > 1 && (
                            <select aria-label="Caisse" value={filtres.caisse ?? ''} onChange={(e) => filtrer(e.target.value)}
                                className="h-9 max-w-full rounded-md border border-input bg-white px-2 text-sm">
                                <option value="">Toutes les caisses</option>
                                {caisses.map((c) => <option key={c.id} value={c.id}>{c.libelle}</option>)}
                            </select>
                        )}
                        <div className="overflow-x-auto rounded-md border bg-white">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                        <th className="px-3 py-2">Journée</th>
                                        <th className="px-3 py-2">Caisse</th>
                                        <th className="px-3 py-2 text-right">Initial</th>
                                        <th className="px-3 py-2 text-right">+ Enc.</th>
                                        <th className="px-3 py-2 text-right">− Déc.</th>
                                        <th className="px-3 py-2 text-right">Final</th>
                                        <th className="px-3 py-2 text-right">Écart veille</th>
                                        <th className="px-3 py-2">Clôture</th>
                                        <th className="px-3 py-2">Rapport</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {clotures.data.map((c) => (
                                        <tr key={c.id} className="border-b last:border-0">
                                            <td className="whitespace-nowrap px-3 py-2 font-medium">{c.date}</td>
                                            <td className="px-3 py-2">{c.caisse}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{c.solde_initial}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{c.encaissements}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{c.decaissements}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums">{c.solde_final}</td>
                                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${c.ecart ? 'font-semibold text-red-700' : 'text-gray-500'}`}>{c.ecart_format}</td>
                                            <td className="px-3 py-2 text-xs">{c.par}<br /><span className="text-gray-500">{c.le}</span></td>
                                            <td className="whitespace-nowrap px-3 py-2 text-xs">
                                                <a href={lien('pdf', c.caisse_id, c.date_iso, c.date_iso)} target="_blank" rel="noreferrer" className="text-neemba-700 hover:underline">PDF</a>
                                                {' · '}
                                                <a href={lien('excel', c.caisse_id, c.date_iso, c.date_iso)} className="text-neemba-700 hover:underline">Excel</a>
                                                <br /><span className="text-gray-500">{c.envoye_le ? `Envoyé le ${c.envoye_le}` : c.operations === 0 ? 'Sans opération' : 'Non envoyé'}</span>
                                            </td>
                                        </tr>
                                    ))}
                                    {clotures.data.length === 0 && (
                                        <tr><td colSpan={9} className="px-3 py-8 text-center text-gray-400">Aucune journée clôturée.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {clotures.last_page > 1 && (
                            <div className="flex flex-wrap gap-1">
                                {clotures.links.map((l, i) => (
                                    <Link key={i} href={l.url ?? '#'} preserveScroll
                                        className={`rounded px-2 py-1 text-xs ${l.active ? 'bg-neemba-500 text-white' : 'border bg-white'} ${l.url ? '' : 'pointer-events-none opacity-40'}`}
                                        dangerouslySetInnerHTML={{ __html: l.label }} />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={aCloturer !== null} onOpenChange={(ouvert) => !ouvert && setACloturer(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Clôturer la journée — {aCloturer?.libelle}</DialogTitle>
                        <DialogDescription>
                            Solde final {aCloturer?.solde_final}. Après la clôture, plus aucune opération (paiement, encaissement, réception)
                            n'est possible sur la caisse aujourd'hui.
                        </DialogDescription>
                    </DialogHeader>
                    <div>
                        <Label htmlFor="cloture-observations">Observations (facultatif)</Label>
                        <Textarea id="cloture-observations" rows={2} className="mt-1" value={form.data.observations} onChange={(e) => form.setData('observations', e.target.value)} />
                        {form.errors.general && <p className="mt-1 text-xs text-red-600">{form.errors.general}</p>}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setACloturer(null)}>Annuler</Button>
                        <Button onClick={cloturer} disabled={form.processing}>Clôturer</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
