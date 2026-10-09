/**
 * Fiche d'un ordre de mission (M12) : en-tête, bandeaux (rejet, dérogation), onglets Détails, Calcul et Historique.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import { AlertTriangle, ArrowLeft, CalendarCheck, CalendarPlus, CheckCircle2, FileText, Info as IconeInfo, Pencil, ShieldAlert, Stamp, Trash2, XCircle } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/Components/ui/dialog';
import BadgeStatutOdm from '@/Components/Odm/BadgeStatutOdm';
import PanneauCalcul from '@/Components/Odm/PanneauCalcul';
import PriseEnChargeFrais from '@/Components/Odm/PriseEnChargeFrais';
import MontantInput from '@/Components/MontantInput';
import BadgeStatut from '@/Components/BadgeStatut';
import { formaterMontant } from '@/utils/format';
import { STATUTS_CADRE } from '@/utils/odm';

function Info({ libelle, children }) {
    return (
        <div>
            <dt className="text-xs text-gray-500">{libelle}</dt>
            <dd className="mt-0.5 text-sm text-gray-900">{children || '—'}</dd>
        </div>
    );
}

/** Couleur d'une étape du circuit */
const COULEURS_ETAPE = {
    validee: 'text-green-700',
    rejetee: 'text-red-700',
    en_attente: 'text-blue-700',
    a_venir: 'text-gray-500',
    sautee: 'text-gray-500 italic',
    annulee: 'text-gray-400',
};

/** Onglet « Validations » : une ligne par niveau, regroupées par version (un ODM rejeté puis resoumis) */
function Validations({ etapes }) {
    const versions = [...new Set(etapes.map((e) => e.version))].sort((a, b) => b - a);

    return (
        <div className="space-y-4">
            {versions.map((version) => (
                <Card key={version}>
                    <CardContent className="overflow-x-auto p-4">
                        {versions.length > 1 && <h3 className="mb-2 text-sm font-semibold text-gray-900">Version {version}</h3>}
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-xs text-gray-500">
                                    <th className="py-2 pr-3 font-medium">Niveau</th>
                                    <th className="py-2 pr-3 font-medium">Statut</th>
                                    <th className="py-2 pr-3 font-medium">Valideur</th>
                                    <th className="py-2 pr-3 font-medium">Date</th>
                                    <th className="py-2 font-medium">Commentaire</th>
                                </tr>
                            </thead>
                            <tbody>
                                {etapes.filter((e) => e.version === version).map((e) => (
                                    <tr key={e.id} className="border-b align-top last:border-b-0" data-testid={`etape-${e.niveau}`}>
                                        <td className="py-2 pr-3 font-medium">{e.libelle}</td>
                                        <td className={`py-2 pr-3 ${COULEURS_ETAPE[e.statut] ?? ''}`}>{e.statut_label}</td>
                                        <td className="py-2 pr-3">
                                            {e.valideur ?? (e.statut === 'en_attente' ? (e.valideurs_possibles.join(', ') || 'Aucun valideur possible') : '—')}
                                            {e.au_titre_de && <p className="text-xs text-gray-500">au titre de {e.au_titre_de}</p>}
                                        </td>
                                        <td className="py-2 pr-3 text-xs text-gray-600">
                                            {e.date_decision ?? e.date_attribution ?? '—'}
                                            {e.duree && <p>en {e.duree}</p>}
                                            {e.attente && <p>en attente depuis {e.attente}</p>}
                                            {e.echeance && <p className={e.en_retard ? 'font-medium text-red-600' : ''}>échéance {e.echeance}</p>}
                                        </td>
                                        <td className="py-2 text-gray-700">{e.commentaire ?? ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            ))}
            {etapes.length === 0 && <p className="text-sm text-gray-500">Le circuit démarre à la soumission.</p>}
        </div>
    );
}

/** Visa ou rejet de l'étape en cours (RG-M12-11, RG-M12-12) */
function ActionsVisa({ odm, visa }) {
    const visaForm = useForm({ commentaire: '' });
    const rejetForm = useForm({ motif: '' });
    const [rejet, setRejet] = useState(false);

    return (
        <Card className="border-blue-200 bg-blue-50/40">
            <CardContent className="space-y-3 p-4">
                <p className="flex items-center gap-2 text-sm font-semibold text-blue-900">
                    <Stamp className="h-4 w-4" /> Votre visa est attendu : {visa.niveau}
                    {visa.au_titre_de && <span className="font-normal">(au titre de {visa.au_titre_de})</span>}
                </p>
                {visa.dernier && <p className="text-xs text-blue-800">Dernier visa : l'ordre de mission sera validé et son calcul figé.</p>}
                <Textarea rows={2} value={visaForm.data.commentaire} onChange={(e) => visaForm.setData('commentaire', e.target.value)}
                    placeholder="Commentaire (facultatif)" aria-label="Commentaire du visa" />
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={() => visaForm.post(route('odm.viser', odm.id), { preserveScroll: true })} disabled={visaForm.processing}>
                        <CheckCircle2 className="mr-1 h-4 w-4" /> Viser
                    </Button>
                    <Button size="sm" variant="outline" className="text-red-700" onClick={() => setRejet(true)}>
                        <XCircle className="mr-1 h-4 w-4" /> Rejeter
                    </Button>
                </div>
            </CardContent>

            <Dialog open={rejet} onOpenChange={setRejet}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Rejeter l'ordre de mission {odm.libelle}</DialogTitle>
                        <DialogDescription>L'ODM revient au demandeur, garde son numéro et pourra être corrigé puis resoumis.</DialogDescription>
                    </DialogHeader>
                    <Textarea rows={3} value={rejetForm.data.motif} onChange={(e) => rejetForm.setData('motif', e.target.value)}
                        placeholder="Motif du rejet (obligatoire)" aria-label="Motif du rejet" />
                    {rejetForm.errors.motif && <p className="text-sm text-red-600">{rejetForm.errors.motif}</p>}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRejet(false)}>Revenir</Button>
                        <Button variant="destructive" disabled={rejetForm.processing}
                            onClick={() => rejetForm.post(route('odm.rejeter', odm.id), { preserveScroll: true, onSuccess: () => setRejet(false) })}>
                            Rejeter
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

/** Génération des bons d'un ODM validé (US-08, RG-M12-13 à RG-M12-15) */
function GenerationBons({ odm, generation }) {
    const premier = generation.participants[0]?.user_id ?? '';
    const form = useForm({ beneficiaire_groupe_id: premier, bp: { montant: '', motif: '', beneficiaire_id: premier } });
    const [avecBp, setAvecBp] = useState(false);
    const groupe = generation.mode === 'groupe';
    const rien = generation.reste_a_generer === 0 && !avecBp;

    const generer = () => {
        form.transform((d) => ({ ...d, bp: avecBp ? d.bp : null }));
        form.post(route('odm.generer-bons', odm.id), { preserveScroll: true });
    };

    return (
        <Card className="border-green-200 bg-green-50/40">
            <CardContent className="space-y-3 p-4">
                <p className="flex items-center gap-2 text-sm font-semibold text-green-900">
                    <FileText className="h-4 w-4" /> Bons de caisse de l'ordre de mission
                </p>
                <p className="text-sm text-gray-700">
                    {groupe
                        ? 'Un bon groupé, de ce que Neemba verse pour l\'ODM, au n° Orange Money du participant désigné.'
                        : 'Un bon définitif par participant, du montant que Neemba lui verse.'}
                    {' '}Les frais payés directement par le client n'entrent pas dans les bons.
                    {' '}Chaque bon est soumis aussitôt et suit son propre circuit (chef de service, CDG, Finance, DP au-delà du seuil).
                    L'ODM tient lieu de justificatif.
                </p>
                {generation.sans_taux && (
                    <p className="flex items-start gap-2 rounded-md bg-amber-50 p-2 text-sm text-amber-800">
                        <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                        Aucun taux FCFA → GNF n'a encore été saisi : la Trésorerie doit le saisir avant la génération.
                    </p>
                )}
                <ul className="space-y-1 text-sm">
                    {generation.participants.map((p) => (
                        <li key={p.user_id} className="flex flex-wrap items-center justify-between gap-2">
                            <span>{p.nom}{p.numero_om && <span className="ml-1 font-mono text-xs text-gray-500">OM {p.numero_om}</span>}</span>
                            {p.sans_bon ? (
                                <span className="text-xs font-medium text-purple-800">Aucun bon : frais payés par le client</span>
                            ) : (
                                <span className="tabular-nums">
                                    {p.montant_bon !== null ? formaterMontant(p.montant_bon) : '—'}
                                    {p.montant_client_direct > 0 && <span className="ml-1 text-xs text-purple-800">(hors {formaterMontant(p.montant_client_direct)} payés par le client)</span>}
                                    {p.a_un_bon && <span className="ml-2 text-xs text-green-700">bon généré</span>}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
                {groupe && generation.reste_a_generer > 0 && (
                    <div>
                        <label htmlFor="beneficiaire-groupe" className="text-sm font-medium">Bon versé à</label>
                        <select id="beneficiaire-groupe" className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                            value={form.data.beneficiaire_groupe_id} onChange={(e) => form.setData('beneficiaire_groupe_id', Number(e.target.value))}>
                            {generation.participants.map((p) => <option key={p.user_id} value={p.user_id}>{p.nom}</option>)}
                        </select>
                    </div>
                )}
                {generation.bp_autorise && (
                    <div className="space-y-2 rounded-md border bg-white p-3">
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <input type="checkbox" checked={avecBp} onChange={(e) => setAvecBp(e.target.checked)} />
                            Générer aussi un bon provisoire (avance pour frais réels de mission)
                        </label>
                        {avecBp && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <label htmlFor="bp-montant" className="text-xs text-gray-600">Montant de l'avance</label>
                                    <MontantInput id="bp-montant" value={form.data.bp.montant} onChange={(v) => form.setData('bp', { ...form.data.bp, montant: v })} />
                                    {form.errors.bp_montant && <p className="mt-1 text-xs text-red-600">{form.errors.bp_montant}</p>}
                                </div>
                                <div>
                                    <label htmlFor="bp-beneficiaire" className="text-xs text-gray-600">Bénéficiaire</label>
                                    <select id="bp-beneficiaire" className="h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                                        value={form.data.bp.beneficiaire_id} onChange={(e) => form.setData('bp', { ...form.data.bp, beneficiaire_id: Number(e.target.value) })}>
                                        {generation.participants.map((p) => <option key={p.user_id} value={p.user_id}>{p.nom}</option>)}
                                    </select>
                                </div>
                                <div className="sm:col-span-2">
                                    <label htmlFor="bp-motif" className="text-xs text-gray-600">Motif (frais réels prévus)</label>
                                    <Textarea id="bp-motif" rows={2} value={form.data.bp.motif} onChange={(e) => form.setData('bp', { ...form.data.bp, motif: e.target.value })} />
                                    {form.errors.bp_motif && <p className="mt-1 text-xs text-red-600">{form.errors.bp_motif}</p>}
                                </div>
                                <p className="text-xs text-gray-500 sm:col-span-2">À régulariser 3 jours ouvrés après le retour de mission.</p>
                            </div>
                        )}
                    </div>
                )}
                {(form.errors.general || form.errors.bp) && <p className="text-sm text-red-600">{form.errors.general ?? form.errors.bp}</p>}
                <Button size="sm" onClick={generer} disabled={form.processing || rien || generation.sans_taux}>
                    <FileText className="mr-1 h-4 w-4" />
                    {generation.reste_a_generer > 0 ? 'Générer les bons de caisse' : 'Générer le bon provisoire'}
                </Button>
            </CardContent>
        </Card>
    );
}

/** Onglet « Bons » : bons générés depuis l'ODM et leur statut */
function Bons({ bons }) {
    if (bons.length === 0) {
        return <p className="text-sm text-gray-500">Aucun bon de caisse généré pour l'instant.</p>;
    }

    return (
        <Card>
            <CardContent className="overflow-x-auto p-4">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs text-gray-500">
                            <th className="py-2 pr-3 font-medium">Bon</th>
                            <th className="py-2 pr-3 font-medium">Bénéficiaire</th>
                            <th className="py-2 pr-3 text-right font-medium">Montant</th>
                            <th className="py-2 pr-3 font-medium">Statut</th>
                            <th className="py-2 font-medium">Paiement</th>
                        </tr>
                    </thead>
                    <tbody>
                        {bons.map((b) => (
                            <tr key={b.id} className="border-b last:border-b-0" data-testid={`bon-${b.numero}`}>
                                <td className="py-2 pr-3">
                                    <Link href={route('bons-caisse.show', b.id)} className="font-medium text-neemba-700 hover:underline">{b.numero}</Link>
                                    <span className="ml-1 text-xs text-gray-500">{b.type_bon}</span>
                                </td>
                                <td className="py-2 pr-3">{b.beneficiaire}</td>
                                <td className="py-2 pr-3 text-right tabular-nums">
                                    {formaterMontant(b.montant)}
                                    {b.taux_change_estime !== null && (
                                        <p className="text-xs text-gray-500">
                                            estimé au taux {String(b.taux_change_estime).replace('.', ',')}
                                            {b.taux_change_applique !== null && ` · payé au taux ${String(b.taux_change_applique).replace('.', ',')}`}
                                        </p>
                                    )}
                                </td>
                                <td className="py-2 pr-3"><BadgeStatut statut={b.statut} /></td>
                                <td className="py-2 text-xs text-gray-600">
                                    {b.montant_verse !== null ? `versé ${formaterMontant(b.montant_verse)}${b.frais_om !== null ? ` (frais OM ${formaterMontant(b.frais_om)})` : ''}` : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </CardContent>
        </Card>
    );
}

/** Onglet « Mission » (RG-M12-19) : segments, cumuls par participant, contrôle nuits = jours − 1 */
function Mission({ mission }) {
    return (
        <div className="space-y-4">
            {mission.incoherences.length > 0 && (
                <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
                    {mission.incoherences.map((m, i) => <p key={i}>{m}</p>)}
                </div>
            )}
            <Card>
                <CardContent className="overflow-x-auto p-4">
                    <h3 className="mb-2 text-sm font-semibold text-gray-900">
                        Mission {mission.numero ?? ''} : {mission.jours} jour(s), du {mission.debut} au {mission.fin}
                    </h3>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs text-gray-500">
                                <th className="py-2 pr-3 font-medium">Segment</th>
                                <th className="py-2 pr-3 font-medium">Période</th>
                                <th className="py-2 pr-3 text-right font-medium">Jours</th>
                                <th className="py-2 pr-3 text-right font-medium">Total</th>
                                <th className="py-2 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            {mission.segments.map((s) => (
                                <tr key={s.id} className="border-b last:border-b-0">
                                    <td className="py-2 pr-3">
                                        <Link href={route('odm.show', s.id)} className="font-medium text-neemba-700 hover:underline">{s.libelle}</Link>
                                        {s.libelle_prolongation && <p className="text-xs text-gray-500">{s.libelle_prolongation}</p>}
                                    </td>
                                    <td className="py-2 pr-3">{s.periode}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums">{s.jours}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums">{s.total !== null ? formaterMontant(s.total) : '—'}</td>
                                    <td className="py-2"><BadgeStatutOdm statut={s.statut} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </CardContent>
            </Card>
            <Card>
                <CardContent className="overflow-x-auto p-4">
                    <h3 className="mb-2 text-sm font-semibold text-gray-900">Cumul par participant</h3>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs text-gray-500">
                                <th className="py-2 pr-3 font-medium">Participant</th>
                                <th className="py-2 pr-3 text-right font-medium">Jours</th>
                                <th className="py-2 pr-3 text-right font-medium">Nuits payées</th>
                                <th className="py-2 pr-3 text-right font-medium">Montant</th>
                                <th className="py-2 font-medium">Contrôle nuits = jours − 1</th>
                            </tr>
                        </thead>
                        <tbody>
                            {mission.participants.map((p) => (
                                <tr key={p.user_id} className="border-b last:border-b-0" data-testid={`mission-${p.user_id}`}>
                                    <td className="py-2 pr-3 font-medium">{p.nom}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums">{p.jours}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums">{p.nuits}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums">{formaterMontant(p.montant)}</td>
                                    <td className={`py-2 ${p.coherent ? 'text-green-700' : 'font-medium text-red-700'}`}>
                                        {p.jours_heberges === 0 ? 'Sans objet (non hébergé)' : p.coherent ? `Cohérent (${p.nuits} = ${p.jours_heberges} − 1)` : `Écart : ${p.nuits} nuits pour ${p.jours_heberges} jours`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="mt-3 text-right text-sm font-semibold">Coût de la mission : {formaterMontant(mission.total)}</p>
                </CardContent>
            </Card>
        </div>
    );
}

/** RG-M12-20 : clôture, retour réel ; retour anticipé : trop-perçu à reverser ou à retenir ; extérieur : factures payées au retour */
function Cloturer({ odm, regularisationsPossibles }) {
    const [ouvert, setOuvert] = useState(false);
    const form = useForm({
        date_retour_reelle: odm.date_retour_prevue,
        regularisations: Object.fromEntries(odm.participants.map((p) => [p.user_id, 'reversement'])),
        factures_retour: {},
    });
    const anticipe = form.data.date_retour_reelle && form.data.date_retour_reelle < odm.date_retour_prevue;
    const auRetour = odm.type === 'exterieur' && odm.hebergement_exterieur === 'au_retour';

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => setOuvert(true)}><CalendarCheck className="mr-1 h-4 w-4" /> Clôturer la mission</Button>
            <Dialog open={ouvert} onOpenChange={setOuvert}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Clôturer la mission {odm.libelle}</DialogTitle>
                        <DialogDescription>
                            Retour prévu le {odm.date_retour_prevue_format}. Un retour plus tardif exige d'abord une prolongation.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div>
                            <label htmlFor="retour-reel" className="text-sm font-medium">Date de retour réelle</label>
                            <Input id="retour-reel" type="date" className="mt-1" max={odm.date_retour_prevue} min={odm.date_depart}
                                value={form.data.date_retour_reelle} onChange={(e) => form.setData('date_retour_reelle', e.target.value)} />
                            {(form.errors.date_retour_reelle || form.errors.general) && (
                                <p className="mt-1 text-sm text-red-600">{form.errors.date_retour_reelle ?? form.errors.general}</p>
                            )}
                        </div>
                        {anticipe && (
                            <div className="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm">
                                <p className="text-amber-900">
                                    Retour anticipé : le calcul est refait au réel. Un participant déjà payé a un trop-perçu à régulariser ; un bon
                                    non encore payé est remplacé par un bon au montant réel.
                                </p>
                                {odm.participants.map((p) => (
                                    <div key={p.user_id} className="flex flex-wrap items-center justify-between gap-2">
                                        <span>{p.nom}</span>
                                        <select aria-label={`Régularisation de ${p.nom}`} className="h-8 rounded-md border border-input bg-white px-2 text-sm"
                                            value={form.data.regularisations[p.user_id]}
                                            onChange={(e) => form.setData('regularisations', { ...form.data.regularisations, [p.user_id]: e.target.value })}>
                                            {Object.entries(regularisationsPossibles).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                        </select>
                                    </div>
                                ))}
                            </div>
                        )}
                        {auRetour && (
                            <div className="space-y-2 rounded-md border p-3 text-sm">
                                <p className="font-medium">Factures d'hébergement payées au retour</p>
                                <p className="text-xs text-gray-500">Un bon complémentaire est préparé en brouillon pour chaque facture : joignez-la, puis soumettez le bon.</p>
                                {odm.participants.map((p) => (
                                    <div key={p.user_id} className="flex flex-wrap items-center justify-between gap-2">
                                        <span>{p.nom}</span>
                                        <MontantInput className="w-40" value={form.data.factures_retour[p.user_id] ?? ''}
                                            onChange={(v) => form.setData('factures_retour', { ...form.data.factures_retour, [p.user_id]: v })} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOuvert(false)}>Revenir</Button>
                        <Button disabled={form.processing || !form.data.date_retour_reelle} onClick={() => form.post(route('odm.cloturer', odm.id), { onSuccess: () => setOuvert(false) })}>
                            Clôturer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** RG-M12-22 : annulation par le DAF, bons non payés annulés */
function AnnulerDaf({ odm }) {
    const [ouvert, setOuvert] = useState(false);
    const form = useForm({ motif: '' });

    return (
        <>
            <Button size="sm" variant="ghost" className="text-red-600 hover:bg-red-50 hover:text-red-700" onClick={() => setOuvert(true)}>
                <Trash2 className="mr-1 h-4 w-4" /> Annuler (DAF)
            </Button>
            <Dialog open={ouvert} onOpenChange={setOuvert}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Annuler l'ordre de mission {odm.libelle}</DialogTitle>
                        <DialogDescription>Les bons non payés de l'ODM sont annulés. Un ODM dont un bon est payé se clôture au lieu de s'annuler.</DialogDescription>
                    </DialogHeader>
                    <Textarea rows={3} value={form.data.motif} onChange={(e) => form.setData('motif', e.target.value)} placeholder="Motif de l'annulation" aria-label="Motif de l'annulation (DAF)" />
                    {(form.errors.motif || form.errors.general) && <p className="text-sm text-red-600">{form.errors.motif ?? form.errors.general}</p>}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOuvert(false)}>Revenir</Button>
                        <Button variant="destructive" disabled={form.processing} onClick={() => form.post(route('odm.annuler-daf', odm.id), { onSuccess: () => setOuvert(false) })}>
                            Annuler l'ODM
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Trop-perçus d'un retour anticipé : reversement en caisse (caissier) ou retenue sur salaire (RH) */
function Regularisations({ odm, regularisations }) {
    return (
        <Card className="border-amber-200">
            <CardContent className="space-y-2 p-4">
                <p className="text-sm font-semibold text-amber-900">Trop-perçus à régulariser (retour anticipé)</p>
                {regularisations.map((r) => (
                    <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm" data-testid={`regularisation-${r.id}`}>
                        <span>{r.nom} : <strong>{formaterMontant(r.trop_percu)}</strong> · {r.mode}</span>
                        {r.statut === 'regularise' ? (
                            <span className="text-green-700">Régularisé le {r.regularise_le} par {r.regularise_par}</span>
                        ) : r.peut_regulariser ? (
                            <Button size="sm" onClick={() => router.post(route('odm.regulariser', [odm.id, r.id]), {}, { preserveScroll: true })}>
                                {r.mode === 'Retenue sur salaire' ? 'Confirmer la retenue' : 'Enregistrer le reversement'}
                            </Button>
                        ) : (
                            <span className="text-amber-700">À régulariser</span>
                        )}
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

/** RG-M12-17 : prolongation depuis le dernier segment validé */
function Prolonger({ odm }) {
    const [ouvert, setOuvert] = useState(false);
    const form = useForm({ date_retour_prevue: '' });

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => setOuvert(true)}><CalendarPlus className="mr-1 h-4 w-4" /> Prolonger la mission</Button>
            <Dialog open={ouvert} onOpenChange={setOuvert}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Prolonger la mission {odm.libelle}</DialogTitle>
                        <DialogDescription>
                            Un nouveau segment est créé en brouillon : départ le lendemain du retour ({odm.date_retour_reelle_format ?? odm.date_retour_prevue_format}),
                            mêmes participants (retrait possible), nuitée de rattrapage du segment précédent. Il suit le même circuit de validation.
                        </DialogDescription>
                    </DialogHeader>
                    <div>
                        <label htmlFor="retour-prolongation" className="text-sm font-medium">Nouvelle date de retour prévue</label>
                        <Input id="retour-prolongation" type="date" className="mt-1" value={form.data.date_retour_prevue}
                            onChange={(e) => form.setData('date_retour_prevue', e.target.value)} />
                        {(form.errors.date_retour_prevue || form.errors.general) && (
                            <p className="mt-1 text-sm text-red-600">{form.errors.date_retour_prevue ?? form.errors.general}</p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOuvert(false)}>Revenir</Button>
                        <Button disabled={form.processing || !form.data.date_retour_prevue} onClick={() => form.post(route('odm.prolonger', odm.id))}>
                            Créer la prolongation
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default function Show({
    odm, etapes = [], visa = null, bons = [], generation = null, sansBon = false, mission = null,
    peutModifier = false, peutAnnuler = false, peutDeciderDerogation = false, peutProlonger = false, prolongation = null,
    peutCloturer = false, peutAnnulerDaf = false, regularisations = [], regularisationsPossibles = {},
}) {
    const [annulation, setAnnulation] = useState(false);
    const [motifAnnulation, setMotifAnnulation] = useState('');
    const [derogation, setDerogation] = useState(false);
    const decision = useForm({ decision: 'accorder', motif: '' });

    const annuler = async () => {
        const { data } = await axios.post(route('api.odm.annuler', odm.id), { motif: motifAnnulation || null });
        router.visit(data.redirection);
    };

    const decider = (choix) => {
        decision.transform((d) => ({ ...d, decision: choix }));
        decision.post(route('odm.derogation', odm.id), { preserveScroll: true, onSuccess: () => setDerogation(false) });
    };

    return (
        <AuthenticatedLayout header={`Ordre de mission ${odm.libelle}`}>
            <Head title={`ODM ${odm.libelle}`} />

            <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
                <Link href={route('odm.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                    <ArrowLeft className="h-4 w-4" /> Ordres de mission
                </Link>

                <Card>
                    <CardContent className="flex flex-wrap items-start justify-between gap-4 p-4">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-semibold text-gray-900">{odm.libelle}</h1>
                                <BadgeStatutOdm statut={odm.statut} />
                                {odm.a_refacturer && (
                                    <span className="rounded bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800" data-testid="badge-a-refacturer">
                                        À refacturer · {odm.montant_a_refacturer_format}
                                    </span>
                                )}
                            </div>
                            {odm.libelle_prolongation && <p className="text-sm text-gray-600">{odm.libelle_prolongation}</p>}
                            <p className="text-sm text-gray-600">
                                {odm.type_label} · {odm.service} · du {odm.date_depart_format} au {odm.date_retour_reelle_format ?? odm.date_retour_prevue_format}
                                {odm.version > 1 && ` · version ${odm.version}`}
                            </p>
                            <p className="text-xs text-gray-500">
                                Demandeur : {odm.demandeur}{odm.initiateur && ` (saisi par ${odm.initiateur})`}
                                {odm.date_soumission_format && ` · soumis le ${odm.date_soumission_format}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {odm.numero && (
                                <a href={route('odm.pdf', odm.id)} target="_blank" rel="noreferrer">
                                    <Button size="sm" variant="outline"><FileText className="mr-1 h-4 w-4" /> Imprimer (PDF)</Button>
                                </a>
                            )}
                            {peutProlonger && <Prolonger odm={odm} />}
                            {peutCloturer && <Cloturer odm={odm} regularisationsPossibles={regularisationsPossibles} />}
                            {peutAnnulerDaf && <AnnulerDaf odm={odm} />}
                            {prolongation && (
                                <Link href={route('odm.show', prolongation.id)}>
                                    <Button size="sm" variant="outline"><CalendarPlus className="mr-1 h-4 w-4" /> Prolongation : {prolongation.libelle}</Button>
                                </Link>
                            )}
                            {peutModifier && (
                                <Link href={route('odm.edit', odm.id)}>
                                    <Button size="sm"><Pencil className="mr-1 h-4 w-4" /> {odm.statut === 'REJETE' ? 'Corriger et resoumettre' : 'Reprendre'}</Button>
                                </Link>
                            )}
                            {peutDeciderDerogation && (
                                <Button size="sm" variant="outline" onClick={() => setDerogation(true)}><ShieldAlert className="mr-1 h-4 w-4" /> Décider de la dérogation</Button>
                            )}
                            {peutAnnuler && (
                                <Button size="sm" variant="ghost" className="text-red-600 hover:bg-red-50 hover:text-red-700" onClick={() => setAnnulation(true)}>
                                    <Trash2 className="mr-1 h-4 w-4" /> Annuler
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {odm.rejet && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                        <p className="font-semibold">Rejeté au niveau {odm.rejet.niveau} par {odm.rejet.valideur}, le {odm.rejet.date}</p>
                        <p className="mt-1">Motif : {odm.rejet.motif}</p>
                    </div>
                )}
                {odm.derogation && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        {odm.derogation.statut === 'demandee' && <>Dérogation au chevauchement demandée : {odm.derogation.demande_motif}</>}
                        {odm.derogation.statut === 'accordee' && <>Dérogation au chevauchement accordée par {odm.derogation.par} le {odm.derogation.le} : {odm.derogation.motif}</>}
                        {odm.derogation.statut === 'refusee' && <>Dérogation au chevauchement refusée par {odm.derogation.par} le {odm.derogation.le} : {odm.derogation.motif}</>}
                    </div>
                )}

                {visa && <ActionsVisa odm={odm} visa={visa} />}
                {regularisations.length > 0 && <Regularisations odm={odm} regularisations={regularisations} />}
                {generation && <GenerationBons odm={odm} generation={generation} />}
                {sansBon && ['VALIDE', 'BONS_GENERES'].includes(odm.statut) && (
                    <p className="flex items-start gap-2 rounded-lg border border-purple-200 bg-purple-50 p-3 text-sm text-purple-900">
                        <IconeInfo className="mt-0.5 h-4 w-4 flex-shrink-0" />
                        Tous les frais sont payés directement par le client : aucun bon de caisse n'est généré.
                    </p>
                )}

                <Tabs defaultValue="details">
                    <TabsList>
                        <TabsTrigger value="details">Détails</TabsTrigger>
                        <TabsTrigger value="calcul">Calcul</TabsTrigger>
                        <TabsTrigger value="validations">Validations</TabsTrigger>
                        <TabsTrigger value="bons">Bons{bons.length > 0 && ` (${bons.length})`}</TabsTrigger>
                        {mission && mission.segments.length > 0 && <TabsTrigger value="mission">Mission{mission.segments.length > 1 && ` (${mission.segments.length} segments)`}</TabsTrigger>}
                        <TabsTrigger value="historique">Historique</TabsTrigger>
                    </TabsList>

                    <TabsContent value="bons" className="mt-4">
                        <Bons bons={bons} />
                    </TabsContent>

                    {mission && (
                        <TabsContent value="mission" className="mt-4">
                            <Mission mission={mission} />
                        </TabsContent>
                    )}

                    <TabsContent value="validations" className="mt-4">
                        <Validations etapes={etapes} />
                    </TabsContent>

                    <TabsContent value="details" className="mt-4 space-y-4">
                        <Card>
                            <CardContent className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                <Info libelle="Destination(s)">{odm.destinations.join(', ')}</Info>
                                <Info libelle="Client(s)">{odm.clients.join(', ')}</Info>
                                <Info libelle="Frais à la charge de">
                                    {odm.prise_en_charge_label}{odm.mode_client_label && ` — ${odm.mode_client_label.toLowerCase()}`}
                                    {odm.resume_prise_en_charge && <span className="block text-xs text-purple-800">{odm.resume_prise_en_charge}</span>}
                                </Info>
                                <Info libelle="But">{odm.but}</Info>
                                <Info libelle="Code analytique">{odm.code_analytique && `${odm.code_analytique}${odm.code_analytique_libelle ? ' — ' + odm.code_analytique_libelle : ''}`}</Info>
                                <Info libelle="Véhicule">{odm.vehicule}</Info>
                                <Info libelle="Mission technique">{odm.technique ? 'Oui' : 'Non'}</Info>
                                <Info libelle="OR liés">{odm.ordres_reparation.map((or) => `${or.numero} (${or.type === 'garantie' ? 'Garantie' : 'Vente'})`).join(', ')}</Info>
                                {odm.motif_depart_passe && <Info libelle="Motif (départ dans le passé)">{odm.motif_depart_passe}</Info>}
                                {odm.type === 'exterieur' && <Info libelle="Hébergement à l'étranger">{odm.hebergement_exterieur_label}</Info>}
                                {odm.type === 'exterieur' && <Info libelle="Référence billet / bon de commande Wanda">{odm.reference_billet}</Info>}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="overflow-x-auto p-4">
                                <h2 className="mb-2 text-sm font-semibold text-gray-900">Participants ({odm.participants.length})</h2>
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs text-gray-500">
                                            <th className="py-2 pr-3 font-medium">Participant</th>
                                            <th className="py-2 pr-3 font-medium">Service</th>
                                            <th className="py-2 pr-3 font-medium">Statut</th>
                                            <th className="py-2 pr-3 font-medium">N° OM</th>
                                            <th className="py-2 font-medium">Base vie</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {odm.participants.map((p) => (
                                            <tr key={p.user_id} className="border-b last:border-b-0">
                                                <td className="py-2 pr-3 font-medium">{p.nom}{p.matricule && <span className="ml-1 text-xs text-gray-500">({p.matricule})</span>}</td>
                                                <td className="py-2 pr-3">{p.service ?? '—'}</td>
                                                <td className="py-2 pr-3">{STATUTS_CADRE[p.statut_cadre] ?? 'Non renseigné'}</td>
                                                <td className="py-2 pr-3 font-mono text-xs">{p.numero_om ?? '—'}</td>
                                                <td className="py-2">{p.base_vie ? 'Oui' : 'Non'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="calcul" className="mt-4">
                        <div className="grid gap-4 lg:grid-cols-2">
                            <PanneauCalcul calcul={odm.calcul} type={odm.type} titre={odm.calcul?.fige ? 'Calcul figé à la validation' : 'Calcul des indemnités'} />
                            <Card>
                                <CardContent className="space-y-3 p-4">
                                    <h2 className="text-sm font-semibold text-gray-900">Prise en charge des frais</h2>
                                    <PriseEnChargeFrais participants={odm.participants} calcul={odm.calcul} lectureSeule />
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    <TabsContent value="historique" className="mt-4">
                        <Card>
                            <CardContent className="p-4">
                                <ol className="space-y-3">
                                    {odm.historique.map((h) => (
                                        <li key={h.id} className="border-l-2 border-neemba-300 pl-3 text-sm">
                                            <p className="font-medium text-gray-900">{h.action}{h.statut_apres && ` → ${h.statut_apres}`}</p>
                                            <p className="text-xs text-gray-500">{h.date}{h.utilisateur && ` · ${h.utilisateur}`}</p>
                                            {h.commentaire && <p className="mt-0.5 text-gray-700">{h.commentaire}</p>}
                                        </li>
                                    ))}
                                </ol>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>

            <Dialog open={annulation} onOpenChange={setAnnulation}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Annuler l'ordre de mission {odm.libelle} ?</DialogTitle>
                        <DialogDescription>Possible tant qu'aucun bon de caisse n'a été généré (RG-M12-22).</DialogDescription>
                    </DialogHeader>
                    <Textarea rows={3} value={motifAnnulation} onChange={(e) => setMotifAnnulation(e.target.value)} placeholder="Motif de l'annulation" aria-label="Motif de l'annulation" />
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAnnulation(false)}>Revenir</Button>
                        <Button variant="destructive" onClick={annuler}>Annuler l'ODM</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={derogation} onOpenChange={setDerogation}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Dérogation au chevauchement</DialogTitle>
                        <DialogDescription>{odm.derogation?.demande_motif}</DialogDescription>
                    </DialogHeader>
                    <Textarea rows={3} value={decision.data.motif} onChange={(e) => decision.setData('motif', e.target.value)} placeholder="Motif de votre décision" aria-label="Motif de votre décision" />
                    {decision.errors.motif && <p className="text-sm text-red-600">{decision.errors.motif}</p>}
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => decider('refuser')} disabled={decision.processing}>Refuser</Button>
                        <Button onClick={() => decider('accorder')} disabled={decision.processing}>Accorder</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
