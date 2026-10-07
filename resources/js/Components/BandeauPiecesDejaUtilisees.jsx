/**
 * RG-BC-19 : bandeau visible du contrôle de gestion (et de tout lecteur du bon) quand une pièce figure déjà
 * sur un autre bon. Le demandeur a confirmé qu'elle concerne une autre dépense ; sa justification est affichée.
 */
import { AlertTriangle } from 'lucide-react';

export default function BandeauPiecesDejaUtilisees({ pieces = [] }) {
    const doublons = pieces.filter((piece) => piece.doublon_de_id && !piece.remplacee_par_id);
    if (doublons.length === 0) return null;

    return (
        <div className="mb-6 flex items-start gap-3 rounded-md border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-900">
            <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-orange-500" />
            <div className="space-y-1">
                <p className="font-semibold">Pièce(s) déjà présentée(s) sur un autre bon</p>
                {doublons.map((piece) => (
                    <p key={piece.id}>
                        « {piece.nom_fichier} » figure aussi sur le bon {piece.doublon_de?.bon_caisse?.numero ?? 'en brouillon'}.
                        {piece.justification_doublon ? ` Justification du demandeur : ${piece.justification_doublon}` : ' Non confirmée par le demandeur.'}
                    </p>
                ))}
            </div>
        </div>
    );
}
