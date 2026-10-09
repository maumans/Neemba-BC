/**
 * Qui prend en charge une ligne de frais d'ODM (Q49) : Neemba ou le client ; pour le client, la ligne est
 * avancée par Neemba puis refacturée (elle reste dans le bon de caisse) ou payée directement (hors bon).
 *
 * - ChoixPriseEnCharge : choix compact d'une ligne (ou de toutes les lignes d'un participant) ;
 * - ChoixGlobal : en-tête de l'ODM, appliqué à toutes les lignes, « Mixte » quand elles diffèrent.
 */
import { Building2, HandCoins, Receipt } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn } from '@/lib/utils';

const AIDE_MODES = {
    avance: 'Neemba verse ce montant dans le bon de caisse, puis le refacture au client.',
    direct: 'Le client règle lui-même ce montant : il sort du bon de caisse et Neemba ne décaisse rien.',
};

function Option({ choisi, onClick, desactive, ton = 'neutre', taille = 'sm', children, ...props }) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={choisi}
            disabled={desactive}
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1 rounded font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-neemba-400 disabled:cursor-not-allowed disabled:opacity-60',
                taille === 'sm' ? 'px-2 py-1 text-xs' : 'px-3 py-1.5 text-sm',
                choisi && ton === 'neutre' && 'bg-white text-gray-900 shadow-sm ring-1 ring-gray-300',
                choisi && ton === 'client' && 'bg-purple-600 text-white shadow-sm',
                choisi && ton === 'mode' && 'bg-purple-100 text-purple-900 ring-1 ring-purple-300',
                !choisi && 'text-gray-600 hover:bg-white/70 hover:text-gray-900',
            )}
            {...props}
        >
            {children}
        </button>
    );
}

/** Sous-choix d'une ligne du client : avancé par Neemba, ou payé directement */
function ChoixMode({ mode, onChoisir, desactive, taille, libelle }) {
    return (
        <div role="radiogroup" aria-label={`Règlement par le client${libelle ? ` : ${libelle}` : ''}`}
            className="inline-flex rounded-md border border-purple-200 bg-purple-50/60 p-0.5">
            {[
                ['avance', 'Avancé · refacturé', HandCoins],
                ['direct', 'Payé par le client', Receipt],
            ].map(([valeur, texte, Icone]) => (
                <Tooltip key={valeur}>
                    <TooltipTrigger asChild>
                        <span>
                            <Option choisi={mode === valeur} ton="mode" taille={taille} desactive={desactive} onClick={() => onChoisir(valeur)}>
                                <Icone className="h-3 w-3" aria-hidden />
                                {texte}
                            </Option>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-xs">{AIDE_MODES[valeur]}</TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}

/**
 * @param {{ valeur: 'neemba'|'client_avance'|'client_direct', onChange: (v: string) => void, modeDefaut?: 'avance'|'direct',
 *           libelle?: string, desactive?: boolean, taille?: 'sm'|'md' }} props
 */
export default function ChoixPriseEnCharge({ valeur = 'neemba', onChange, modeDefaut = 'avance', libelle = '', desactive = false, taille = 'sm' }) {
    const client = valeur !== 'neemba';
    const mode = valeur === 'client_direct' ? 'direct' : 'avance';

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <div role="radiogroup" aria-label={`Prise en charge${libelle ? ` : ${libelle}` : ''}`} className="inline-flex rounded-md border border-gray-200 bg-gray-100 p-0.5">
                <Option choisi={!client} taille={taille} desactive={desactive} onClick={() => onChange('neemba')}>Neemba</Option>
                <Option choisi={client} ton="client" taille={taille} desactive={desactive} onClick={() => !client && onChange(`client_${modeDefaut}`)}>
                    <Building2 className="h-3 w-3" aria-hidden />
                    Client
                </Option>
            </div>
            {client && <ChoixMode mode={mode} taille={taille} desactive={desactive} libelle={libelle} onChoisir={(m) => onChange(`client_${m}`)} />}
        </div>
    );
}

/**
 * En-tête de l'ODM : défaut appliqué à toutes les lignes. « Mixte » quand des lignes ont été ajustées une à une.
 *
 * @param {{ priseEnCharge: 'neemba'|'client'|'mixte', modeClient: 'avance'|'direct', onChoisir: (prise: string, mode: string) => void,
 *           desactive?: boolean }} props
 */
export function ChoixGlobal({ priseEnCharge, modeClient = 'avance', onChoisir, desactive = false }) {
    const mixte = priseEnCharge === 'mixte';

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div role="radiogroup" aria-label="Frais à la charge de" className="inline-flex rounded-md border border-gray-200 bg-gray-100 p-0.5">
                <Option choisi={priseEnCharge === 'neemba'} taille="md" desactive={desactive} onClick={() => onChoisir('neemba', modeClient)}>Neemba</Option>
                <Option choisi={priseEnCharge === 'client'} ton="client" taille="md" desactive={desactive} onClick={() => onChoisir('client', modeClient)}>
                    <Building2 className="h-3.5 w-3.5" aria-hidden />
                    Client
                </Option>
            </div>
            {mixte && (
                <span className="rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-800 ring-1 ring-purple-200" data-testid="prise-mixte">
                    Mixte : selon les lignes
                </span>
            )}
            {priseEnCharge === 'client' && (
                <ChoixMode mode={modeClient} taille="sm" desactive={desactive} onChoisir={(m) => onChoisir('client', m)} />
            )}
        </div>
    );
}
