/**
 * Étape 4 « Pièces » (E-03.6, US-BC-08, US-BC-09) : chaque fichier est envoyé dès son dépôt, avec barre de progression.
 *
 * - Qualité par ligne (RG-BC-16) : conforme, qualité moyenne, illisible (MSG-BC-018, la pièce ne compte pas).
 * - Pièce déjà jointe à un autre bon (RG-BC-19) : confirmation et justification (MSG-BC-019).
 * - Ticket carburant : lecture puis panneau de vérification (RG-BC-20, RG-BC-21).
 * - Une pièce jamais soumise se supprime ; une pièce déjà soumise se remplace par une nouvelle version.
 */
import { useEffect, useRef, useState } from 'react';
import { Camera, ExternalLink, FileText, Loader2, RefreshCw, ScanLine, Trash2, Upload } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import { erreurFichier, typePieceParDefaut } from '@/utils/assistant';
import { msg } from '@/utils/messages';
import PanneauLecture from './PanneauLecture';

const QUALITES = {
    conforme: { libelle: 'Conforme', variante: 'statut_vert_clair' },
    moyenne: { libelle: 'Qualité moyenne', variante: 'statut_orange' },
    illisible: { libelle: 'Illisible', variante: 'statut_rouge' },
};

function taille(octets) {
    if (octets >= 1024 * 1024) return `${(octets / (1024 * 1024)).toFixed(1).replace('.', ',')} Mo`;
    return `${Math.max(1, Math.round(octets / 1024))} Ko`;
}

/** RG-BC-19 : confirmer qu'une pièce déjà utilisée concerne une autre dépense */
function Doublon({ piece, confirmer, desactive }) {
    const [confirme, setConfirme] = useState(false);
    const [justification, setJustification] = useState('');
    const [erreurs, setErreurs] = useState({});
    const [envoi, setEnvoi] = useState(false);
    const doublon = piece.doublon;

    if (doublon.confirme) {
        return (
            <p className="mt-2 rounded-md bg-orange-50 px-3 py-2 text-xs text-orange-800">
                Pièce déjà présentée sur le bon {doublon.numero}. Justification : {doublon.justification}
            </p>
        );
    }

    const envoyer = async () => {
        setEnvoi(true);
        setErreurs((await confirmer(piece, confirme, justification)) ?? {});
        setEnvoi(false);
    };

    return (
        <div className="mt-2 space-y-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
            <p>{doublon.message}</p>
            <label className="flex items-center gap-2">
                <input type="checkbox" className="rounded border-red-300 text-red-600" checked={confirme} onChange={(e) => setConfirme(e.target.checked)} disabled={desactive} />
                Je confirme que cette pièce concerne une autre dépense
            </label>
            {erreurs.confirme && <p className="text-red-700">{erreurs.confirme}</p>}
            <Textarea
                rows={2}
                value={justification}
                maxLength={500}
                onChange={(e) => setJustification(e.target.value)}
                placeholder="Pourquoi cette pièce est-elle jointe une seconde fois ? (10 caractères minimum)"
                className="bg-white"
                disabled={desactive}
            />
            {erreurs.justification && <p className="text-red-700">{erreurs.justification}</p>}
            <Button type="button" size="sm" variant="outline" onClick={envoyer} disabled={desactive || envoi}>
                {envoi && <Loader2 className="mr-1 h-4 w-4 animate-spin" />} Confirmer
            </Button>
        </div>
    );
}

/** Statut de lecture d'un ticket et bouton du panneau */
function Lecture({ piece, ouvrir }) {
    const lecture = piece.lecture;
    if (!lecture) return null;
    const statut = {
        en_cours: <Badge variant="statut_gris"><Loader2 className="mr-1 h-3 w-3 animate-spin" />Lecture en cours…</Badge>,
        terminee: <Badge variant="statut_orange">À vérifier</Badge>,
        indisponible: <Badge variant="statut_orange">À vérifier</Badge>,
        validee: <Badge variant="statut_vert_clair">Lecture validée</Badge>,
    }[lecture.statut];

    return (
        <div className="mt-2 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
                {statut}
                <Button
                    type="button"
                    size="sm"
                    variant={lecture.statut === 'validee' ? 'ghost' : 'outline'}
                    onClick={() => ouvrir(piece.id)}
                    disabled={lecture.statut === 'en_cours'}
                >
                    <ScanLine className="mr-1 h-4 w-4" /> {lecture.statut === 'validee' ? 'Revoir la lecture' : 'Vérifier la lecture'}
                </Button>
            </div>
            {lecture.avertissements?.map((avertissement) => (
                <p key={avertissement.message_cle} className="text-xs text-orange-700">{avertissement.message}</p>
            ))}
        </div>
    );
}

export default function EtapePieces({
    donnees,
    pieces,
    typesPiece,
    erreurs,
    envoyer,
    typer,
    supprimer,
    remplacer,
    confirmerDoublon,
    validerLecture,
    actualiser,
    desactive,
    dateDuJour,
    prixReference,
}) {
    const [survol, setSurvol] = useState(false);
    const [envois, setEnvois] = useState([]);
    const [erreurDepot, setErreurDepot] = useState(null);
    const [panneau, setPanneau] = useState(null);
    const [aOuvrir, setAOuvrir] = useState([]);
    const champFichier = useRef(null);
    const champPhoto = useRef(null);
    const champRemplacement = useRef(null);
    const pieceARemplacer = useRef(null);
    const bd = donnees.type_bon !== 'BP';

    /* RG-BC-20 : suivi des lectures en cours (60 secondes au plus côté serveur) */
    const enCours = pieces.filter((p) => p.lecture?.statut === 'en_cours').map((p) => p.id).join(',');
    useEffect(() => {
        if (!enCours) return undefined;
        const minuterie = setInterval(() => {
            pieces.filter((p) => p.lecture?.statut === 'en_cours').forEach((p) => actualiser(p));
        }, 2000);

        return () => clearInterval(minuterie);
    }, [enCours]);

    /* Le panneau s'ouvre de lui-même quand la lecture d'un ticket déposé à l'instant est terminée */
    useEffect(() => {
        if (panneau !== null) return;
        const pret = pieces.find((p) => aOuvrir.includes(p.id) && p.lecture && p.lecture.statut !== 'en_cours');
        if (pret) {
            setPanneau(pret.id);
            setAOuvrir((ids) => ids.filter((id) => id !== pret.id));
        }
    }, [pieces, aOuvrir, panneau]);

    const avecProgression = async (nom, action) => {
        const id = `${nom}-${Date.now()}-${Math.random()}`;
        setEnvois((liste) => [...liste, { id, nom, progression: 0 }]);
        try {
            return await action((progression) => setEnvois((liste) => liste.map((e) => (e.id === id ? { ...e, progression } : e))));
        } catch (erreur) {
            setErreurDepot(`${nom} : ${erreur.message}`);
            return null;
        } finally {
            setEnvois((liste) => liste.filter((e) => e.id !== id));
        }
    };

    const deposer = async (fichiers) => {
        setErreurDepot(null);
        let dejaLa = [...pieces];
        for (const fichier of Array.from(fichiers)) {
            const cle = erreurFichier(fichier, dejaLa);
            if (cle) {
                setErreurDepot(`${fichier.name} : ${msg(cle)}`);
                continue;
            }
            const type = typePieceParDefaut(donnees.categorie_depense);
            const piece = await avecProgression(fichier.name, (progression) => envoyer(fichier, type, progression));
            if (piece) {
                dejaLa = [...dejaLa, piece];
                if (piece.lecture) setAOuvrir((ids) => [...ids, piece.id]);
            }
        }
    };

    const remplacerPar = async (fichier) => {
        const ancienne = pieceARemplacer.current;
        setErreurDepot(null);
        const cle = erreurFichier(fichier, pieces.filter((p) => p.id !== ancienne?.id));
        if (!ancienne || cle) {
            if (cle) setErreurDepot(`${fichier.name} : ${msg(cle)}`);
            return;
        }
        await avecProgression(fichier.name, (progression) => remplacer(ancienne, fichier, progression));
    };

    const changerType = async (piece, type) => {
        const nouvelle = await typer(piece, type);
        if (nouvelle?.lecture && !piece.lecture) setAOuvrir((ids) => [...ids, piece.id]);
    };

    const pieceDuPanneau = pieces.find((p) => p.id === panneau) ?? null;

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
                <input
                    ref={champRemplacement}
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                    className="hidden"
                    data-remplacement
                    onChange={(e) => {
                        if (e.target.files?.[0]) remplacerPar(e.target.files[0]);
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
                        const image = piece.mime_type?.startsWith('image/');
                        const qualite = QUALITES[piece.qualite];
                        const illisible = piece.qualite === 'illisible';
                        return (
                            <li key={piece.id} data-piece={piece.nom_fichier} className={cn('px-3 py-3', illisible && 'bg-red-50/60')}>
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <div className="flex min-w-0 flex-1 items-center gap-3">
                                        {image ? (
                                            <img src={piece.url} alt="" className="h-10 w-10 shrink-0 rounded border object-cover" />
                                        ) : (
                                            <FileText className="h-10 w-10 shrink-0 p-1.5 text-gray-400" />
                                        )}
                                        <div className="min-w-0">
                                            <a href={piece.url} target="_blank" rel="noreferrer" className="flex items-center gap-1 truncate text-sm font-medium hover:underline">
                                                <span className="truncate">{piece.nom_fichier}</span>
                                                <ExternalLink className="h-3 w-3 shrink-0" />
                                            </a>
                                            <p className="text-xs text-gray-500">
                                                {taille(piece.taille)}
                                                {piece.version > 1 && ` · version ${piece.version}`}
                                            </p>
                                        </div>
                                        {qualite && <Badge variant={qualite.variante} className="shrink-0">{qualite.libelle}</Badge>}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Select value={piece.type_document ?? undefined} onValueChange={(type) => changerType(piece, type)} disabled={desactive}>
                                            <SelectTrigger
                                                className={cn('w-full sm:w-52', !piece.type_document && erreurs.pieces && 'border-red-500')}
                                                aria-label={`Type de ${piece.nom_fichier}`}
                                            >
                                                <SelectValue placeholder="Type de pièce *" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {Object.entries(typesPiece).map(([valeur, libelle]) => (
                                                    <SelectItem key={valeur} value={valeur}>{libelle}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {piece.supprimable ? (
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
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                title="Remplacer par une nouvelle version"
                                                aria-label={`Remplacer ${piece.nom_fichier}`}
                                                disabled={desactive}
                                                onClick={() => {
                                                    pieceARemplacer.current = piece;
                                                    champRemplacement.current?.click();
                                                }}
                                            >
                                                <RefreshCw className="h-4 w-4 text-gray-600" />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                {illisible && <p className="mt-2 text-sm text-red-600">{msg('MSG-BC-018')}</p>}
                                {piece.doublon && <Doublon piece={piece} confirmer={confirmerDoublon} desactive={desactive} />}
                                <Lecture piece={piece} ouvrir={setPanneau} />
                            </li>
                        );
                    })}
                </ul>
            )}

            <PanneauLecture
                piece={pieceDuPanneau}
                ouvert={pieceDuPanneau !== null}
                onFermer={() => setPanneau(null)}
                onValider={validerLecture}
                dateDuJour={dateDuJour}
                prixReference={prixReference}
            />
        </div>
    );
}
