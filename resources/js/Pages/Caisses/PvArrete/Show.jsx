/**
 * PV d'arrêté (spec v2.4, RG-M10-02, RG-M10-03, Annexe 2) : comptage, écart, trois signatures, impression ; l'écart d'un
 * PV signé devient un ajustement d'inventaire en attente du DAF.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock, FileText, PenLine } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { formaterNombre } from '@/utils/format';

export default function Show({ pv, signaturesPossibles = [] }) {
    const signer = (signature) => router.post(route('pv-arrete.signer', pv.id), { signature }, { preserveScroll: true });

    return (
        <AuthenticatedLayout header={`PV d'arrêté ${pv.numero}`}>
            <Head title={pv.numero} />

            <div className="mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link href={route('pv-arrete.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                        <ArrowLeft className="h-4 w-4" /> PV d'arrêté
                    </Link>
                    <a href={route('pv-arrete.pdf', pv.id)} target="_blank" rel="noreferrer">
                        <Button variant="outline" size="sm"><FileText className="mr-1 h-4 w-4" /> Imprimer (Annexe 2)</Button>
                    </a>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">{pv.caisse} — {pv.date} à {pv.heure}</CardTitle>
                        <p className="text-sm text-gray-500">{pv.motif} · {pv.implantation} · établi par {pv.etabli_par}</p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {pv.type_caisse !== 'orange_money' && (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                            <th className="px-3 py-2">Coupure (GNF)</th><th className="px-3 py-2 text-right">Nombre</th><th className="px-3 py-2 text-right">Montant</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pv.billetage.map((l) => (
                                            <tr key={l.coupure} className="border-b last:border-0">
                                                <td className="px-3 py-1.5 tabular-nums">{formaterNombre(l.coupure)}</td>
                                                <td className="px-3 py-1.5 text-right tabular-nums">{formaterNombre(l.nombre)}</td>
                                                <td className="px-3 py-1.5 text-right tabular-nums">{formaterNombre(l.montant)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <div className="grid gap-2 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-3">
                            <p>Solde physique<br /><strong className="tabular-nums">{pv.solde_physique}</strong></p>
                            <p>Solde comptable<br /><strong className="tabular-nums">{pv.solde_comptable}</strong></p>
                            <p>Écart<br /><strong className={`tabular-nums ${pv.ecart !== 0 ? 'text-red-700' : 'text-green-700'}`}>{pv.ecart_format}</strong></p>
                        </div>
                        {pv.observations && <p className="text-sm"><span className="text-gray-500">Observations : </span>{pv.observations}</p>}
                        {pv.ajustement && (
                            <p className="rounded-md bg-amber-50 p-2 text-sm text-amber-900">
                                Ajustement d'inventaire {pv.ajustement.reference} : {pv.ajustement.statut}. Le DAF le valide avec un motif (Mouvements de caisse).
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="text-base">Signatures</CardTitle></CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-3">
                        {pv.signes.map((s) => (
                            <div key={s.cle} className="rounded-md border p-3 text-sm" data-testid={`signature-${s.cle}`}>
                                <p className="font-medium">{s.libelle}</p>
                                {s.le ? (
                                    <p className="mt-1 flex items-start gap-1 text-green-700"><CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> {s.par}<br />{s.le}</p>
                                ) : signaturesPossibles.includes(s.cle) ? (
                                    <Button size="sm" className="mt-2" onClick={() => signer(s.cle)}><PenLine className="mr-1 h-4 w-4" /> Signer</Button>
                                ) : (
                                    <p className="mt-1 flex items-center gap-1 text-gray-400"><Clock className="h-4 w-4" /> En attente</p>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
