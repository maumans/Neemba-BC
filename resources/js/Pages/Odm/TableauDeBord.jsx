/**
 * Tableau de bord des ordres de mission (US-14) : missions en cours (durée cumulée, coût), dérogations au chevauchement,
 * ODM « à refacturer » (RG-M12-15). Export Excel.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Download } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import BadgeStatutOdm from '@/Components/Odm/BadgeStatutOdm';
import { formaterMontant } from '@/utils/format';

function Indicateur({ libelle, valeur, alerte = false }) {
    return (
        <Card>
            <CardContent className="p-4">
                <p className="text-xs text-gray-500">{libelle}</p>
                <p className={`mt-1 text-xl font-semibold tabular-nums ${alerte ? 'text-red-600' : 'text-gray-900'}`}>{valeur}</p>
            </CardContent>
        </Card>
    );
}

function Tableau({ titre, entetes, lignes, vide }) {
    return (
        <Card>
            <CardHeader className="pb-2"><CardTitle className="text-base">{titre}</CardTitle></CardHeader>
            <CardContent className="overflow-x-auto">
                {lignes.length === 0 ? (
                    <p className="py-4 text-center text-sm text-gray-400">{vide}</p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs text-gray-500">
                                {entetes.map((e) => <th key={e} className="py-2 pr-3 font-medium">{e}</th>)}
                            </tr>
                        </thead>
                        <tbody>{lignes}</tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

export default function TableauDeBord({ indicateurs, missionsEnCours = [], derogations = [], aRefacturer = [] }) {
    return (
        <AuthenticatedLayout header="Ordres de mission — tableau de bord">
            <Head title="Tableau de bord des ODM" />

            <div className="mx-auto max-w-7xl space-y-4 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link href={route('odm.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                        <ArrowLeft className="h-4 w-4" /> Ordres de mission
                    </Link>
                    <a href={route('odm.tableau-de-bord.export')}>
                        <Button size="sm" variant="outline"><Download className="mr-1 h-4 w-4" /> Exporter (Excel)</Button>
                    </a>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <Indicateur libelle="Missions en cours" valeur={indicateurs.missions_en_cours} />
                    <Indicateur libelle="Salariés en mission" valeur={indicateurs.participants_en_mission} />
                    <Indicateur libelle="Coût des missions en cours" valeur={formaterMontant(indicateurs.cout_en_cours)} />
                    <Indicateur libelle={`À refacturer (${indicateurs.a_refacturer})`} valeur={formaterMontant(indicateurs.montant_a_refacturer)} />
                    <Indicateur libelle="Missions incohérentes" valeur={indicateurs.incoherences} alerte={indicateurs.incoherences > 0} />
                </div>

                <Tableau
                    titre="Missions en cours"
                    entetes={['Mission', 'Service', 'Destinations', 'Participants', 'Période', 'Jours', 'Coût', 'Statut']}
                    vide="Aucune mission en cours."
                    lignes={missionsEnCours.map((m) => (
                        <tr key={m.id} className="border-b last:border-b-0" data-testid={`mission-${m.numero}`}>
                            <td className="py-2 pr-3">
                                <Link href={route('odm.show', m.id)} className="font-medium text-neemba-700 hover:underline">{m.numero}</Link>
                                {m.segments > 1 && <p className="text-xs text-gray-500">{m.segments} segments (dernier : {m.dernier_segment})</p>}
                                {m.incoherences > 0 && <p className="flex items-center gap-1 text-xs text-red-600"><AlertTriangle className="h-3 w-3" /> nuits incohérentes</p>}
                            </td>
                            <td className="py-2 pr-3">{m.service}</td>
                            <td className="py-2 pr-3">{m.destinations}</td>
                            <td className="py-2 pr-3 text-center">{m.participants}</td>
                            <td className="whitespace-nowrap py-2 pr-3">{m.debut} → {m.fin_prevue}</td>
                            <td className="py-2 pr-3 text-right tabular-nums">{m.jours}</td>
                            <td className="whitespace-nowrap py-2 pr-3 text-right tabular-nums">{m.cout_format}</td>
                            <td className="py-2"><BadgeStatutOdm statut={m.statut} /></td>
                        </tr>
                    ))}
                />

                <Tableau
                    titre="Dérogations au chevauchement"
                    entetes={['ODM', 'Période', 'Demandeur', 'Statut', 'Motif', 'Décision']}
                    vide="Aucune dérogation."
                    lignes={derogations.map((d) => (
                        <tr key={d.id} className="border-b last:border-b-0">
                            <td className="py-2 pr-3"><Link href={route('odm.show', d.id)} className="text-neemba-700 hover:underline">{d.libelle}</Link></td>
                            <td className="whitespace-nowrap py-2 pr-3">{d.periode}</td>
                            <td className="py-2 pr-3">{d.demandeur}</td>
                            <td className="py-2 pr-3">{d.statut}</td>
                            <td className="py-2 pr-3">{d.motif}</td>
                            <td className="py-2 text-xs text-gray-500">{d.par ? `${d.par}, ${d.le}` : '—'}</td>
                        </tr>
                    ))}
                />

                <Tableau
                    titre="Ordres de mission à refacturer (frais du client avancés par Neemba)"
                    entetes={['ODM', 'Client(s)', 'OR', 'Période', 'À refacturer', 'Payé par le client', 'Statut']}
                    vide="Aucun ODM à refacturer."
                    lignes={aRefacturer.map((r) => (
                        <tr key={r.id} className="border-b last:border-b-0">
                            <td className="py-2 pr-3"><Link href={route('odm.show', r.id)} className="text-neemba-700 hover:underline">{r.libelle}</Link></td>
                            <td className="py-2 pr-3">{r.clients || '—'}</td>
                            <td className="py-2 pr-3">{r.or || '—'}</td>
                            <td className="whitespace-nowrap py-2 pr-3">{r.periode}</td>
                            <td className="whitespace-nowrap py-2 pr-3 text-right font-medium tabular-nums">{r.montant_format}</td>
                            <td className="whitespace-nowrap py-2 pr-3 text-right tabular-nums text-gray-500">{r.client_direct > 0 ? r.client_direct_format : '—'}</td>
                            <td className="py-2">{r.statut_label}</td>
                        </tr>
                    ))}
                />
            </div>
        </AuthenticatedLayout>
    );
}
