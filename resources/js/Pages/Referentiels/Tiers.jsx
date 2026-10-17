/**
 * Référentiel des tiers (spec v2.3, §6.5, RG-M02-06, RG-M02-07) : la Comptabilité, le CDG et la direction financière
 * valident ou refusent (avec un motif) les tiers proposés par les demandeurs, en ajoutent, les modifient et les
 * désactivent. Un tiers au même téléphone, n° OM ou NIF qu'un autre est refusé. L'administrateur consulte.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Building2, Check, Pencil, Plus, Power, Search, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import TelephoneInput from '@/Components/TelephoneInput';
import { cn } from '@/lib/utils';

const COULEURS = {
    propose: 'bg-amber-100 text-amber-800',
    valide: 'bg-green-100 text-green-800',
    refuse: 'bg-red-100 text-red-700',
    desactive: 'bg-gray-100 text-gray-500',
};

function FicheTiers({ tiers, types, onFermer }) {
    const form = useForm({
        nom: tiers?.nom ?? '', type: tiers?.type ?? 'fournisseur', telephone: tiers?.telephone ?? '', numero_om: tiers?.numero_om ?? '', nif: tiers?.nif ?? '',
    });
    const envoyer = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onFermer };
        if (tiers) form.put(route('tiers.update', tiers.id), options);
        else form.post(route('tiers.store'), options);
    };

    return (
        <Dialog open onOpenChange={(ouvert) => !ouvert && onFermer()}>
            <DialogContent>
                <form onSubmit={envoyer}>
                    <DialogHeader>
                        <DialogTitle>{tiers ? `Modifier ${tiers.nom}` : 'Ajouter un tiers'}</DialogTitle>
                        <DialogDescription>{tiers ? 'Le téléphone, le n° OM et le NIF restent uniques.' : 'Un tiers ajouté ici est validé d\'emblée.'}</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3 py-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <Label htmlFor="fiche-nom">Raison sociale ou nom</Label>
                            <Input id="fiche-nom" value={form.data.nom} maxLength={150} autoFocus onChange={(e) => form.setData('nom', e.target.value)} />
                            {form.errors.nom && <p className="mt-1 text-sm text-red-600" role="alert">{form.errors.nom}</p>}
                        </div>
                        <div>
                            <Label htmlFor="fiche-type">Type</Label>
                            <select id="fiche-type" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}
                                className="mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm">
                                {Object.entries(types).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="fiche-nif">NIF</Label>
                            <Input id="fiche-nif" value={form.data.nif} maxLength={40} onChange={(e) => form.setData('nif', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="fiche-telephone">Téléphone</Label>
                            <TelephoneInput id="fiche-telephone" value={form.data.telephone} onChange={(v) => form.setData('telephone', v)} />
                            {form.errors.telephone && <p className="mt-1 text-sm text-red-600">{form.errors.telephone}</p>}
                        </div>
                        <div>
                            <Label htmlFor="fiche-om">N° Orange Money</Label>
                            <TelephoneInput id="fiche-om" value={form.data.numero_om} onChange={(v) => form.setData('numero_om', v)} />
                            {form.errors.numero_om && <p className="mt-1 text-sm text-red-600">{form.errors.numero_om}</p>}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onFermer}>Annuler</Button>
                        <Button type="submit" disabled={form.processing}>{tiers ? 'Enregistrer' : 'Ajouter'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** RG-M02-06 : refus motivé */
function Refus({ tiers, onFermer }) {
    const form = useForm({ decision: 'refuser', motif: '' });
    const envoyer = (e) => {
        e.preventDefault();
        form.post(route('tiers.decider', tiers.id), { preserveScroll: true, onSuccess: onFermer });
    };

    return (
        <Dialog open onOpenChange={(ouvert) => !ouvert && onFermer()}>
            <DialogContent>
                <form onSubmit={envoyer}>
                    <DialogHeader>
                        <DialogTitle>Refuser {tiers.nom}</DialogTitle>
                        <DialogDescription>Le demandeur qui l'a proposé reçoit le motif.</DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                        <Label htmlFor="refus-motif">Motif du refus</Label>
                        <Textarea id="refus-motif" rows={3} value={form.data.motif} onChange={(e) => form.setData('motif', e.target.value)} placeholder="10 caractères minimum" />
                        {form.errors.motif && <p className="mt-1 text-sm text-red-600">{form.errors.motif}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onFermer}>Annuler</Button>
                        <Button type="submit" variant="destructive" disabled={form.processing}>Refuser</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Tiers({ tiers, filtres = { q: '', statut: 'propose' }, compteurs = {}, types = {}, statuts = {}, peutValider = false }) {
    const [recherche, setRecherche] = useState(filtres.q ?? '');
    const [fiche, setFiche] = useState(null);
    const [refus, setRefus] = useState(null);
    const premier = useRef(true);

    useEffect(() => {
        if (premier.current) { premier.current = false; return undefined; }
        const minuterie = setTimeout(() => {
            router.get(route('tiers.index'), { q: recherche || undefined, statut: filtres.statut }, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
        return () => clearTimeout(minuterie);
    }, [recherche]);   // eslint-disable-line react-hooks/exhaustive-deps

    const filtrer = (statut) => router.get(route('tiers.index'), { q: recherche || undefined, statut }, { preserveState: true, preserveScroll: true });
    const valider = (t) => router.post(route('tiers.decider', t.id), { decision: 'valider' }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header="Tiers">
            <Head title="Tiers" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                <Card>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-lg"><Building2 className="h-5 w-5 text-neemba-600" /> Tiers</CardTitle>
                            <p className="mt-1 text-sm text-gray-500">
                                Bénéficiaires non salariés. Un tiers proposé par un demandeur n'est utilisable qu'une fois validé par la comptabilité ou le contrôle de gestion.
                            </p>
                        </div>
                        {peutValider && <Button onClick={() => setFiche('nouveau')}><Plus className="mr-1 h-4 w-4" /> Ajouter</Button>}
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" />
                                <Input value={recherche} onChange={(e) => setRecherche(e.target.value)} placeholder="Nom, téléphone, n° OM ou NIF" className="pl-8" aria-label="Rechercher un tiers" />
                            </div>
                            <div role="radiogroup" aria-label="Statut" className="inline-flex flex-wrap rounded-md border border-gray-200 bg-gray-100 p-0.5">
                                {Object.entries(statuts).map(([valeur, libelle]) => (
                                    <button key={valeur} type="button" role="radio" aria-checked={filtres.statut === valeur} onClick={() => filtrer(valeur)}
                                        className={cn('rounded px-3 py-1.5 text-sm font-medium', filtres.statut === valeur ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900')}>
                                        {libelle.split(',')[0]} <span className="ml-1 tabular-nums text-xs text-gray-500">{compteurs[valeur] ?? 0}</span>
                                    </button>
                                ))}
                            </div>
                        </div>

                        {tiers.data.length === 0 ? (
                            <p className="py-10 text-center text-sm text-gray-500">
                                {filtres.statut === 'propose' ? 'Aucun tiers à valider.' : 'Aucun tiers dans cette liste.'}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Tiers</TableHead>
                                            <TableHead>Téléphone · OM</TableHead>
                                            <TableHead>NIF</TableHead>
                                            <TableHead>{filtres.statut === 'propose' ? 'Proposé par' : 'Décision'}</TableHead>
                                            <TableHead className="text-right">Bons</TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {tiers.data.map((t) => (
                                            <TableRow key={t.id}>
                                                <TableCell>
                                                    <p className="font-medium">{t.nom}</p>
                                                    <p className="text-xs text-gray-500">
                                                        {t.type_label}
                                                        <span className={cn('ml-2 rounded-full px-2 py-0.5 font-medium', COULEURS[t.statut])}>{t.statut_label}</span>
                                                        {t.origine === 'reprise' && <span className="ml-1 text-gray-400">· repris des anciens bons</span>}
                                                    </p>
                                                    {t.motif_refus && <p className="text-xs text-red-700">Motif : {t.motif_refus}</p>}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap font-mono text-xs">
                                                    {t.telephone ?? '—'}{t.numero_om && t.numero_om !== t.telephone ? <><br />OM {t.numero_om}</> : null}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">{t.nif ?? '—'}</TableCell>
                                                <TableCell className="text-xs">
                                                    {filtres.statut === 'propose'
                                                        ? <>{t.propose_par ?? '—'}<br /><span className="text-gray-500">{t.propose_le}</span></>
                                                        : <>{t.valide_par ?? '—'}<br /><span className="text-gray-500">{t.valide_le}</span></>}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{t.bons}</TableCell>
                                                <TableCell className="whitespace-nowrap text-right">
                                                    {peutValider && t.statut === 'propose' && (
                                                        <>
                                                            <Button size="sm" className="mr-1 bg-green-600 hover:bg-green-700" onClick={() => valider(t)} aria-label={`Valider ${t.nom}`}>
                                                                <Check className="mr-1 h-4 w-4" /> Valider
                                                            </Button>
                                                            <Button size="sm" variant="outline" className="mr-1 text-red-700" onClick={() => setRefus(t)} aria-label={`Refuser ${t.nom}`}>
                                                                <X className="mr-1 h-4 w-4" /> Refuser
                                                            </Button>
                                                        </>
                                                    )}
                                                    {peutValider && (
                                                        <>
                                                            <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`Modifier ${t.nom}`} onClick={() => setFiche(t)}>
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                            {['valide', 'desactive'].includes(t.statut) && (
                                                                <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`${t.statut === 'valide' ? 'Désactiver' : 'Réactiver'} ${t.nom}`}
                                                                    onClick={() => router.post(route('tiers.basculer', t.id), {}, { preserveScroll: true })}>
                                                                    <Power className={cn('h-4 w-4', t.statut === 'valide' ? 'text-red-500' : 'text-green-600')} />
                                                                </Button>
                                                            )}
                                                        </>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {tiers.last_page > 1 && (
                            <div className="flex flex-col items-center justify-between gap-2 border-t pt-3 sm:flex-row">
                                <p className="text-sm text-gray-500">{tiers.from} à {tiers.to} sur {tiers.total}</p>
                                <div className="flex flex-wrap justify-center gap-1">
                                    {tiers.links.map((lien, index) => (
                                        <Link key={index} href={lien.url || '#'} preserveScroll
                                            className={`rounded-md px-3 py-1.5 text-sm ${lien.active ? 'bg-neemba-400 font-semibold text-marine-950' : lien.url ? 'text-gray-600 hover:bg-gray-100' : 'cursor-not-allowed text-gray-300'}`}
                                            dangerouslySetInnerHTML={{ __html: lien.label }} />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {fiche && <FicheTiers tiers={fiche === 'nouveau' ? null : fiche} types={types} onFermer={() => setFiche(null)} />}
            {refus && <Refus tiers={refus} onFermer={() => setRefus(null)} />}
        </AuthenticatedLayout>
    );
}
