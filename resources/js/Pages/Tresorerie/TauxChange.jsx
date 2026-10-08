/**
 * Trésorerie — Taux du jour FCFA → GNF (M12-0, spec v2.2 §6.6, PO-08)
 *
 * La Trésorerie saisit chaque jour le taux communiqué par la banque. Il sert au paiement des ODM extérieurs :
 * sans taux du jour, ce paiement est bloqué (RG-M12-10, MSG-M12-05).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Landmark } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';

export default function TauxChange({ tauxDuJour = null, dernierTaux = null, historique = [], peutSaisir = false, dateDuJour = '' }) {
    const form = useForm({ taux: tauxDuJour ? String(tauxDuJour.taux) : '', motif: '' });
    const correction = Boolean(tauxDuJour);

    const enregistrer = (e) => {
        e.preventDefault();
        form.post(route('tresorerie.taux.store'), {
            preserveScroll: true,
            onSuccess: () => form.setData('motif', ''),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Taux du jour</h2>}>
            <Head title="Taux du jour" />

            <div className="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-lg">
                            <Landmark className="h-5 w-5 text-neemba-600" />
                            Taux FCFA → GNF du {dateDuJour}
                        </CardTitle>
                        <CardDescription>
                            Taux communiqué par la banque, en francs guinéens pour 1 franc CFA. Il est appliqué au paiement des ordres de mission
                            à l'étranger.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {tauxDuJour ? (
                            <div className="flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4">
                                <CheckCircle2 className="mt-0.5 h-5 w-5 flex-shrink-0 text-green-600" />
                                <div className="text-sm">
                                    <p className="font-semibold text-green-800">1 FCFA = {tauxDuJour.taux_format} GNF</p>
                                    <p className="text-green-700">Saisi par {tauxDuJour.saisi_par} le {tauxDuJour.saisi_le}</p>
                                    {tauxDuJour.commentaire && <p className="mt-1 text-green-700">{tauxDuJour.commentaire}</p>}
                                </div>
                            </div>
                        ) : (
                            <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4" role="alert">
                                <AlertTriangle className="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-600" />
                                <div className="text-sm text-amber-800">
                                    <p className="font-semibold">Aucun taux saisi aujourd'hui.</p>
                                    <p>
                                        Le paiement des ordres de mission à l'étranger est bloqué jusqu'à la saisie.
                                        {dernierTaux && ` Dernier taux connu : 1 FCFA = ${dernierTaux.taux_format} GNF (${dernierTaux.date}).`}
                                    </p>
                                </div>
                            </div>
                        )}

                        {peutSaisir && (
                            <form onSubmit={enregistrer} className="grid gap-4 sm:grid-cols-[200px_1fr_auto] sm:items-end">
                                <div>
                                    <Label htmlFor="taux">{correction ? 'Corriger le taux (GNF)' : 'Taux du jour (GNF)'}</Label>
                                    <Input
                                        id="taux"
                                        inputMode="decimal"
                                        value={form.data.taux}
                                        onChange={(e) => form.setData('taux', e.target.value.replace(',', '.'))}
                                        placeholder="ex. 14,52"
                                        className="mt-1"
                                        aria-invalid={Boolean(form.errors.taux)}
                                    />
                                    {form.errors.taux && <p className="mt-1 text-xs text-red-600">{form.errors.taux}</p>}
                                </div>
                                {correction ? (
                                    <div>
                                        <Label htmlFor="motif">Motif de la correction</Label>
                                        <Input
                                            id="motif"
                                            value={form.data.motif}
                                            onChange={(e) => form.setData('motif', e.target.value)}
                                            maxLength={200}
                                            className="mt-1"
                                            aria-invalid={Boolean(form.errors.motif)}
                                        />
                                        {form.errors.motif && <p className="mt-1 text-xs text-red-600">{form.errors.motif}</p>}
                                    </div>
                                ) : (
                                    <div />
                                )}
                                <Button type="submit" disabled={form.processing || form.data.taux === ''}>
                                    {correction ? 'Corriger' : 'Enregistrer'}
                                </Button>
                            </form>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Derniers taux saisis</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {historique.length === 0 ? (
                            <p className="py-6 text-center text-sm text-gray-400">Aucun taux saisi pour l'instant.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Date</TableHead>
                                            <TableHead className="text-right">1 FCFA =</TableHead>
                                            <TableHead>Saisi par</TableHead>
                                            <TableHead>Le</TableHead>
                                            <TableHead>Commentaire</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {historique.map((t) => (
                                            <TableRow key={t.id}>
                                                <TableCell className="whitespace-nowrap">{t.date}</TableCell>
                                                <TableCell className="whitespace-nowrap text-right font-mono">{t.taux_format} GNF</TableCell>
                                                <TableCell className="whitespace-nowrap">{t.saisi_par}</TableCell>
                                                <TableCell className="whitespace-nowrap text-xs text-gray-500">{t.saisi_le}</TableCell>
                                                <TableCell className="text-xs text-gray-600">{t.commentaire ?? ''}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
