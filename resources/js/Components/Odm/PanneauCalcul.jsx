/**
 * Panneau « Calcul » d'un ODM (US-05, RG-M12-07) : par participant, jours, nuits, les deux lignes d'indemnité,
 * hébergement, nuitée de rattrapage et total. Les montants viennent du serveur, qui fait foi.
 * Q49 : une ligne à la charge du client porte une étiquette ; sous chaque total, ce que Neemba verse dans le bon,
 * ce qui sera refacturé et ce que le client paie directement.
 */
import { Calculator, Info, Loader2 } from 'lucide-react';
import { formaterMontant } from '@/utils/format';
import { msg } from '@/utils/messages';
import { Ventilation } from './PriseEnChargeFrais';

function EtiquetteClient({ prise }) {
    if (!prise || prise === 'neemba') return null;

    return (
        <span className="ml-1.5 whitespace-nowrap rounded bg-purple-100 px-1 py-px text-[10px] font-medium uppercase tracking-wide text-purple-800">
            {prise === 'client_direct' ? 'Client · direct' : 'Client'}
        </span>
    );
}

function Ligne({ libelle, valeur, fort = false, attenue = false, prise = null }) {
    return (
        <div className={`flex items-baseline justify-between gap-3 text-sm ${fort ? 'font-semibold text-gray-900' : attenue ? 'text-gray-500' : 'text-gray-700'}`}>
            <span>{libelle}<EtiquetteClient prise={prise} /></span>
            <span className={`whitespace-nowrap tabular-nums ${prise === 'client_direct' ? 'text-purple-700' : ''}`}>{valeur}</span>
        </div>
    );
}

function valeurLigne(ligne) {
    if (ligne.a_la_cloture) return 'à la clôture';
    if (ligne.montant === null) return '—';

    return formaterMontant(ligne.montant);
}

export default function PanneauCalcul({ calcul, type = 'interieur', enCours = false, titre = 'Calcul des indemnités' }) {
    const participants = calcul?.participants ?? [];
    const exterieur = type === 'exterieur';

    return (
        <section className="rounded-lg border bg-white p-4 shadow-sm" aria-live="polite">
            <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-900">
                <Calculator className="h-4 w-4 text-neemba-600" />
                {titre}
                {enCours && <Loader2 className="h-3.5 w-3.5 animate-spin text-gray-400" aria-label="Calcul en cours" />}
            </h3>

            {exterieur && (
                <p className="mb-3 flex items-start gap-1.5 rounded-md bg-blue-50 p-2 text-xs text-blue-800">
                    <Info className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                    {calcul?.taux
                        ? `Indemnité estimée au taux ${calcul.taux.fige ? 'figé' : 'du ' + calcul.taux.date} : 1 FCFA = ${String(calcul.taux.taux).replace('.', ',')} GNF. Elle sera recalculée au taux du jour du paiement.`
                        : 'Aucun taux FCFA → GNF saisi : le montant en GNF sera calculé au paiement, au taux du jour.'}
                </p>
            )}

            {participants.length === 0 ? (
                <p className="py-4 text-center text-sm text-gray-400">Ajoutez des participants et les dates de la mission pour voir le calcul.</p>
            ) : (
                <div className="space-y-4">
                    {participants.map((p) => (
                        <div key={p.user_id} className="space-y-1 border-b pb-3 last:border-b-0 last:pb-0" data-testid={`calcul-${p.user_id}`}>
                            <p className="text-sm font-medium text-gray-900">{p.nom}</p>
                            <Ligne
                                libelle="Jours / nuits"
                                valeur={`${p.jours} j · ${p.nuits} nuit${p.nuits > 1 ? 's' : ''}${p.nuit_rattrapage ? ' + 1 de rattrapage' : ''}${p.base_vie ? ' (base vie)' : ''}`}
                                attenue
                            />
                            {exterieur && p.indemnite_fcfa === null && (
                                <p className="text-xs font-medium text-red-600">Statut cadre manquant : indemnité non calculable.</p>
                            )}
                            {(p.lignes ?? [])
                                .filter((l) => !(l.sans_objet && l.cle === 'rattrapage'))
                                .map((l) => (
                                    <Ligne key={l.cle} libelle={l.libelle} valeur={valeurLigne(l)} attenue={l.sans_objet} prise={l.sans_objet ? null : l.prise_en_charge} />
                                ))}
                            <Ligne libelle="Total" valeur={p.total !== null ? formaterMontant(p.total) : 'Au paiement'} fort />
                            {(p.montant_refacturable > 0 || p.montant_client_direct > 0) && (
                                <Ventilation bon={p.montant_bon} refacturable={p.montant_refacturable} direct={p.montant_client_direct} className="pt-1" />
                            )}
                            {p.frais_om !== null && (
                                <p className="text-xs text-gray-500">
                                    {msg('MSG-M03-06', { frais: p.frais_om, taux: p.frais_om_taux, montant_verse: p.montant_verse_om })}
                                </p>
                            )}
                        </div>
                    ))}
                    <div className="flex items-baseline justify-between border-t pt-3 text-base font-semibold text-gray-900">
                        <span>Total de l'ODM</span>
                        <span className="tabular-nums" data-testid="total-odm">{calcul?.total !== null && calcul?.total !== undefined ? formaterMontant(calcul.total) : '—'}</span>
                    </div>
                    {(calcul?.montant_refacturable > 0 || calcul?.montant_client_direct > 0) && (
                        <div className="space-y-1 rounded-md bg-purple-50/60 p-2 text-sm" data-testid="ventilation-odm">
                            <div className="flex justify-between gap-3"><span className="text-gray-700">Bons de caisse (versé par Neemba)</span><span className="tabular-nums font-medium">{formaterMontant(calcul.montant_bon)}</span></div>
                            {calcul.montant_refacturable > 0 && (
                                <div className="flex justify-between gap-3 text-purple-800"><span>dont à refacturer au client</span><span className="tabular-nums font-medium">{formaterMontant(calcul.montant_refacturable)}</span></div>
                            )}
                            {calcul.montant_client_direct > 0 && (
                                <div className="flex justify-between gap-3 text-purple-800"><span>Payé directement par le client</span><span className="tabular-nums font-medium">{formaterMontant(calcul.montant_client_direct)}</span></div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
