/**
 * Formulaire « Ordre de mission » (M12-2, spec v2.2 §7.3, US-01 à US-06, US-11).
 *
 * Le brouillon s'enregistre au fil de la saisie et le serveur renvoie le calcul de chaque participant
 * (il fait foi : aucun calcul n'est refait ici). La soumission refait tous les contrôles côté serveur ;
 * en cas de chevauchement (RG-M12-16), le demandeur peut demander une dérogation au DAF.
 *
 * Prise en charge des frais (spec v2.3, RG-M12-30 à RG-M12-33) : chaque ligne de frais de chaque participant se
 * répartit en parts (Neemba ou client, OR, code analytique, pourcentage), Neemba 100 % par défaut. Les parts modifiées
 * à l'écran (form.repartitions) partent avec l'enregistrement automatique.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, ArrowLeft, CheckCircle2, Loader2, Send, ShieldAlert, Trash2, X, XCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/Components/ui/dialog';
import MontantInput from '@/Components/MontantInput';
import Champ, { placerCurseur } from '@/Components/Assistant/Champ';
import EtiquettesTexte from '@/Components/Odm/EtiquettesTexte';
import EtiquettesOrOdm from '@/Components/Odm/EtiquettesOrOdm';
import RechercheParticipant from '@/Components/Odm/RechercheParticipant';
import { SelectionClients } from '@/Components/Odm/ChoixClient';
import PanneauCalcul from '@/Components/Odm/PanneauCalcul';
import RepartitionFrais from '@/Components/Odm/RepartitionFrais';
import BadgeStatutOdm from '@/Components/Odm/BadgeStatutOdm';
import { nouvelleCleIdempotence } from '@/utils/assistant';
import { msg, msgErreur } from '@/utils/messages';
import {
    ajouterParticipant,
    clientsAvecCeuxDesOr,
    codesDuServiceOdm,
    departPasse,
    donneesOdm,
    erreursOdmParChamp,
    joursDeMission,
    natureTechniqueParDefaut,
    finDePeriodeLongue,
    retourAvantDepart,
    STATUTS_CADRE,
} from '@/utils/odm';

const SELECT = 'h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-neemba-400';

function etatInitial(odm, defauts) {
    if (odm) return { repartitions: {}, ...odm };

    return {
        type: 'interieur',
        technique: Boolean(defauts?.technique),
        site: defauts?.site ?? '',
        service: defauts?.service ?? '',
        code_analytique: '',
        but: '',
        clients: [],
        destinations: [],
        vehicule: '',
        immatriculation: '',
        itineraire: '',
        or_a_regulariser: false,
        niveau_urgence: 'normale',
        motif_urgence: '',
        date_depart: '',
        date_retour_prevue: '',
        motif_depart_passe: '',
        /* Complément du 22/10, RG-M12-43 */
        duree_mission: 'ponctuelle',
        frequence_renouvellement: null,
        date_fin_previsionnelle: '',
        type_segment: null,
        motif_prolongation: '',
        repartitions: {},
        hebergement_exterieur: null,
        reference_billet: '',
        participants: [],
        ordres_reparation: [],
    };
}

/** Un nouveau formulaire n'est enregistré qu'une fois quelque chose saisi */
function aDuContenu(form) {
    return Boolean(form.participants.length || form.destinations.length || form.but.trim() || form.date_depart || form.ordres_reparation.length);
}

export default function Formulaire({
    odm = null, defauts = null, services = [], codesAnalytiques = [], types = {},
    hebergementsExterieurs = {}, typesOr = {}, maxParticipants = 10, dateDuJour, niveauxUrgence = {}, motifsUrgence = [], tauxEnVigueur = null,
    durees = {}, frequencesRenouvellement = [],
}) {
    const [form, setForm] = useState(() => etatInitial(odm, defauts));
    const [odmId, setOdmId] = useState(odm?.id ?? null);
    /* Identifiant du brouillon et création en cours, lus par les enregistrements automatiques successifs */
    const idBrouillon = useRef(odm?.id ?? null);
    const creation = useRef(null);
    const [statut] = useState(odm?.statut ?? 'BROUILLON');
    const [numero] = useState(odm?.numero ?? null);
    const [calcul, setCalcul] = useState(odm?.calcul ?? null);
    const [derogation, setDerogation] = useState(odm?.derogation ?? null);
    const [enregistrement, setEnregistrement] = useState('idle'); // idle | en_cours | enregistre | erreur
    const [erreursSaisie, setErreursSaisie] = useState({});
    const [erreursSoumission, setErreursSoumission] = useState([]);
    const [chevauchement, setChevauchement] = useState(false);
    const [soumission, setSoumission] = useState(false);
    const [motifDerogation, setMotifDerogation] = useState('');
    const [erreurDerogation, setErreurDerogation] = useState(null);
    const [annulation, setAnnulation] = useState(false);
    const [erreurParticipant, setErreurParticipant] = useState(null);

    const modifie = useRef(false);
    const requete = useRef(0);
    const enregistrementEnCours = useRef(Promise.resolve());
    const cleSoumission = useRef(nouvelleCleIdempotence());

    const erreursParChamp = useMemo(() => erreursOdmParChamp(erreursSoumission), [erreursSoumission]);
    const erreurDe = (champ) => erreursSaisie[champ]?.[0] ?? erreursParChamp[champ]?.[0] ?? null;
    const codes = useMemo(() => codesDuServiceOdm(codesAnalytiques, services, form.service), [codesAnalytiques, services, form.service]);
    const jours = joursDeMission(form.date_depart, form.date_retour_prevue);
    /* Complément du 22/10, RG-M12-43, 44 : mission longue, le retour prévu est la fin de la période (sauf prolongation courte) */
    const longue = form.duree_mission === 'longue';
    const periodeCalculee = longue && form.type_segment !== 'prolongation'
        ? finDePeriodeLongue(form.date_depart, Number(form.frequence_renouvellement), form.date_fin_previsionnelle) : '';

    const changer = (champ, valeur) => {
        modifie.current = true;
        setForm((f) => ({ ...f, [champ]: valeur }));
    };
    const majForm = (transformer) => {
        modifie.current = true;
        setForm(transformer);
    };
    /* RG-M12-44 : la fin de la période suit le départ, la fréquence et la fin prévisionnelle (le serveur la recalcule) */
    useEffect(() => {
        if (periodeCalculee && periodeCalculee !== form.date_retour_prevue) {
            majForm((f) => ({ ...f, date_retour_prevue: periodeCalculee }));
        }
    }, [periodeCalculee]); // eslint-disable-line react-hooks/exhaustive-deps

    /* Enregistrement du brouillon ; seule la réponse de la dernière requête est retenue */
    const enregistrer = useCallback(async (donnees) => {
        const numeroRequete = ++requete.current;
        setEnregistrement('en_cours');
        try {
            /* Un seul brouillon : un enregistrement lancé pendant la création attend l'identifiant puis met à jour */
            if (!idBrouillon.current && creation.current) {
                await creation.current.catch(() => null);
            }
            let data;
            if (idBrouillon.current) {
                ({ data } = await axios.put(route('api.odm.enregistrer', idBrouillon.current), donnees));
            } else {
                creation.current = axios.post(route('api.odm.creer'), donnees);
                ({ data } = await creation.current);
                idBrouillon.current = data.odm.id;
                setOdmId(data.odm.id);
                window.history.replaceState(window.history.state, '', route('odm.edit', data.odm.id));
            }
            if (numeroRequete === requete.current) {
                setCalcul(data.odm.calcul);
                setDerogation(data.odm.derogation);
                setErreursSaisie({});
                setEnregistrement('enregistre');
            }
            return data.odm;
        } catch (e) {
            if (numeroRequete === requete.current) {
                setEnregistrement('erreur');
                if (e.response?.status === 422 && e.response.data?.errors) setErreursSaisie(e.response.data.errors);
            }
            throw e;
        }
    }, []);

    /* Enregistrement automatique 800 ms après la dernière modification */
    useEffect(() => {
        if (!modifie.current || (!odmId && !aDuContenu(form))) return undefined;
        const minuterie = setTimeout(() => {
            enregistrementEnCours.current = enregistrer(donneesOdm(form)).catch(() => null);
        }, 800);

        return () => clearTimeout(minuterie);
    }, [form, enregistrer, odmId]);

    const changerService = (nom) => {
        modifie.current = true;
        setForm((f) => ({ ...f, service: nom, code_analytique: '', technique: natureTechniqueParDefaut(services, nom) }));
    };

    const ajouter = (employe) => {
        const { liste, erreur } = ajouterParticipant(form.participants, employe, maxParticipants);
        setErreurParticipant(erreur ? msg(erreur, { max: maxParticipants }) : null);
        if (!erreur) changer('participants', liste);
    };

    const changerParticipant = (userId, champ, valeur) => {
        changer('participants', form.participants.map((p) => (p.user_id === userId ? { ...p, [champ]: valeur } : p)));
    };

    const soumettre = async () => {
        setSoumission(true);
        setErreursSoumission([]);
        setChevauchement(false);
        try {
            await enregistrementEnCours.current;
            const brouillon = await enregistrer(donneesOdm(form));
            const { data } = await axios.post(route('api.odm.soumettre', brouillon.id), { cle_soumission: cleSoumission.current });
            router.visit(data.redirection);
        } catch (e) {
            const reponse = e.response?.data;
            if (reponse?.erreurs) {
                setErreursSoumission(reponse.erreurs);
                setChevauchement(Boolean(reponse.chevauchement));
                if (reponse.erreurs[0]?.champ) placerCurseur(reponse.erreurs[0].champ);
            } else if (reponse?.message_cle) {
                setErreursSoumission([{ message: msgErreur(reponse), champ: reponse.champ }]);
            } else if (!reponse?.errors) {
                setErreursSoumission([{ message: 'Soumission impossible : vérifiez votre connexion puis réessayez.' }]);
            }
            cleSoumission.current = nouvelleCleIdempotence();
            setSoumission(false);
        }
    };

    const demanderDerogation = async () => {
        setErreurDerogation(null);
        try {
            const { data } = await axios.post(route('api.odm.derogation', odmId), { motif: motifDerogation });
            setDerogation(data.odm.derogation);
            setMotifDerogation('');
        } catch (e) {
            setErreurDerogation(msgErreur(e.response?.data) || e.response?.data?.message || 'Demande impossible.');
        }
    };

    const annuler = async () => {
        if (!odmId) {
            router.visit(route('odm.index'));
            return;
        }
        await enregistrementEnCours.current;
        const { data } = await axios.post(route('api.odm.annuler', odmId), {});
        router.visit(data.redirection);
    };

    const exterieur = form.type === 'exterieur';
    const passe = departPasse(form.date_depart, dateDuJour);
    /* RG-M12-17 : une prolongation garde son type et son départ ; ses participants peuvent être retirés, pas ajoutés */
    const prolongation = odm?.prolongation ?? null;

    return (
        <AuthenticatedLayout header={numero ? `Ordre de mission ${numero}` : 'Nouvel ordre de mission'}>
            <Head title={numero ? `ODM ${numero}` : 'Nouvel ordre de mission'} />

            <div className="mx-auto max-w-7xl space-y-4 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link href={route('odm.index')} className="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                        <ArrowLeft className="h-4 w-4" /> Ordres de mission
                    </Link>
                    <div className="flex items-center gap-3 text-xs text-gray-500">
                        <BadgeStatutOdm statut={statut} />
                        {enregistrement === 'en_cours' && <span className="inline-flex items-center gap-1"><Loader2 className="h-3 w-3 animate-spin" /> Enregistrement…</span>}
                        {enregistrement === 'enregistre' && <span className="inline-flex items-center gap-1 text-green-700"><CheckCircle2 className="h-3 w-3" /> Brouillon enregistré</span>}
                        {enregistrement === 'erreur' && <span className="inline-flex items-center gap-1 text-red-600"><XCircle className="h-3 w-3" /> Non enregistré</span>}
                    </div>
                </div>

                {prolongation && (
                    <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                        <p className="font-semibold">{prolongation.libelle}</p>
                        <p className="mt-1">
                            Départ fixé au {prolongation.depart}, lendemain du retour de l'ordre de mission {prolongation.precedent}. Participants repris :
                            vous pouvez en retirer, pas en ajouter.
                        </p>
                        {prolongation.rattrapages > 0 && (
                            <p className="mt-1">{msg('MSG-M12-06', { segment: prolongation.precedent, nombre: prolongation.rattrapages })}</p>
                        )}
                    </div>
                )}

                {odm?.rejet && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                        <p className="font-semibold">ODM rejeté au niveau {odm.rejet.niveau} par {odm.rejet.valideur}, le {odm.rejet.date}</p>
                        <p className="mt-1">Motif : {odm.rejet.motif}</p>
                        <p className="mt-1 text-red-700">Corrigez puis soumettez à nouveau : le numéro {numero} est conservé.</p>
                    </div>
                )}

                {erreursSoumission.length > 0 && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-4" role="alert">
                        <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-red-800"><AlertTriangle className="h-4 w-4" /> Soumission impossible</p>
                        <ul className="list-disc space-y-1 pl-5 text-sm text-red-700">
                            {erreursSoumission.map((e, i) => (
                                <li key={i}>
                                    {e.champ ? <button type="button" className="text-left underline-offset-2 hover:underline" onClick={() => placerCurseur(e.champ)}>{e.message}</button> : e.message}
                                </li>
                            ))}
                        </ul>
                        {chevauchement && !derogation && odmId && (
                            <div className="mt-4 rounded-md border border-amber-200 bg-white p-3">
                                <p className="mb-2 text-sm font-medium text-gray-900">Demander une dérogation au DAF</p>
                                <Textarea value={motifDerogation} onChange={(e) => setMotifDerogation(e.target.value)} rows={2}
                                    placeholder="Pourquoi ce participant doit-il figurer sur les deux missions ?" aria-label="Motif de la dérogation" />
                                {erreurDerogation && <p className="mt-1 text-sm text-red-600">{erreurDerogation}</p>}
                                <Button type="button" size="sm" className="mt-2" onClick={demanderDerogation}>
                                    <ShieldAlert className="mr-1 h-4 w-4" /> Demander la dérogation
                                </Button>
                            </div>
                        )}
                    </div>
                )}

                {derogation && (
                    <div className={`rounded-lg border p-3 text-sm ${derogation.statut === 'accordee' ? 'border-green-200 bg-green-50 text-green-800' : derogation.statut === 'refusee' ? 'border-red-200 bg-red-50 text-red-800' : 'border-amber-200 bg-amber-50 text-amber-800'}`}>
                        {derogation.statut === 'demandee' && <>Dérogation au chevauchement demandée au DAF : {derogation.demande_motif}</>}
                        {derogation.statut === 'accordee' && <>Dérogation accordée par {derogation.par} le {derogation.le} : {derogation.motif}. Vous pouvez soumettre.</>}
                        {derogation.statut === 'refusee' && <>Dérogation refusée par {derogation.par} le {derogation.le} : {derogation.motif}</>}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="min-w-0 space-y-4">
                        {/* Type et émetteur */}
                        <Card>
                            <CardHeader className="pb-3"><CardTitle className="text-base">Type et service émetteur</CardTitle></CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2">
                                <Champ champ="type" libelle="Type d'ODM" obligatoire erreur={erreurDe('type')}>
                                    <div className="flex gap-4 pt-1">
                                        {Object.entries(types).map(([valeur, libelle]) => (
                                            <label key={valeur} className="flex items-center gap-2 text-sm">
                                                <input type="radio" name="type" value={valeur} checked={form.type === valeur} onChange={() => changer('type', valeur)} disabled={Boolean(prolongation)} />
                                                {libelle}
                                            </label>
                                        ))}
                                    </div>
                                </Champ>
                                <Champ champ="service" libelle="Service émetteur" obligatoire erreur={erreurDe('service')}>
                                    <select id="champ-service" className={SELECT} value={form.service ?? ''} onChange={(e) => changerService(e.target.value)}>
                                        <option value="">Choisir…</option>
                                        {services.map((s) => <option key={s.id} value={s.nom}>{s.nom}</option>)}
                                    </select>
                                </Champ>
                                <Champ champ="code_analytique" libelle="Code analytique" obligatoire erreur={erreurDe('code_analytique')} aide="Repris sur les bons générés">
                                    <select id="champ-code_analytique" className={SELECT} value={form.code_analytique ?? ''} onChange={(e) => changer('code_analytique', e.target.value)}>
                                        <option value="">Choisir…</option>
                                        {codes.map((c) => <option key={c.code} value={c.code}>{c.code} — {c.libelle}</option>)}
                                    </select>
                                </Champ>
                                <Champ champ="technique" erreur={erreurDe('technique')}>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={Boolean(form.technique)} onChange={(e) => changer('technique', e.target.checked)} />
                                        Mission technique (au moins un OR, ou OR à régulariser)
                                    </label>
                                </Champ>
                                {/* Spec v2.4, RG-M12-42 : besoin carburant ; le véhicule garde le modèle, l'immatriculation et l'itinéraire à part */}
                                <Champ champ="vehicule" libelle="Véhicule" erreur={erreurDe('vehicule')} aide="Modèle ; facultatif, exigé pour une avance carburant">
                                    <Input id="champ-vehicule" value={form.vehicule ?? ''} onChange={(e) => changer('vehicule', e.target.value)} maxLength={60} placeholder="Ex. : Toyota Hilux" />
                                </Champ>
                                <Champ champ="immatriculation" libelle="Immatriculation" erreur={erreurDe('immatriculation')}>
                                    <Input id="champ-immatriculation" value={form.immatriculation ?? ''} onChange={(e) => changer('immatriculation', e.target.value.toUpperCase())} maxLength={20} placeholder="Ex. : RC 1234 A" />
                                </Champ>
                                <Champ champ="itineraire" libelle="Itinéraire" erreur={erreurDe('itineraire')} aide="Besoin carburant : trajet prévu">
                                    <Input id="champ-itineraire" value={form.itineraire ?? ''} onChange={(e) => changer('itineraire', e.target.value)} maxLength={255} placeholder="Ex. : Conakry – Kindia – Mamou – Kouroussa" />
                                </Champ>
                                {/* Spec v2.4, RG-M01-07, Q76 : un ODM urgent se vise avec un code reçu par SMS */}
                                <Champ champ="niveau_urgence" libelle="Urgence" erreur={erreurDe('niveau_urgence')}>
                                    <select id="champ-niveau_urgence" className={SELECT} value={form.niveau_urgence ?? 'normale'} onChange={(e) => changer('niveau_urgence', e.target.value)}>
                                        {Object.entries(niveauxUrgence).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                    </select>
                                </Champ>
                                {(form.niveau_urgence ?? 'normale') !== 'normale' && (
                                    <Champ champ="motif_urgence" libelle="Motif de l'urgence" obligatoire erreur={erreurDe('motif_urgence')}>
                                        <select id="champ-motif_urgence" className={SELECT} value={form.motif_urgence ?? ''} onChange={(e) => changer('motif_urgence', e.target.value)}>
                                            <option value="">Choisir…</option>
                                            {motifsUrgence.map((m) => <option key={m} value={m}>{m}</option>)}
                                        </select>
                                    </Champ>
                                )}
                            </CardContent>
                        </Card>

                        {/* Mission */}
                        <Card>
                            <CardHeader className="pb-3"><CardTitle className="text-base">Mission</CardTitle></CardHeader>
                            <CardContent className="grid gap-4">
                                <Champ champ="destinations" libelle="Destination(s)" obligatoire erreur={erreurDe('destinations')} aide="Entrée pour ajouter une destination">
                                    <EtiquettesTexte id="champ-destinations" valeurs={form.destinations} onChange={(v) => changer('destinations', v)} placeholder="Ex. Kouroussa" invalide={Boolean(erreurDe('destinations'))} />
                                </Champ>
                                <Champ champ="clients" libelle="Client(s)" erreur={erreurDe('clients')}>
                                    <SelectionClients valeurs={form.clients} onChange={(v) => changer('clients', v)} />
                                </Champ>
                                <Champ champ="but" libelle="But de la mission" obligatoire erreur={erreurDe('but')} aide={`${form.but.trim().length} caractère(s) — 10 au minimum`}>
                                    <Textarea id="champ-but" value={form.but} onChange={(e) => changer('but', e.target.value)} rows={3} maxLength={2000} />
                                </Champ>
                            </CardContent>
                        </Card>

                        {/* Dates */}
                        <Card>
                            <CardHeader className="pb-3"><CardTitle className="text-base">Dates{jours > 0 && <span className="ml-2 text-sm font-normal text-gray-500">{jours} jour{jours > 1 ? 's' : ''}</span>}</CardTitle></CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2">
                                {/* Complément du 22/10, RG-M12-43 : nature de la mission, choisie à la création */}
                                <Champ champ="duree_mission" libelle="Nature de la mission" obligatoire erreur={erreurDe('duree_mission')} className="sm:col-span-2">
                                    <div className="flex flex-wrap gap-4" role="radiogroup" aria-label="Nature de la mission">
                                        {Object.entries(durees).map(([valeur, libelle]) => (
                                            <label key={valeur} className="flex items-center gap-2 text-sm">
                                                <input type="radio" name="duree_mission" value={valeur} checked={(form.duree_mission ?? 'ponctuelle') === valeur}
                                                    disabled={Boolean(prolongation)} onChange={() => changer('duree_mission', valeur)} />
                                                {libelle}
                                            </label>
                                        ))}
                                    </div>
                                    <p className="mt-1 text-xs text-gray-500">
                                        {longue
                                            ? 'Renouvelée période après période ; chaque renouvellement est soumis par le demandeur et validé par tout le circuit.'
                                            : 'Dates de départ et de retour ; une prolongation imprévue reste possible, avec un motif.'}
                                    </p>
                                </Champ>
                                {longue && (
                                    <>
                                        <Champ champ="frequence_renouvellement" libelle="Fréquence de renouvellement" obligatoire erreur={erreurDe('frequence_renouvellement')}>
                                            <select id="champ-frequence_renouvellement" className={SELECT} value={form.frequence_renouvellement ?? ''}
                                                onChange={(e) => changer('frequence_renouvellement', e.target.value ? Number(e.target.value) : null)}>
                                                <option value="">Choisir…</option>
                                                {frequencesRenouvellement.map((j) => <option key={j} value={j}>Tous les {j} jours</option>)}
                                            </select>
                                        </Champ>
                                        <Champ champ="date_fin_previsionnelle" libelle="Fin prévisionnelle de la mission" obligatoire erreur={erreurDe('date_fin_previsionnelle')}>
                                            <Input id="champ-date_fin_previsionnelle" type="date" value={form.date_fin_previsionnelle ?? ''} min={form.date_depart || undefined}
                                                onChange={(e) => changer('date_fin_previsionnelle', e.target.value)} />
                                        </Champ>
                                    </>
                                )}
                                <Champ champ="date_depart" libelle="Départ" obligatoire erreur={erreurDe('date_depart')}>
                                    <Input id="champ-date_depart" type="date" value={form.date_depart ?? ''} onChange={(e) => changer('date_depart', e.target.value)} disabled={Boolean(prolongation)} />
                                </Champ>
                                <Champ champ="date_retour_prevue" libelle={periodeCalculee ? 'Fin de la période' : 'Retour prévu'} obligatoire
                                    erreur={erreurDe('date_retour_prevue') ?? (retourAvantDepart(form.date_depart, form.date_retour_prevue) ? msg('MSG-M12-02') : null)}>
                                    <Input id="champ-date_retour_prevue" type="date" value={form.date_retour_prevue ?? ''} min={form.date_depart || undefined}
                                        readOnly={Boolean(periodeCalculee)} onChange={(e) => changer('date_retour_prevue', e.target.value)} />
                                    {periodeCalculee && <p className="mt-1 text-xs text-gray-500">Calculée d'après la fréquence et la fin prévisionnelle.</p>}
                                </Champ>
                                {/* RG-M12-50 : une prolongation porte son motif */}
                                {form.type_segment === 'prolongation' && (
                                    <Champ champ="motif_prolongation" libelle="Motif de la prolongation" obligatoire erreur={erreurDe('motif_prolongation')} className="sm:col-span-2">
                                        <Textarea id="champ-motif_prolongation" rows={2} maxLength={500} value={form.motif_prolongation ?? ''}
                                            onChange={(e) => changer('motif_prolongation', e.target.value)} placeholder="Travaux non terminés, panne plus grave que prévu…" />
                                    </Champ>
                                )}
                                {passe && (
                                    <Champ champ="motif_depart_passe" libelle="Motif (départ dans le passé)" obligatoire erreur={erreurDe('motif_depart_passe')} className="sm:col-span-2">
                                        <Input id="champ-motif_depart_passe" value={form.motif_depart_passe ?? ''} onChange={(e) => changer('motif_depart_passe', e.target.value)} maxLength={500}
                                            placeholder="Régularisation d'une mission déjà commencée…" />
                                    </Champ>
                                )}
                                {exterieur && (
                                    <>
                                        <Champ champ="hebergement_exterieur" libelle="Hébergement à l'étranger" obligatoire erreur={erreurDe('hebergement_exterieur')}>
                                            <select id="champ-hebergement_exterieur" className={SELECT} value={form.hebergement_exterieur ?? ''} onChange={(e) => changer('hebergement_exterieur', e.target.value || null)}>
                                                <option value="">Choisir…</option>
                                                {Object.entries(hebergementsExterieurs).map(([valeur, libelle]) => <option key={valeur} value={valeur}>{libelle}</option>)}
                                            </select>
                                        </Champ>
                                        <Champ champ="reference_billet" libelle="Référence billet / bon de commande Wanda" erreur={erreurDe('reference_billet')}>
                                            <Input id="champ-reference_billet" value={form.reference_billet ?? ''} onChange={(e) => changer('reference_billet', e.target.value)} maxLength={100} />
                                        </Champ>
                                    </>
                                )}
                            </CardContent>
                        </Card>

                        {/* OR */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">OR liés{form.technique && <span className="text-red-600"> *</span>}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Champ champ="ordres_reparation" erreur={null}>
                                    <EtiquettesOrOdm valeurs={form.ordres_reparation} typesOr={typesOr} erreur={erreurDe('ordres_reparation')} clients={form.clients}
                                        onChange={(v) => majForm((f) => ({ ...f, ordres_reparation: v, clients: clientsAvecCeuxDesOr(f.clients, v),
                                            or_a_regulariser: v.length > 0 ? false : f.or_a_regulariser }))} />
                                </Champ>
                                {/* Spec v2.4, RG-M12-39 : mission technique dont l'OR n'est pas encore connu */}
                                {form.technique && form.ordres_reparation.length === 0 && (
                                    <label className="mt-3 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900">
                                        <input type="checkbox" id="champ-or_a_regulariser" className="mt-0.5" checked={Boolean(form.or_a_regulariser)}
                                            onChange={(e) => changer('or_a_regulariser', e.target.checked)} />
                                        <span>
                                            <strong>OR à régulariser</strong> : l'OR n'est pas encore connu. Il sera rattaché après coup, depuis la fiche de l'ODM,
                                            avant l'échéance fixée à la validation.
                                        </span>
                                    </label>
                                )}
                            </CardContent>
                        </Card>

                        {/* Participants */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Participants <span className="text-sm font-normal text-gray-500">({form.participants.length} / {maxParticipants})</span></CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Champ champ="participants" erreur={erreurParticipant ?? (erreursParChamp.participants ? null : erreurDe('participants'))}>
                                    {!prolongation && (
                                        <RechercheParticipant onChoisir={ajouter} dejaChoisis={form.participants.map((p) => p.user_id)} desactive={form.participants.length >= maxParticipants} />
                                    )}
                                </Champ>
                                {erreursParChamp.participants && (
                                    <ul className="space-y-1 text-sm text-red-600" role="alert">
                                        {erreursParChamp.participants.map((m, i) => <li key={i}>{m}</li>)}
                                    </ul>
                                )}
                                {form.participants.length > 0 && (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-xs text-gray-500">
                                                    <th className="py-2 pr-3 font-medium">Participant</th>
                                                    <th className="py-2 pr-3 font-medium">Statut</th>
                                                    <th className="py-2 pr-3 font-medium">N° OM</th>
                                                    {!exterieur && <th className="py-2 pr-3 font-medium">Logé sur base vie</th>}
                                                    {exterieur && form.hebergement_exterieur === 'avant_depart' && <th className="py-2 pr-3 font-medium">Facture d'hébergement</th>}
                                                    <th className="py-2" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {form.participants.map((p) => (
                                                    <tr key={p.user_id} className="border-b last:border-b-0">
                                                        <td className="py-2 pr-3">
                                                            <p className="font-medium text-gray-900">{p.nom}</p>
                                                            <p className="text-xs text-gray-500">{[p.matricule, p.service].filter(Boolean).join(' · ') || '—'}</p>
                                                        </td>
                                                        <td className={`py-2 pr-3 ${exterieur && !p.statut_cadre ? 'font-medium text-red-600' : ''}`}>
                                                            {STATUTS_CADRE[p.statut_cadre] ?? 'Non renseigné'}
                                                        </td>
                                                        <td className="py-2 pr-3 font-mono text-xs">{p.numero_om ?? '—'}</td>
                                                        {!exterieur && (
                                                            <td className="py-2 pr-3">
                                                                <input type="checkbox" checked={Boolean(p.base_vie)} aria-label={`${p.nom} logé sur base vie`}
                                                                    onChange={(e) => changerParticipant(p.user_id, 'base_vie', e.target.checked)} />
                                                            </td>
                                                        )}
                                                        {exterieur && form.hebergement_exterieur === 'avant_depart' && (
                                                            <td className="py-2 pr-3">
                                                                <MontantInput value={p.hebergement_facture ?? ''} onChange={(v) => changerParticipant(p.user_id, 'hebergement_facture', v)} className="w-40" />
                                                            </td>
                                                        )}
                                                        <td className="py-2 text-right">
                                                            <Button type="button" variant="ghost" size="icon" className="h-8 w-8" aria-label={`Retirer ${p.nom}`}
                                                                onClick={() => changer('participants', form.participants.filter((x) => x.user_id !== p.user_id))}>
                                                                <X className="h-4 w-4" />
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Prise en charge des frais : lignes et parts (spec v2.3) */}
                        <Card data-champ="repartition">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Prise en charge des frais</CardTitle>
                                <p className="text-xs text-gray-500">
                                    Neemba prend tout en charge par défaut. Une ligne peut être répartie entre Neemba et un ou plusieurs clients : Neemba
                                    avance le montant (le bon de caisse porte le total), puis le refacture au client.
                                </p>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {erreursParChamp.repartition && (
                                    <ul className="space-y-1 rounded-md bg-red-50 p-2 text-sm text-red-700" role="alert">
                                        {erreursParChamp.repartition.map((m, i) => <li key={i}>{m}</li>)}
                                    </ul>
                                )}
                                <RepartitionFrais
                                    participants={form.participants}
                                    calcul={calcul}
                                    repartitions={form.repartitions ?? {}}
                                    onChange={(repartitions) => changer('repartitions', repartitions)}
                                    ordres={form.ordres_reparation}
                                    clientsOdm={form.clients}
                                    codes={codes}
                                    codeOdm={form.code_analytique}
                                    enCours={enregistrement === 'en_cours'}
                                />
                            </CardContent>
                        </Card>
                    </div>

                    {/* Calcul et actions */}
                    <aside className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                        <PanneauCalcul calcul={calcul} type={form.type} enCours={enregistrement === 'en_cours'} tauxEnVigueur={tauxEnVigueur} />
                        <div className="space-y-2 rounded-lg border bg-white p-4 shadow-sm">
                            <Button type="button" className="w-full" onClick={soumettre} disabled={soumission}>
                                {soumission ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                                {statut === 'REJETE' ? 'Soumettre à nouveau' : 'Soumettre pour validation'}
                            </Button>
                            <p className="text-xs text-gray-500">Circuit : chef d'atelier ou chef d'équipe, puis DAF, puis Directeur Pays.</p>
                            <Button type="button" variant="ghost" className="w-full text-red-600 hover:bg-red-50 hover:text-red-700" onClick={() => setAnnulation(true)}>
                                <Trash2 className="mr-2 h-4 w-4" /> {odmId ? 'Annuler cet ordre de mission' : 'Abandonner'}
                            </Button>
                        </div>
                    </aside>
                </div>
            </div>

            <Dialog open={annulation} onOpenChange={setAnnulation}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Annuler cet ordre de mission ?</DialogTitle>
                        <DialogDescription>L'ordre de mission passe au statut « Annulé ». Il reste consultable dans la liste.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setAnnulation(false)}>Revenir</Button>
                        <Button variant="destructive" onClick={annuler}>Annuler l'ODM</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
