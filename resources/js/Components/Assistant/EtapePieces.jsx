/**
 * Étape 4 « Pièces » (E-03.6, US-BC-08) : chaque fichier est envoyé dès son dépôt, avec barre de progression.
 * Chaque pièce doit avoir un type (RG-BC-18) ; un BD exige un justificatif (RG-BC-15).
 * La lecture assistée des tickets carburant (US-BC-09) arrive au lot 4.
 */
import { useRef, useState } from 'react';
import { Camera, ExternalLink, FileText, Image as IconeImage, Loader2, Trash2, Upload } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import { erreurFichier, typePieceParDefaut } from '@/utils/assistant';
import { msg } from '@/utils/messages';

function taille(octets) {
    if (octets >= 1024 * 1024) return `${(octets / (1024 * 1024)).toFixed(1).replace('.', ',')} Mo`;
    return `${Math.max(1, Math.round(octets / 1024))} Ko`;
}

function BadgeQualite({ qualite }) {
    if (qualite === true) return <Badge variant="statut_vert_clair">Conforme</Badge>;
    if (qualite === false) return <Badge variant="statut_rouge">Illisible</Badge>;
    return null;
}

export default function EtapePieces({ donnees, pieces, typesPiece, erreurs, envoyer, typer, supprimer, desactive }) {
    const [survol, setSurvol] = useState(false);
    const [envois, setEnvois] = useState([]);
    const [erreurDepot, setErreurDepot] = useState(null);
    const champFichier = useRef(null);
    const champPhoto = useRef(null);
    const bd = donnees.type_bon !== 'BP';

    const deposer = async (fichiers) => {
        setErreurDepot(null);
        let dejaLa = [...pieces];
        for (const fichier of Array.from(fichiers)) {
            const cle = erreurFichier(fichier, dejaLa);
            if (cle) {
                setErreurDepot(`${fichier.name} : ${msg(cle)}`);
                continue;
            }
            const id = `${fichier.name}-${Date.now()}-${Math.random()}`;
            setEnvois((liste) => [...liste, { id, nom: fichier.name, progression: 0 }]);
            try {
                const piece = await envoyer(fichier, typePieceParDefaut(donnees.categorie_depense), (progression) =>
                    setEnvois((liste) => liste.map((e) => (e.id === id ? { ...e, progression } : e))),
                );
                if (piece) dejaLa = [...dejaLa, piece];
            } catch (erreur) {
                setErreurDepot(`${fichier.name} : ${erreur.message}`);
            } finally {
                setEnvois((liste) => liste.filter((e) => e.id !== id));
            }
        }
    };

    return (
        <div className="space-y-5">
            <p className="text-sm text-gray-600">
                {bd
                    ? 'Bon définitif : joignez au moins une facture, un reçu, un ticket carburant ou une proforma.'
                    : 'Bon provisoire : les justificatifs pourront être fournis à la régularisation.'}
            </p>

            {/* Zone de dépôt */}
            <div
                data-champ="pieces"
                onDragOver={(e) => {
                    e.preventDefault();
                    setSurvol(true);
                }}
                onDragLeave={() => setSurvol(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setSurvol(false);
                    if (!desactive) deposer(e.dataTransfer.files);
                }}
                className={cn(
                    'flex flex-col items-center justify-center gap-3 rounded-lg border-2 border-dashed px-4 py-8 text-center transition-colors',
                    survol ? 'border-neemba-400 bg-neemba-50' : 'border-gray-300',
                    erreurs.pieces && 'border-red-400',
                )}
            >
                <Upload className="h-8 w-8 text-gray-400" />
                <p className="text-sm text-gray-600">Glissez vos fichiers ici ou</p>
                <div className="flex flex-wrap justify-center gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={() => champFichier.current?.click()} disabled={desactive}>
                        Choisir des fichiers
                    </Button>
                    <Button type="button" variant="outline" size="sm" className="sm:hidden" onClick={() => champPhoto.current?.click()} disabled={desactive}>
                        <Camera className="mr-1 h-4 w-4" /> Prendre une photo
                    </Button>
                </div>
                <p className="text-xs text-gray-500">PDF, JPG, PNG — 10 Mo maximum par fichier, 20 fichiers et 50 Mo par bon.</p>
                <input
                    ref={champFichier}
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                    className="hidden"
                    onChange={(e) => {
                        deposer(e.target.files);
                        e.target.value = '';
                    }}
                />
                <input
                    ref={champPhoto}
                    type="file"
                    accept="image/jpeg,image/png"
                    capture="environment"
                    className="hidden"
                    onChange={(e) => {
                        deposer(e.target.files);
                        e.target.value = '';
                    }}
                />
            </div>

            {(erreurDepot || erreurs.pieces) && (
                <p className="text-sm text-red-600" role="alert">{erreurDepot ?? erreurs.pieces}</p>
            )}

            {/* Envois en cours */}
            {envois.map((envoi) => (
                <div key={envoi.id} className="rounded-md border border-gray-200 px-3 py-2">
                    <div className="flex items-center gap-2 text-sm">
                        <Loader2 className="h-4 w-4 animate-spin text-gray-400" />
                        <span className="truncate">{envoi.nom}</span>
                        <span className="ml-auto text-xs text-gray-500">{envoi.progression} %</span>
                    </div>
                    <div className="mt-2 h-1 rounded-full bg-gray-200">
                        <div className="h-1 rounded-full bg-neemba-400" style={{ width: `${envoi.progression}%` }} />
                    </div>
                </div>
            ))}

            {/* Pièces du bon */}
            {pieces.length > 0 && (
                <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
                    {pieces.map((piece) => {
                        const Icone = piece.mime_type === 'application/pdf' ? FileText : IconeImage;
                        const sansType = !piece.type_document;
                        return (
                            <li key={piece.id} className="flex flex-col gap-3 px-3 py-3 sm:flex-row sm:items-center">
                                <div className="flex min-w-0 flex-1 items-center gap-2">
                                    <Icone className="h-5 w-5 shrink-0 text-gray-400" />
                                    <div className="min-w-0">
                                        <a href={piece.url} target="_blank" rel="noreferrer" className="flex items-center gap-1 truncate text-sm font-medium hover:underline">
                                            <span className="truncate">{piece.nom_fichier}</span>
                                            <ExternalLink className="h-3 w-3 shrink-0" />
                                        </a>
                                        <p className="text-xs text-gray-500">{taille(piece.taille)}</p>
                                    </div>
                                    <BadgeQualite qualite={piece.qualite_ok} />
                                </div>
                                <div className="flex items-center gap-2">
                                    <Select value={piece.type_document ?? undefined} onValueChange={(type) => typer(piece, type)} disabled={desactive}>
                                        <SelectTrigger className={cn('w-full sm:w-52', sansType && erreurs.pieces && 'border-red-500')} aria-label={`Type de ${piece.nom_fichier}`}>
                                            <SelectValue placeholder="Type de pièce *" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(typesPiece).map(([valeur, libelle]) => (
                                                <SelectItem key={valeur} value={valeur}>{libelle}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => supprimer(piece)}
                                        disabled={desactive}
                                        aria-label={`Supprimer ${piece.nom_fichier}`}
                                    >
                                        <Trash2 className="h-4 w-4 text-red-500" />
                                    </Button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
