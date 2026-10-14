/**
 * Justification d'un bon provisoire conforme à l'Annexe 3 (spec v2.3, RG-M07-03) : en-tête (date, nom, prénom, matricule,
 * département, avance, motif), jusqu'à 10 lignes (intitulé, code analytique, n° de compte, montant, pièce), calculs
 * (justifié, à retourner en caisse, restant à justifier, trop justifié), visas initiateur → N+1 → CDG → Comptabilité.
 * Au dernier visa, le BP est régularisé ; le reliquat est encaissé par le caissier, le trop justifié versé par un BD lié
 * (RG-M07-08).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import {
    ArrowLeft, Banknote, CheckCircle2, ClipboardCheck, FileText, Paperclip, Plus, Save, Send, Trash2, Upload, XCircle,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import MontantInput from '@/Components/MontantInput';
import { formaterDate, formaterDateHeure, formaterMontant } from '@/utils/format';
import { calculsJustification } from '@/utils/justification';
import { cn } from '@/lib/utils';

const COULEURS_STATUT = {
    brouillon: 'bg-gray-100 text-gray-700',
    en_validation: 'bg-blue-100 text-blue-800',
    rejetee: 'bg-red-100 text-red-800',
    validee: 'bg-emerald-100 text-emerald-800',
};

const DECISIONS = {
    soumise: { libelle: 'Soumise', classe: 'bg-blue-100 text-blue-800' },
    visee: { libelle: 'Visée', classe: 'bg-emerald-100 text-emerald-800' },
    rejetee: { libelle: 'Rejetée', classe: 'bg-red-100 text-red-800' },
    sautee: { libelle: 'Étape sautée', classe: 'bg-gray-100 text-gray-600' },
};

const LIGNE_VIDE = { intitule: '', code_analytique: '', compte: '', montant: '', piece_jointe_id: '' };

function Info({ libelle, children }) {
    return (
        <div>
            <p className="text-xs text-gray-500">{libelle}</p>
            <p className="font-medium text-gray-900">{children || '—'}</p>
        </div>
    );
}

function Calcul({ libelle, montant, ton = 'neutre', aide }) {
    const tons = {
        neutre: 'border-gray-200 bg-white text-gray-900',
        marine: 'border-blue-200 bg-blue-50 text-blue-900',
        alerte: 'border-red-200 bg-red-50 text-red-800',
        or: 'border-neemba-300 bg-neemba-50 text-neemba-900',
    };

    return (
        <div className={cn('rounded-lg border p-3', tons[ton])}>
            <p className="text-xs font-medium opacity-80">{libelle}</p>
            <p className="mt-1 text-lg font-bold tabular-nums">{formaterMontant(montant)}</p>
            {aide && <p className="mt-0.5 text-[11px] opacity-75">{aide}</p>}
        </div>
    );
}

export default function Justification({ bon, justification, piecesDuBon = [], codesAnalytiques = [], lignesMax = 10, droits = {}, errors = {} }) {
    const modifiable = droits.modifier;
    const [lignes, setLignes] = useState(() => (justification?.lignes?.length
        ? justification.lignes.map((l) => ({ ...l, montant: l.montant ? String(Math.round(l.montant)) : '', piece_jointe_id: l.piece_jointe_id ?? '' }))
        : [{ ...LIGNE_VIDE }]));
    const [aRetourner, setARetourner] = useState(() => (justification?.calculs?.a_retourner ? String(Math.round(justification.calculs.a_retourner)) : ''));
    const [envoi, setEnvoi] = useState(false);
    /* Les erreurs du serveur portent sur la dernière version enregistrée : masquées pendant la saisie */
    const [modifie, setModifie] = useState(false);
    const [rejet, setRejet] = useState({ ouvert: false, commentaire: '' });
    const fichierRef = useRef(null);
    const [ligneUpload, setLigneUpload] = useState(null);

    const calculs = useMemo(
        () => (modifiable ? calculsJustification(bon.montant, lignes, aRetourner) : justification?.calculs ?? calculsJustification(bon.montant, [], 0)),
        [modifiable, bon.montant, lignes, aRetourner, justification],
    );

    /* En lecture seule (soumise, visée), les lignes affichées sont celles enregistrées, avec leur pièce */
    const lignesAffichees = modifiable ? lignes : (justification?.lignes ?? []);
    const changerLigne = (index, champ, valeur) => {
        setModifie(true);
        setLignes((actuelles) => actuelles.map((l, i) => (i === index ? { ...l, [champ]: valeur } : l)));
    };
    const donnees = () => ({
        lignes: lignes.map((l) => ({ ...l, montant: l.montant === '' ? 0 : Number(l.montant), piece_jointe_id: l.piece_jointe_id || null })),
        montant_a_retourner: aRetourner === '' ? 0 : Number(aRetourner),
    });
    const options = { preserveScroll: true, preserveState: true, onFinish: () => setEnvoi(false) };

    const enregistrer = (ensuite) => {
        setEnvoi(true);
        router.put(route('bons-caisse.justification.enregistrer', bon.id), donnees(), {
            ...options,
            onSuccess: () => {
                setModifie(false);
                ensuite?.();
            },
        });
    };
    const soumettre = () => enregistrer(() => {
        setEnvoi(true);
        router.post(route('bons-caisse.justification.soumettre', bon.id), {}, options);
    });
    const viser = (decision, commentaire = null) => {
        setEnvoi(true);
        router.post(route('bons-caisse.justification.viser', bon.id), { decision, commentaire }, {
            ...options,
            onSuccess: () => setRejet({ ouvert: false, commentaire: '' }),
        });
    };

    /* Pièce d'une ligne : déposée sur le BP, puis rattachée à la ligne */
    const choisirFichier = (index) => {
        setLigneUpload(index);
        fichierRef.current?.click();
    };
    const deposer = (e) => {
        const fichier = e.target.files?.[0];
        e.target.value = '';
        if (!fichier || ligneUpload === null) return;
        const connues = new Set(piecesDuBon.map((p) => p.id));
        setEnvoi(true);
        router.post(route('bons-caisse.justification.piece', bon.id), { fichier }, {
            ...options,
            forceFormData: true,
            onSuccess: (page) => {
                const nouvelle = (page.props.piecesDuBon ?? []).find((p) => !connues.has(p.id));
                if (nouvelle) changerLigne(ligneUpload, 'piece_jointe_id', nouvelle.id);
            },
        });
    };

    const versions = useMemo(() => {
        const groupes = {};
        (justification?.visas ?? []).forEach((v) => { (groupes[v.version] ??= []).push(v); });
        return Object.entries(groupes).sort(([a], [b]) => Number(b) - Number(a));
    }, [justification]);

    return (
        <AuthenticatedLayout>
            <Head title={`Justification ${bon.numero}`} />
            <div className="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <Link href={route('bons-caisse.show', bon.id)} className="inline-flex items-center gap-1 text-sm text-blue-700 hover:underline">
                            <ArrowLeft className="h-4 w-4" /> Retour au bon {bon.numero}
                        </Link>
                        <h1 className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl">Justification du bon provisoire {bon.numero}</h1>
                        <p className="text-sm text-gray-500">Annexe 3 — visas : initiateur, N+1, Contrôle de gestion, Comptabilité</p>
                    </div>
                    {justification && (
                        <span className={cn('rounded-full px-3 py-1 text-sm font-semibold', COULEURS_STATUT[justification.statut])}>
                            {justification.statut_label}
                            {justification.etape_label ? ` · ${justification.etape_label}` : ''}
                        </span>
                    )}
                </div>

                {!justification && (
                    <Card><CardContent className="p-6 text-sm text-gray-600">La justification n&apos;a pas encore été établie par l&apos;initiateur.</CardContent></Card>
                )}

                {/* En-tête de l'Annexe 3 */}
                <Card className="border-t-4 border-t-neemba-400">
                    <CardHeader className="pb-2"><CardTitle className="text-base">Bon provisoire</CardTitle></CardHeader>
                    <CardContent className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                        <Info libelle="Date">{formaterDate(justification?.soumise_le ?? new Date())}</Info>
                        <Info libelle="Nom">{bon.nom}</Info>
                        <Info libelle="Prénom">{bon.prenom}</Info>
                        <Info libelle="Matricule">{bon.matricule}</Info>
                        <Info libelle="Département">{bon.departement}</Info>
                        <Info libelle="Montant de l'avance">{formaterMontant(bon.montant)}</Info>
                        <Info libelle="Payé le">{formaterDate(bon.date_paiement)}</Info>
                        <Info libelle="À régulariser avant le">{formaterDate(bon.date_limite_regularisation)}</Info>
                        <div className="col-span-2 sm:col-span-4"><Info libelle="Motif">{bon.motif}</Info></div>
                        {bon.visiteur && (
                            <p className="col-span-2 rounded-md bg-violet-50 px-3 py-2 text-xs text-violet-800 sm:col-span-4">
                                Avance d&apos;un visiteur du groupe ({bon.visiteur}) : la justification relève du directeur du département d&apos;accueil
                                {justification?.initiateur ? ` (${justification.initiateur})` : ''}.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {justification && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-base">Dépenses justifiées</CardTitle>
                            {modifiable && lignes.length < lignesMax && (
                                <Button type="button" variant="outline" size="sm" onClick={() => { setModifie(true); setLignes([...lignes, { ...LIGNE_VIDE }]); }}>
                                    <Plus className="mr-1 h-4 w-4" /> Ajouter une ligne
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <input ref={fichierRef} type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png" onChange={deposer} aria-label="Pièce justificative" />
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[820px] text-sm">
                                    <thead>
                                        <tr className="bg-slate-900 text-left text-xs uppercase tracking-wide text-white">
                                            <th className="w-8 rounded-tl-md px-2 py-2">N°</th>
                                            <th className="px-2 py-2">Intitulé</th>
                                            <th className="w-40 px-2 py-2">Code analytique</th>
                                            <th className="w-28 px-2 py-2">N° de compte</th>
                                            <th className="w-40 px-2 py-2 text-right">Montant</th>
                                            <th className="w-56 px-2 py-2">Pièce jointe</th>
                                            {modifiable && <th className="w-8 rounded-tr-md" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lignesAffichees.map((ligne, i) => (
                                            <tr key={i} className="border-b border-gray-100 align-top odd:bg-white even:bg-neemba-50/40">
                                                <td className="px-2 py-2 text-gray-500">{i + 1}</td>
                                                {modifiable ? (
                                                    <>
                                                        <td className="px-2 py-1.5">
                                                            <Input aria-label={`Intitulé ligne ${i + 1}`} value={ligne.intitule} maxLength={150}
                                                                onChange={(e) => changerLigne(i, 'intitule', e.target.value)} placeholder="Ex : Hôtel Kankan, 2 nuits" />
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            <select aria-label={`Code analytique ligne ${i + 1}`} value={ligne.code_analytique ?? ''}
                                                                onChange={(e) => changerLigne(i, 'code_analytique', e.target.value)}
                                                                className="h-9 w-full rounded-md border border-input bg-white px-2 text-sm">
                                                                <option value="">Choisir…</option>
                                                                {codesAnalytiques.map((c) => <option key={c.code} value={c.code}>{c.code} — {c.libelle}</option>)}
                                                            </select>
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            <Input aria-label={`Compte ligne ${i + 1}`} value={ligne.compte ?? ''} maxLength={20}
                                                                onChange={(e) => changerLigne(i, 'compte', e.target.value)} placeholder="625100" />
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            <MontantInput aria-label={`Montant ligne ${i + 1}`} value={ligne.montant}
                                                                onChange={(v) => changerLigne(i, 'montant', v)} className="text-right" />
                                                        </td>
                                                        <td className="px-2 py-1.5">
                                                            <div className="flex gap-1">
                                                                <select aria-label={`Pièce ligne ${i + 1}`} value={ligne.piece_jointe_id ?? ''}
                                                                    onChange={(e) => changerLigne(i, 'piece_jointe_id', e.target.value ? Number(e.target.value) : '')}
                                                                    className="h-9 min-w-0 flex-1 rounded-md border border-input bg-white px-2 text-sm">
                                                                    <option value="">Aucune</option>
                                                                    {piecesDuBon.map((p) => <option key={p.id} value={p.id}>{p.doublon ? '⚠ ' : ''}{p.nom}</option>)}
                                                                </select>
                                                                <Button type="button" variant="outline" size="icon" title="Déposer une pièce" disabled={envoi}
                                                                    onClick={() => choisirFichier(i)} aria-label={`Déposer une pièce ligne ${i + 1}`}>
                                                                    <Upload className="h-4 w-4" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                        <td className="px-1 py-1.5">
                                                            <Button type="button" variant="ghost" size="icon" title="Retirer la ligne" aria-label={`Retirer la ligne ${i + 1}`}
                                                                onClick={() => { setModifie(true); setLignes(lignes.length > 1 ? lignes.filter((_, j) => j !== i) : [{ ...LIGNE_VIDE }]); }}>
                                                                <Trash2 className="h-4 w-4 text-red-500" />
                                                            </Button>
                                                        </td>
                                                    </>
                                                ) : (
                                                    <>
                                                        <td className="px-2 py-2">{ligne.intitule}</td>
                                                        <td className="px-2 py-2 font-mono text-xs">{ligne.code_analytique}</td>
                                                        <td className="px-2 py-2 font-mono text-xs">{ligne.compte || '—'}</td>
                                                        <td className="px-2 py-2 text-right tabular-nums">{formaterMontant(ligne.montant)}</td>
                                                        <td className="px-2 py-2">
                                                            {ligne.piece ? (
                                                                <a href={ligne.piece.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-blue-700 hover:underline">
                                                                    <Paperclip className="h-3.5 w-3.5" /> {ligne.piece.nom}
                                                                </a>
                                                            ) : '—'}
                                                        </td>
                                                    </>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {errors.lignes && <p className="text-sm text-red-600" role="alert">{errors.lignes}</p>}

                            {modifiable && (
                                <div className="grid gap-3 sm:grid-cols-[1fr_260px] sm:items-end">
                                    <p className="text-xs text-gray-500">
                                        {lignes.length} / {lignesMax} lignes. Chaque ligne exige un intitulé, un code analytique actif, un montant et sa pièce.
                                        Ce que vous n&apos;avez pas dépensé se rend en caisse : portez-le dans « Montant à retourner en caisse ».
                                    </p>
                                    <div>
                                        <Label htmlFor="a_retourner">Montant à retourner en caisse</Label>
                                        <MontantInput id="a_retourner" value={aRetourner} onChange={(v) => { setModifie(true); setARetourner(v); }} />
                                    </div>
                                </div>
                            )}

                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                <Calcul libelle="Montant justifié" montant={calculs.justifie} ton="marine" />
                                <Calcul libelle="À retourner en caisse" montant={calculs.a_retourner} ton={calculs.a_retourner > 0 ? 'or' : 'neutre'}
                                    aide={calculs.a_retourner > 0 ? 'Rétrocédé au caissier après les visas' : null} />
                                <Calcul libelle="Restant à justifier" montant={calculs.restant} ton={calculs.restant > 0 ? 'alerte' : 'neutre'}
                                    aide={calculs.restant > 0 ? 'Doit être nul pour soumettre' : null} />
                                <Calcul libelle="Trop justifié à percevoir" montant={calculs.trop_justifie} ton={calculs.trop_justifie > 0 ? 'or' : 'neutre'}
                                    aide={calculs.trop_justifie > 0 ? 'Versé par un BD de complément' : null} />
                            </div>

                            {modifiable && !modifie && justification.erreurs?.length > 0 && (
                                <ul className="list-inside list-disc rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                                    {justification.erreurs.map((e) => <li key={e}>{e}</li>)}
                                </ul>
                            )}

                            {modifiable && (
                                <div className="flex flex-wrap justify-end gap-2">
                                    <Button type="button" variant="outline" disabled={envoi} onClick={() => enregistrer()}>
                                        <Save className="mr-1 h-4 w-4" /> Enregistrer
                                    </Button>
                                    <Button type="button" disabled={envoi || calculs.restant > 0} onClick={soumettre}
                                        className="bg-slate-900 text-white hover:bg-slate-800">
                                        <Send className="mr-1 h-4 w-4" /> {justification.statut === 'rejetee' ? 'Corriger et resoumettre' : 'Soumettre aux visas'}
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Visa de l'étape en cours */}
                {droits.viser && (
                    <Card className="border-blue-200 bg-blue-50/50">
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
                            <p className="text-sm text-blue-900">
                                <ClipboardCheck className="mr-1 inline h-4 w-4" />
                                Votre visa est attendu : <strong>{justification.etape_label}</strong>
                                {droits.au_titre_de ? ` (au titre de ${droits.au_titre_de})` : ''}.
                            </p>
                            <div className="flex gap-2">
                                <Button type="button" variant="outline" className="border-red-300 text-red-700 hover:bg-red-50" disabled={envoi}
                                    onClick={() => setRejet({ ouvert: true, commentaire: '' })}>
                                    <XCircle className="mr-1 h-4 w-4" /> Rejeter
                                </Button>
                                <Button type="button" className="bg-emerald-600 text-white hover:bg-emerald-700" disabled={envoi} onClick={() => viser('visee')}>
                                    <CheckCircle2 className="mr-1 h-4 w-4" /> Viser
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Issue de la justification : complément, reliquat */}
                {justification?.statut === 'validee' && (
                    <Card className="border-emerald-200 bg-emerald-50/40">
                        <CardContent className="space-y-2 p-4 text-sm">
                            <p className="font-semibold text-emerald-800"><CheckCircle2 className="mr-1 inline h-4 w-4" /> Justification visée le {formaterDateHeure(justification.validee_le)} : BP régularisé.</p>
                            {justification.complement && (
                                <p>
                                    Complément de {formaterMontant(justification.calculs.trop_justifie)} versé par le bon{' '}
                                    <Link href={route('bons-caisse.show', justification.complement.id)} className="font-semibold text-blue-700 hover:underline">
                                        {justification.complement.numero}
                                    </Link>{' '}({justification.complement.statut_label}).
                                </p>
                            )}
                            {justification.calculs.a_retourner > 0 && (
                                <p>
                                    <Banknote className="mr-1 inline h-4 w-4 text-neemba-600" />
                                    Reliquat de {formaterMontant(justification.calculs.a_retourner)} :{' '}
                                    {justification.reliquat_encaisse_le
                                        ? `encaissé le ${formaterDateHeure(justification.reliquat_encaisse_le)} par ${justification.reliquat_encaisse_par}.`
                                        : 'à rendre à la caisse.'}
                                </p>
                            )}
                            {droits.encaisser && (
                                <Button type="button" disabled={envoi} className="bg-neemba-500 text-slate-900 hover:bg-neemba-400"
                                    onClick={() => { setEnvoi(true); router.post(route('bons-caisse.justification.reliquat', bon.id), {}, options); }}>
                                    <Banknote className="mr-1 h-4 w-4" /> Encaisser le reliquat
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Circuit des visas, par version */}
                {versions.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-base">Visas</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            {versions.map(([version, visas]) => (
                                <div key={version}>
                                    {versions.length > 1 && <p className="mb-1 text-xs font-semibold uppercase text-gray-500">Version {version}</p>}
                                    <ol className="space-y-2">
                                        {visas.map((v, i) => (
                                            <li key={i} className="flex flex-wrap items-start gap-2 rounded-md border border-gray-100 p-2 text-sm">
                                                <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold', DECISIONS[v.decision]?.classe)}>{DECISIONS[v.decision]?.libelle}</span>
                                                <span className="font-medium">{v.etape}</span>
                                                <span className="text-gray-600">
                                                    {v.valideur ?? ''}{v.au_titre_de ? ` (au titre de ${v.au_titre_de})` : ''} · {formaterDateHeure(v.le)}
                                                </span>
                                                {v.commentaire && <p className="w-full text-xs text-gray-600"><FileText className="mr-1 inline h-3 w-3" />{v.commentaire}</p>}
                                            </li>
                                        ))}
                                    </ol>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={rejet.ouvert} onOpenChange={(ouvert) => setRejet((r) => ({ ...r, ouvert }))}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Rejeter la justification</DialogTitle>
                        <DialogDescription>La justification revient à l&apos;initiateur, qui la corrige et la soumet de nouveau.</DialogDescription>
                    </DialogHeader>
                    <div>
                        <Label htmlFor="commentaire_rejet">Motif du rejet (10 caractères au moins)</Label>
                        <Textarea id="commentaire_rejet" rows={3} value={rejet.commentaire} onChange={(e) => setRejet((r) => ({ ...r, commentaire: e.target.value }))} />
                        {errors.commentaire && <p className="mt-1 text-sm text-red-600" role="alert">{errors.commentaire}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setRejet({ ouvert: false, commentaire: '' })}>Annuler</Button>
                        <Button type="button" className="bg-red-600 text-white hover:bg-red-700" disabled={envoi || rejet.commentaire.trim().length < 10}
                            onClick={() => viser('rejetee', rejet.commentaire)}>
                            Rejeter
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
