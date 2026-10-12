/**
 * Référentiel clients (spec v2.3, §6.5) : géré par la Comptabilité, le CDG et l'administrateur.
 * Import d'un fichier Excel ou CSV (code, raison sociale) avec aperçu avant application (PO-26), puis ajouts au fil de l'eau.
 * Un client ne se supprime pas : désactivé, il n'est plus proposé sur les ordres de mission.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Building2, FileUp, Loader2, Pencil, Plus, Power, Search } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

const FILTRES = { tous: 'Tous', actifs: 'Actifs', inactifs: 'Désactivés' };

function FicheClient({ client, onFermer }) {
    const form = useForm({ code: client?.code ?? '', raison_sociale: client?.raison_sociale ?? '' });
    const envoyer = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onFermer };
        if (client) form.put(route('clients.update', client.id), options);
        else form.post(route('clients.store'), options);
    };

    return (
        <Dialog open onOpenChange={(ouvert) => !ouvert && onFermer()}>
            <DialogContent>
                <form onSubmit={envoyer}>
                    <DialogHeader>
                        <DialogTitle>{client ? `Modifier le client ${client.code}` : 'Ajouter un client'}</DialogTitle>
                        <DialogDescription>Le code client est celui de la comptabilité ; il sert de clé à l'import.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 py-4">
                        <div>
                            <Label htmlFor="client-code">Code client</Label>
                            <Input id="client-code" value={form.data.code} maxLength={30} autoFocus
                                onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />
                            {form.errors.code && <p className="mt-1 text-sm text-red-600">{form.errors.code}</p>}
                        </div>
                        <div>
                            <Label htmlFor="client-raison">Raison sociale</Label>
                            <Input id="client-raison" value={form.data.raison_sociale} maxLength={150}
                                onChange={(e) => form.setData('raison_sociale', e.target.value)} />
                            {form.errors.raison_sociale && <p className="mt-1 text-sm text-red-600">{form.errors.raison_sociale}</p>}
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onFermer}>Annuler</Button>
                        <Button type="submit" disabled={form.processing}>{client ? 'Enregistrer' : 'Ajouter'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

const LIBELLES_ACTIONS = { cree: 'Créé', mis_a_jour: 'Mis à jour', inchange: 'Inchangé', ignore: 'Ignoré' };

function ImportClients({ onFermer }) {
    const [fichier, setFichier] = useState(null);
    const [resultat, setResultat] = useState(null);
    const [erreur, setErreur] = useState(null);
    const [enCours, setEnCours] = useState(false);

    const envoyer = async (appliquer) => {
        if (!fichier) return;
        setEnCours(true);
        setErreur(null);
        const donnees = new FormData();
        donnees.append('fichier', fichier);
        donnees.append('appliquer', appliquer ? '1' : '0');
        try {
            const { data } = await axios.post(route('clients.importer'), donnees);
            setResultat(data);
            if (data.applique) router.reload({ only: ['clients', 'totaux'] });
        } catch (e) {
            setErreur(e.response?.data?.errors?.fichier?.[0] ?? e.response?.data?.message ?? 'Le fichier n\'a pas pu être lu.');
        } finally {
            setEnCours(false);
        }
    };
    const aAppliquer = resultat && !resultat.applique && (resultat.resume.crees + resultat.resume.mis_a_jour) > 0;
    const ignores = resultat?.lignes.filter((l) => l.action === 'ignore') ?? [];

    return (
        <Dialog open onOpenChange={(ouvert) => !ouvert && onFermer()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Importer des clients</DialogTitle>
                    <DialogDescription>
                        Classeur Excel ou CSV avec une colonne « Code » et une colonne « Raison sociale ». Un aperçu s'affiche d'abord : rien n'est
                        enregistré avant « Appliquer ».
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input type="file" accept=".xlsx,.xls,.csv" className="max-w-sm" aria-label="Fichier des clients"
                            onChange={(e) => { setFichier(e.target.files?.[0] ?? null); setResultat(null); }} />
                        <Button type="button" variant="outline" disabled={!fichier || enCours} onClick={() => envoyer(false)}>
                            {enCours && !resultat ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : null} Aperçu
                        </Button>
                    </div>
                    {erreur && <p className="text-sm text-red-600" role="alert">{erreur}</p>}
                    {resultat && (
                        <div className="space-y-3">
                            <div className={cn('rounded-md p-3 text-sm', resultat.applique ? 'bg-green-50 text-green-900' : 'bg-gray-50 text-gray-800')} data-testid="resume-import">
                                {resultat.applique ? 'Import appliqué : ' : 'Aperçu : '}
                                {resultat.resume.crees} créé(s), {resultat.resume.mis_a_jour} mis à jour, {resultat.resume.inchanges} inchangé(s),
                                {' '}{resultat.resume.ignores} ignoré(s).
                            </div>
                            {ignores.length > 0 && (
                                <div className="max-h-40 overflow-y-auto rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
                                    <p className="mb-1 font-medium">Lignes ignorées</p>
                                    <ul className="space-y-0.5">
                                        {ignores.map((l) => <li key={l.ligne}>Ligne {l.ligne}{l.code ? ` (${l.code})` : ''} : {l.motif}</li>)}
                                    </ul>
                                </div>
                            )}
                            <div className="max-h-56 overflow-y-auto rounded-md border">
                                <table className="w-full text-xs">
                                    <thead className="sticky top-0 bg-gray-50 text-left text-gray-500">
                                        <tr><th className="px-2 py-1">Ligne</th><th className="px-2 py-1">Code</th><th className="px-2 py-1">Raison sociale</th><th className="px-2 py-1">Action</th></tr>
                                    </thead>
                                    <tbody>
                                        {resultat.lignes.filter((l) => l.action !== 'ignore').slice(0, 200).map((l) => (
                                            <tr key={l.ligne} className="border-t">
                                                <td className="px-2 py-1 text-gray-500">{l.ligne}</td>
                                                <td className="px-2 py-1 font-mono">{l.code}</td>
                                                <td className="px-2 py-1">{l.raison_sociale}</td>
                                                <td className="px-2 py-1">{LIBELLES_ACTIONS[l.action]}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onFermer}>{resultat?.applique ? 'Fermer' : 'Annuler'}</Button>
                    {aAppliquer && (
                        <Button type="button" disabled={enCours} onClick={() => envoyer(true)}>
                            {enCours && <Loader2 className="mr-1 h-4 w-4 animate-spin" />} Appliquer
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function Clients({ clients, filtres = { q: '', statut: 'tous' }, totaux = { actifs: 0, tous: 0 } }) {
    const [recherche, setRecherche] = useState(filtres.q ?? '');
    const [fiche, setFiche] = useState(null);       // null | 'nouveau' | client
    const [importer, setImporter] = useState(false);
    const premier = useRef(true);

    /* Recherche au fil de la frappe */
    useEffect(() => {
        if (premier.current) { premier.current = false; return undefined; }
        const minuterie = setTimeout(() => {
            router.get(route('clients.index'), { q: recherche || undefined, statut: filtres.statut !== 'tous' ? filtres.statut : undefined },
                { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
        return () => clearTimeout(minuterie);
    }, [recherche]);   // eslint-disable-line react-hooks/exhaustive-deps

    const filtrer = (statut) => router.get(route('clients.index'), { q: recherche || undefined, statut: statut !== 'tous' ? statut : undefined },
        { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header="Clients">
            <Head title="Clients" />

            <div className="mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
                <Card>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-lg"><Building2 className="h-5 w-5 text-neemba-600" /> Clients</CardTitle>
                            <p className="mt-1 text-sm text-gray-500">
                                {totaux.actifs} actif(s) sur {totaux.tous}. Proposés sur les ordres de mission et dans la prise en charge des frais.
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={() => setImporter(true)}><FileUp className="mr-1 h-4 w-4" /> Importer</Button>
                            <Button onClick={() => setFiche('nouveau')}><Plus className="mr-1 h-4 w-4" /> Ajouter</Button>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" />
                                <Input value={recherche} onChange={(e) => setRecherche(e.target.value)} placeholder="Code ou raison sociale" className="pl-8" aria-label="Rechercher un client" />
                            </div>
                            <div role="radiogroup" aria-label="Statut" className="inline-flex rounded-md border border-gray-200 bg-gray-100 p-0.5">
                                {Object.entries(FILTRES).map(([valeur, libelle]) => (
                                    <button key={valeur} type="button" role="radio" aria-checked={filtres.statut === valeur} onClick={() => filtrer(valeur)}
                                        className={cn('rounded px-3 py-1.5 text-sm font-medium', filtres.statut === valeur ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900')}>
                                        {libelle}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {clients.data.length === 0 ? (
                            <p className="py-10 text-center text-sm text-gray-500">
                                {filtres.q ? 'Aucun client ne correspond à la recherche.' : 'Aucun client : importez le fichier de Neemba ou ajoutez-en un.'}
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Code</TableHead>
                                            <TableHead>Raison sociale</TableHead>
                                            <TableHead className="text-right">ODM</TableHead>
                                            <TableHead>Statut</TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {clients.data.map((c) => (
                                            <TableRow key={c.id} className={c.actif ? '' : 'text-gray-400'}>
                                                <TableCell className="font-mono text-sm">{c.code}</TableCell>
                                                <TableCell>{c.raison_sociale}</TableCell>
                                                <TableCell className="text-right tabular-nums">{c.odm}</TableCell>
                                                <TableCell>
                                                    <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', c.actif ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500')}>
                                                        {c.actif ? 'Actif' : 'Désactivé'}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap text-right">
                                                    <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`Modifier ${c.code}`} onClick={() => setFiche(c)}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`${c.actif ? 'Désactiver' : 'Réactiver'} ${c.code}`}
                                                        onClick={() => router.post(route('clients.basculer', c.id), {}, { preserveScroll: true })}>
                                                        <Power className={cn('h-4 w-4', c.actif ? 'text-red-500' : 'text-green-600')} />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {clients.last_page > 1 && (
                            <div className="flex flex-col items-center justify-between gap-2 border-t pt-3 sm:flex-row">
                                <p className="text-sm text-gray-500">{clients.from} à {clients.to} sur {clients.total}</p>
                                <div className="flex flex-wrap justify-center gap-1">
                                    {clients.links.map((lien, index) => (
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

            {fiche && <FicheClient client={fiche === 'nouveau' ? null : fiche} onFermer={() => setFiche(null)} />}
            {importer && <ImportClients onFermer={() => setImporter(false)} />}
        </AuthenticatedLayout>
    );
}
