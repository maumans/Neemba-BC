/**
 * Panneau « Calcul » d'un ODM (US-05, RG-M12-07) : par participant, jours, nuits, les deux lignes d'indemnité,
 * hébergement, nuitée de rattrapage et total. Les montants viennent du serveur, qui fait foi.
 */
import { Calculator, Info, Loader2 } from 'lucide-react';
import { formaterMontant, formaterNombre } from '@/utils/format';
import { msg } from '@/utils/messages';

function Ligne({ libelle, valeur, fort = false, attenue = false }) {
    return (
        <div className={`flex items-baseline justify-between gap-3 text-sm ${fort ? 'font-semibold text-gray-900' : attenue ? 'text-gray-500' : 'text-gray-700'}`}>
            <span>{libelle}</span>
            <span className="whitespace-nowrap tabular-nums">{valeur}</span>
        </div>
    );
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
                            {exterieur ? (
                                <Ligne
                                    libelle={`Indemnité (${p.indemnite_fcfa !== null ? formaterNombre(p.indemnite_fcfa) + ' FCFA' : 'statut cadre manquant'})`}
                                    valeur={p.indemnite !== null ? formaterMontant(p.indemnite) : '—'}
                                />
                            ) : (
                                <>
                                    <Ligne libelle={calcul.libelle_indemnite_1} valeur={formaterMontant(p.indemnite_ligne_1)} />
                                    <Ligne libelle={calcul.libelle_indemnite_2} valeur={formaterMontant(p.indemnite_ligne_2)} />
                                </>
                            )}
                            <Ligne libelle="Hébergement" valeur={formaterMontant(p.hebergement)} />
                            {p.nuit_rattrapage > 0 && <Ligne libelle="Nuitée de rattrapage" valeur={formaterMontant(p.rattrapage)} />}
                            <Ligne libelle="Total" valeur={p.total !== null ? formaterMontant(p.total) : 'Au paiement'} fort />
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
                </div>
            )}
        </section>
    );
}
