/**
 * Nouveau PV d'arrêté (spec v2.4, RG-M10-02, Annexe 2) : billetage par coupure (caisse espèces) ou solde lu sur la
 * plateforme (caisse Orange Money) ; l'écart avec le solde comptable s'affiche au fil de la saisie.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { formaterMontant, formaterNombre } from '@/utils/format';

const SELECT = 'mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm';

export default function Create({ caisses = [], caisseChoisie = null, coupures = [], motifs = {} }) {
    const form = useForm({
        caisse_id: caisseChoisie ?? caisses[0]?.id ?? '',
        motif: Object.keys(motifs)[0] ?? '',
        billetage: Object.fromEntries(coupures.map((c) => [c, ''])),
        solde_physique: '',
        observations: '',
    });
    const caisse = caisses.find((c) => Number(c.id) === Number(form.data.caisse_id));
    const om = caisse?.type === 'orange_money';
    const physique = om
        ? Number(form.data.solde_physique || 0)
        : coupures.reduce((total, c) => total + c * Number(form.data.billetage[c] || 0), 0);
    const ecart = physique - Number(caisse?.solde ?? 0);

    const enregistrer = (e) => {
        e.preventDefault();
        form.post(route('pv-arrete.store'));
    };

    return (
        <AuthenticatedLayout header="Nouveau PV d'arrêté">
            <Head title="Nouveau PV d'arrêté" />

            <form onSubmit={enregistrer} className="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
                <Link href={route('pv-arrete.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                    <ArrowLeft className="h-4 w-4" /> PV d'arrêté
                </Link>
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-lg"><ClipboardCheck className="h-5 w-5 text-neemba-600" /> Arrêté de caisse</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="pv-caisse">Caisse</Label>
                                <select id="pv-caisse" className={SELECT} value={form.data.caisse_id} onChange={(e) => form.setData('caisse_id', e.target.value)}>
                                    {caisses.map((c) => <option key={c.id} value={c.id}>{c.libelle} ({c.sites})</option>)}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="pv-motif">Motif</Label>
                                <select id="pv-motif" className={SELECT} value={form.data.motif} onChange={(e) => form.setData('motif', e.target.value)}>
                                    {Object.entries(motifs).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </select>
                            </div>
                        </div>

                        {om ? (
                            <div>
                                <Label htmlFor="pv-solde">Solde lu sur la plateforme Orange Money (GNF)</Label>
                                <Input id="pv-solde" inputMode="numeric" className="mt-1" value={form.data.solde_physique}
                                    onChange={(e) => form.setData('solde_physique', e.target.value.replace(/\D/g, ''))} />
                                {form.errors.solde_physique && <p className="mt-1 text-xs text-red-600">{form.errors.solde_physique}</p>}
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                            <th className="px-3 py-2">Coupure (GNF)</th>
                                            <th className="px-3 py-2">Nombre</th>
                                            <th className="px-3 py-2 text-right">Montant</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {coupures.map((c) => (
                                            <tr key={c} className="border-b last:border-0">
                                                <td className="px-3 py-1.5 tabular-nums">{formaterNombre(c)}</td>
                                                <td className="px-3 py-1.5">
                                                    <Input aria-label={`Nombre de ${c}`} inputMode="numeric" className="h-8 w-28" value={form.data.billetage[c]}
                                                        onChange={(e) => form.setData('billetage', { ...form.data.billetage, [c]: e.target.value.replace(/\D/g, '') })} />
                                                </td>
                                                <td className="px-3 py-1.5 text-right tabular-nums">{formaterNombre(c * Number(form.data.billetage[c] || 0))}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        <div className="grid gap-2 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-3">
                            <p>Solde physique<br /><strong className="tabular-nums">{formaterMontant(physique)}</strong></p>
                            <p>Solde comptable<br /><strong className="tabular-nums">{formaterMontant(caisse?.solde ?? 0)}</strong></p>
                            <p>Écart<br /><strong data-testid="ecart" className={`tabular-nums ${ecart !== 0 ? 'text-red-700' : 'text-green-700'}`}>{formaterMontant(ecart)}</strong></p>
                        </div>

                        <div>
                            <Label htmlFor="pv-observations">Observations (facultatif)</Label>
                            <Textarea id="pv-observations" rows={2} className="mt-1" value={form.data.observations} onChange={(e) => form.setData('observations', e.target.value)} />
                        </div>
                        <div className="flex justify-end">
                            <Button type="submit" disabled={form.processing}>Établir le PV</Button>
                        </div>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
