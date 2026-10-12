/**
 * Imputation comptable (M16, spec v2.3 §8.11) : bons payés à imputer (alerte au-delà de J+1 ouvré, RG-M16-04),
 * imputations à exporter, exports passés. L'export (Excel ou CSV générique, RG-M16-03) fige les écritures.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, BookOpen, Download, FileSpreadsheet } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { cn } from '@/lib/utils';
import { formaterMontant } from '@/utils/format';

const ONGLETS = { a_imputer: 'À imputer', a_exporter: 'À exporter', exportees: 'Exportées' };

/** Formulaire d'export : un téléchargement classique, pour recevoir le fichier */
function FormulaireExport({ du, au, jeton }) {
    const [periode, setPeriode] = useState({ du, au });

    return (
        <form method="post" action={route('comptabilite.exporter')} className="flex flex-wrap items-end gap-2">
            <input type="hidden" name="_token" value={jeton} />
            <div>
                <label htmlFor="export-du" className="text-xs text-gray-600">Paiements du</label>
                <Input id="export-du" type="date" name="du" value={periode.du} onChange={(e) => setPeriode({ ...periode, du: e.target.value })} className="h-9 w-40" />
            </div>
            <div>
                <label htmlFor="export-au" className="text-xs text-gray-600">au</label>
                <Input id="export-au" type="date" name="au" value={periode.au} onChange={(e) => setPeriode({ ...periode, au: e.target.value })} className="h-9 w-40" />
            </div>
            <Button type="submit" name="format" value="xlsx" size="sm"><FileSpreadsheet className="mr-1 h-4 w-4" /> Exporter (Excel)</Button>
            <Button type="submit" name="format" value="csv" size="sm" variant="outline"><Download className="mr-1 h-4 w-4" /> CSV</Button>
            <p className="w-full text-xs text-gray-500">Seules les imputations validées sont exportées ; elles ne se modifient plus ensuite. Rechargez la page après le téléchargement.</p>
        </form>
    );
}

export default function Index({ onglet, filtres, lignes, compteurs, exports = [], parametresManquants = [], jeton = '' }) {
    const [periode, setPeriode] = useState(filtres);
    const aller = (valeurs) => router.get(route('comptabilite.index'), { onglet, ...periode, ...valeurs }, { preserveState: true, preserveScroll: true });

    return (
        <AuthenticatedLayout header="Imputation comptable">
            <Head title="Imputation comptable" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                {parametresManquants.length > 0 && (
                    <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="alert">
                        <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                        <p>
                            À renseigner dans Paramétrage → Comptabilité pour un pré-remplissage complet : {parametresManquants.join(', ')}.
                            En attendant, les comptes concernés restent à saisir.
                        </p>
                    </div>
                )}

                <div className="grid grid-cols-3 gap-3">
                    {[
                        ['À imputer', compteurs.a_imputer, false],
                        ['En retard (J+1 ouvré)', compteurs.en_retard, compteurs.en_retard > 0],
                        ['À exporter', compteurs.a_exporter, false],
                    ].map(([libelle, valeur, alerte]) => (
                        <Card key={libelle} className={alerte ? 'border-red-200 bg-red-50' : ''}>
                            <CardContent className="p-4">
                                <p className="text-xs text-gray-500">{libelle}</p>
                                <p className={cn('text-2xl font-semibold tabular-nums', alerte ? 'text-red-700' : 'text-gray-900')}>{valeur}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader className="space-y-3 pb-3">
                        <CardTitle className="flex items-center gap-2 text-base"><BookOpen className="h-4 w-4 text-neemba-600" /> Bons payés</CardTitle>
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div role="tablist" className="inline-flex rounded-md border border-gray-200 bg-gray-100 p-0.5">
                                {Object.entries(ONGLETS).map(([valeur, libelle]) => (
                                    <button key={valeur} type="button" role="tab" aria-selected={onglet === valeur}
                                        onClick={() => router.get(route('comptabilite.index'), { onglet: valeur, ...periode }, { preserveScroll: true })}
                                        className={cn('rounded px-3 py-1.5 text-sm font-medium', onglet === valeur ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900')}>
                                        {libelle}
                                    </button>
                                ))}
                            </div>
                            <div className="flex items-end gap-2">
                                <div>
                                    <label htmlFor="filtre-du" className="text-xs text-gray-600">Payés du</label>
                                    <Input id="filtre-du" type="date" value={periode.du} onChange={(e) => setPeriode({ ...periode, du: e.target.value })} className="h-9 w-40" />
                                </div>
                                <div>
                                    <label htmlFor="filtre-au" className="text-xs text-gray-600">au</label>
                                    <Input id="filtre-au" type="date" value={periode.au} onChange={(e) => setPeriode({ ...periode, au: e.target.value })} className="h-9 w-40" />
                                </div>
                                <Button size="sm" variant="outline" onClick={() => aller({})}>Filtrer</Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {lignes.data.length === 0 ? (
                            <p className="py-8 text-center text-sm text-gray-500">Aucun bon sur cette période.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Bon</TableHead>
                                            <TableHead>Payé le</TableHead>
                                            <TableHead>Bénéficiaire</TableHead>
                                            <TableHead className="text-right">Montant versé</TableHead>
                                            <TableHead>Mode · journal</TableHead>
                                            <TableHead>{onglet === 'a_imputer' ? 'État' : 'Imputé'}</TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {lignes.data.map((b) => (
                                            <TableRow key={b.id} className={b.en_retard ? 'bg-red-50/60' : ''}>
                                                <TableCell className="whitespace-nowrap">
                                                    <span className="font-mono text-sm">{b.numero}</span>
                                                    <span className="ml-1 text-xs text-gray-500">{b.type_bon}{b.odm ? ' · ODM' : ''}</span>
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">{b.date_paiement}</TableCell>
                                                <TableCell className="max-w-[16rem]">
                                                    <p className="truncate">{b.beneficiaire}</p>
                                                    <p className="truncate text-xs text-gray-500">{b.motif}</p>
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap text-right tabular-nums">{formaterMontant(b.montant_verse)}</TableCell>
                                                <TableCell className="whitespace-nowrap text-sm">{b.mode}<span className="ml-1 font-mono text-xs text-gray-500">{b.code_journal ?? '—'}</span></TableCell>
                                                <TableCell className="text-xs">
                                                    {onglet === 'a_imputer'
                                                        ? (b.en_retard
                                                            ? <span className="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-700">En retard</span>
                                                            : b.statut_imputation === 'brouillon' ? <span className="text-amber-700">Brouillon</span> : <span className="text-gray-500">À imputer</span>)
                                                        : <>{b.valide_par}<br /><span className="text-gray-500">{b.valide_le}{b.export ? ` · export ${b.export}` : ''}</span></>}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Link href={route('comptabilite.imputation', b.id)} className="text-sm font-medium text-neemba-700 hover:underline">
                                                        {onglet === 'a_imputer' ? 'Imputer' : 'Voir'}
                                                    </Link>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                        {lignes.last_page > 1 && (
                            <div className="flex flex-wrap justify-center gap-1 border-t pt-3">
                                {lignes.links.map((lien, i) => (
                                    <Link key={i} href={lien.url || '#'} preserveScroll dangerouslySetInnerHTML={{ __html: lien.label }}
                                        className={`rounded-md px-3 py-1.5 text-sm ${lien.active ? 'bg-neemba-400 font-semibold text-marine-950' : lien.url ? 'text-gray-600 hover:bg-gray-100' : 'cursor-not-allowed text-gray-300'}`} />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-3"><CardTitle className="text-base">Export des écritures (RG-M16-03)</CardTitle></CardHeader>
                    <CardContent className="space-y-4">
                        <FormulaireExport du={filtres.du} au={filtres.au} jeton={jeton} />
                        {exports.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead><tr className="border-b text-left text-xs text-gray-500">
                                        <th className="py-2 pr-3 font-medium">N°</th><th className="py-2 pr-3 font-medium">Paiements</th>
                                        <th className="py-2 pr-3 font-medium">Écritures</th><th className="py-2 pr-3 text-right font-medium">Montant</th>
                                        <th className="py-2 pr-3 font-medium">Exporté par</th><th className="py-2 font-medium" />
                                    </tr></thead>
                                    <tbody>
                                        {exports.map((e) => (
                                            <tr key={e.id} className="border-b last:border-b-0">
                                                <td className="py-2 pr-3">{e.id}</td>
                                                <td className="py-2 pr-3">{e.periode}</td>
                                                <td className="py-2 pr-3 tabular-nums">{e.ecritures}</td>
                                                <td className="py-2 pr-3 text-right tabular-nums">{e.montant}</td>
                                                <td className="py-2 pr-3 text-xs">{e.par}<br /><span className="text-gray-500">{e.le}</span></td>
                                                <td className="whitespace-nowrap py-2 text-right text-xs">
                                                    <a className="text-neemba-700 hover:underline" href={route('comptabilite.export.telecharger', [e.id, 'xlsx'])}>Excel</a>
                                                    {' · '}
                                                    <a className="text-neemba-700 hover:underline" href={route('comptabilite.export.telecharger', [e.id, 'csv'])}>CSV</a>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
