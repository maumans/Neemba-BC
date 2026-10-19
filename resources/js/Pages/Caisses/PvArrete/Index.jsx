/**
 * PV d'arrêté de caisse (spec v2.4, RG-M10-02, Annexe 2) : liste par caisse. Le gérant de la caisse, la trésorerie et le
 * Chef comptable signent chaque PV ; un écart crée un ajustement validé par le DAF.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardCheck, Plus } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

export default function Index({ pvs, caisses = [], filtres = {}, peutEtablir = false }) {
    const filtrer = (caisse) => router.get(route('pv-arrete.index'), caisse ? { caisse } : {}, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header="PV d'arrêté de caisse">
            <Head title="PV d'arrêté" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-lg">
                                <ClipboardCheck className="h-5 w-5 text-neemba-600" /> PV d'arrêté de caisse
                            </CardTitle>
                            <CardDescription>
                                Comptage d'une caisse (billetage), comparé au solde comptable, signé par le gérant, la trésorerie et le Chef comptable.
                            </CardDescription>
                        </div>
                        {peutEtablir && (
                            <Link href={route('pv-arrete.create', filtres.caisse ? { caisse: filtres.caisse } : {})}>
                                <Button><Plus className="mr-1 h-4 w-4" /> Nouveau PV</Button>
                            </Link>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {caisses.length > 1 && (
                            <select aria-label="Caisse" value={filtres.caisse ?? ''} onChange={(e) => filtrer(e.target.value)}
                                className="h-9 rounded-md border border-input bg-white px-2 text-sm">
                                <option value="">Toutes les caisses</option>
                                {caisses.map((c) => <option key={c.id} value={c.id}>{c.libelle}</option>)}
                            </select>
                        )}
                        <div className="overflow-x-auto rounded-md border bg-white">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                        <th className="px-3 py-2">PV</th>
                                        <th className="px-3 py-2">Caisse</th>
                                        <th className="px-3 py-2">Arrêté le</th>
                                        <th className="px-3 py-2">Motif</th>
                                        <th className="px-3 py-2 text-right">Solde physique</th>
                                        <th className="px-3 py-2 text-right">Écart</th>
                                        <th className="px-3 py-2">Signatures</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pvs.data.map((pv) => (
                                        <tr key={pv.id} className="border-b last:border-0">
                                            <td className="px-3 py-2"><Link href={route('pv-arrete.show', pv.id)} className="font-medium text-neemba-700 hover:underline">{pv.numero}</Link></td>
                                            <td className="px-3 py-2">{pv.caisse}</td>
                                            <td className="whitespace-nowrap px-3 py-2">{pv.arrete_le}</td>
                                            <td className="px-3 py-2 text-xs">{pv.motif}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{pv.solde_physique}</td>
                                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${pv.ecart !== 0 ? 'font-semibold text-red-700' : 'text-gray-500'}`}>{pv.ecart_format}</td>
                                            <td className="px-3 py-2">{pv.signatures} / 3</td>
                                        </tr>
                                    ))}
                                    {pvs.data.length === 0 && (
                                        <tr><td colSpan={7} className="px-3 py-8 text-center text-gray-400">Aucun PV d'arrêté.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {pvs.last_page > 1 && (
                            <div className="flex flex-wrap gap-1">
                                {pvs.links.map((lien, i) => (
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
