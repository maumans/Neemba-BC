/**
 * Onglet « Barèmes ODM » du paramétrage (spec v2.4, RG-M02-10, RG-M02-01, §6.6) : tenu par le gestionnaire des
 * référentiels, sans circuit de validation. Une nouvelle valeur porte une date d'effet (aujourd'hui ou plus tard) ;
 * l'historique est conservé. Un ODM est calculé avec les barèmes en vigueur à sa soumission.
 */
import { router, useForm } from '@inertiajs/react';
import { CalendarClock, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { formaterNombre } from '@/utils/format';

const SELECT = 'mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-neemba-400';

export default function BaremesOdm({ baremes }) {
    const { cles, enVigueur, historique = [], aujourdhui } = baremes;
    const premiere = Object.keys(cles)[0];
    const form = useForm({ cle: premiere, valeur: '', date_effet: aujourdhui, commentaire: '' });
    const type = cles[form.data.cle]?.type;
    const afficher = (cle, valeur) => (cles[cle]?.type === 'number' ? formaterNombre(Number(valeur)) : valeur);

    const enregistrer = (e) => {
        e.preventDefault();
        form.post(route('parametrage.baremes-odm.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('valeur', 'commentaire'),
        });
    };
    const retirer = (ligne) => {
        if (window.confirm(`Retirer la valeur ${afficher(ligne.cle, ligne.valeur)} prévue au ${ligne.date_effet} ?`)) {
            router.delete(route('parametrage.baremes-odm.destroy', ligne.id), { preserveScroll: true });
        }
    };

    return (
        <div className="space-y-5">
            <div className="rounded-lg border border-neemba-200 bg-neemba-50/50 p-4 text-sm text-gray-700">
                Les barèmes des ordres de mission se modifient sans second validateur. Chaque nouvelle valeur prend effet à la date
                choisie (aujourd'hui ou plus tard) ; les valeurs précédentes restent dans l'historique. Un ODM est calculé avec les
                barèmes en vigueur le jour de sa soumission, puis son calcul est figé.
            </div>

            {/* Barèmes en vigueur */}
            <div className="overflow-x-auto rounded-md border bg-white">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                            <th className="px-3 py-2">Barème</th>
                            <th className="px-3 py-2">En vigueur</th>
                            <th className="px-3 py-2">Depuis le</th>
                            <th className="px-3 py-2">Prochaine valeur</th>
                        </tr>
                    </thead>
                    <tbody>
                        {Object.entries(cles).map(([cle, { libelle }]) => (
                            <tr key={cle} className="border-b last:border-0">
                                <td className="px-3 py-2">{libelle}</td>
                                <td className="px-3 py-2 font-semibold">{afficher(cle, enVigueur[cle]?.valeur)}</td>
                                <td className="px-3 py-2 text-gray-500">{enVigueur[cle]?.depuis ?? 'valeur de la spec'}</td>
                                <td className="px-3 py-2">
                                    {enVigueur[cle]?.prochaine ? (
                                        <span className="inline-flex items-center gap-1 text-amber-700">
                                            <CalendarClock className="h-3.5 w-3.5" />
                                            {afficher(cle, enVigueur[cle].prochaine.valeur)} au {enVigueur[cle].prochaine.date}
                                        </span>
                                    ) : <span className="text-gray-400">—</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* Nouvelle valeur */}
            <form onSubmit={enregistrer} className="space-y-3 rounded-lg border bg-white p-4" aria-label="Nouvelle valeur de barème">
                <h4 className="text-sm font-semibold text-gray-900">Nouvelle valeur</h4>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="lg:col-span-2">
                        <Label htmlFor="bareme-cle" className="text-xs">Barème</Label>
                        <select id="bareme-cle" className={SELECT} value={form.data.cle}
                            onChange={(e) => form.setData({ ...form.data, cle: e.target.value, valeur: '' })}>
                            {Object.entries(cles).map(([cle, { libelle }]) => <option key={cle} value={cle}>{libelle}</option>)}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="bareme-valeur" className="text-xs">Valeur</Label>
                        <Input id="bareme-valeur" className="mt-1" value={form.data.valeur} inputMode={type === 'number' ? 'numeric' : 'text'}
                            placeholder={String(enVigueur[form.data.cle]?.valeur ?? '')}
                            onChange={(e) => form.setData('valeur', type === 'number' ? e.target.value.replace(/\D/g, '') : e.target.value)} />
                        {form.errors.valeur && <p className="mt-1 text-xs text-red-600" role="alert">{form.errors.valeur}</p>}
                    </div>
                    <div>
                        <Label htmlFor="bareme-date" className="text-xs">Date d'effet</Label>
                        <Input id="bareme-date" type="date" className="mt-1" min={aujourdhui} value={form.data.date_effet}
                            onChange={(e) => form.setData('date_effet', e.target.value)} />
                        {form.errors.date_effet && <p className="mt-1 text-xs text-red-600" role="alert">{form.errors.date_effet}</p>}
                    </div>
                </div>
                <div>
                    <Label htmlFor="bareme-commentaire" className="text-xs">Commentaire (facultatif)</Label>
                    <Input id="bareme-commentaire" className="mt-1" value={form.data.commentaire} maxLength={255}
                        placeholder="Ex. : note de la direction du 14/10" onChange={(e) => form.setData('commentaire', e.target.value)} />
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing || form.data.valeur === ''}>
                        <Plus className="mr-1 h-4 w-4" /> Enregistrer la valeur
                    </Button>
                </div>
            </form>

            {/* Historique */}
            <div>
                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-400">Historique</h4>
                <div className="overflow-x-auto rounded-md border bg-white">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-gray-50 text-left text-xs text-gray-500">
                                <th className="px-3 py-2">Date d'effet</th>
                                <th className="px-3 py-2">Barème</th>
                                <th className="px-3 py-2">Valeur</th>
                                <th className="px-3 py-2">Saisi par</th>
                                <th className="px-3 py-2">Commentaire</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {historique.map((ligne) => (
                                <tr key={ligne.id} className="border-b last:border-0">
                                    <td className="whitespace-nowrap px-3 py-2">
                                        {ligne.date_effet} {ligne.a_venir && <Badge className="ml-1 bg-amber-100 text-amber-800 hover:bg-amber-100">à venir</Badge>}
                                    </td>
                                    <td className="px-3 py-2">{ligne.libelle}</td>
                                    <td className="whitespace-nowrap px-3 py-2 font-medium">{afficher(ligne.cle, ligne.valeur)}</td>
                                    <td className="px-3 py-2 text-xs">{ligne.auteur}<br /><span className="text-gray-400">{ligne.saisi_le}</span></td>
                                    <td className="px-3 py-2 text-xs text-gray-600">{ligne.commentaire}</td>
                                    <td className="px-3 py-2 text-right">
                                        {ligne.a_venir && (
                                            <Button variant="ghost" size="sm" onClick={() => retirer(ligne)} aria-label={`Retirer la valeur prévue au ${ligne.date_effet}`}>
                                                <Trash2 className="h-3.5 w-3.5 text-red-600" />
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
