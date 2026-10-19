/**
 * Demande de réapprovisionnement (spec v2.4, RG-M08-05 à RG-M08-09) : montants, PV d'arrêté du jour, pièces, circuit,
 * puis l'action attendue de l'utilisateur (validation, visa, approbation, refus, exécution, réception).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock, Paperclip } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { formaterMontant } from '@/utils/format';

const SELECT = 'mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm';
const Erreur = ({ message }) => (message ? <p className="mt-1 text-xs text-red-600">{message}</p> : null);

const VISAS = {
    valider_tresorerie: 'Valider (trésorerie)',
    viser_finance: 'Viser (Finance)',
    approuver_dp: 'Approuver (Directeur Pays)',
    approuver_codir: 'Approuver (membre du CODIR)',
};

function Ligne({ libelle, valeur }) {
    return valeur ? <p><span className="text-gray-500">{libelle} : </span>{valeur}</p> : null;
}

export default function Show({ demande: d, actions = [], origines = {}, caissesSources = [] }) {
    const form = useForm({
        action: '', montant_valide: String(d.montant_demande_brut), motif_reduction: '', motif: '',
        origine_fonds: Object.keys(origines)[0] ?? '', caisse_source_id: '', reference: '',
        montant_emis: String(d.montant_retenu_brut), montant_recu: d.execution ? String(d.execution.montant_emis_brut) : '', piece: null,
    });
    const agir = (action) => {
        form.transform((donnees) => ({ ...donnees, action }));
        form.post(route('reapprovisionnements.agir', d.id), { forceFormData: true, preserveScroll: true });
    };
    const visa = actions.find((a) => VISAS[a]);
    const reduit = actions.includes('valider_tresorerie') && Number(form.data.montant_valide || 0) < d.montant_demande_brut;

    return (
        <AuthenticatedLayout header={`Réapprovisionnement ${d.numero}`}>
            <Head title={d.numero} />

            <div className="mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
                <Link href={route('reapprovisionnements.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                    <ArrowLeft className="h-4 w-4" /> Réapprovisionnements
                </Link>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-lg">{d.caisse} — {d.montant}</CardTitle>
                        <p className="text-sm text-gray-500" data-testid="statut">{d.statut_libelle}</p>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div className="grid gap-2 rounded-md bg-gray-50 p-3 sm:grid-cols-3">
                            <p>Solde à la demande<br /><strong className="tabular-nums">{d.solde_a_la_demande}</strong></p>
                            <p>Montant proposé<br /><strong className="tabular-nums">{d.montant_propose}</strong></p>
                            <p>Montant demandé<br /><strong className="tabular-nums">{d.montant_demande}</strong></p>
                        </div>
                        <Ligne libelle="Motif de la baisse" valeur={d.motif_baisse} />
                        <Ligne libelle="Montant validé par la trésorerie" valeur={d.montant_valide} />
                        <Ligne libelle="Motif de la réduction" valeur={d.motif_reduction} />
                        {d.pv && (
                            <p>
                                <span className="text-gray-500">PV d'arrêté : </span>
                                <Link href={route('pv-arrete.show', d.pv.id)} className="font-medium text-neemba-700 hover:underline">{d.pv.numero}</Link>
                                {' '}({d.pv.arrete_le}) — écart billetage / solde comptable :{' '}
                                <strong className={d.pv.ecart !== 0 ? 'text-red-700' : 'text-green-700'}>{d.pv.ecart_format}</strong>
                            </p>
                        )}
                        {d.pieces.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                {d.pieces.map((p) => (
                                    <a key={p.cle} href={route('reapprovisionnements.piece', [d.id, p.cle])} target="_blank" rel="noreferrer"
                                        className="inline-flex items-center gap-1 rounded border px-2 py-1 text-xs hover:bg-gray-50">
                                        <Paperclip className="h-3.5 w-3.5" /> {p.libelle}
                                    </a>
                                ))}
                            </div>
                        )}
                        {d.execution && (
                            <div className="rounded-md border p-3">
                                <p className="font-medium">Exécution</p>
                                <Ligne libelle="Origine des fonds" valeur={d.execution.origine} />
                                <Ligne libelle="Caisse source" valeur={d.execution.caisse_source} />
                                <Ligne libelle="Référence" valeur={d.execution.reference} />
                                <Ligne libelle="Montant émis" valeur={d.execution.montant_emis} />
                                {d.reception && <Ligne libelle="Montant reçu" valeur={d.reception.montant_recu} />}
                                {d.reception && d.reception.ecart !== 0 && (
                                    <p className="mt-1 text-red-700">Écart de réception : {d.reception.ecart_format} (le DAF est alerté).</p>
                                )}
                            </div>
                        )}
                        {d.refus && (
                            <p className="rounded-md bg-red-50 p-2 text-red-800">Refusée par {d.refus.par} le {d.refus.le} : {d.refus.motif}</p>
                        )}
                        {d.codir_attendu && (
                            <p className="rounded-md bg-amber-50 p-2 text-amber-900">
                                Le Directeur Pays et son adjoint sont absents ou n'ont pas visé dans le délai : deux membres du CODIR peuvent approuver ensemble.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle className="text-base">Circuit</CardTitle></CardHeader>
                    <CardContent>
                        <ol className="space-y-2 text-sm">
                            {d.etapes.map((e) => (
                                <li key={e.libelle} className="flex flex-wrap items-start gap-2">
                                    {e.le ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-green-600" /> : <Clock className="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />}
                                    <span className="font-medium">{e.libelle}</span>
                                    {e.le && <span className="text-gray-500">{e.par} — {e.le}</span>}
                                </li>
                            ))}
                        </ol>
                    </CardContent>
                </Card>

                {actions.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">À vous d'agir</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            {actions.includes('valider_tresorerie') && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="reappro-valide">Montant validé (GNF)</Label>
                                        <Input id="reappro-valide" type="number" min="1" className="mt-1" value={form.data.montant_valide} onChange={(e) => form.setData('montant_valide', e.target.value)} />
                                        <Erreur message={form.errors.montant_valide ?? form.errors.montant} />
                                    </div>
                                    {reduit && (
                                        <div>
                                            <Label htmlFor="reappro-reduction">Motif de la réduction</Label>
                                            <Textarea id="reappro-reduction" className="mt-1" rows={2} value={form.data.motif_reduction} onChange={(e) => form.setData('motif_reduction', e.target.value)} />
                                            <Erreur message={form.errors.motif_reduction} />
                                        </div>
                                    )}
                                </div>
                            )}

                            {visa && <Button onClick={() => agir(visa)} disabled={form.processing}>{VISAS[visa]}</Button>}

                            {actions.includes('executer') && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="reappro-origine">Origine des fonds</Label>
                                        <select id="reappro-origine" className={SELECT} value={form.data.origine_fonds} onChange={(e) => form.setData('origine_fonds', e.target.value)}>
                                            {Object.entries(origines).map(([cle, libelle]) => <option key={cle} value={cle}>{libelle}</option>)}
                                        </select>
                                        <Erreur message={form.errors.origine_fonds} />
                                    </div>
                                    {form.data.origine_fonds === 'transfert' && (
                                        <div>
                                            <Label htmlFor="reappro-source">Caisse source</Label>
                                            <select id="reappro-source" className={SELECT} value={form.data.caisse_source_id} onChange={(e) => form.setData('caisse_source_id', e.target.value)}>
                                                <option value="">Choisir la caisse</option>
                                                {caissesSources.map((c) => <option key={c.id} value={c.id}>{c.libelle} ({formaterMontant(c.solde)})</option>)}
                                            </select>
                                            <Erreur message={form.errors.caisse_source_id} />
                                        </div>
                                    )}
                                    <div>
                                        <Label htmlFor="reappro-emis">Montant émis (GNF)</Label>
                                        <Input id="reappro-emis" type="number" min="1" className="mt-1" value={form.data.montant_emis} onChange={(e) => form.setData('montant_emis', e.target.value)} />
                                    </div>
                                    <div>
                                        <Label htmlFor="reappro-reference">Référence (n° de chèque, de bordereau…)</Label>
                                        <Input id="reappro-reference" className="mt-1" maxLength={100} value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <Label htmlFor="reappro-piece-exec">Pièce (facultative)</Label>
                                        <Input id="reappro-piece-exec" type="file" accept=".pdf,.jpg,.jpeg,.png" className="mt-1" onChange={(e) => form.setData('piece', e.target.files[0] ?? null)} />
                                    </div>
                                    <div><Button onClick={() => agir('executer')} disabled={form.processing}>Enregistrer l'exécution</Button></div>
                                </div>
                            )}

                            {actions.includes('confirmer_reception') && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="reappro-recu">Montant reçu (GNF)</Label>
                                        <Input id="reappro-recu" type="number" min="0" className="mt-1" value={form.data.montant_recu} onChange={(e) => form.setData('montant_recu', e.target.value)} />
                                        <Erreur message={form.errors.montant_recu} />
                                    </div>
                                    <div>
                                        <Label htmlFor="reappro-piece-recu">Bordereau, copie de mise à disposition ou reçu</Label>
                                        <Input id="reappro-piece-recu" type="file" accept=".pdf,.jpg,.jpeg,.png" className="mt-1" onChange={(e) => form.setData('piece', e.target.files[0] ?? null)} />
                                        <Erreur message={form.errors.piece} />
                                    </div>
                                    <div><Button onClick={() => agir('confirmer_reception')} disabled={form.processing}>Confirmer la réception</Button></div>
                                </div>
                            )}

                            {actions.includes('refuser') && (
                                <div className="space-y-2 border-t pt-3">
                                    <Label htmlFor="reappro-refus">Motif du refus</Label>
                                    <Textarea id="reappro-refus" rows={2} value={form.data.motif} onChange={(e) => form.setData('motif', e.target.value)} />
                                    <Erreur message={form.errors.motif} />
                                    <Button variant="outline" className="border-red-300 text-red-700 hover:bg-red-50" onClick={() => agir('refuser')} disabled={form.processing}>Refuser</Button>
                                </div>
                            )}
                            <Erreur message={form.errors.general} />
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
