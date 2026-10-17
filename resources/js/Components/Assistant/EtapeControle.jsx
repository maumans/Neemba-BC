/**
 * Étape 5 « Contrôle » (E-03.7, US-BC-10) : les contrôles du serveur (§5.4.7, plus 13 et 14 de la spec v2.3).
 * Vert = OK ; orange = avertissement (n'empêche pas la soumission) ; rouge = bloquant.
 * Doublon probable (RG-M03-20) : le demandeur confirme. BP en retard (RG-M07-06) : il peut demander une dérogation au DAF.
 */
import { useState } from 'react';
import axios from 'axios';
import { AlertTriangle, CheckCircle2, Loader2, RefreshCw, Send, XCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import { msgErreur } from '@/utils/messages';

/** RG-M03-20 : « ce n'est pas un doublon » */
function ConfirmerDoublon({ bonId, onFait }) {
    const [envoi, setEnvoi] = useState(false);
    const [erreur, setErreur] = useState(null);
    const confirmer = async () => {
        setEnvoi(true);
        setErreur(null);
        try {
            await axios.post(route('api.bons.doublon-probable', bonId), { confirme: true });
            onFait();
        } catch (e) {
            setErreur(msgErreur(e.response?.data) || 'La confirmation a échoué.');
        } finally {
            setEnvoi(false);
        }
    };

    return (
        <div className="mt-2 space-y-1">
            <Button type="button" size="sm" variant="outline" onClick={confirmer} disabled={envoi} className="border-red-300 bg-white text-red-700 hover:bg-red-50">
                {envoi ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <CheckCircle2 className="mr-1 h-4 w-4" />}
                Je confirme qu'il ne s'agit pas d'un doublon
            </Button>
            <p className="text-xs text-gray-500">Les valideurs verront l'alerte et votre confirmation.</p>
            {erreur && <p className="text-xs text-red-700">{erreur}</p>}
        </div>
    );
}

/** RG-M07-06 : dérogation demandée au DAF, avec un motif */
function DemanderDerogation({ bonId, onFait }) {
    const [ouvert, setOuvert] = useState(false);
    const [motif, setMotif] = useState('');
    const [envoi, setEnvoi] = useState(false);
    const [erreur, setErreur] = useState(null);
    const envoyer = async () => {
        setEnvoi(true);
        setErreur(null);
        try {
            await axios.post(route('api.bons.derogation-bp', bonId), { motif });
            setOuvert(false);
            onFait();
        } catch (e) {
            const donnees = e.response?.data;
            setErreur(donnees?.errors?.motif?.[0] ?? (msgErreur(donnees) || 'La demande a échoué.'));
        } finally {
            setEnvoi(false);
        }
    };

    if (!ouvert) {
        return (
            <Button type="button" size="sm" variant="outline" className="mt-2 border-red-300 bg-white text-red-700 hover:bg-red-50" onClick={() => setOuvert(true)}>
                <Send className="mr-1 h-4 w-4" /> Demander une dérogation au DAF
            </Button>
        );
    }

    return (
        <div className="mt-2 space-y-2 rounded-md border border-red-200 bg-white p-2">
            <label htmlFor="motif-derogation" className="text-xs font-medium text-gray-700">Pourquoi ce bon ne peut-il pas attendre la régularisation ?</label>
            <Textarea id="motif-derogation" rows={2} value={motif} maxLength={500} onChange={(e) => setMotif(e.target.value)} placeholder="10 caractères minimum" />
            {erreur && <p className="text-xs text-red-700">{erreur}</p>}
            <div className="flex gap-2">
                <Button type="button" size="sm" onClick={envoyer} disabled={envoi}>
                    {envoi && <Loader2 className="mr-1 h-4 w-4 animate-spin" />} Envoyer au DAF
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setOuvert(false)} disabled={envoi}>Annuler</Button>
            </div>
        </div>
    );
}

const STYLES = {
    ok: { Icone: CheckCircle2, couleur: 'text-green-600', fond: '' },
    avertissement: { Icone: AlertTriangle, couleur: 'text-orange-500', fond: 'bg-orange-50' },
    bloquant: { Icone: XCircle, couleur: 'text-red-600', fond: 'bg-red-50' },
};

export default function EtapeControle({ resultat, chargement, onCorriger, onRelancer, bonId }) {
    if (chargement && !resultat) {
        return (
            <p className="flex items-center gap-2 py-6 text-sm text-gray-500">
                <Loader2 className="h-4 w-4 animate-spin" /> Contrôle du bon en cours…
            </p>
        );
    }
    if (!resultat) return null;

    const bloquants = resultat.controles.filter((c) => c.niveau === 'bloquant').length;

    return (
        <div className="space-y-5">
            <div
                className={cn(
                    'flex items-center justify-between gap-3 rounded-md px-3 py-2 text-sm font-medium',
                    bloquants ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700',
                )}
            >
                <span>
                    {bloquants
                        ? `${bloquants} point(s) bloquant(s) à corriger avant de soumettre.`
                        : 'Le bon peut être soumis.'}
                </span>
                <Button type="button" variant="ghost" size="sm" onClick={onRelancer} disabled={chargement}>
                    <RefreshCw className={cn('mr-1 h-4 w-4', chargement && 'animate-spin')} /> Recontrôler
                </Button>
            </div>

            <ol className="divide-y divide-gray-100 rounded-md border border-gray-200">
                {resultat.controles.map((controle) => {
                    const { Icone, couleur, fond } = STYLES[controle.niveau] ?? STYLES.ok;
                    return (
                        <li key={controle.numero} className={cn('flex items-start gap-3 px-3 py-3', fond)}>
                            <Icone className={cn('mt-0.5 h-5 w-5 shrink-0', couleur)} aria-label={controle.niveau} />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium text-gray-900">
                                    {controle.numero}. {controle.libelle}
                                </p>
                                <p className={cn('text-sm', controle.niveau === 'ok' ? 'text-gray-600' : couleur)}>{controle.message}</p>
                                {bonId && controle.code === 'DOUBLON_PROBABLE' && <ConfirmerDoublon bonId={bonId} onFait={onRelancer} />}
                                {bonId && controle.code === 'BP_EN_RETARD' && !['demandee', 'accordee'].includes(controle.valeurs?.derogation) && (
                                    <DemanderDerogation bonId={bonId} onFait={onRelancer} />
                                )}
                            </div>
                            {controle.niveau !== 'ok' && controle.etape && (
                                <Button type="button" variant="outline" size="sm" onClick={() => onCorriger(controle.etape, controle.champ)}>
                                    Corriger
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ol>

            {resultat.circuit?.length > 0 && (
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">Circuit de validation prévu</h3>
                    <ol className="mt-2 space-y-1 text-sm">
                        {resultat.circuit.map((niveau, index) => (
                            <li key={niveau.niveau} className="flex gap-2">
                                <span className="w-5 shrink-0 text-gray-400">{index + 1}.</span>
                                <span className="font-medium">{niveau.libelle}</span>
                                <span className="text-gray-600">
                                    {niveau.valideurs.length ? `— ${niveau.valideurs.join(', ')}` : '— aucun valideur disponible (sera escaladé)'}
                                </span>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </div>
    );
}
