/**
 * Réconciliation Orange Money (spec v2.4, RG-M08-13) : soldes du brouillard et du relevé, écart, lignes non rapprochées des
 * deux côtés, lignes rapprochées, trois signatures (trésorière, Chef comptable, DAF).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock, Download, PenLine } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

const SENS = { entree: 'Entrée', sortie: 'Sortie' };

function Tableau({ titre, lignes, colonnes, vide, testId }) {
    return (
        <Card data-testid={testId}>
            <CardHeader><CardTitle className="text-base">{titre} ({lignes.length})</CardTitle></CardHeader>
            <CardContent>
                {lignes.length === 0 ? <p className="text-sm text-gray-400">{vide}</p> : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                    {colonnes.map((c) => <th key={c.cle} className={`px-3 py-2 ${c.droite ? 'text-right' : ''}`}>{c.titre}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {lignes.map((l, i) => (
                                    <tr key={i} className="border-b last:border-0">
                                        {colonnes.map((c) => (
                                            <td key={c.cle} className={`px-3 py-1.5 ${c.droite ? 'whitespace-nowrap text-right tabular-nums' : ''}`}>
                                                {c.cle === 'sens' ? SENS[l.sens] : l[c.cle] ?? '—'}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function Show({ reconciliation: r, signaturesPossibles = [] }) {
    const signer = (signature) => router.post(route('reconciliations-om.signer', r.id), { signature }, { preserveScroll: true });
    const colonnesReleve = [
        { cle: 'date', titre: 'Date' }, { cle: 'reference', titre: 'Référence' }, { cle: 'libelle', titre: 'Libellé' },
        { cle: 'sens', titre: 'Sens' }, { cle: 'montant', titre: 'Montant', droite: true },
    ];

    return (
        <AuthenticatedLayout header={`Réconciliation ${r.numero}`}>
            <Head title={r.numero} />

            <div className="mx-auto max-w-5xl space-y-4 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link href={route('reconciliations-om.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                        <ArrowLeft className="h-4 w-4" /> Réconciliations
                    </Link>
                    <a href={route('reconciliations-om.releve', r.id)}>
                        <Button variant="outline" size="sm"><Download className="mr-1 h-4 w-4" /> Relevé importé</Button>
                    </a>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">{r.caisse} — {r.periode}</CardTitle>
                        <p className="text-sm text-gray-500">{r.statut_libelle} · établie par {r.etabli_par} · {r.lignes_rapprochees} ligne(s) rapprochée(s)</p>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div className="grid gap-2 rounded-md bg-gray-50 p-3 sm:grid-cols-4">
                            <p>Brouillard, début<br /><strong className="tabular-nums">{r.solde_brouillard_debut}</strong></p>
                            <p>Brouillard, fin<br /><strong className="tabular-nums">{r.solde_brouillard_fin}</strong></p>
                            <p>Relevé, fin<br /><strong className="tabular-nums">{r.solde_releve_fin ?? '—'}</strong></p>
                            <p>Écart<br /><strong data-testid="ecart" className={`tabular-nums ${r.ecart ? 'text-red-700' : 'text-green-700'}`}>{r.ecart_format}</strong></p>
                        </div>
                        {r.observations && <p><span className="text-gray-500">Observations : </span>{r.observations}</p>}
                    </CardContent>
                </Card>

                <Tableau titre="Relevé : lignes absentes du brouillard" lignes={r.releve_non_rapproche} colonnes={colonnesReleve}
                    vide="Toutes les lignes du relevé sont rapprochées." testId="releve-non-rapproche" />
                <Tableau titre="Brouillard : écritures absentes du relevé" lignes={r.brouillard_non_rapproche}
                    colonnes={[{ cle: 'date', titre: 'Date' }, { cle: 'libelle', titre: 'Libellé' }, { cle: 'sens', titre: 'Sens' }, { cle: 'montant', titre: 'Montant', droite: true }]}
                    vide="Toutes les écritures du brouillard sont rapprochées." testId="brouillard-non-rapproche" />
                <Tableau titre="Lignes rapprochées" lignes={r.releve_rapproche} colonnes={[...colonnesReleve, { cle: 'ecriture', titre: 'Écriture du brouillard' }]}
                    vide="Aucune ligne rapprochée." testId="rapprochees" />

                <Card>
                    <CardHeader><CardTitle className="text-base">Validation</CardTitle></CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-3">
                        {r.signes.map((s) => (
                            <div key={s.cle} className="rounded-md border p-3 text-sm" data-testid={`signature-${s.cle}`}>
                                <p className="font-medium">{s.libelle}</p>
                                {s.le ? (
                                    <p className="mt-1 flex items-start gap-1 text-green-700"><CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" /> {s.par}<br />{s.le}</p>
                                ) : signaturesPossibles.includes(s.cle) ? (
                                    <Button size="sm" className="mt-2" onClick={() => signer(s.cle)}><PenLine className="mr-1 h-4 w-4" /> Valider</Button>
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
