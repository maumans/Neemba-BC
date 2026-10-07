/**
 * Étape 5 « Contrôle » (E-03.7, US-BC-10) : les 12 contrôles du serveur (§5.4.7).
 * Vert = OK ; orange = avertissement (n'empêche pas la soumission) ; rouge = bloquant.
 */
import { AlertTriangle, CheckCircle2, Loader2, RefreshCw, XCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

const STYLES = {
    ok: { Icone: CheckCircle2, couleur: 'text-green-600', fond: '' },
    avertissement: { Icone: AlertTriangle, couleur: 'text-orange-500', fond: 'bg-orange-50' },
    bloquant: { Icone: XCircle, couleur: 'text-red-600', fond: 'bg-red-50' },
};

export default function EtapeControle({ resultat, chargement, onCorriger, onRelancer }) {
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
