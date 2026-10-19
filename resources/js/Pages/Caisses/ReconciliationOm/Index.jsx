/**
 * Réconciliation hebdomadaire de la caisse Orange Money (spec v2.4, RG-M08-13, §5.4) : la trésorerie importe le relevé de
 * la plateforme ; le brouillard de caisse est rapproché ligne à ligne ; la trésorière, le Chef comptable et le DAF valident.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

const SELECT = 'mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm';
const Erreur = ({ message }) => (message ? <p className="mt-1 text-xs text-red-600">{message}</p> : null);

export default function Index({ reconciliations, caisses = [], semaineProposee, peutEtablir = false }) {
    const form = useForm({ caisse_id: caisses[0]?.id ?? '', semaine: semaineProposee, releve: null, solde_releve: '', observations: '' });
    const etablir = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, solde_releve: d.solde_releve === '' ? null : d.solde_releve }));
        form.post(route('reconciliations-om.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header="Réconciliation Orange Money">
            <Head title="Réconciliation OM" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                {peutEtablir && caisses.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-lg"><Scale className="h-5 w-5 text-neemba-600" /> Nouvelle réconciliation</CardTitle>
                            <CardDescription>
                                Semaine du lundi au dimanche. Importez le relevé exporté de la plateforme (CSV ou Excel) : colonnes « Date », « Montant »
                                (ou « Débit » et « Crédit »), et si possible « Référence », « Libellé » et « Solde ».
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={etablir} className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="rom-caisse">Caisse</Label>
                                    <select id="rom-caisse" className={SELECT} value={form.data.caisse_id} onChange={(e) => form.setData('caisse_id', e.target.value)}>
                                        {caisses.map((c) => <option key={c.id} value={c.id}>{c.libelle}</option>)}
                                    </select>
                                </div>
                                <div>
                                    <Label htmlFor="rom-semaine">Semaine (un jour de la semaine)</Label>
                                    <Input id="rom-semaine" type="date" className="mt-1" value={form.data.semaine} onChange={(e) => form.setData('semaine', e.target.value)} />
                                    <Erreur message={form.errors.semaine} />
                                </div>
                                <div>
                                    <Label htmlFor="rom-releve">Relevé de la plateforme</Label>
                                    <Input id="rom-releve" type="file" accept=".csv,.txt,.xlsx,.xls" className="mt-1" onChange={(e) => form.setData('releve', e.target.files[0] ?? null)} />
                                    <Erreur message={form.errors.releve} />
                                </div>
                                <div>
                                    <Label htmlFor="rom-solde">Solde de fin du relevé (GNF, si le relevé n'a pas de colonne « Solde »)</Label>
                                    <Input id="rom-solde" type="number" className="mt-1" value={form.data.solde_releve} onChange={(e) => form.setData('solde_releve', e.target.value)} />
                                    <Erreur message={form.errors.solde_releve} />
                                </div>
                                <div className="sm:col-span-2">
                                    <Label htmlFor="rom-observations">Observations</Label>
                                    <Textarea id="rom-observations" rows={2} className="mt-1" value={form.data.observations} onChange={(e) => form.setData('observations', e.target.value)} />
                                </div>
                                <Erreur message={form.errors.general} />
                                <div className="sm:col-span-2"><Button type="submit" disabled={form.processing}>Rapprocher</Button></div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader><CardTitle className="text-base">Réconciliations</CardTitle></CardHeader>
                    <CardContent className="space-y-3">
                        <div className="overflow-x-auto rounded-md border bg-white">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                        <th className="px-3 py-2">N°</th>
                                        <th className="px-3 py-2">Période</th>
                                        <th className="px-3 py-2 text-right">Non rapprochées</th>
                                        <th className="px-3 py-2 text-right">Écart</th>
                                        <th className="px-3 py-2">Signatures</th>
                                        <th className="px-3 py-2">Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {reconciliations.data.map((r) => (
                                        <tr key={r.id} className="border-b last:border-0">
                                            <td className="whitespace-nowrap px-3 py-2"><Link href={route('reconciliations-om.show', r.id)} className="font-medium text-neemba-700 hover:underline">{r.numero}</Link></td>
                                            <td className="whitespace-nowrap px-3 py-2">{r.periode}</td>
                                            <td className="px-3 py-2 text-right tabular-nums">{r.non_rapprochees}</td>
                                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${r.ecart ? 'font-semibold text-red-700' : 'text-gray-500'}`}>{r.ecart_format}</td>
                                            <td className="px-3 py-2">{r.signatures} / 3</td>
                                            <td className="px-3 py-2">{r.statut_libelle}</td>
                                        </tr>
                                    ))}
                                    {reconciliations.data.length === 0 && (
                                        <tr><td colSpan={6} className="px-3 py-8 text-center text-gray-400">Aucune réconciliation.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {reconciliations.last_page > 1 && (
                            <div className="flex flex-wrap gap-1">
                                {reconciliations.links.map((lien, i) => (
                                    <Link key={i} href={lien.url ?? '#'} preserveScroll
                                        className={`rounded px-2 py-1 text-xs ${lien.active ? 'bg-neemba-500 text-white' : 'border bg-white'} ${lien.url ? '' : 'pointer-events-none opacity-40'}`}
                                        dangerouslySetInnerHTML={{ __html: lien.label }} />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
