/**
 * Panneau « Résumé » de l'assistant (E-03.2), mis à jour en direct.
 * Sur mobile, il est replié en bas de l'écran (« Voir le résumé »).
 */
import { useState } from 'react';
import { ChevronUp } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { formaterMontant } from '@/utils/format';

function Ligne({ libelle, children }) {
    return (
        <div className="flex items-start justify-between gap-3 py-1.5 text-sm">
            <dt className="shrink-0 text-gray-500">{libelle}</dt>
            <dd className="min-w-0 text-right font-medium text-gray-900">{children || <span className="text-gray-400">—</span>}</dd>
        </div>
    );
}

function Contenu({ resume }) {
    return (
        <dl className="divide-y divide-gray-100">
            <Ligne libelle="Numéro">
                {resume.numero ? <span className="font-mono">{resume.numero}</span> : <span className="font-normal text-gray-500">Attribué à la soumission</span>}
            </Ligne>
            <Ligne libelle="Type">
                {resume.type && (
                    <Badge variant={resume.type === 'BP' ? 'statut_orange' : 'statut_bleu'}>{resume.type}</Badge>
                )}
            </Ligne>
            {resume.pourLeCompteDe && <Ligne libelle="Pour le compte de">{resume.pourLeCompteDe}</Ligne>}
            {resume.initiePar && <Ligne libelle="Initié par">{resume.initiePar}</Ligne>}
            <Ligne libelle="Site">{resume.site}</Ligne>
            <Ligne libelle="Caisse payeuse">{resume.caisse}</Ligne>
            <Ligne libelle="Service">{resume.service}</Ligne>
            <Ligne libelle="Bénéficiaire">{resume.beneficiaire}</Ligne>
            <Ligne libelle="Catégorie">{resume.categorie}</Ligne>
            <Ligne libelle="Montant">
                {resume.montant ? <span className="text-orange-600">{formaterMontant(resume.montant)}</span> : null}
            </Ligne>
            <Ligne libelle="Mode de paiement">{resume.mode}</Ligne>
            <Ligne libelle="Urgence">{resume.urgence}</Ligne>
            <Ligne libelle="Fichiers">{`${resume.fichiers} pièce(s)`}</Ligne>
        </dl>
    );
}

export default function Resume({ resume }) {
    const [ouvert, setOuvert] = useState(false);

    return (
        <>
            {/* Écran large : panneau à droite */}
            <Card className="hidden lg:sticky lg:top-6 lg:block">
                <CardHeader className="pb-2">
                    <CardTitle className="text-base">Résumé</CardTitle>
                </CardHeader>
                <CardContent>
                    <Contenu resume={resume} />
                </CardContent>
            </Card>

            {/* Mobile et tablette : replié en bas d'écran */}
            <div className="fixed inset-x-0 bottom-0 z-30 border-t bg-white shadow-lg lg:hidden">
                <button
                    type="button"
                    onClick={() => setOuvert((valeur) => !valeur)}
                    className="flex w-full items-center justify-between px-4 py-3 text-sm font-medium"
                    aria-expanded={ouvert}
                >
                    <span>{ouvert ? 'Masquer le résumé' : 'Voir le résumé'}</span>
                    <span className="flex items-center gap-2">
                        {resume.montant ? <span className="text-orange-600">{formaterMontant(resume.montant)}</span> : null}
                        <ChevronUp className={`h-4 w-4 transition-transform ${ouvert ? 'rotate-180' : ''}`} />
                    </span>
                </button>
                {ouvert && (
                    <div className="max-h-[60vh] overflow-y-auto px-4 pb-4">
                        <Contenu resume={resume} />
                    </div>
                )}
            </div>
        </>
    );
}
