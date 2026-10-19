/**
 * Encaissements clients (spec v2.4, RG-M08-10) : paiement au comptant d'un client sur la caisse de Conakry principale,
 * avec reçu PDF. Un encaissement en espèces qui dépasserait le plafond de la caisse est refusé (remise en banque).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FileText, HandCoins } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Combobox } from '@/Components/ui/combobox';
import { formaterMontant } from '@/utils/format';

const SELECT = 'mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm';

export default function Index({ encaissements, caisses = [], clients = [], modes = {} }) {
    const form = useForm({ caisse_id: caisses[0]?.id ?? '', client_id: '', numero_piece: '', montant: '', mode: 'especes', observations: '', piece: null });
    const caisse = caisses.find((c) => Number(c.id) === Number(form.data.caisse_id));
    const apres = Number(caisse?.solde ?? 0) + Number(form.data.montant || 0);
    const auDela = form.data.mode === 'especes' && caisse?.plafond !== null && caisse?.plafond !== undefined && apres > caisse.plafond;

    const enregistrer = (e) => {
        e.preventDefault();
        form.post(route('encaissements-clients.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset('client_id', 'numero_piece', 'montant', 'observations', 'piece') });
    };

    return (
        <AuthenticatedLayout header="Encaissements clients">
            <Head title="Encaissements clients" />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                {caisses.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-lg"><HandCoins className="h-5 w-5 text-neemba-600" /> Nouvel encaissement</CardTitle>
                            <CardDescription>Paiement au comptant d'un client. Le reçu se télécharge depuis la liste.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={enregistrer} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <Label htmlFor="enc-caisse">Caisse</Label>
                                    <select id="enc-caisse" className={SELECT} value={form.data.caisse_id} onChange={(e) => form.setData('caisse_id', e.target.value)}>
                                        {caisses.map((c) => <option key={c.id} value={c.id}>{c.libelle}</option>)}
                                    </select>
                                    {caisse && <p className="mt-1 text-[11px] text-gray-500">Solde {formaterMontant(caisse.solde)}{caisse.plafond !== null ? ` · plafond ${formaterMontant(caisse.plafond)}` : ''}</p>}
                                </div>
                                <div className="lg:col-span-2">
                                    <Label>Client</Label>
                                    <div className="mt-1">
                                        <Combobox options={clients.map((c) => ({ value: String(c.id), label: `${c.raison_sociale}${c.code ? ` (${c.code})` : ''}` }))}
                                            value={form.data.client_id ? String(form.data.client_id) : ''} onChange={(v) => form.setData('client_id', v)}
                                            placeholder="Choisir le client" searchPlaceholder="Rechercher un client…" />
                                    </div>
                                    {form.errors.client_id && <p className="mt-1 text-xs text-red-600">{form.errors.client_id}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="enc-piece">N° de pièce (OR ou facture)</Label>
                                    <Input id="enc-piece" className="mt-1" value={form.data.numero_piece} maxLength={40} onChange={(e) => form.setData('numero_piece', e.target.value)} />
                                    {form.errors.numero_piece && <p className="mt-1 text-xs text-red-600">{form.errors.numero_piece}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="enc-montant">Montant (GNF)</Label>
                                    <Input id="enc-montant" className="mt-1" inputMode="numeric" value={form.data.montant} onChange={(e) => form.setData('montant', e.target.value.replace(/\D/g, ''))} />
                                    {form.errors.montant && <p className="mt-1 text-xs text-red-600" role="alert">{form.errors.montant}</p>}
                                    {auDela && !form.errors.montant && <p className="mt-1 text-xs text-amber-700">Au-delà du plafond de la caisse : remise en banque nécessaire.</p>}
                                </div>
                                <div>
                                    <Label htmlFor="enc-mode">Mode</Label>
                                    <select id="enc-mode" className={SELECT} value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)}>
                                        {Object.entries(modes).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </div>
                                <div className="lg:col-span-2">
                                    <Label htmlFor="enc-obs">Observations (facultatif)</Label>
                                    <Input id="enc-obs" className="mt-1" value={form.data.observations} maxLength={500} onChange={(e) => form.setData('observations', e.target.value)} />
                                </div>
                                <div>
                                    <Label htmlFor="enc-fichier">Pièce jointe (facultatif)</Label>
                                    <Input id="enc-fichier" type="file" accept=".pdf,.jpg,.jpeg,.png" className="mt-1" onChange={(e) => form.setData('piece', e.target.files[0] ?? null)} />
                                </div>
                                {form.errors.caisse_id && <p className="text-xs text-red-600 sm:col-span-2 lg:col-span-3" role="alert">{form.errors.caisse_id}</p>}
                                <div className="flex justify-end sm:col-span-2 lg:col-span-3">
                                    <Button type="submit" disabled={form.processing || !form.data.client_id || !form.data.montant}>Enregistrer l'encaissement</Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader><CardTitle className="text-base">Encaissements enregistrés</CardTitle></CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                    <th className="px-3 py-2">Reçu</th><th className="px-3 py-2">Date</th><th className="px-3 py-2">Client</th>
                                    <th className="px-3 py-2">Pièce</th><th className="px-3 py-2 text-right">Montant</th><th className="px-3 py-2">Mode</th><th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {encaissements.data.map((e) => (
                                    <tr key={e.id} className="border-b last:border-0">
                                        <td className="whitespace-nowrap px-3 py-2 font-medium">{e.numero}</td>
                                        <td className="whitespace-nowrap px-3 py-2">{e.date}</td>
                                        <td className="px-3 py-2">{e.client}</td>
                                        <td className="px-3 py-2">{e.numero_piece}</td>
                                        <td className="whitespace-nowrap px-3 py-2 text-right tabular-nums">{e.montant}</td>
                                        <td className="px-3 py-2 text-xs">{e.mode}</td>
                                        <td className="px-3 py-2 text-right">
                                            <a href={route('encaissements-clients.recu', e.id)} target="_blank" rel="noreferrer" aria-label={`Reçu ${e.numero}`}>
                                                <Button variant="ghost" size="sm"><FileText className="h-4 w-4" /></Button>
                                            </a>
                                        </td>
                                    </tr>
                                ))}
                                {encaissements.data.length === 0 && <tr><td colSpan={7} className="px-3 py-8 text-center text-gray-400">Aucun encaissement.</td></tr>}
                            </tbody>
                        </table>
                        {encaissements.last_page > 1 && (
                            <div className="mt-3 flex flex-wrap gap-1">
                                {encaissements.links.map((lien, i) => (
                                    <Link key={i} href={lien.url ?? '#'} preserveScroll dangerouslySetInnerHTML={{ __html: lien.label }}
                                        className={`rounded px-2 py-1 text-xs ${lien.active ? 'bg-neemba-500 text-white' : 'border bg-white'} ${lien.url ? '' : 'pointer-events-none opacity-40'}`} />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
