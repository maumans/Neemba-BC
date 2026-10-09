/**
 * Carte « Prise en charge des frais » de l'ODM (Q49) : pour chaque participant et chaque ligne de frais,
 * qui la prend en charge. Les lignes et leurs montants viennent du calcul du serveur ; une ligne sans montant
 * (base vie, pas de rattrapage…) est « sans objet ». En bas de chaque participant : ce que Neemba verse dans
 * le bon de caisse, ce qui sera refacturé au client et ce que le client paie directement.
 */
import { Building2, Loader2, Wallet } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { formaterMontant } from '@/utils/format';
import ChoixPriseEnCharge from './ChoixPriseEnCharge';

function montantLigne(ligne) {
    if (ligne.a_la_cloture) return 'à la clôture';
    if (ligne.montant === null) return 'au paiement';

    return formaterMontant(ligne.montant);
}

/** Bon de caisse, à refacturer, payé par le client */
export function Ventilation({ bon, refacturable, direct, className = '' }) {
    if (bon === null || bon === undefined) {
        return <p className={cn('text-xs text-gray-500', className)}>Montants connus au paiement (taux du jour).</p>;
    }

    return (
        <div className={cn('flex flex-wrap gap-x-4 gap-y-1 text-xs', className)} data-testid="ventilation">
            <span className="inline-flex items-center gap-1 text-gray-700">
                <Wallet className="h-3.5 w-3.5 text-neemba-600" aria-hidden />
                {bon > 0 ? <>Bon de caisse <strong className="tabular-nums">{formaterMontant(bon)}</strong></> : <strong>Aucun bon de caisse</strong>}
            </span>
            {refacturable > 0 && (
                <span className="text-purple-800">À refacturer <strong className="tabular-nums">{formaterMontant(refacturable)}</strong></span>
            )}
            {direct > 0 && (
                <span className="text-purple-800">Payé par le client <strong className="tabular-nums">{formaterMontant(direct)}</strong></span>
            )}
        </div>
    );
}

export default function PriseEnChargeFrais({
    participants = [], calcul = null, modeDefaut = 'avance', onLigne, onParticipant, onTous, enCours = false, lectureSeule = false,
}) {
    if (participants.length === 0) {
        return <p className="py-2 text-sm text-gray-500">Ajoutez des participants : leurs frais apparaîtront ici, ligne par ligne.</p>;
    }
    const client = `client_${modeDefaut}`;

    return (
        <div className="space-y-4">
            {!lectureSeule && participants.length > 1 && (
                <div className="flex flex-wrap items-center justify-end gap-2 text-xs">
                    <span className="text-gray-500">Pour tous les participants :</span>
                    <Button type="button" variant="outline" size="sm" className="h-7 text-xs" onClick={() => onTous('neemba')}>Tout Neemba</Button>
                    <Button type="button" variant="outline" size="sm" className="h-7 border-purple-200 text-xs text-purple-800 hover:bg-purple-50" onClick={() => onTous(client)}>
                        <Building2 className="mr-1 h-3 w-3" /> Tout client
                    </Button>
                </div>
            )}

            {participants.map((p) => {
                const calculP = calcul?.participants?.find((c) => c.user_id === p.user_id);
                const lignes = calculP?.lignes ?? [];

                return (
                    <section key={p.user_id} className="rounded-lg border border-gray-200" aria-label={`Prise en charge des frais de ${p.nom}`} data-testid={`prise-${p.user_id}`}>
                        <header className="flex flex-wrap items-center justify-between gap-2 border-b bg-gray-50 px-3 py-2">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-gray-900">{p.nom}</p>
                                {calculP && (
                                    <p className="text-xs text-gray-500">
                                        {calculP.jours} jour{calculP.jours > 1 ? 's' : ''} · {calculP.nuits} nuit{calculP.nuits > 1 ? 's' : ''}
                                        {calculP.nuit_rattrapage ? ' + 1 de rattrapage' : ''}{calculP.base_vie ? ' · base vie' : ''}
                                    </p>
                                )}
                            </div>
                            {!lectureSeule && (
                                <div className="flex gap-1">
                                    <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => onParticipant(p.user_id, 'neemba')}>Tout Neemba</Button>
                                    <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs text-purple-800 hover:bg-purple-50" onClick={() => onParticipant(p.user_id, client)}>Tout client</Button>
                                </div>
                            )}
                        </header>

                        {lignes.length === 0 ? (
                            <p className="flex items-center gap-2 px-3 py-3 text-sm text-gray-500">
                                {enCours && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                                Renseignez les dates de la mission pour voir les frais.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {lignes.map((ligne) => {
                                    const prise = p.prises_en_charge?.[ligne.cle] ?? ligne.prise_en_charge;

                                    return (
                                        <li key={ligne.cle} className={cn(
                                            /* Le choix reste sur la ligne s'il a la place, sinon il passe dessous, aligné à droite */
                                            'flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 px-3 py-2',
                                            ligne.sans_objet && 'bg-gray-50/60',
                                        )}>
                                            <div className="flex min-w-[14rem] flex-1 items-baseline justify-between gap-3">
                                                <span className={cn('text-sm', ligne.sans_objet ? 'text-gray-400' : 'text-gray-700')}>{ligne.libelle}</span>
                                                <span className={cn('whitespace-nowrap text-right text-sm tabular-nums', ligne.sans_objet ? 'text-gray-400' : 'text-gray-900')}>
                                                    {ligne.sans_objet ? '—' : montantLigne(ligne)}
                                                </span>
                                            </div>
                                            <div className="ml-auto">
                                                {ligne.sans_objet ? (
                                                    <span className="text-xs italic text-gray-400">sans objet</span>
                                                ) : lectureSeule ? (
                                                    <BadgePrise prise={prise} />
                                                ) : (
                                                    <ChoixPriseEnCharge valeur={prise} modeDefaut={modeDefaut} libelle={`${ligne.libelle}, ${p.nom}`}
                                                        onChange={(v) => onLigne(p.user_id, ligne.cle, v)} />
                                                )}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        {calculP && lignes.length > 0 && (
                            <footer className="border-t px-3 py-2">
                                <Ventilation bon={calculP.montant_bon} refacturable={calculP.montant_refacturable} direct={calculP.montant_client_direct} />
                            </footer>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

/** Lecture seule : qui prend en charge une ligne */
export function BadgePrise({ prise }) {
    if (prise === 'neemba') return <span className="text-xs text-gray-500">Neemba</span>;

    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800">
            <Building2 className="h-3 w-3" aria-hidden />
            {prise === 'client_direct' ? 'Client · payé directement' : 'Client · refacturé'}
        </span>
    );
}
