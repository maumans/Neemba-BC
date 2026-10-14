/**
 * Encart « Justification » de la fiche d'un BP (spec v2.3, M07) : état de la justification (Annexe 3) et lien vers son
 * écran, reliquat à encaisser par le caissier et BD de complément (RG-M07-08), suivi RH d'un BP en retard (RG-M07-05).
 * Sur un BD de complément, rappelle le BP qu'il régularise.
 */
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Banknote, ClipboardCheck, FileSignature, Link2, Mail, UserX } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import MontantInput from '@/Components/MontantInput';
import { formaterDateHeure, formaterMontant } from '@/utils/format';
import { cn } from '@/lib/utils';

const COULEURS = {
    brouillon: 'bg-gray-100 text-gray-700',
    en_validation: 'bg-blue-100 text-blue-800',
    rejetee: 'bg-red-100 text-red-800',
    validee: 'bg-emerald-100 text-emerald-800',
};

function SuiviRh({ bonId, suivi, peutSuivre, montant }) {
    const [action, setAction] = useState(null);
    const [commentaire, setCommentaire] = useState('');
    const [montantRetenue, setMontantRetenue] = useState(String(Math.round(montant)));
    const [envoi, setEnvoi] = useState(false);
    const envoyer = () => {
        setEnvoi(true);
        router.post(route('bons-caisse.suivi-rh', bonId), { action, commentaire, montant: action === 'retenue' ? montantRetenue : null }, {
            preserveScroll: true,
            onSuccess: () => setAction(null),
            onFinish: () => setEnvoi(false),
        });
    };

    return (
        <div className="space-y-2 rounded-md border border-red-200 bg-red-50/60 p-3 text-xs text-red-900">
            <p className="font-semibold">BP en retard transmis aux RH le {formaterDateHeure(suivi.transmis_le)}</p>
            <p>
                <Mail className="mr-1 inline h-3.5 w-3.5" />
                Information de la DRH : {suivi.information_drh ? `envoyée le ${formaterDateHeure(suivi.information_drh.le)} (${suivi.information_drh.par})` : 'à envoyer'}
            </p>
            <p>
                <UserX className="mr-1 inline h-3.5 w-3.5" />
                Retenue sur salaire : {suivi.retenue
                    ? `${formaterMontant(suivi.retenue.montant)} le ${formaterDateHeure(suivi.retenue.le)} (${suivi.retenue.par})`
                    : (suivi.information_drh ? 'possible' : 'après l\'information de la DRH')}
            </p>
            {peutSuivre && (
                <div className="flex flex-wrap gap-2 pt-1">
                    {!suivi.information_drh && (
                        <Button type="button" size="sm" variant="outline" className="bg-white" onClick={() => { setCommentaire(''); setAction('information_drh'); }}>
                            <Mail className="mr-1 h-3.5 w-3.5" /> Tracer l&apos;information de la DRH
                        </Button>
                    )}
                    {suivi.information_drh && !suivi.retenue && (
                        <Button type="button" size="sm" variant="outline" className="bg-white" onClick={() => { setCommentaire(''); setAction('retenue'); }}>
                            <UserX className="mr-1 h-3.5 w-3.5" /> Tracer la retenue sur salaire
                        </Button>
                    )}
                </div>
            )}
            <Dialog open={action !== null} onOpenChange={(ouvert) => !ouvert && setAction(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{action === 'retenue' ? 'Retenue sur salaire' : 'Email d\'information de la DRH'}</DialogTitle>
                        <DialogDescription>
                            {action === 'retenue'
                                ? 'La retenue est tracée sur le bon ; l\'intéressé et le DAF sont notifiés.'
                                : 'Indiquez la date ou la référence de l\'email envoyé à l\'intéressé.'}
                        </DialogDescription>
                    </DialogHeader>
                    {action === 'retenue' && (
                        <div>
                            <Label htmlFor="montant_retenue">Montant retenu</Label>
                            <MontantInput id="montant_retenue" value={montantRetenue} onChange={setMontantRetenue} />
                        </div>
                    )}
                    <div>
                        <Label htmlFor="commentaire_rh">Commentaire</Label>
                        <Textarea id="commentaire_rh" rows={3} value={commentaire} onChange={(e) => setCommentaire(e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setAction(null)}>Annuler</Button>
                        <Button type="button" disabled={envoi || (action === 'retenue' && !Number(montantRetenue))} onClick={envoyer}>Enregistrer</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function CarteJustificationBp({ bonId, montant, justification, bpRegularise }) {
    const [envoi, setEnvoi] = useState(false);

    if (bpRegularise) {
        return (
            <Card className="border-neemba-300 bg-neemba-50/60">
                <CardContent className="p-4 text-sm">
                    <p className="flex items-center gap-2 font-medium text-slate-900">
                        <Link2 className="h-4 w-4 text-neemba-600" /> Complément de régularisation
                    </p>
                    <p className="mt-1 text-gray-700">
                        Ce bon verse le trop justifié du BP{' '}
                        <Link href={route('bons-caisse.show', bpRegularise.id)} className="font-semibold text-blue-700 hover:underline">{bpRegularise.numero}</Link>.
                    </p>
                </CardContent>
            </Card>
        );
    }
    if (!justification) return null;

    const libelleBouton = justification.peut_viser
        ? 'Viser la justification'
        : justification.peut_modifier
            ? (justification.existe && justification.statut !== 'brouillon' ? 'Corriger la justification' : 'Établir la justification')
            : 'Voir la justification';

    return (
        <Card className="border-t-4 border-t-orange-400">
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center justify-between gap-2 text-base">
                    <span className="flex items-center gap-2"><FileSignature className="h-4 w-4 text-orange-500" /> Justification</span>
                    <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold', COULEURS[justification.statut] ?? 'bg-amber-100 text-amber-800')}>
                        {justification.statut_label}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                {justification.etape_label && <p className="text-xs text-gray-600">Visa attendu : <strong>{justification.etape_label}</strong></p>}
                {justification.existe && (
                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                        <dt className="text-gray-500">Justifié</dt><dd className="text-right font-semibold tabular-nums">{formaterMontant(justification.montant_justifie)}</dd>
                        <dt className="text-gray-500">À retourner</dt><dd className="text-right tabular-nums">{formaterMontant(justification.montant_a_retourner)}</dd>
                        <dt className="text-gray-500">Trop justifié</dt><dd className="text-right tabular-nums">{formaterMontant(justification.trop_justifie)}</dd>
                    </dl>
                )}

                <Link href={route('bons-caisse.justification', bonId)} className="block">
                    <Button type="button" className={cn('w-full', (justification.peut_viser || justification.peut_modifier) ? 'bg-slate-900 text-white hover:bg-slate-800' : '')}
                        variant={(justification.peut_viser || justification.peut_modifier) ? 'default' : 'outline'}>
                        <ClipboardCheck className="mr-2 h-4 w-4" /> {libelleBouton}
                    </Button>
                </Link>

                {justification.complement && (
                    <p className="text-xs text-gray-700">
                        Complément versé par le bon{' '}
                        <Link href={route('bons-caisse.show', justification.complement.id)} className="font-semibold text-blue-700 hover:underline">
                            {justification.complement.numero}
                        </Link>{' '}({justification.complement.statut_label}).
                    </p>
                )}

                {justification.reliquat_a_encaisser && (
                    <div className="space-y-2 rounded-md border border-neemba-300 bg-neemba-50 p-3 text-xs text-slate-900">
                        <p><Banknote className="mr-1 inline h-3.5 w-3.5" /> Reliquat de <strong>{formaterMontant(justification.montant_a_retourner)}</strong> à rendre à la caisse.</p>
                        {justification.peut_encaisser && (
                            <Button type="button" size="sm" disabled={envoi} className="w-full bg-neemba-500 text-slate-900 hover:bg-neemba-400"
                                onClick={() => {
                                    setEnvoi(true);
                                    router.post(route('bons-caisse.justification.reliquat', bonId), {}, { preserveScroll: true, onFinish: () => setEnvoi(false) });
                                }}>
                                <Banknote className="mr-1 h-4 w-4" /> Encaisser le reliquat
                            </Button>
                        )}
                    </div>
                )}
                {justification.reliquat_encaisse_le && (
                    <p className="text-xs text-emerald-700">Reliquat encaissé le {formaterDateHeure(justification.reliquat_encaisse_le)}.</p>
                )}

                {justification.suivi_rh && (
                    <SuiviRh bonId={bonId} suivi={justification.suivi_rh} peutSuivre={justification.peut_suivre_rh} montant={montant} />
                )}
            </CardContent>
        </Card>
    );
}
