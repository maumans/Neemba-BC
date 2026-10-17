/**
 * Référentiel des visiteurs du groupe (spec v2.3, §6.5) : salariés d'autres filiales en mission en Guinée. Ils peuvent
 * recevoir un BP ; le directeur du département d'accueil répond de sa régularisation (RG-M03-04, RG-M07-01).
 * Tenu par les RH, la direction financière et l'administrateur. Un visiteur se désactive, il ne se supprime pas.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Pencil, Plus, Power, Search, Users } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import TelephoneInput from '@/Components/TelephoneInput';
import { cn } from '@/lib/utils';

const FILTRES = { actifs: 'Actifs', inactifs: 'Désactivés', tous: 'Tous' };

function Erreur({ message }) {
    return message ? <p className="mt-1 text-sm text-red-600" role="alert">{message}</p> : null;
}

function FicheVisiteur({ visiteur, services, directeurs, onFermer }) {
    const form = useForm({
        nom: visiteur?.nom ?? '', prenom: visiteur?.prenom ?? '', filiale: visiteur?.filiale ?? '', matricule_groupe: visiteur?.matricule_groupe ?? '',
        numero_om: visiteur?.numero_om ?? '', departement_accueil: visiteur?.departement_accueil ?? '', directeur_id: visiteur?.directeur_id ?? '',
        date_debut: visiteur?.date_debut ?? '', date_fin: visiteur?.date_fin ?? '',
    });
    const envoyer = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onFermer };
        if (visiteur) form.put(route('visiteurs.update', visiteur.id), options);
        else form.post(route('visiteurs.store'), options);
    };
    const champ = (nom, libelle, props = {}) => (
        <div>
            <Label htmlFor={`visiteur-${nom}`}>{libelle}</Label>
            <Input id={`visiteur-${nom}`} value={form.data[nom]} onChange={(e) => form.setData(nom, e.target.value)} {...props} />
            <Erreur message={form.errors[nom]} />
        </div>
    );

    return (
        <Dialog open onOpenChange={(ouvert) => !ouvert && onFermer()}>
            <DialogContent className="max-w-2xl">
                <form onSubmit={envoyer}>
                    <DialogHeader>
                        <DialogTitle>{visiteur ? `Modifier ${visiteur.nom_complet}` : 'Ajouter un visiteur du groupe'}</DialogTitle>
                        <DialogDescription>Le directeur désigné répond de la régularisation des bons provisoires du visiteur.</DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3 py-4 sm:grid-cols-2">
                        {champ('nom', 'Nom', { maxLength: 100, autoFocus: true })}
                        {champ('prenom', 'Prénom', { maxLength: 100 })}
                        {champ('filiale', "Filiale d'origine", { maxLength: 120, placeholder: "Ex. Neemba Côte d'Ivoire" })}
                        {champ('matricule_groupe', 'Matricule groupe', { maxLength: 40 })}
                        <div>
                            <Label htmlFor="visiteur-numero_om">N° Orange Money</Label>
                            <TelephoneInput id="visiteur-numero_om" value={form.data.numero_om} onChange={(v) => form.setData('numero_om', v)} />
                            <Erreur message={form.errors.numero_om} />
                        </div>
                        <div>
                            <Label htmlFor="visiteur-departement">Département d'accueil</Label>
                            <select id="visiteur-departement" value={form.data.departement_accueil} onChange={(e) => form.setData('departement_accueil', e.target.value)}
                                className="mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm">
                                <option value="">Choisir…</option>
                                {services.map((s) => <option key={s} value={s}>{s}</option>)}
                            </select>
                            <Erreur message={form.errors.departement_accueil} />
                        </div>
                        <div className="sm:col-span-2">
                            <Label htmlFor="visiteur-directeur">Directeur responsable de la régularisation</Label>
                            <select id="visiteur-directeur" value={form.data.directeur_id} onChange={(e) => form.setData('directeur_id', e.target.value ? Number(e.target.value) : '')}
                                className="mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm">
                                <option value="">Choisir…</option>
                                {directeurs.map((d) => <option key={d.id} value={d.id}>{d.nom}{d.service ? ` — ${d.service}` : ''}</option>)}
                            </select>
                            <Erreur message={form.errors.directeur_id} />
                        </div>
                        {champ('date_debut', 'Début du séjour', { type: 'date' })}
                        {champ('date_fin', 'Fin du séjour', { type: 'date' })}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onFermer}>Annuler</Button>
                        <Button type="submit" disabled={form.processing}>{visiteur ? 'Enregistrer' : 'Ajouter'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Visiteurs({ visiteurs, filtres = { q: '', statut: 'actifs' }, services = [], directeurs = [] }) {
    const [recherche, setRecherche] = useState(filtres.q ?? '');
    const [fiche, setFiche] = useState(null);
    const premier = useRef(true);

    useEffect(() => {
        if (premier.current) { premier.current = false; return undefined; }
        const minuterie = setTimeout(() => {
            router.get(route('visiteurs.index'), { q: recherche || undefined, statut: filtres.statut }, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
        return () => clearTimeout(minuterie);
    }, [recherche]);   // eslint-disable-line react-hooks/exhaustive-deps

    const filtrer = (statut) => router.get(route('visiteurs.index'), { q: recherche || undefined, statut }, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header="Visiteurs du groupe">
            <Head title="Visiteurs du groupe" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                <Card>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-lg"><Users className="h-5 w-5 text-neemba-600" /> Visiteurs du groupe</CardTitle>
                            <p className="mt-1 text-sm text-gray-500">Salariés d'autres filiales en mission en Guinée : ils peuvent recevoir un bon provisoire.</p>
                        </div>
                        <Button onClick={() => setFiche('nouveau')}><Plus className="mr-1 h-4 w-4" /> Ajouter</Button>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" />
                                <Input value={recherche} onChange={(e) => setRecherche(e.target.value)} placeholder="Nom, filiale ou matricule groupe" className="pl-8" aria-label="Rechercher un visiteur" />
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

                        {visiteurs.data.length === 0 ? (
                            <p className="py-10 text-center text-sm text-gray-500">Aucun visiteur dans cette liste.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Visiteur</TableHead>
                                            <TableHead>Accueil</TableHead>
                                            <TableHead>Séjour</TableHead>
                                            <TableHead>N° OM</TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {visiteurs.data.map((v) => (
                                            <TableRow key={v.id} className={v.actif ? '' : 'text-gray-400'}>
                                                <TableCell>
                                                    <p className="font-medium">{v.nom_complet}</p>
                                                    <p className="text-xs text-gray-500">{v.filiale}{v.matricule_groupe ? ` · ${v.matricule_groupe}` : ''}</p>
                                                </TableCell>
                                                <TableCell className="text-sm">{v.departement_accueil}<br /><span className="text-xs text-gray-500">{v.directeur ?? '—'}</span></TableCell>
                                                <TableCell className="whitespace-nowrap text-sm">
                                                    {v.sejour}
                                                    {v.actif && !v.en_sejour && <span className="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">hors séjour</span>}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">{v.numero_om ?? '—'}</TableCell>
                                                <TableCell className="whitespace-nowrap text-right">
                                                    <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`Modifier ${v.nom_complet}`} onClick={() => setFiche(v)}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`${v.actif ? 'Désactiver' : 'Réactiver'} ${v.nom_complet}`}
                                                        onClick={() => router.post(route('visiteurs.basculer', v.id), {}, { preserveScroll: true })}>
                                                        <Power className={cn('h-4 w-4', v.actif ? 'text-red-500' : 'text-green-600')} />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {visiteurs.last_page > 1 && (
                            <div className="flex flex-wrap justify-center gap-1 border-t pt-3">
                                {visiteurs.links.map((lien, index) => (
                                    <Link key={index} href={lien.url || '#'} preserveScroll
                                        className={`rounded-md px-3 py-1.5 text-sm ${lien.active ? 'bg-neemba-400 font-semibold text-marine-950' : lien.url ? 'text-gray-600 hover:bg-gray-100' : 'cursor-not-allowed text-gray-300'}`}
                                        dangerouslySetInnerHTML={{ __html: lien.label }} />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {fiche && <FicheVisiteur visiteur={fiche === 'nouveau' ? null : fiche} services={services} directeurs={directeurs} onFermer={() => setFiche(null)} />}
        </AuthenticatedLayout>
    );
}
