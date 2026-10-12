/**
 * Panneau « Calcul » d'un ODM (US-05, RG-M12-07) : l'essentiel, les montants venant du serveur, qui fait foi.
 * Par participant : jours, nuits et total ; la part du client seulement s'il y en a une. Pour l'ODM : le total,
 * la part Neemba et la part de chaque client (spec v2.3, RG-M12-34) et les frais Orange Money estimés.
 * Le détail ligne par ligne est dans la carte « Prise en charge des frais ».
 */
import { Building2, Calculator, Info, Loader2 } from 'lucide-react';
import { formaterMontant } from '@/utils/format';

function Ligne({ libelle, valeur, className = '' }) {
    return (
        <div className={`flex items-baseline justify-between gap-3 ${className}`}>
            <span>{libelle}</span>
            <span className="whitespace-nowrap font-medium tabular-nums">{valeur}</span>
        </div>
    );
}

/** « 4 j · 3 nuits + 1 de rattrapage · base vie » */
function duree(p) {
    return `${p.jours} j · ${p.nuits} nuit${p.nuits > 1 ? 's' : ''}${p.nuit_rattrapage ? ' + 1 de rattrapage' : ''}${p.base_vie ? ' · base vie' : ''}`;
}

/** Part des clients d'un participant (RG-M12-31), en une ligne : rien s'il n'y en a pas */
function PartClient({ p }) {
    const parClient = {};
    (p.lignes ?? []).forEach((l) => l.parts.filter((x) => x.payeur === 'client').forEach((x) => {
        const nom = x.client?.raison_sociale ?? 'Client';
        parClient[nom] = (parClient[nom] ?? 0) + (x.montant ?? 0);
    }));
    const entrees = Object.entries(parClient);
    if (entrees.length === 0) return null;

    return <p className="text-xs text-purple-800">{entrees.map(([nom, m]) => `${nom} ${formaterMontant(m)}`).join(' · ')}</p>;
}

export default function PanneauCalcul({ calcul, type = 'interieur', enCours = false, titre = 'Calcul des indemnités' }) {
    const participants = calcul?.participants ?? [];
    const exterieur = type === 'exterieur';
    const fraisOm = participants.reduce((somme, p) => somme + (p.frais_om ?? 0), 0);
    const recap = calcul?.recapitulatif;
    const partClient = recap?.client > 0;

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
                        ? `Taux ${calcul.taux.fige ? 'figé' : 'du ' + calcul.taux.date} : 1 FCFA = ${String(calcul.taux.taux).replace('.', ',')} GNF, recalculé au paiement.`
                        : 'Aucun taux FCFA → GNF saisi : montant calculé au paiement.'}
                </p>
            )}

            {participants.length === 0 ? (
                <p className="py-4 text-center text-sm text-gray-400">Ajoutez des participants et les dates de la mission pour voir le calcul.</p>
            ) : (
                <div className="space-y-3">
                    <ul className="divide-y">
                        {participants.map((p) => (
                            <li key={p.user_id} className="space-y-0.5 py-2 first:pt-0" data-testid={`calcul-${p.user_id}`}>
                                <div className="flex items-baseline justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-gray-900">{p.nom}</p>
                                        <p className="text-xs text-gray-500">{duree(p)}</p>
                                    </div>
                                    <span className="whitespace-nowrap text-sm font-semibold tabular-nums text-gray-900">
                                        {p.total !== null ? formaterMontant(p.total) : 'Au paiement'}
                                    </span>
                                </div>
                                {exterieur && p.indemnite_fcfa === null && (
                                    <p className="text-xs font-medium text-red-600">Statut cadre manquant : indemnité non calculable.</p>
                                )}
                                <PartClient p={p} />
                            </li>
                        ))}
                    </ul>

                    <div className="flex items-baseline justify-between border-t pt-3 text-base font-semibold text-gray-900">
                        <span>Total de l'ODM</span>
                        <span className="tabular-nums" data-testid="total-odm">{calcul?.total !== null && calcul?.total !== undefined ? formaterMontant(calcul.total) : '—'}</span>
                    </div>
                    {partClient && (
                        <div className="space-y-1 rounded-md bg-purple-50/60 p-2 text-sm" data-testid="ventilation-odm">
                            <Ligne libelle="Part Neemba" valeur={formaterMontant(recap.neemba)} className="text-gray-700" />
                            {recap.par_client.map((c) => (
                                <Ligne key={c.client.id ?? 'x'} className="text-purple-800" valeur={formaterMontant(c.montant)}
                                    libelle={<span className="inline-flex items-center gap-1"><Building2 className="h-3.5 w-3.5" aria-hidden /> {c.client.raison_sociale}</span>} />
                            ))}
                            <p className="pt-1 text-xs text-gray-500">Neemba avance le total, puis refacture la part des clients.</p>
                        </div>
                    )}
                    {fraisOm > 0 && (
                        <p className="text-xs text-gray-500">Frais Orange Money estimés : {formaterMontant(fraisOm)} (si paiement par Orange Money).</p>
                    )}
                </div>
            )}
        </section>
    );
}
