/**
 * Demande de réapprovisionnement (spec v2.4, RG-M08-04 à RG-M08-06) : montant proposé = plafond − solde (caisse en avance
 * fixe : avance − solde), modifiable à la baisse avec motif ; PV d'arrêté du jour et rapprochement comptable signé
 * exigés ; le solde ne dépasse jamais le plafond.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck, RefreshCcw } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { formaterMontant } from '@/utils/format';

const SELECT = 'mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm';
const Erreur = ({ message }) => (message ? <p className="mt-1 text-xs text-red-600">{message}</p> : null);

export default function Create({ caisses = [], caisseChoisie, habilitation }) {
    const depart = caisses.find((c) => Number(c.id) === Number(caisseChoisie)) ?? caisses[0];
    const form = useForm({ caisse_id: depart?.id ?? '', montant: depart ? String(depart.propose) : '', motif_baisse: '', rapprochement: null });
    const caisse = caisses.find((c) => Number(c.id) === Number(form.data.caisse_id));
    const montant = Number(form.data.montant || 0);
    const baisse = caisse && montant > 0 && montant < caisse.propose;
    const apres = Number(caisse?.solde ?? 0) + montant;
    const auDela = caisse?.plafond !== null && caisse?.plafond !== undefined && apres > caisse.plafond;

    const choisir = (id) => {
        const c = caisses.find((x) => Number(x.id) === Number(id));
        form.setData((d) => ({ ...d, caisse_id: id, montant: c ? String(c.propose) : '' }));
    };
    const envoyer = (e) => {
        e.preventDefault();
        form.post(route('reapprovisionnements.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header="Demande de réapprovisionnement">
            <Head title="Demande de réapprovisionnement" />

            <div className="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
                <Link href={route('reapprovisionnements.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                    <ArrowLeft className="h-4 w-4" /> Réapprovisionnements
                </Link>
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-lg"><RefreshCcw className="h-5 w-5 text-neemba-600" /> Nouvelle demande</CardTitle>
                        <CardDescription>
                            Avant de demander : établissez le PV d'arrêté du jour (billetage) et signez-le, puis joignez le rapprochement comptable signé.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={envoyer} className="space-y-4">
                            <div>
                                <Label htmlFor="reappro-caisse">Caisse</Label>
                                <select id="reappro-caisse" className={SELECT} value={form.data.caisse_id} onChange={(e) => choisir(e.target.value)}>
                                    {caisses.map((c) => <option key={c.id} value={c.id}>{c.libelle}</option>)}
                                </select>
                            </div>

                            {caisse && (
                                <div className="grid gap-2 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-3">
                                    <p>Solde<br /><strong className="tabular-nums">{formaterMontant(caisse.solde)}</strong></p>
                                    <p>{caisse.mode === 'avance_fixe' ? 'Avance fixe' : 'Plafond de caisse'}<br /><strong className="tabular-nums">{caisse.plafond !== null ? formaterMontant(caisse.plafond) : '—'}</strong></p>
                                    <p>Montant proposé<br /><strong className="tabular-nums">{formaterMontant(caisse.propose)}</strong></p>
                                </div>
                            )}

                            {caisse && (caisse.pv ? (
                                <p className="flex flex-wrap items-center gap-1 rounded-md border border-green-200 bg-green-50 p-2 text-sm text-green-900" data-testid="pv-du-jour">
                                    <ClipboardCheck className="h-4 w-4" /> PV d'arrêté du jour
                                    <Link href={route('pv-arrete.show', caisse.pv.id)} className="font-medium underline">{caisse.pv.numero}</Link>
                                    ({caisse.pv.arrete_le}) — écart billetage / solde comptable :
                                    <strong className={caisse.pv.ecart !== 0 ? 'text-red-700' : ''}>{caisse.pv.ecart_format}</strong>
                                </p>
                            ) : (
                                <p className="rounded-md border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900" data-testid="pv-manquant">
                                    Aucun PV d'arrêté du jour signé par le gérant.{' '}
                                    <Link href={route('pv-arrete.create', { caisse: caisse.id })} className="font-medium underline">Établir le PV</Link>
                                </p>
                            ))}
                            <Erreur message={form.errors.pv} />

                            <div>
                                <Label htmlFor="reappro-montant">Montant demandé (GNF)</Label>
                                <Input id="reappro-montant" type="number" min="1" className="mt-1" value={form.data.montant} onChange={(e) => form.setData('montant', e.target.value)} />
                                {auDela && <p className="mt-1 text-xs text-red-600">Au-delà du plafond : le solde passerait à {formaterMontant(apres)}.</p>}
                                {montant > habilitation && <p className="mt-1 text-xs text-red-600">Au-delà de l'habilitation du Directeur Pays ({formaterMontant(habilitation)}).</p>}
                                <Erreur message={form.errors.montant} />
                            </div>

                            {baisse && (
                                <div>
                                    <Label htmlFor="reappro-motif">Motif de la baisse</Label>
                                    <Textarea id="reappro-motif" className="mt-1" rows={2} maxLength={500} value={form.data.motif_baisse} onChange={(e) => form.setData('motif_baisse', e.target.value)} />
                                    <Erreur message={form.errors.motif_baisse} />
                                </div>
                            )}

                            <div>
                                <Label htmlFor="reappro-rapprochement">Rapprochement comptable signé (PDF ou image)</Label>
                                <Input id="reappro-rapprochement" type="file" accept=".pdf,.jpg,.jpeg,.png" className="mt-1" onChange={(e) => form.setData('rapprochement', e.target.files[0] ?? null)} />
                                <Erreur message={form.errors.rapprochement} />
                            </div>
                            <Erreur message={form.errors.general} />

                            <Button type="submit" disabled={form.processing || !caisse}>Envoyer la demande</Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
