/**
 * Liste des ordres de mission (M12) : les siens, ceux de son service (chef d'atelier), tous pour le DAF, le DP,
 * la Trésorerie, les RH et l'administration. Le DAF y trouve les dérogations au chevauchement à décider (RG-M12-16).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Eye, Pencil, Plus, Search, ShieldAlert, Stamp } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/Components/ui/dialog';
import BadgeStatutOdm from '@/Components/Odm/BadgeStatutOdm';

const SELECT = 'h-9 rounded-md border border-input bg-white px-3 text-sm';

function DecisionDerogation({ odm, onFermer }) {
    const form = useForm({ decision: 'accorder', motif: '' });

    const envoyer = (decision) => {
        form.transform((d) => ({ ...d, decision }));
        form.post(route('odm.derogation', odm.id), { preserveScroll: true, onSuccess: onFermer });
    };

    return (
        <Dialog open onOpenChange={(ouvert) => !ouvert && onFermer()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Dérogation au chevauchement — {odm.libelle}</DialogTitle>
                    <DialogDescription>Demande de {odm.demandeur} : {odm.derogation_demande_motif}</DialogDescription>
                </DialogHeader>
                <ul className="list-disc space-y-1 pl-5 text-sm text-gray-700">
                    {odm.conflits.map((c, i) => (
                        <li key={i}>{c.participant} est déjà en mission du {c.debut} au {c.fin} (ODM {c.numero}).</li>
                    ))}
                </ul>
                <div>
                    <label htmlFor="motif-derogation" className="text-sm font-medium">Motif de votre décision</label>
                    <Textarea id="motif-derogation" rows={3} value={form.data.motif} onChange={(e) => form.setData('motif', e.target.value)} className="mt-1" />
                    {form.errors.motif && <p className="mt-1 text-sm text-red-600">{form.errors.motif}</p>}
                    {form.errors.decision && <p className="mt-1 text-sm text-red-600">{form.errors.decision}</p>}
                </div>
                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => envoyer('refuser')} disabled={form.processing}>Refuser</Button>
                    <Button onClick={() => envoyer('accorder')} disabled={form.processing}>Accorder la dérogation</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Index({ odms, filtres = {}, statuts = {}, types = {}, peutCreer = false, derogations = [], aViser = [] }) {
    const [recherche, setRecherche] = useState(filtres.recherche ?? '');
    const [derogation, setDerogation] = useState(null);
    const premier = useRef(true);

    const filtrer = (params) => {
        router.get(route('odm.index'), { ...filtres, ...params }, { preserveState: true, preserveScroll: true, replace: true });
    };

    /* Recherche à la frappe (300 ms) */
    useEffect(() => {
        if (premier.current) {
            premier.current = false;
            return undefined;
        }
        const minuterie = setTimeout(() => filtrer({ recherche: recherche || undefined, page: undefined }), 300);
        return () => clearTimeout(minuterie);
    }, [recherche]);

    return (
        <AuthenticatedLayout header="Ordres de mission">
            <Head title="Ordres de mission" />

            <div className="mx-auto max-w-7xl space-y-4 p-4 sm:p-6">
                {aViser.length > 0 && (
                    <Card className="border-blue-200">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base text-blue-900">
                                <Stamp className="h-4 w-4" /> Ordres de mission à viser ({aViser.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {aViser.map((o) => (
                                <div key={o.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm">
                                    <div className="min-w-0">
                                        <p className="font-medium text-gray-900">{o.libelle} · {o.service} · {o.periode}</p>
                                        <p className="text-gray-600">{o.destinations.join(', ')} · {o.participants} participant(s) · {o.total_format} · demandé par {o.demandeur}</p>
                                    </div>
                                    <Link href={route('odm.show', o.id)}><Button size="sm">Examiner</Button></Link>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {derogations.length > 0 && (
                    <Card className="border-amber-200">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base text-amber-800">
                                <ShieldAlert className="h-4 w-4" /> Dérogations au chevauchement à décider ({derogations.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {derogations.map((d) => (
                                <div key={d.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm">
                                    <div className="min-w-0">
                                        <p className="font-medium text-gray-900">{d.libelle} · {d.demandeur} · {d.periode}</p>
                                        <p className="text-gray-600">{d.derogation_demande_motif}</p>
                                    </div>
                                    <Button size="sm" variant="outline" onClick={() => setDerogation(d)}>Examiner</Button>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="flex flex-col gap-3 pb-3 sm:flex-row sm:items-center sm:justify-between">
                        <CardTitle className="text-base">Ordres de mission</CardTitle>
                        {peutCreer && (
                            <Link href={route('odm.create')}>
                                <Button size="sm"><Plus className="mr-1 h-4 w-4" /> Nouvel ordre de mission</Button>
                            </Link>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-3 p-0 sm:p-0">
                        <div className="flex flex-wrap gap-2 px-4">
                            <div className="relative min-w-[220px] flex-1">
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                                <Input value={recherche} onChange={(e) => setRecherche(e.target.value)} placeholder="Numéro, destination, but, participant…" className="pl-9" />
                            </div>
                            <select className={SELECT} value={filtres.statut ?? ''} onChange={(e) => filtrer({ statut: e.target.value || undefined, page: undefined })} aria-label="Statut">
                                <option value="">Tous les statuts</option>
                                {Object.entries(statuts).map(([valeur, libelle]) => <option key={valeur} value={valeur}>{libelle}</option>)}
                            </select>
                            <select className={SELECT} value={filtres.type ?? ''} onChange={(e) => filtrer({ type: e.target.value || undefined, page: undefined })} aria-label="Type">
                                <option value="">Tous les types</option>
                                {Object.entries(types).map(([valeur, libelle]) => <option key={valeur} value={valeur}>{libelle}</option>)}
                            </select>
                        </div>

                        {odms.data.length === 0 ? (
                            <p className="py-12 text-center text-sm text-gray-400">Aucun ordre de mission.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table className="[&_td]:px-3 [&_th]:px-3">
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="text-xs">Numéro</TableHead>
                                            <TableHead className="hidden text-xs sm:table-cell">Type</TableHead>
                                            <TableHead className="hidden text-xs md:table-cell">Destination(s)</TableHead>
                                            <TableHead className="text-xs">Période</TableHead>
                                            <TableHead className="hidden text-center text-xs sm:table-cell">Participants</TableHead>
                                            <TableHead className="text-right text-xs">Total</TableHead>
                                            <TableHead className="text-xs">Statut</TableHead>
                                            <TableHead className="text-right text-xs">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {odms.data.map((odm) => (
                                            <TableRow key={odm.id}>
                                                <TableCell className="py-2 text-sm font-medium">
                                                    {odm.libelle}
                                                    {odm.libelle_prolongation && <p className="text-xs font-normal text-gray-500">{odm.libelle_prolongation}</p>}
                                                    <p className="text-xs font-normal text-gray-500">{odm.service}</p>
                                                </TableCell>
                                                <TableCell className="hidden py-2 text-sm sm:table-cell">{odm.type_label}</TableCell>
                                                <TableCell className="hidden max-w-[180px] truncate py-2 text-sm md:table-cell">{odm.destinations.join(', ') || '—'}</TableCell>
                                                <TableCell className="whitespace-nowrap py-2 text-sm">{odm.periode}</TableCell>
                                                <TableCell className="hidden py-2 text-center text-sm sm:table-cell">{odm.participants}</TableCell>
                                                <TableCell className="whitespace-nowrap py-2 text-right text-sm tabular-nums">{odm.total_format}</TableCell>
                                                <TableCell className="py-2"><BadgeStatutOdm statut={odm.statut} /></TableCell>
                                                <TableCell className="py-2 text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Link href={route('odm.show', odm.id)} title="Voir">
                                                            <Button variant="ghost" size="icon" className="h-8 w-8"><Eye className="h-4 w-4" /></Button>
                                                        </Link>
                                                        {odm.modifiable && (
                                                            <Link href={route('odm.edit', odm.id)} title={odm.statut === 'REJETE' ? 'Corriger et resoumettre' : 'Reprendre'}>
                                                                <Button variant="ghost" size="icon" className="h-8 w-8"><Pencil className="h-4 w-4" /></Button>
                                                            </Link>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {odms.last_page > 1 && (
                            <div className="flex flex-col items-center justify-between gap-2 border-t px-4 py-3 sm:flex-row">
                                <p className="text-sm text-gray-500">{odms.from} à {odms.to} sur {odms.total}</p>
                                <div className="flex flex-wrap justify-center gap-1">
                                    {odms.links.map((lien, index) => (
                                        <Link
                                            key={index}
                                            href={lien.url || '#'}
                                            preserveScroll
                                            className={`rounded-md px-3 py-1.5 text-sm ${lien.active ? 'bg-neemba-400 font-semibold text-marine-950' : lien.url ? 'text-gray-600 hover:bg-gray-100' : 'cursor-not-allowed text-gray-300'}`}
                                            dangerouslySetInnerHTML={{ __html: lien.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {derogation && <DecisionDerogation odm={derogation} onFermer={() => setDerogation(null)} />}
        </AuthenticatedLayout>
    );
}
