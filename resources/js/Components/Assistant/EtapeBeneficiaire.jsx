/**
 * Étape 2 « Bénéficiaire » (E-03.4, US-BC-04 ; spec v2.3 US-02, US-03, RG-M03-03) : le bénéficiaire est choisi dans un
 * référentiel, sans saisie libre :
 * - salarié (utilisateurs actifs ; nom et téléphone du profil, RG-BC-06) ;
 * - visiteur du groupe (salarié d'une autre filiale) : bon définitif seulement, à titre exceptionnel, avec un motif de la
 *   liste fermée et un commentaire ; marqué « refacturation inter-filiale » (spec v2.4, RG-M03-04, RG-M03-28) ;
 * - tiers validé par la Comptabilité ou le CDG (RG-M02-06). S'il n'existe pas, le demandeur le propose : il devient
 *   sélectionnable une fois validé ; un tiers au même téléphone, n° OM ou NIF est refusé (RG-M02-07).
 */
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Building2, Info, Loader2, Plus, Search, User, Users, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import TelephoneInput from '@/Components/TelephoneInput';
import { cn } from '@/lib/utils';
import { msg, msgErreur } from '@/utils/messages';
import Champ from './Champ';

const TYPES = [
    { valeur: 'employe', libelle: 'Salarié', Icone: User, aide: 'Employé de Neemba Guinée' },
    { valeur: 'visiteur', libelle: 'Visiteur du groupe', Icone: Users, aide: "Salarié d'une autre filiale en mission" },
    { valeur: 'tiers', libelle: 'Tiers', Icone: Building2, aide: 'Fournisseur, prestataire, particulier' },
];

const TYPES_TIERS = { fournisseur: 'Fournisseur', prestataire: 'Prestataire', particulier: 'Particulier' };

/** Recherche dans un référentiel dès 2 caractères, 300 ms après la frappe */
function Recherche({ url, choisi, onChoisir, erreur, idChamp, placeholder, vide }) {
    const [recherche, setRecherche] = useState('');
    const [resultats, setResultats] = useState([]);
    const [chargement, setChargement] = useState(false);
    const [modeRecherche, setModeRecherche] = useState(!choisi);
    const requete = useRef(0);

    useEffect(() => {
        if (recherche.trim().length < 2) {
            setResultats([]);
            return undefined;
        }
        const numero = ++requete.current;
        const minuterie = setTimeout(async () => {
            setChargement(true);
            try {
                const { data } = await axios.get(url, { params: { q: recherche.trim() } });
                if (numero === requete.current) setResultats(data.resultats ?? []);
            } finally {
                if (numero === requete.current) setChargement(false);
            }
        }, 300);

        return () => clearTimeout(minuterie);
    }, [recherche, url]);

    useEffect(() => {
        if (!choisi) setModeRecherche(true);
    }, [choisi]);

    if (!modeRecherche && choisi) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
                <span className="truncate text-sm font-medium">{choisi.libelle}</span>
                <button type="button" onClick={() => setModeRecherche(true)} className="shrink-0 text-sm text-neemba-700 hover:underline">
                    Changer
                </button>
            </div>
        );
    }

    return (
        <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
            <Input
                id={idChamp}
                value={recherche}
                onChange={(e) => setRecherche(e.target.value)}
                placeholder={placeholder}
                className="pl-9 pr-9"
                autoComplete="off"
                aria-invalid={Boolean(erreur)}
            />
            {chargement && <Loader2 className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-gray-400" />}
            {!chargement && choisi && (
                <button type="button" onClick={() => setModeRecherche(false)} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                    aria-label="Garder le bénéficiaire choisi">
                    <X className="h-4 w-4" />
                </button>
            )}
            {recherche.trim().length >= 2 && !chargement && (
                <ul className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-white py-1 shadow-lg">
                    {resultats.length === 0 && <li className="px-3 py-2 text-sm text-gray-500">{vide}</li>}
                    {resultats.map((element) => (
                        <li key={element.id}>
                            <button
                                type="button"
                                disabled={element.selectionnable === false}
                                onClick={() => {
                                    onChoisir(element);
                                    setRecherche('');
                                    setModeRecherche(false);
                                }}
                                className="w-full px-3 py-2 text-left text-sm hover:bg-neemba-50 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-white"
                            >
                                {element.libelle}
                                {element.selectionnable === false && <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">en attente de validation</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** US-03 : proposer un tiers ; il est sélectionnable dès sa validation (aussitôt si l'auteur est lui-même valideur) */
function ProposerTiers({ onFermer, onPropose }) {
    const [donnees, setDonnees] = useState({ nom: '', type: 'fournisseur', telephone: '', numero_om: '', nif: '' });
    const [erreurs, setErreurs] = useState({});
    const [envoi, setEnvoi] = useState(false);
    const changer = (champ, valeur) => setDonnees((d) => ({ ...d, [champ]: valeur }));

    const envoyer = async (e) => {
        e.preventDefault();
        setEnvoi(true);
        setErreurs({});
        try {
            const { data } = await axios.post(route('api.tiers.proposer'), donnees);
            onPropose(data);
        } catch (erreur) {
            const reponse = erreur.response?.data;
            if (reponse?.errors) setErreurs(Object.fromEntries(Object.entries(reponse.errors).map(([c, m]) => [c, m[0]])));
            else setErreurs({ [reponse?.champ ?? 'general']: msgErreur(reponse) || 'La proposition a échoué.' });
        } finally {
            setEnvoi(false);
        }
    };

    return (
        <Dialog open onOpenChange={(ouvert) => !ouvert && onFermer()}>
            <DialogContent>
                <form onSubmit={envoyer}>
                    <DialogHeader>
                        <DialogTitle>Proposer un tiers</DialogTitle>
                        <DialogDescription>
                            La comptabilité ou le contrôle de gestion le valide avant qu'il puisse être choisi. Vous serez notifié.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-3 py-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <Label htmlFor="tiers-nom">Raison sociale ou nom *</Label>
                            <Input id="tiers-nom" value={donnees.nom} maxLength={150} autoFocus onChange={(e) => changer('nom', e.target.value)} />
                            {erreurs.nom && <p className="mt-1 text-sm text-red-600" role="alert">{erreurs.nom}</p>}
                        </div>
                        <div>
                            <Label htmlFor="tiers-type">Type *</Label>
                            <select id="tiers-type" value={donnees.type} onChange={(e) => changer('type', e.target.value)}
                                className="mt-1 h-10 w-full rounded-md border border-input bg-white px-3 text-sm">
                                {Object.entries(TYPES_TIERS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="tiers-nif">NIF (si connu)</Label>
                            <Input id="tiers-nif" value={donnees.nif} maxLength={40} onChange={(e) => changer('nif', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="tiers-telephone">Téléphone</Label>
                            <TelephoneInput id="tiers-telephone" value={donnees.telephone} onChange={(v) => changer('telephone', v)} placeholder="6XX XX XX XX" />
                            {erreurs.telephone && <p className="mt-1 text-sm text-red-600">{erreurs.telephone}</p>}
                        </div>
                        <div>
                            <Label htmlFor="tiers-om">N° Orange Money</Label>
                            <TelephoneInput id="tiers-om" value={donnees.numero_om} onChange={(v) => changer('numero_om', v)} placeholder="6XX XX XX XX" />
                            {erreurs.numero_om && <p className="mt-1 text-sm text-red-600">{erreurs.numero_om}</p>}
                        </div>
                        {erreurs.general && <p className="text-sm text-red-600 sm:col-span-2" role="alert">{erreurs.general}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onFermer} disabled={envoi}>Annuler</Button>
                        <Button type="submit" disabled={envoi}>{envoi && <Loader2 className="mr-1 h-4 w-4 animate-spin" />} Proposer</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function EtapeBeneficiaire({
    donnees, changer, erreurs, employeChoisi, choisirEmploye, demandeur, tiersChoisi, visiteurChoisi, choisirReferentiel, motifsVisiteur = [],
}) {
    const type = ['employe', 'visiteur', 'tiers'].includes(donnees.type_beneficiaire) ? donnees.type_beneficiaire : null;
    const [proposition, setProposition] = useState(false);
    const [info, setInfo] = useState(null);

    const changerType = (nouveau) => {
        if (nouveau === type) return;
        setInfo(null);
        changer('type_beneficiaire', nouveau);
        if (nouveau === 'employe') {
            /* RG-BC-02 : par défaut, le bénéficiaire est le demandeur */
            choisirEmploye(employeChoisi ?? demandeur);
            return;
        }
        changer('beneficiaire_id', null);
        choisirReferentiel(nouveau, nouveau === 'tiers' ? tiersChoisi : visiteurChoisi);
    };

    const propose = ({ tiers, message }) => {
        setProposition(false);
        setInfo(message);
        if (tiers.selectionnable) choisirReferentiel('tiers', tiers);
    };

    return (
        <div className="space-y-5">
            <Champ champ="type_beneficiaire" libelle="Type de bénéficiaire" obligatoire erreur={erreurs.type_beneficiaire}>
                <div role="radiogroup" aria-label="Type de bénéficiaire" className="grid gap-2 sm:grid-cols-3">
                    {TYPES.map(({ valeur, libelle, Icone, aide }) => (
                        <button
                            key={valeur}
                            type="button"
                            role="radio"
                            aria-checked={type === valeur}
                            onClick={() => changerType(valeur)}
                            className={cn(
                                'flex items-start gap-2 rounded-lg border px-3 py-2 text-left transition',
                                type === valeur ? 'border-neemba-400 bg-neemba-50 ring-1 ring-neemba-400' : 'border-gray-200 hover:border-gray-300',
                            )}
                        >
                            <Icone className={cn('mt-0.5 h-4 w-4 shrink-0', type === valeur ? 'text-neemba-700' : 'text-gray-400')} />
                            <span>
                                <span className="block text-sm font-medium">{libelle}</span>
                                <span className="block text-xs text-gray-500">{aide}</span>
                            </span>
                        </button>
                    ))}
                </div>
            </Champ>

            {type === 'employe' && (
                <>
                    <Champ champ="beneficiaire_id" libelle="Bénéficiaire" obligatoire erreur={erreurs.beneficiaire_id}>
                        <Recherche url={route('api.referentiels.beneficiaires')} choisi={employeChoisi} onChoisir={choisirEmploye} erreur={erreurs.beneficiaire_id}
                            idChamp="champ-beneficiaire_id" placeholder="Nom, prénom ou matricule (2 caractères minimum)" vide="Aucun salarié trouvé." />
                    </Champ>
                    <Champ champ="telephone_beneficiaire" libelle="Téléphone" erreur={erreurs.telephone_beneficiaire} aide="Repris du profil de l'employé, non modifiable." className="sm:max-w-sm">
                        <TelephoneInput id="champ-telephone_beneficiaire" value={donnees.telephone_beneficiaire ?? ''} onChange={() => {}} readOnly disabled placeholder="Aucun téléphone au profil" />
                    </Champ>
                </>
            )}

            {/* RG-M03-04 (v2.4) : un bon provisoire est réservé aux salariés */}
            {donnees.type_bon === 'BP' && (type === 'visiteur' || type === 'tiers') && (
                <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{msg('MSG-M03-02')}</p>
            )}

            {type === 'visiteur' && (
                <>
                    <Champ champ="visiteur_id" libelle="Visiteur du groupe" obligatoire erreur={erreurs.visiteur_id}
                        aide="Collaborateur d'une autre filiale : bon définitif seulement, à titre exceptionnel ; le bon est refacturé à sa filiale. Référentiel tenu par les RH.">
                        <Recherche url={route('api.referentiels.visiteurs')} choisi={visiteurChoisi} onChoisir={(v) => choisirReferentiel('visiteur', v)} erreur={erreurs.visiteur_id}
                            idChamp="champ-visiteur_id" placeholder="Nom, filiale ou matricule groupe" vide="Aucun visiteur actif trouvé : demandez aux RH de l'enregistrer." />
                    </Champ>
                    {/* RG-M03-28 : motif d'une liste fermée (PO-30) et commentaire obligatoire (MSG-M03-17) */}
                    <Champ champ="motif_visiteur" libelle="Motif du bon exceptionnel" obligatoire erreur={erreurs.motif_visiteur}
                        aide={motifsVisiteur.length === 0 ? 'Aucun motif n\'est encore paramétré : le DAF doit fournir la liste (Paramétrage → Motifs bon visiteur).' : 'Liste fixée par la direction financière.'}>
                        <select id="champ-motif_visiteur" value={donnees.motif_visiteur ?? ''} onChange={(e) => changer('motif_visiteur', e.target.value)}
                            className="h-10 w-full rounded-md border border-input bg-white px-3 text-sm">
                            <option value="">Choisir le motif</option>
                            {motifsVisiteur.map((m) => <option key={m} value={m}>{m}</option>)}
                        </select>
                    </Champ>
                    <Champ champ="commentaire_visiteur" libelle="Commentaire" obligatoire erreur={erreurs.commentaire_visiteur} aide="10 caractères au moins.">
                        <textarea id="champ-commentaire_visiteur" rows={3} maxLength={500} value={donnees.commentaire_visiteur ?? ''}
                            onChange={(e) => changer('commentaire_visiteur', e.target.value)}
                            className="w-full rounded-md border border-input bg-white px-3 py-2 text-sm"
                            placeholder="Pourquoi Neemba Guinée avance-t-elle cette dépense pour la filiale ?" />
                    </Champ>
                </>
            )}

            {type === 'tiers' && (
                <Champ champ="tiers_id" libelle="Tiers" obligatoire erreur={erreurs.tiers_id} aide="Seuls les tiers validés par la comptabilité ou le contrôle de gestion peuvent être choisis. Un tiers ne reçoit qu'un bon définitif.">
                    <div className="space-y-2">
                        <Recherche url={route('api.referentiels.tiers')} choisi={tiersChoisi} onChoisir={(t) => choisirReferentiel('tiers', t)} erreur={erreurs.tiers_id}
                            idChamp="champ-tiers_id" placeholder="Nom, téléphone, n° OM ou NIF" vide="Aucun tiers validé trouvé." />
                        <button type="button" onClick={() => setProposition(true)} className="inline-flex items-center text-sm font-medium text-neemba-700 hover:underline">
                            <Plus className="mr-1 h-4 w-4" /> Proposer un tiers
                        </button>
                    </div>
                </Champ>
            )}

            {info && (
                <p className="flex items-start gap-2 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-800" role="status">
                    <Info className="mt-0.5 h-4 w-4 shrink-0" /> {info}
                </p>
            )}

            {type && type !== 'employe' && (
                <Champ champ="telephone_beneficiaire" libelle="Téléphone" erreur={erreurs.telephone_beneficiaire}
                    aide="Repris du référentiel (n° Orange Money). Obligatoire pour un paiement en espèces : le bénéficiaire recevra le code de retrait." className="sm:max-w-sm">
                    <TelephoneInput id="champ-telephone_beneficiaire" value={donnees.telephone_beneficiaire ?? ''} onChange={(valeur) => changer('telephone_beneficiaire', valeur)} placeholder="6XX XX XX XX" />
                </Champ>
            )}

            {!type && donnees.beneficiaire && (
                <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Bénéficiaire saisi en texte libre (« {donnees.beneficiaire} ») : choisissez-le maintenant dans un référentiel.
                </p>
            )}

            {proposition && <ProposerTiers onFermer={() => setProposition(false)} onPropose={propose} />}
        </div>
    );
}
