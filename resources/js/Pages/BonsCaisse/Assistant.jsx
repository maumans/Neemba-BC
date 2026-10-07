/**
 * Assistant « Nouveau bon de caisse » (SFD M03, E-03.2 à E-03.7).
 *
 * Une seule page pour la création, la reprise d'un brouillon (US-BC-11) et la correction après rejet (US-BC-14).
 * - Aucun bon n'est créé à l'ouverture : le brouillon naît au premier « Suivant » ou « Brouillon » (RG-BC-01).
 * - « Suivant » fait contrôler l'étape par le serveur puis enregistre ; « Brouillon » n'exige que des formats valides (RG-BC-25).
 * - L'étape 5 affiche les 12 contrôles du serveur ; la soumission les refait (RG-BC-24) et porte une clé d'idempotence (RG-BC-27).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { AlertOctagon, ArrowLeft, ArrowRight, Loader2, Save, Send } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { TooltipProvider } from '@/Components/ui/tooltip';
import BarreEtapes from '@/Components/Assistant/BarreEtapes';
import { placerCurseur } from '@/Components/Assistant/Champ';
import EtapeBeneficiaire from '@/Components/Assistant/EtapeBeneficiaire';
import EtapeControle from '@/Components/Assistant/EtapeControle';
import EtapeDepense from '@/Components/Assistant/EtapeDepense';
import EtapeIdentification from '@/Components/Assistant/EtapeIdentification';
import EtapePieces from '@/Components/Assistant/EtapePieces';
import Resume from '@/Components/Assistant/Resume';
import { ETAPES, erreursParChamp, etapeDuChamp, nouvelleCleIdempotence, premierChampEnErreur } from '@/utils/assistant';
import { formaterDate } from '@/utils/format';
import { msg, msgErreur } from '@/utils/messages';

const DESCRIPTIONS = {
    1: "Type de bon, imputation et niveau d'urgence",
    2: 'Qui reçoit les fonds',
    3: 'Motif, montant et mode de paiement',
    4: 'Factures, reçus, tickets carburant…',
    5: 'Vérifications avant la soumission',
};

const URGENCES = { normale: 'Normale', urgente: 'Urgente', tres_urgente: 'Très urgente' };

/** Valeurs de la saisie : celles du bon enregistré, sinon les valeurs par défaut (RG-BC-02) */
function valeursDe(bon, demandeur) {
    return {
        type_bon: bon?.type_bon ?? '',
        code_analytique: bon?.code_analytique ?? '',
        site: bon?.site ?? demandeur.site ?? '',
        service: bon?.service ?? demandeur.service ?? '',
        niveau_urgence: bon?.niveau_urgence ?? 'normale',
        motif_urgence: bon?.motif_urgence ?? '',
        justification_urgence: bon?.justification_urgence ?? '',
        type_beneficiaire: bon?.type_beneficiaire ?? 'employe',
        beneficiaire_id: bon ? bon.beneficiaire_id : demandeur.id,
        beneficiaire: bon?.beneficiaire ?? demandeur.nom_complet,
        telephone_beneficiaire: (bon ? bon.telephone_beneficiaire : demandeur.telephone) ?? '',
        motif: bon?.motif ?? '',
        categorie_depense: bon?.categorie_depense ?? '',
        montant: bon?.montant ? String(bon.montant) : '',
        mode_paiement: bon?.mode_paiement ?? 'especes',
        vehicule: bon?.vehicule ?? '',
        references_or: bon?.references_or ?? [],
        lie_mission: Boolean(bon?.lie_mission),
        date_retour_mission: bon?.date_retour_mission ?? '',
    };
}

/** Employé bénéficiaire affiché à l'étape 2 */
function employeDe(bon, demandeur) {
    if (!bon) return demandeur;
    if (bon.type_beneficiaire !== 'employe' || !bon.beneficiaire_id) return null;

    return {
        id: bon.beneficiaire_id,
        nom_complet: bon.beneficiaire,
        libelle: bon.beneficiaire_libelle ?? bon.beneficiaire,
        telephone: bon.telephone_beneficiaire,
    };
}

/** Saisie envoyée au serveur : un champ vide devient null */
function corpsDe(donnees) {
    return Object.fromEntries(Object.entries(donnees).map(([champ, valeur]) => [champ, valeur === '' ? null : valeur]));
}

/** Erreurs de champ d'une réponse 422 (validation Laravel ou erreur métier §5.7) ; toute autre erreur est relancée */
function erreursDe(erreur) {
    const reponse = erreur.response;
    if (reponse?.status !== 422) throw erreur;
    if (reponse.data?.errors) return erreursParChamp(reponse.data.errors);

    return { [reponse.data?.champ ?? 'general']: msgErreur(reponse.data) };
}

function signaler(erreur) {
    const reponse = erreur.response;
    if (reponse?.status === 403) {
        toast.error(msg('MSG-APP-002'));
    } else if (reponse?.data?.message_cle || reponse?.data?.message) {
        toast.error(msgErreur(reponse.data));
    } else {
        toast.error("L'enregistrement a échoué. Vérifiez votre connexion puis réessayez.");
    }
}

export default function Assistant({
    bon: bonInitial,
    etapeInitiale = 1,
    demandeur,
    dateDuJour,
    sites = [],
    services = [],
    codesAnalytiques = [],
    categories = [],
    motifsUrgence = [],
    typesBeneficiaire = {},
    modesPaiement = {},
    typesPiece = {},
    seuilDP,
}) {
    const [bon, setBon] = useState(bonInitial);
    const [donnees, setDonnees] = useState(() => valeursDe(bonInitial, demandeur));
    const [employeChoisi, setEmployeChoisi] = useState(() => employeDe(bonInitial, demandeur));
    const [etape, setEtape] = useState(etapeInitiale);
    const [visitees, setVisitees] = useState(() => new Set(Array.from({ length: etapeInitiale }, (_, i) => i + 1)));
    const [etats, setEtats] = useState(() =>
        Object.fromEntries(Array.from({ length: etapeInitiale - 1 }, (_, i) => [i + 1, 'complete'])),
    );
    const [erreurs, setErreurs] = useState({});
    const [modifie, setModifie] = useState(false);
    const [action, setAction] = useState(null);
    const [controles, setControles] = useState(null);
    const [chargementControles, setChargementControles] = useState(false);
    const [caisses, setCaisses] = useState({ payeuse: bonInitial?.caisse ?? null, especes: null });
    const [confirmation, setConfirmation] = useState(false);
    const [sortie, setSortie] = useState(null);

    /* Valeurs lues par les fonctions asynchrones (toujours la dernière version) */
    const bonActuel = useRef(bonInitial);
    const revision = useRef(0);
    const sortieAutorisee = useRef(false);
    const cleSoumission = useRef(nouvelleCleIdempotence());

    const occupe = action !== null;
    const rejete = bon?.statut === 'REJETE';

    /* ------------------------------------------------------------------
     * Saisie
     * ------------------------------------------------------------------ */

    const changer = useCallback((champ, valeur) => {
        revision.current += 1;
        setDonnees((actuelles) => ({ ...actuelles, [champ]: valeur }));
        setModifie(true);
        setErreurs((actuelles) => {
            if (!actuelles[champ]) return actuelles;
            const { [champ]: _retiree, ...autres } = actuelles;
            return autres;
        });
    }, []);

    /* RG-BC-06 : nom et téléphone de l'employé repris du référentiel */
    const choisirEmploye = useCallback(
        (employe) => {
            setEmployeChoisi(employe);
            changer('beneficiaire_id', employe?.id ?? null);
            changer('beneficiaire', employe?.nom_complet ?? '');
            changer('telephone_beneficiaire', employe?.telephone ?? '');
        },
        [changer],
    );

    const mettreAJourBon = (nouveau) => {
        bonActuel.current = nouveau;
        setBon(nouveau);
    };

    /**
     * Enregistre la saisie (création du brouillon la première fois). `etapeControlee` : l'étape quittée par « Suivant »,
     * dont le serveur vérifie la complétude. Renvoie les erreurs par champ (vide si tout va bien).
     */
    const enregistrer = async (etapeControlee = null) => {
        const revisionEnvoyee = revision.current;
        const corps = { ...corpsDe(donnees), ...(etapeControlee ? { etape: etapeControlee } : {}) };
        const existant = bonActuel.current;

        try {
            const { data } = existant
                ? await axios.patch(route('api.bons.enregistrer', existant.id), corps)
                : await axios.post(route('api.bons.creer'), corps);

            if (!existant) {
                /* L'adresse devient celle du brouillon : un rechargement ou un retour arrière le rouvre (US-BC-11) */
                router.replace({
                    url: route('bons-caisse.edit', data.bon.id),
                    props: (props) => ({ ...props, bon: data.bon }),
                    preserveState: true,
                    preserveScroll: true,
                });
            }
            mettreAJourBon(data.bon);
            /* Valeurs normalisées par le serveur, sauf si l'utilisateur a saisi pendant l'enregistrement */
            if (revision.current === revisionEnvoyee) {
                setDonnees(valeursDe(data.bon, demandeur));
                setModifie(false);
            }

            return erreursParChamp(data.erreurs ?? {});
        } catch (erreur) {
            return erreursDe(erreur);
        }
    };

    /* ------------------------------------------------------------------
     * Navigation
     * ------------------------------------------------------------------ */

    const ouvrir = (numero) => {
        setEtape(numero);
        setVisitees((actuelles) => new Set(actuelles).add(numero));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /** Erreurs affichées sur leurs champs ; l'étape concernée passe en rouge et le curseur va au premier champ */
    const montrerErreurs = (liste, etapeCourante) => {
        if (liste.general) {
            toast.error(liste.general);
        }
        setErreurs(liste);
        const etapes = [...new Set(Object.keys(liste).map(etapeDuChamp).filter(Boolean))];
        if (etapes.length === 0) return;

        setEtats((actuels) => ({ ...actuels, ...Object.fromEntries(etapes.map((n) => [n, 'erreur'])) }));
        const cible = etapes.includes(etapeCourante) ? etapeCourante : Math.min(...etapes);
        if (cible !== etapeCourante) ouvrir(cible);
        const champ = premierChampEnErreur(liste, cible);
        if (champ) setTimeout(() => placerCurseur(champ), 100);
    };

    const suivant = async () => {
        setAction('suivant');
        try {
            const liste = await enregistrer(etape);
            if (Object.keys(liste).length > 0) {
                montrerErreurs(liste, etape);
                return;
            }
            setErreurs({});
            setEtats((actuels) => ({ ...actuels, [etape]: 'complete' }));
            ouvrir(etape + 1);
        } catch (erreur) {
            signaler(erreur);
        } finally {
            setAction(null);
        }
    };

    /** « Précédent » et clic sur une étape visitée : pas de contrôle, la saisie est seulement enregistrée */
    const allerA = async (numero) => {
        if (modifie && (bonActuel.current || numero > etape)) {
            setAction('navigation');
            try {
                const liste = await enregistrer(null);
                if (Object.keys(liste).length > 0) {
                    montrerErreurs(liste, etape);
                    return;
                }
            } catch (erreur) {
                signaler(erreur);
                return;
            } finally {
                setAction(null);
            }
        }
        setErreurs({});
        ouvrir(numero);
    };

    const brouillon = async () => {
        setAction('brouillon');
        try {
            const liste = await enregistrer(null);
            if (Object.keys(liste).length > 0) {
                montrerErreurs(liste, etape);
                return false;
            }
            toast.success(msg('MSG-BC-030'), { duration: 3000 });
            return true;
        } catch (erreur) {
            signaler(erreur);
            return false;
        } finally {
            setAction(null);
        }
    };

    /* ------------------------------------------------------------------
     * Caisse payeuse et plafond (RG-BC-11, RG-BC-12)
     * ------------------------------------------------------------------ */

    useEffect(() => {
        const { site, mode_paiement: mode } = donnees;
        if (!site) {
            setCaisses({ payeuse: null, especes: null });
            return undefined;
        }
        let actif = true;
        const url = route('api.referentiels.caisse-payeuse');
        Promise.all([
            axios.get(url, { params: { site, mode: 'especes' } }),
            mode && mode !== 'especes' ? axios.get(url, { params: { site, mode } }) : Promise.resolve(null),
        ])
            .then(([especes, autre]) => {
                if (!actif) return;
                const caisseEspeces = especes.data.caisse;
                setCaisses({ especes: caisseEspeces, payeuse: mode === 'especes' ? caisseEspeces : (autre?.data.caisse ?? null) });
            })
            .catch(() => {});

        return () => {
            actif = false;
        };
    }, [donnees.site, donnees.mode_paiement]);

    /* ------------------------------------------------------------------
     * Pièces (étape 4) : envoyées dès le dépôt
     * ------------------------------------------------------------------ */

    const changerPieces = (transformer) => {
        mettreAJourBon({ ...bonActuel.current, pieces: transformer(bonActuel.current?.pieces ?? []) });
        setErreurs((actuelles) => {
            const { pieces: _retiree, ...autres } = actuelles;
            return autres;
        });
    };

    const envoyerPiece = async (fichier, type, progression) => {
        if (!bonActuel.current) {
            const liste = await enregistrer(null);
            if (Object.keys(liste).length > 0) {
                montrerErreurs(liste, etape);
                throw new Error('corrigez d\'abord la saisie.');
            }
        }
        const formulaire = new FormData();
        formulaire.append('fichier', fichier);
        if (type) formulaire.append('type_document', type);

        try {
            const { data } = await axios.post(route('api.bons.pieces.ajouter', bonActuel.current.id), formulaire, {
                onUploadProgress: (e) => e.total && progression(Math.round((e.loaded * 100) / e.total)),
            });
            changerPieces((pieces) => [...pieces, data.piece]);
            return data.piece;
        } catch (erreur) {
            const donneesErreur = erreur.response?.data;
            throw new Error(donneesErreur?.errors?.fichier?.[0] ?? (msgErreur(donneesErreur) || "l'envoi a échoué."));
        }
    };

    const typerPiece = async (piece, type) => {
        try {
            const { data } = await axios.patch(route('api.bons.pieces.typer', [bonActuel.current.id, piece.id]), { type_document: type });
            changerPieces((pieces) => pieces.map((p) => (p.id === piece.id ? data.piece : p)));
        } catch (erreur) {
            signaler(erreur);
        }
    };

    const supprimerPiece = async (piece) => {
        try {
            await axios.delete(route('api.bons.pieces.supprimer', [bonActuel.current.id, piece.id]));
            changerPieces((pieces) => pieces.filter((p) => p.id !== piece.id));
        } catch (erreur) {
            signaler(erreur);
        }
    };

    /* ------------------------------------------------------------------
     * Contrôle (étape 5) et soumission
     * ------------------------------------------------------------------ */

    const chargerControles = useCallback(async () => {
        if (!bonActuel.current) return;
        setChargementControles(true);
        try {
            const { data } = await axios.get(route('api.bons.controles', bonActuel.current.id));
            setControles(data);
        } catch (erreur) {
            signaler(erreur);
        } finally {
            setChargementControles(false);
        }
    }, []);

    useEffect(() => {
        if (etape === 5) chargerControles();
    }, [etape, chargerControles]);

    const corriger = (numero, champ) => {
        ouvrir(numero);
        if (champ) setTimeout(() => placerCurseur(champ), 150);
    };

    const soumettre = async () => {
        setAction('soumettre');
        try {
            const { data } = await axios.post(
                route('api.bons.soumettre', bonActuel.current.id),
                {},
                { headers: { 'Idempotency-Key': cleSoumission.current } },
            );
            /* MSG-BC-032 s'affiche sur la fiche du bon */
            sortieAutorisee.current = true;
            router.visit(route('bons-caisse.show', data.id));
        } catch (erreur) {
            const reponse = erreur.response;
            setConfirmation(false);
            if (reponse?.status === 422 && reponse.data?.controles) {
                /* RG-BC-24 : un contrôle refait par le serveur a échoué (ex. plafond modifié entre-temps) */
                setControles((actuels) => ({ ...(actuels ?? {}), controles: reponse.data.controles, soumission_possible: false }));
                toast.error(msgErreur(reponse.data));
            } else if (reponse?.status === 409) {
                toast.error(msgErreur(reponse.data));
                sortieAutorisee.current = true;
                router.visit(route('bons-caisse.show', bonActuel.current.id));
            } else {
                signaler(erreur);
            }
        } finally {
            setAction(null);
        }
    };

    /* ------------------------------------------------------------------
     * Quitter avec des modifications non enregistrées (MSG-BC-035)
     * ------------------------------------------------------------------ */

    useEffect(() => {
        if (!modifie) return undefined;

        const avantDechargement = (e) => {
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', avantDechargement);
        const retirer = router.on('before', (evenement) => {
            const visite = evenement.detail.visit;
            /* Seule une vraie navigation est retenue (ni rechargement partiel ni préchargement) */
            if (sortieAutorisee.current || visite.method !== 'get' || visite.only?.length || visite.prefetch) return;
            evenement.preventDefault();
            setSortie(visite.url.href ?? String(visite.url));
        });

        return () => {
            window.removeEventListener('beforeunload', avantDechargement);
            retirer();
        };
    }, [modifie]);

    const quitter = () => {
        const destination = sortie;
        sortieAutorisee.current = true;
        setSortie(null);
        router.visit(destination);
    };

    const enregistrerPuisQuitter = async () => {
        const destination = sortie;
        setSortie(null);
        if (await brouillon()) {
            sortieAutorisee.current = true;
            router.visit(destination);
        }
    };

    /* ------------------------------------------------------------------ */

    const resume = {
        numero: bon?.numero,
        type: donnees.type_bon,
        site: donnees.site,
        caisse: caisses.payeuse?.libelle ?? (['cheque', 'virement'].includes(donnees.mode_paiement) ? 'Aucune (hors caisse)' : null),
        service: donnees.service,
        beneficiaire: donnees.type_beneficiaire === 'employe' ? employeChoisi?.nom_complet : donnees.beneficiaire,
        categorie: categories.find((c) => c.code === donnees.categorie_depense)?.libelle,
        montant: donnees.montant ? Number(donnees.montant) : null,
        mode: modesPaiement[donnees.mode_paiement],
        urgence: URGENCES[donnees.niveau_urgence],
        fichiers: bon?.pieces?.length ?? 0,
    };

    const titre = rejete ? `Corriger et resoumettre le bon ${bon.numero}` : bonInitial ? 'Reprendre le brouillon' : 'Nouveau bon de caisse';
    const soumissionPossible = etape === 5 && controles?.soumission_possible && !modifie && !chargementControles;

    return (
        <AuthenticatedLayout header={titre}>
            <Head title={titre} />
            <TooltipProvider delayDuration={150}>
                <div className="pb-20 lg:pb-0">
                    <div className="mb-4">
                        <Link href={route('bons-caisse.index')} className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700">
                            <ArrowLeft className="mr-1 h-4 w-4" />
                            Retour à la liste
                        </Link>
                    </div>

                    {rejete && (
                        <div className="mb-6 flex items-start gap-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                            <AlertOctagon className="mt-0.5 h-5 w-5 shrink-0" />
                            <div>
                                <p className="font-semibold">Bon rejeté — corrigez puis soumettez à nouveau (version {Number(bon.version ?? 1) + 1}).</p>
                                {bon.commentaire_rejet && <p className="mt-1">{bon.commentaire_rejet}</p>}
                            </div>
                        </div>
                    )}

                    <BarreEtapes etape={etape} etats={etats} visitees={visitees} onChoisir={allerA} desactivee={occupe} />

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Étape {etape} — {ETAPES[etape - 1].libelle}
                                    </CardTitle>
                                    <CardDescription>
                                        {DESCRIPTIONS[etape]}
                                        {etape === 1 && (
                                            <span className="block pt-1">
                                                Date : {formaterDate(dateDuJour)} · Demandeur : {demandeur.nom_complet}
                                            </span>
                                        )}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {etape === 1 && (
                                        <EtapeIdentification
                                            donnees={donnees}
                                            changer={changer}
                                            erreurs={erreurs}
                                            sites={sites}
                                            services={services}
                                            codesAnalytiques={codesAnalytiques}
                                            motifsUrgence={motifsUrgence}
                                        />
                                    )}
                                    {etape === 2 && (
                                        <EtapeBeneficiaire
                                            donnees={donnees}
                                            changer={changer}
                                            erreurs={erreurs}
                                            typesBeneficiaire={typesBeneficiaire}
                                            employeChoisi={employeChoisi}
                                            choisirEmploye={choisirEmploye}
                                            demandeur={demandeur}
                                        />
                                    )}
                                    {etape === 3 && (
                                        <EtapeDepense
                                            donnees={donnees}
                                            changer={changer}
                                            erreurs={erreurs}
                                            categories={categories}
                                            modesPaiement={modesPaiement}
                                            caisses={caisses}
                                            seuilDP={seuilDP}
                                            dateDuJour={dateDuJour}
                                        />
                                    )}
                                    {etape === 4 && (
                                        <EtapePieces
                                            donnees={donnees}
                                            pieces={bon?.pieces ?? []}
                                            typesPiece={typesPiece}
                                            erreurs={erreurs}
                                            envoyer={envoyerPiece}
                                            typer={typerPiece}
                                            supprimer={supprimerPiece}
                                            desactive={occupe}
                                        />
                                    )}
                                    {etape === 5 && (
                                        <EtapeControle
                                            resultat={controles}
                                            chargement={chargementControles}
                                            onCorriger={corriger}
                                            onRelancer={chargerControles}
                                        />
                                    )}
                                </CardContent>
                            </Card>

                            {/* Actions */}
                            <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
                                <Button type="button" variant="outline" onClick={() => allerA(etape - 1)} disabled={etape === 1 || occupe}>
                                    <ArrowLeft className="mr-1 h-4 w-4" /> Précédent
                                </Button>
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" variant="outline" onClick={brouillon} disabled={occupe}>
                                        {action === 'brouillon' ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <Save className="mr-1 h-4 w-4" />}
                                        Brouillon
                                    </Button>
                                    {etape < 5 ? (
                                        <Button type="button" onClick={suivant} disabled={occupe}>
                                            {action === 'suivant' && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}
                                            Suivant <ArrowRight className="ml-1 h-4 w-4" />
                                        </Button>
                                    ) : (
                                        <Button type="button" onClick={() => setConfirmation(true)} disabled={!soumissionPossible || occupe}>
                                            <Send className="mr-1 h-4 w-4" /> Soumettre
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>

                        <aside>
                            <Resume resume={resume} />
                        </aside>
                    </div>
                </div>

                {/* MSG-BC-031 : confirmation de la soumission */}
                <Dialog open={confirmation} onOpenChange={(ouvert) => !occupe && setConfirmation(ouvert)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Soumettre le bon</DialogTitle>
                            <DialogDescription>
                                {msg('MSG-BC-031', { montant: Number(donnees.montant || 0), beneficiaire: resume.beneficiaire ?? '' })}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setConfirmation(false)} disabled={occupe}>
                                Annuler
                            </Button>
                            <Button type="button" onClick={soumettre} disabled={occupe}>
                                {action === 'soumettre' && <Loader2 className="mr-1 h-4 w-4 animate-spin" />}
                                Soumettre
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                {/* MSG-BC-035 : quitter avec des modifications non enregistrées */}
                <Dialog open={sortie !== null} onOpenChange={(ouvert) => !ouvert && setSortie(null)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{msg('MSG-BC-035')}</DialogTitle>
                            <DialogDescription>Vos dernières modifications ne sont pas encore enregistrées.</DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="gap-2 sm:gap-0">
                            <Button type="button" variant="ghost" onClick={() => setSortie(null)}>
                                Annuler
                            </Button>
                            <Button type="button" variant="outline" onClick={quitter}>
                                Quitter sans enregistrer
                            </Button>
                            <Button type="button" onClick={enregistrerPuisQuitter}>
                                Enregistrer
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </TooltipProvider>
        </AuthenticatedLayout>
    );
}
