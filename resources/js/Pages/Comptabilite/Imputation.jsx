/**
 * Imputation comptable d'un bon payé (M16, RG-M16-01, 02, 05, 06).
 *
 * Lignes : compte, libellé, code analytique, montant ; la somme doit égaler le montant versé (frais OM compris).
 * Bon issu d'un ODM : une ligne par part de prise en charge (payeur, client, OR). Modifier cette répartition la corrige
 * aussi sur l'ODM, avec un motif obligatoire, tant que l'écriture n'est pas exportée.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Copy, Lock, Plus, Save, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import MontantInput from '@/Components/MontantInput';
import ChoixClient from '@/Components/Odm/ChoixClient';
import { cn } from '@/lib/utils';
import { formaterMontant } from '@/utils/format';

const SELECT = 'h-9 w-full rounded-md border border-input bg-white px-2 text-sm';

function Info({ libelle, children }) {
    return (
        <div>
            <p className="text-xs text-gray-500">{libelle}</p>
            <p className="text-sm font-medium text-gray-900">{children ?? '—'}</p>
        </div>
    );
}

export default function Imputation({ bon, imputation, lignesFrais = [], ordres = [], clientsOdm = [], codesAnalytiques = [] }) {
    const form = useForm({ lignes: imputation.lignes, motif: imputation.motif_correction ?? '', valider: false });
    const lectureSeule = !imputation.modifiable;
    const odm = Boolean(bon.odm) && lignesFrais.length > 0;
    const somme = form.data.lignes.reduce((s, l) => s + Number(l.montant || 0), 0);
    const ecart = Math.round(somme - bon.montant_verse);

    const changer = (i, valeurs) => form.setData('lignes', form.data.lignes.map((l, j) => (j === i ? { ...l, ...valeurs } : l)));
    const ajouter = (modele = null) => form.setData('lignes', [...form.data.lignes, modele
        ? { ...modele, montant: 0 }
        : { nature: 'depense', compte: '', libelle: bon.motif ?? '', montant: Math.max(0, -ecart), code_analytique: '', payeur: null, client: null, ordre_reparation: '', ligne_frais_id: null }]);
    const retirer = (i) => form.setData('lignes', form.data.lignes.filter((_, j) => j !== i));
    const envoyer = (valider) => {
        form.transform((d) => ({
            ...d,
            valider,
            lignes: d.lignes.map((l) => ({ ...l, client_id: l.client?.id ?? null, montant: Number(l.montant || 0) })),
        }));
        form.put(route('comptabilite.imputation.enregistrer', bon.id), { preserveScroll: true });
    };
    const totalLigneFrais = (id) => form.data.lignes.filter((l) => l.ligne_frais_id === id).reduce((s, l) => s + Number(l.montant || 0), 0);

    return (
        <AuthenticatedLayout header={`Imputation du bon ${bon.numero}`}>
            <Head title={`Imputation ${bon.numero}`} />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                <Link href={route('comptabilite.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                    <ArrowLeft className="h-4 w-4" /> Imputation comptable
                </Link>

                <Card>
                    <CardContent className="grid gap-4 p-4 sm:grid-cols-3 lg:grid-cols-6">
                        <Info libelle="Bon"><Link href={route('bons-caisse.show', bon.id)} className="text-neemba-700 hover:underline">{bon.numero}</Link> · {bon.type_bon}</Info>
                        <Info libelle="Payé le">{bon.date_paiement}</Info>
                        <Info libelle="Bénéficiaire">{bon.beneficiaire}</Info>
                        <Info libelle="Montant · frais OM">{formaterMontant(bon.montant)}{bon.frais_om ? ` + ${formaterMontant(bon.frais_om)}` : ''}</Info>
                        <Info libelle="Montant versé">{formaterMontant(bon.montant_verse)}</Info>
                        <Info libelle="Mode · code journal">{bon.mode} · <span className="font-mono">{bon.code_journal ?? 'à paramétrer'}</span></Info>
                        <div className="sm:col-span-3 lg:col-span-6">
                            <p className="text-xs text-gray-500">Motif</p>
                            <p className="text-sm text-gray-800">{bon.motif}</p>
                            {bon.odm && (
                                <p className="mt-1 text-xs">Ordre de mission <Link href={route('odm.show', bon.odm.id)} className="text-neemba-700 hover:underline">{bon.odm.libelle}</Link></p>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 pb-3">
                        <CardTitle className="text-base">Lignes d'imputation</CardTitle>
                        <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium',
                            imputation.statut === 'brouillon' ? 'bg-amber-100 text-amber-800' : imputation.statut === 'validee' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700')}>
                            {lectureSeule && <Lock className="mr-1 inline h-3 w-3" />}{imputation.statut_label}
                        </span>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {form.errors.lignes && (
                            <ul className="space-y-1 rounded-md bg-red-50 p-2 text-sm text-red-700" role="alert">
                                {[].concat(form.errors.lignes).map((m, i) => <li key={i}>{m}</li>)}
                            </ul>
                        )}
                        <div className="space-y-2">
                            {form.data.lignes.map((l, i) => (
                                <div key={i} className={cn('rounded-md border p-2', l.payeur === 'client' ? 'border-purple-200 bg-purple-50/40' : 'border-gray-200')} data-testid="ligne-imputation">
                                    <div className="grid gap-2 md:grid-cols-[8rem_minmax(0,1fr)_12rem_10rem_auto]">
                                        <Input value={l.compte} placeholder="Compte" maxLength={20} disabled={lectureSeule} aria-label={`Compte, ligne ${i + 1}`}
                                            className={cn('font-mono', !l.compte && 'border-amber-400')} onChange={(e) => changer(i, { compte: e.target.value.toUpperCase() })} />
                                        <Input value={l.libelle} maxLength={150} disabled={lectureSeule} aria-label={`Libellé, ligne ${i + 1}`} onChange={(e) => changer(i, { libelle: e.target.value })} />
                                        <select className={SELECT} value={l.code_analytique ?? ''} disabled={lectureSeule} aria-label={`Code analytique, ligne ${i + 1}`}
                                            onChange={(e) => changer(i, { code_analytique: e.target.value })}>
                                            <option value="">Sans code analytique</option>
                                            {codesAnalytiques.map((c) => <option key={c.code} value={c.code}>{c.code} — {c.libelle}</option>)}
                                        </select>
                                        <MontantInput value={l.montant} disabled={lectureSeule} aria-label={`Montant, ligne ${i + 1}`} onChange={(v) => changer(i, { montant: v })} />
                                        {!lectureSeule && (
                                            <div className="flex gap-1">
                                                {l.ligne_frais_id && (
                                                    <Button type="button" variant="ghost" size="icon" className="h-9 w-9" aria-label={`Partager la ligne ${i + 1}`} onClick={() => ajouter(l)}>
                                                        <Copy className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                <Button type="button" variant="ghost" size="icon" className="h-9 w-9 text-gray-400 hover:text-red-600" aria-label={`Supprimer la ligne ${i + 1}`}
                                                    onClick={() => retirer(i)} disabled={form.data.lignes.length === 1}>
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                    {(l.ligne_frais_id || l.payeur) && (
                                        <div className="mt-2 grid gap-2 md:grid-cols-[minmax(0,1fr)_8rem_14rem_12rem]">
                                            <select className={SELECT} value={l.ligne_frais_id ?? ''} disabled={lectureSeule} aria-label={`Ligne de frais de l'ODM, ligne ${i + 1}`}
                                                onChange={(e) => changer(i, { ligne_frais_id: e.target.value ? Number(e.target.value) : null })}>
                                                <option value="">Hors ligne de frais (frais OM…)</option>
                                                {lignesFrais.map((lf) => <option key={lf.id} value={lf.id}>{lf.libelle} ({formaterMontant(lf.montant)})</option>)}
                                            </select>
                                            <select className={SELECT} value={l.payeur ?? 'neemba'} disabled={lectureSeule} aria-label={`Payeur, ligne ${i + 1}`}
                                                onChange={(e) => changer(i, { payeur: e.target.value, client: e.target.value === 'client' ? (l.client ?? clientsOdm[0] ?? null) : null })}>
                                                <option value="neemba">Neemba</option>
                                                <option value="client">Client</option>
                                            </select>
                                            {l.payeur === 'client' ? (
                                                <ChoixClient compact valeur={l.client} suggestions={clientsOdm} desactive={lectureSeule} effacable={false} libelle={`Client, ligne ${i + 1}`}
                                                    onChange={(client) => changer(i, { client })} />
                                            ) : <span />}
                                            <select className={SELECT} value={l.ordre_reparation ?? ''} disabled={lectureSeule} aria-label={`OR, ligne ${i + 1}`}
                                                onChange={(e) => changer(i, { ordre_reparation: e.target.value })}>
                                                <option value="">Sans OR</option>
                                                {ordres.map((o) => <option key={o.numero} value={o.numero}>OR {o.numero}</option>)}
                                            </select>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                            {!lectureSeule ? (
                                <Button type="button" variant="outline" size="sm" onClick={() => ajouter()}><Plus className="mr-1 h-4 w-4" /> Ajouter une ligne</Button>
                            ) : <span />}
                            <p className={cn('text-sm font-medium tabular-nums', ecart === 0 ? 'text-green-700' : 'text-red-600')} data-testid="somme">
                                Total {formaterMontant(somme)} / montant versé {formaterMontant(bon.montant_verse)}
                                {ecart !== 0 && ` (écart ${ecart > 0 ? '+' : '−'}${formaterMontant(Math.abs(ecart))})`}
                            </p>
                        </div>

                        {odm && (
                            <div className="space-y-2 rounded-md border border-purple-100 bg-purple-50/40 p-3">
                                <p className="text-sm font-medium text-gray-900">Répartition de l'ordre de mission (RG-M16-06)</p>
                                <ul className="space-y-0.5 text-xs">
                                    {lignesFrais.map((lf) => {
                                        const total = totalLigneFrais(lf.id);
                                        return (
                                            <li key={lf.id} className={cn('flex justify-between gap-2', Math.round(total) === Math.round(lf.montant) ? 'text-gray-600' : 'font-medium text-red-600')}>
                                                <span>{lf.libelle}</span>
                                                <span className="tabular-nums">{formaterMontant(total)} / {formaterMontant(lf.montant)}</span>
                                            </li>
                                        );
                                    })}
                                </ul>
                                <label htmlFor="motif" className="block pt-1 text-xs text-gray-600">
                                    Motif de la correction : obligatoire si vous modifiez la répartition (payeur, client, OR, code analytique ou montants des parts).
                                    La correction est reportée sur l'ordre de mission et tracée.
                                </label>
                                <Textarea id="motif" rows={2} value={form.data.motif} disabled={lectureSeule} onChange={(e) => form.setData('motif', e.target.value)} />
                                {form.errors.motif && <p className="text-sm text-red-600">{form.errors.motif}</p>}
                            </div>
                        )}

                        {!lectureSeule && (
                            <div className="flex flex-wrap justify-end gap-2">
                                <Button type="button" variant="outline" onClick={() => envoyer(false)} disabled={form.processing}><Save className="mr-1 h-4 w-4" /> Enregistrer le brouillon</Button>
                                <Button type="button" onClick={() => envoyer(true)} disabled={form.processing}><CheckCircle2 className="mr-1 h-4 w-4" /> Valider l'imputation</Button>
                            </div>
                        )}
                        {lectureSeule && <p className="text-sm text-gray-600">Écriture exportée : elle ne se modifie plus.</p>}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
