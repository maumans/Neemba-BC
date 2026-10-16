/**
 * Alertes visibles à chaque étape (spec v2.3, RG-M04-05) : doublon probable confirmé par le demandeur (RG-M03-20),
 * BP du bénéficiaire en retard (RG-M03-21) et dérogation du DAF (RG-M07-06), avec la décision du DAF.
 *
 * Partagé par BonsCaisse/Show et Validations/Show : le valideur voit les mêmes alertes que le demandeur,
 * sur l'écran où il vise.
 */
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, Clock } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { formaterMontant as formatMontant } from '@/utils/nombreEnLettres';

export default function AlertesDuBon({ bonId, alertes = {}, peutDecider = false }) {
    const [decision, setDecision] = useState(null);
    const form = useForm({ decision: '', motif: '' });
    const { doublon, bp_en_retard: retards = [], derogation } = alertes;
    if (!doublon && retards.length === 0 && !derogation) return null;

    const decider = () => {
        form.transform((d) => ({ ...d, decision }));
        form.post(route('bons-caisse.derogation-bp', bonId), { preserveScroll: true, onSuccess: () => setDecision(null) });
    };

    return (
        <div className="mb-6 space-y-3" data-testid="alertes-bon">
            {doublon && (
                <div className="flex gap-3 rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-900" role="alert">
                    <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-orange-500" />
                    <div>
                        <p className="font-semibold">Doublon probable</p>
                        <p>{doublon.texte}</p>
                        <p className="text-xs text-orange-800">Confirmé par {doublon.confirme_par} le {doublon.confirme_le_format} : il ne s'agirait pas d'un doublon.</p>
                        {doublon.bon_id && <Link href={route('bons-caisse.show', doublon.bon_id)} className="text-xs font-medium underline">Voir le bon {doublon.numero}</Link>}
                    </div>
                </div>
            )}
            {retards.length > 0 && (
                <div className="flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
                    <Clock className="mt-0.5 h-5 w-5 shrink-0 text-red-500" />
                    <div>
                        <p className="font-semibold">Le bénéficiaire a {retards.length > 1 ? `${retards.length} bons provisoires` : 'un bon provisoire'} non régularisé{retards.length > 1 ? 's' : ''} en retard</p>
                        <ul className="mt-1 space-y-0.5">
                            {retards.map((bp) => (
                                <li key={bp.id}>
                                    <Link href={route('bons-caisse.show', bp.id)} className="font-mono underline">{bp.numero}</Link>
                                    {' '}· {formatMontant(bp.montant)} · échéance dépassée le {bp.echeance}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
            {derogation && (
                <div className={`rounded-lg border px-4 py-3 text-sm ${derogation.statut === 'accordee' ? 'border-green-200 bg-green-50 text-green-900' : derogation.statut === 'refusee' ? 'border-red-200 bg-red-50 text-red-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}>
                    <p className="font-semibold">
                        Dérogation « BP en retard » {derogation.statut === 'accordee' ? 'accordée' : derogation.statut === 'refusee' ? 'refusée' : 'demandée au DAF'}
                    </p>
                    <p>Demande de {derogation.demande_par} le {derogation.demande_le_format} : {derogation.demande_motif}</p>
                    {derogation.decide_par && <p>Décision de {derogation.decide_par} le {derogation.decide_le_format} : {derogation.decision_motif}</p>}
                    {peutDecider && (
                        <div className="mt-3 space-y-2">
                            {decision ? (
                                <>
                                    <Label htmlFor="motif-decision">Motif de votre décision *</Label>
                                    <Textarea id="motif-decision" rows={2} className="bg-white" value={form.data.motif} onChange={(e) => form.setData('motif', e.target.value)} placeholder="10 caractères minimum" />
                                    {form.errors.motif && <p className="text-xs text-red-600">{form.errors.motif}</p>}
                                    <div className="flex gap-2">
                                        <Button size="sm" onClick={decider} disabled={form.processing} className={decision === 'accorder' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}>
                                            {decision === 'accorder' ? 'Accorder la dérogation' : 'Refuser la dérogation'}
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => setDecision(null)} disabled={form.processing}>Retour</Button>
                                    </div>
                                </>
                            ) : (
                                <div className="flex gap-2">
                                    <Button size="sm" className="bg-green-600 hover:bg-green-700" onClick={() => setDecision('accorder')}>Accorder</Button>
                                    <Button size="sm" variant="outline" className="text-red-700" onClick={() => setDecision('refuser')}>Refuser</Button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
