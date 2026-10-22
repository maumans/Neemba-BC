/**
 * PV d'arrêté de caisse (spec v2.4, RG-M10-02, Annexe 2) : liste par caisse. Le gérant de la caisse, la trésorerie et le
 * Chef comptable signent chaque PV ; un écart crée un ajustement validé par le DAF.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardCheck, Eye, FileText, PenLine, Plus } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';

const LIBELLES_SIGNATURES = { gerant: 'Gérant de la caisse', tresorerie: 'Trésorerie', chef_comptable: 'Chef comptable' };

export default function Index({ pvs, caisses = [], filtres = {}, peutEtablir = false, suivi = null }) {
    const filtrer = (caisse) => router.get(route('pv-arrete.index'), caisse ? { caisse } : {}, { preserveState: true, replace: true });
    /* Signature depuis la liste : confirmée après rappel des soldes et de l'écart */
    const [aSigner, setASigner] = useState(null);
    const [enCours, setEnCours] = useState(false);
    const signer = () => router.post(route('pv-arrete.signer', aSigner.pv.id), { signature: aSigner.signature }, {
        preserveScroll: true,
        onStart: () => setEnCours(true),
        onFinish: () => { setEnCours(false); setASigner(null); },
    });

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
                                        <th className="px-3 py-2 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {pvs.data.map((pv) => (
                                        <tr key={pv.id} className="border-b last:border-0">
                                            <td className="whitespace-nowrap px-3 py-2"><Link href={route('pv-arrete.show', pv.id)} className="font-medium text-neemba-700 hover:underline">{pv.numero}</Link></td>
                                            <td className="px-3 py-2">{pv.caisse}</td>
                                            <td className="whitespace-nowrap px-3 py-2">{pv.arrete_le}</td>
                                            <td className="px-3 py-2 text-xs">{pv.motif}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{pv.solde_physique}</td>
                                            <td className={`whitespace-nowrap px-3 py-2 text-right tabular-nums ${pv.ecart !== 0 ? 'font-semibold text-red-700' : 'text-gray-500'}`}>{pv.ecart_format}</td>
                                            <td className="px-3 py-2">
                                                <span className={pv.signatures === 3 ? 'font-medium text-green-700' : ''}>{pv.signatures} / 3</span>
                                                {pv.manquantes?.length > 0 && <p className="text-[11px] text-gray-500">Manque : {pv.manquantes.join(', ')}</p>}
                                            </td>
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap justify-end gap-1">
                                                    {(pv.signatures_possibles ?? []).map((signature) => (
                                                        <Button key={signature} size="sm" className="h-8" data-testid={`signer-${pv.id}-${signature}`}
                                                            onClick={() => setASigner({ pv, signature })}>
                                                            <PenLine className="mr-1 h-3.5 w-3.5" /> Signer{pv.signatures_possibles.length > 1 ? ` (${LIBELLES_SIGNATURES[signature]})` : ''}
                                                        </Button>
                                                    ))}
                                                    <Link href={route('pv-arrete.show', pv.id)}>
                                                        <Button size="sm" variant="outline" className="h-8" title="Voir le PV" aria-label={`Voir ${pv.numero}`}><Eye className="h-3.5 w-3.5" /></Button>
                                                    </Link>
                                                    <a href={route('pv-arrete.pdf', pv.id)} target="_blank" rel="noreferrer">
                                                        <Button size="sm" variant="outline" className="h-8" title="Imprimer (Annexe 2)" aria-label={`Imprimer ${pv.numero}`}><FileText className="h-3.5 w-3.5" /></Button>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {pvs.data.length === 0 && (
                                        <tr><td colSpan={8} className="px-3 py-8 text-center text-gray-400">Aucun PV d'arrêté.</td></tr>
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

                {suivi && (
                    <Card data-testid="suivi-inventaires">
                        <CardHeader>
                            <CardTitle className="text-base">Inventaires et contrôles inopinés</CardTitle>
                            <CardDescription>
                                Inventaire obligatoire à la fin de chaque mois ; contrôle inopiné par la trésorerie ou le Chef comptable (garant), à la
                                fréquence de la caisse. Ce suivi n'est pas montré aux gérants.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border bg-white">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                            <th className="px-3 py-2">Caisse</th>
                                            <th className="px-3 py-2">Inventaire du mois</th>
                                            <th className="px-3 py-2">Mois précédent</th>
                                            <th className="px-3 py-2">Contrôle inopiné</th>
                                            <th className="px-3 py-2">Période en cours</th>
                                            <th className="px-3 py-2">Dernier contrôle</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {suivi.map((s) => (
                                            <tr key={s.id} className="border-b last:border-0">
                                                <td className="px-3 py-2">{s.caisse}</td>
                                                <td className="px-3 py-2">{s.inventaire_mois ?? <span className="text-amber-700">À faire</span>}</td>
                                                <td className="px-3 py-2">{s.inventaire_mois_precedent ?? <span className="text-red-700">Manquant</span>}</td>
                                                <td className="px-3 py-2">{s.frequence}</td>
                                                <td className="px-3 py-2 text-xs">
                                                    {s.periode}<br />
                                                    {s.controle_periode ? <span className="text-green-700">Fait le {s.controle_periode}</span> : <span className="text-amber-700">À faire</span>}
                                                </td>
                                                <td className="px-3 py-2">{s.dernier_controle ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={aSigner !== null} onOpenChange={(ouvert) => !ouvert && !enCours && setASigner(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Signer le PV {aSigner?.pv.numero}</DialogTitle>
                        <DialogDescription>
                            Vous signez en tant que <strong>{aSigner && LIBELLES_SIGNATURES[aSigner.signature]}</strong>. Une signature ne se retire pas.
                        </DialogDescription>
                    </DialogHeader>
                    {aSigner && (
                        <dl className="grid grid-cols-3 gap-2 rounded-md bg-gray-50 p-3 text-sm">
                            <div><dt className="text-xs text-gray-500">Solde physique</dt><dd className="font-medium tabular-nums">{aSigner.pv.solde_physique}</dd></div>
                            <div><dt className="text-xs text-gray-500">Solde comptable</dt><dd className="font-medium tabular-nums">{aSigner.pv.solde_comptable}</dd></div>
                            <div><dt className="text-xs text-gray-500">Écart</dt><dd className={`font-medium tabular-nums ${aSigner.pv.ecart !== 0 ? 'text-red-700' : 'text-green-700'}`}>{aSigner.pv.ecart_format}</dd></div>
                        </dl>
                    )}
                    <p className="text-xs text-gray-500">{aSigner?.pv.caisse} — arrêté le {aSigner?.pv.arrete_le}. Pour vérifier le billetage, ouvrez le PV.</p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setASigner(null)} disabled={enCours}>Annuler</Button>
                        <Button onClick={signer} disabled={enCours}><PenLine className="mr-1 h-4 w-4" /> Signer</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
