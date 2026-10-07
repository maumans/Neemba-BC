/**
 * Panneau « Lecture du ticket » (E-03.6, US-BC-09).
 *
 * Image du ticket agrandissable à côté des valeurs lues, chacune avec sa confiance (vert ≥ 85 %, orange 60–84 %,
 * rouge < 60 %) et une case « vérifié ». Corriger un champ le coche. « Valider la lecture » n'est actif qu'une fois
 * chaque champ confirmé (RG-BC-21). Sans lecture automatique, les champs sont vides, à remplir à la main.
 */
import { useEffect, useState } from 'react';
import { ExternalLink, Loader2, ZoomIn, ZoomOut } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import MontantInput from '@/Components/MontantInput';
import { cn } from '@/lib/utils';
import { CHAMPS_LECTURE, couleurConfiance, lectureConfirmable, prixAtypique, prixAuLitre } from '@/utils/assistant';
import { formaterMontant } from '@/utils/format';
import { msg } from '@/utils/messages';

const LIBELLES = {
    station: 'Station',
    date: 'Date du ticket',
    litres: 'Litres',
    montant: 'Montant (chiffres)',
    montant_lettres: 'Montant (lettres) lu',
    immatriculation: 'Immatriculation',
};

const OBLIGATOIRES = ['station', 'date', 'montant'];

const PASTILLES = {
    vert: 'bg-green-100 text-green-800',
    orange: 'bg-orange-100 text-orange-800',
    rouge: 'bg-red-100 text-red-700',
};

function valeursDepart(lecture) {
    const valeurs = lecture?.valeurs ?? {};

    return Object.fromEntries(CHAMPS_LECTURE.map((champ) => [champ, valeurs[champ] ?? '']));
}

export default function PanneauLecture({ piece, ouvert, onFermer, onValider, dateDuJour, prixReference }) {
    const lecture = piece?.lecture;
    const [valeurs, setValeurs] = useState(() => valeursDepart(lecture));
    const [confirmes, setConfirmes] = useState([]);
    const [erreurs, setErreurs] = useState({});
    const [envoi, setEnvoi] = useState(false);
    const [zoom, setZoom] = useState(false);

    /* Valeurs reprises à l'ouverture et à la fin de la lecture */
    useEffect(() => {
        if (!ouvert) return;
        setValeurs(valeursDepart(lecture));
        setConfirmes(lecture?.statut === 'validee' ? [...CHAMPS_LECTURE] : []);
        setErreurs({});
    }, [ouvert, piece?.id, lecture?.statut]);

    if (!piece) return null;

    const enCours = lecture?.statut === 'en_cours';
    const indisponible = lecture?.statut === 'indisponible';
    const image = piece.mime_type?.startsWith('image/');
    const prix = prixAuLitre(valeurs.montant, valeurs.litres);

    const basculer = (champ) =>
        setConfirmes((actuels) => (actuels.includes(champ) ? actuels.filter((c) => c !== champ) : [...actuels, champ]));

    /* Corriger un champ le coche automatiquement */
    const changer = (champ, valeur) => {
        setValeurs((actuelles) => ({ ...actuelles, [champ]: valeur }));
        setConfirmes((actuels) => (actuels.includes(champ) ? actuels : [...actuels, champ]));
        setErreurs((actuelles) => ({ ...actuelles, [champ]: undefined }));
    };

    const valider = async () => {
        setEnvoi(true);
        const resultat = await onValider(piece, valeurs, confirmes);
        setEnvoi(false);
        if (resultat) {
            setErreurs(resultat);
        } else {
            onFermer();
        }
    };

    const champ = (nom, saisie) => {
        const couleur = couleurConfiance(lecture?.confiances?.[nom]);
        return (
            <div key={nom} className="grid grid-cols-[1fr_auto] items-start gap-x-3 gap-y-1">
                <label htmlFor={`lecture-${nom}`} className="flex items-center gap-2 text-sm font-medium text-gray-700">
                    {LIBELLES[nom]}
                    {OBLIGATOIRES.includes(nom) && <span className="text-red-600">*</span>}
                    {couleur && (
                        <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-semibold', PASTILLES[couleur])} title="Confiance de la lecture">
                            {lecture.confiances[nom]} %
                        </span>
                    )}
                </label>
                <label className="row-span-2 flex items-center gap-1 pt-6 text-xs text-gray-600">
                    <input
                        type="checkbox"
                        className="rounded border-gray-300 text-neemba-600 focus:ring-neemba-400"
                        checked={confirmes.includes(nom)}
                        onChange={() => basculer(nom)}
                        disabled={enCours}
                        aria-label={`${LIBELLES[nom]} vérifié`}
                    />
                    Vérifié
                </label>
                <div>
                    {saisie}
                    {erreurs[nom] && <p className="mt-1 text-sm text-red-600">{erreurs[nom]}</p>}
                </div>
            </div>
        );
    };

    return (
        <Dialog open={ouvert} onOpenChange={(etat) => !etat && !envoi && onFermer()}>
            <DialogContent className="max-h-[92vh] max-w-5xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Lecture du ticket</DialogTitle>
                    <DialogDescription>
                        {piece.nom_fichier}
                        {indisponible && <span className="ml-2 font-medium text-orange-700">Lecture automatique indisponible : saisissez les valeurs du ticket.</span>}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    {/* Ticket, agrandissable */}
                    <div className="space-y-2">
                        <div className={cn('rounded-md border bg-gray-50', zoom ? 'max-h-[70vh] overflow-auto' : 'overflow-hidden')}>
                            {image ? (
                                <img
                                    src={piece.url}
                                    alt={`Ticket ${piece.nom_fichier}`}
                                    onClick={() => setZoom((z) => !z)}
                                    className={cn('cursor-zoom-in', zoom ? 'w-[200%] max-w-none cursor-zoom-out' : 'max-h-[60vh] w-full object-contain')}
                                />
                            ) : (
                                <object data={piece.url} type="application/pdf" className="h-[60vh] w-full">
                                    <p className="p-4 text-sm">Aperçu indisponible.</p>
                                </object>
                            )}
                        </div>
                        <div className="flex items-center gap-3 text-sm">
                            {image && (
                                <button type="button" onClick={() => setZoom((z) => !z)} className="inline-flex items-center gap-1 text-gray-600 hover:text-gray-900">
                                    {zoom ? <ZoomOut className="h-4 w-4" /> : <ZoomIn className="h-4 w-4" />} {zoom ? 'Réduire' : 'Agrandir'}
                                </button>
                            )}
                            <a href={piece.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-gray-600 hover:text-gray-900">
                                <ExternalLink className="h-4 w-4" /> Ouvrir
                            </a>
                        </div>
                    </div>

                    {/* Valeurs */}
                    <div className="space-y-4">
                        {enCours && (
                            <p className="flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-600">
                                <Loader2 className="h-4 w-4 animate-spin" /> Lecture en cours…
                            </p>
                        )}
                        {champ('station', (
                            <Input id="lecture-station" value={valeurs.station} maxLength={80} disabled={enCours} onChange={(e) => changer('station', e.target.value)} />
                        ))}
                        {champ('date', (
                            <Input id="lecture-date" type="date" max={dateDuJour} value={valeurs.date} disabled={enCours} onChange={(e) => changer('date', e.target.value)} />
                        ))}
                        {champ('litres', (
                            <Input
                                id="lecture-litres"
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0"
                                max="500"
                                value={valeurs.litres}
                                disabled={enCours}
                                onChange={(e) => changer('litres', e.target.value)}
                            />
                        ))}
                        {champ('montant', (
                            <MontantInput id="lecture-montant" value={valeurs.montant} disabled={enCours} onChange={(v) => changer('montant', v)} max={999999999} />
                        ))}
                        {champ('montant_lettres', (
                            <p id="lecture-montant_lettres" className="min-h-9 rounded-md border border-dashed border-gray-200 bg-gray-50 px-3 py-2 text-sm italic text-gray-700">
                                {valeurs.montant_lettres || '—'}
                            </p>
                        ))}
                        {champ('immatriculation', (
                            <Input
                                id="lecture-immatriculation"
                                value={valeurs.immatriculation}
                                maxLength={20}
                                disabled={enCours}
                                className="uppercase"
                                onChange={(e) => changer('immatriculation', e.target.value.toUpperCase())}
                            />
                        ))}

                        {prix && (
                            <p className={cn('rounded-md px-3 py-2 text-sm', prixAtypique(prix, prixReference) ? 'bg-orange-50 text-orange-800' : 'bg-gray-50 text-gray-600')}>
                                {prixAtypique(prix, prixReference)
                                    ? msg('MSG-BC-022', { prix, reference: Number(prixReference) })
                                    : `Prix au litre : ${formaterMontant(prix)}/L (référence ${formaterMontant(prixReference)}/L).`}
                            </p>
                        )}
                        {erreurs.lecture && <p className="text-sm text-red-600">{erreurs.lecture}</p>}
                    </div>
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    <Button type="button" variant="outline" onClick={onFermer} disabled={envoi}>
                        Fermer
                    </Button>
                    <Button type="button" onClick={valider} disabled={envoi || enCours || !lectureConfirmable(confirmes)}>
                        {envoi && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}
                        Valider la lecture
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
