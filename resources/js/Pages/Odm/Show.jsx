/**
 * Fiche d'un ordre de mission (M12) : en-tête, bandeaux (rejet, dérogation), onglets Détails, Calcul et Historique.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import { ArrowLeft, CheckCircle2, Pencil, ShieldAlert, Stamp, Trash2, XCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/Components/ui/dialog';
import BadgeStatutOdm from '@/Components/Odm/BadgeStatutOdm';
import PanneauCalcul from '@/Components/Odm/PanneauCalcul';
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

export default function Show({ odm, etapes = [], visa = null, peutModifier = false, peutAnnuler = false, peutDeciderDerogation = false }) {
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
                                {odm.a_refacturer && <span className="rounded bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800">À refacturer</span>}
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

                <Tabs defaultValue="details">
                    <TabsList>
                        <TabsTrigger value="details">Détails</TabsTrigger>
                        <TabsTrigger value="calcul">Calcul</TabsTrigger>
                        <TabsTrigger value="validations">Validations</TabsTrigger>
                        <TabsTrigger value="historique">Historique</TabsTrigger>
                    </TabsList>

                    <TabsContent value="validations" className="mt-4">
                        <Validations etapes={etapes} />
                    </TabsContent>

                    <TabsContent value="details" className="mt-4 space-y-4">
                        <Card>
                            <CardContent className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                                <Info libelle="Destination(s)">{odm.destinations.join(', ')}</Info>
                                <Info libelle="Client(s)">{odm.clients.join(', ')}</Info>
                                <Info libelle="Frais à la charge de">{odm.prise_en_charge_label}</Info>
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
                        <div className="max-w-xl">
                            <PanneauCalcul calcul={odm.calcul} type={odm.type} titre={odm.calcul?.fige ? 'Calcul figé à la validation' : 'Calcul des indemnités'} />
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
