/**
 * Barre d'étapes de l'assistant (E-03.2) : étape courante en jaune, complète en vert avec coche,
 * en erreur en rouge avec point d'exclamation. Une étape déjà visitée se rouvre d'un clic.
 * Sur mobile, la barre est réduite aux icônes.
 */
import { Banknote, Check, FileText, Paperclip, ShieldCheck, User } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ETAPES } from '@/utils/assistant';

const ICONES = { 1: FileText, 2: User, 3: Banknote, 4: Paperclip, 5: ShieldCheck };

export default function BarreEtapes({ etape, etats = {}, visitees, onChoisir, desactivee = false }) {
    return (
        <nav aria-label="Étapes du bon" className="mb-6">
            <ol className="flex items-center justify-between gap-1 sm:gap-2">
                {ETAPES.map(({ numero, libelle }) => {
                    const Icone = ICONES[numero];
                    const courante = numero === etape;
                    const etat = etats[numero];
                    const accessible = !desactivee && !courante && visitees.has(numero);

                    return (
                        <li key={numero} className="flex-1">
                            <button
                                type="button"
                                onClick={() => accessible && onChoisir(numero)}
                                disabled={!accessible && !courante}
                                aria-current={courante ? 'step' : undefined}
                                title={libelle}
                                className={cn(
                                    'flex w-full items-center justify-center gap-2 rounded-lg px-2 py-2 text-sm font-medium transition-colors sm:justify-start sm:px-3',
                                    courante && 'bg-neemba-400 text-neemba-950',
                                    !courante && etat === 'complete' && 'bg-green-50 text-green-700',
                                    !courante && etat === 'erreur' && 'bg-red-50 text-red-700',
                                    !courante && !etat && 'text-gray-400',
                                    accessible && 'hover:ring-1 hover:ring-gray-300',
                                    !accessible && !courante && 'cursor-default',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                        courante && 'bg-neemba-950 text-neemba-400',
                                        !courante && etat === 'complete' && 'bg-green-600 text-white',
                                        !courante && etat === 'erreur' && 'bg-red-600 text-white',
                                        !courante && !etat && 'bg-gray-100 text-gray-500',
                                    )}
                                >
                                    {!courante && etat === 'complete' ? (
                                        <Check className="h-3.5 w-3.5" />
                                    ) : !courante && etat === 'erreur' ? (
                                        '!'
                                    ) : (
                                        <Icone className="h-3.5 w-3.5" />
                                    )}
                                </span>
                                <span className="hidden truncate md:inline">{libelle}</span>
                            </button>
                        </li>
                    );
                })}
            </ol>

            {/* Barre de progression : 20 % par étape */}
            <div className="mt-3 h-1.5 rounded-full bg-gray-200" role="progressbar" aria-valuenow={etape * 20} aria-valuemin={0} aria-valuemax={100}>
                <div className="h-1.5 rounded-full bg-neemba-400 transition-all duration-300" style={{ width: `${etape * 20}%` }} />
            </div>
            <p className="mt-2 text-sm text-gray-500 md:hidden">
                Étape {etape} sur 5 — {ETAPES[etape - 1].libelle}
            </p>
        </nav>
    );
}
