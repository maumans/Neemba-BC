/**
 * Étape 3 « Dépense » (E-03.5, US-BC-05 à US-BC-07) : motif, catégorie, montant, mode de paiement,
 * caisse payeuse, véhicule selon la catégorie, OR, lien avec une mission pour un BP.
 * Spec v2.3 : motif de 10 à 250 caractères (RG-M03-05) ; OR de 8 chiffres commençant par 110, type vente ou garantie
 * (RG-M03-13) ; Orange Money : estimation des frais (RG-M03-09) et n° OM repris du référentiel, motif s'il est modifié
 * (RG-M03-10).
 */
import { useEffect, useState } from 'react';
import axios from 'axios';
import TelephoneInput from '@/Components/TelephoneInput';
import { Info, X } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import MontantInput from '@/Components/MontantInput';
import { cn } from '@/lib/utils';
import {
    ajouterOr,
    champsDeLaCategorie,
    compteurMotif,
    dateMoinsJours,
    especesAutorisees,
    libelleDansUnePhrase,
    visaDpRequis,
} from '@/utils/assistant';
import { formaterMontant } from '@/utils/format';
import { msg } from '@/utils/messages';
import { nombreEnLettres } from '@/utils/nombreEnLettres';
import Champ from './Champ';

/**
 * N° d'OR sous forme d'étiquettes « OR 11022219 · Vente » (RG-M03-13) : le type se choisit avant d'ajouter, se change d'un
 * clic (type suivant de la liste). Spec v2.4, §6.5 : types servis par le référentiel des types d'OR.
 */
function EtiquettesOr({ valeurs = [], types = {}, typesOr = {}, onChange, erreur }) {
    const codes = Object.keys(typesOr);
    const [saisie, setSaisie] = useState('');
    const [type, setType] = useState(codes[0] ?? '');
    const suivant = (code) => codes[(codes.indexOf(code) + 1) % codes.length] ?? code;
    const [erreurSaisie, setErreurSaisie] = useState(null);

    const valider = () => {
        const numero = saisie.replace(/\s/g, '');
        const { liste, erreur: cle } = ajouterOr(valeurs, saisie);
        setErreurSaisie(cle ? msg(cle) : null);
        if (!cle) {
            onChange(liste, numero ? { ...types, [numero]: types[numero] ?? type } : types);
            setSaisie('');
        }
    };
    const retirer = (numero) => {
        const { [numero]: _retire, ...reste } = types;
        onChange(valeurs.filter((v) => v !== numero), reste);
    };

    return (
        <Champ champ="references_or" libelle="N° d'OR (facultatif)" erreur={erreurSaisie ?? erreur}
            aide="8 chiffres commençant par 110 ; choisissez le type puis Entrée. Cliquez sur le type d'un OR pour le changer.">
            <div className="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input px-2 py-1">
                {valeurs.map((numero) => (
                    <span key={numero} className="inline-flex items-center gap-1 rounded bg-marine-50 px-2 py-0.5 text-xs font-medium text-marine-800">
                        OR {numero}
                        <button type="button" className="rounded bg-white px-1 text-[11px] text-marine-700 ring-1 ring-marine-200 hover:bg-marine-100"
                            onClick={() => onChange(valeurs, { ...types, [numero]: suivant(types[numero]) })}
                            aria-label={`Type de l'OR ${numero} : ${typesOr[types[numero]] ?? 'à choisir'}`}>
                            {typesOr[types[numero]] ?? 'type ?'}
                        </button>
                        <button type="button" onClick={() => retirer(numero)} aria-label={`Retirer l'OR ${numero}`}>
                            <X className="h-3 w-3" />
                        </button>
                    </span>
                ))}
                <select value={type} onChange={(e) => setType(e.target.value)} aria-label="Type du prochain OR"
                    className="h-7 rounded border border-gray-200 bg-white px-1 text-xs">
                    {Object.entries(typesOr).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <input
                    id="champ-references_or"
                    value={saisie}
                    inputMode="numeric"
                    maxLength={9}
                    onChange={(e) => {
                        setSaisie(e.target.value.replace(/[^\d\s]/g, ''));
                        setErreurSaisie(null);
                    }}
                    onKeyDown={(e) => {
                        if (['Enter', ',', ';', ' '].includes(e.key)) {
                            e.preventDefault();
                            valider();
                        }
                    }}
                    onBlur={valider}
                    placeholder={valeurs.length ? '' : 'Ex. 11022219'}
                    aria-label="N° d'OR"
                    className="min-w-[8rem] flex-1 border-0 p-1 text-sm focus:outline-none focus:ring-0"
                />
            </div>
        </Champ>
    );
}

/** RG-M03-09 : estimation des frais Orange Money, pour information (calcul du serveur, paliers paramétrés) */
function EstimationFraisOm({ montant }) {
    const [estimation, setEstimation] = useState(null);
    useEffect(() => {
        if (!montant) {
            setEstimation(null);
            return undefined;
        }
        let actif = true;
        const minuterie = setTimeout(() => {
            axios.get(route('api.referentiels.frais-om'), { params: { montant } })
                .then(({ data }) => actif && setEstimation(data.estimation))
                .catch(() => actif && setEstimation(null));
        }, 300);

        return () => {
            actif = false;
            clearTimeout(minuterie);
        };
    }, [montant]);

    return estimation ? (
        <p className="flex items-start gap-2 rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-800" data-testid="estimation-frais-om">
            <Info className="mt-0.5 h-4 w-4 shrink-0" /> {estimation.message}
        </p>
    ) : null;
}

export default function EtapeDepense({ donnees, changer, erreurs, categories, modesPaiement, caisses, seuilDP, dateDuJour, numeroOmReferentiel = null, typesOr = {} }) {
    const orangeMoney = donnees.mode_paiement === 'orange_money';
    const chiffresOm = (valeur) => String(valeur ?? '').replace(/\D/g, '').slice(-9);
    const omModifie = orangeMoney && numeroOmReferentiel && donnees.numero_om && chiffresOm(donnees.numero_om) !== chiffresOm(numeroOmReferentiel);

    /* RG-M03-10 : n° OM pré-rempli depuis le référentiel du bénéficiaire */
    useEffect(() => {
        if (orangeMoney && !donnees.numero_om && numeroOmReferentiel) changer('numero_om', numeroOmReferentiel);
    }, [orangeMoney, numeroOmReferentiel]);   // eslint-disable-line react-hooks/exhaustive-deps

    const categorie = categories.find((c) => c.code === donnees.categorie_depense) ?? null;
    const champs = champsDeLaCategorie(categorie);
    const caisseEspeces = caisses.especes;
    const plafond = caisseEspeces?.plafond_retrait ?? null;
    const especesPossibles = especesAutorisees(donnees.montant, plafond);
    const messagePlafond = caisseEspeces && plafond !== null
        ? msg('MSG-BC-012', { plafond: Number(plafond), caisse: libelleDansUnePhrase(caisseEspeces.libelle) })
        : null;
    const lettres = donnees.montant ? nombreEnLettres(Number(donnees.montant)) : '';

    /* Les champs masqués par la nouvelle catégorie sont vidés */
    const changerCategorie = (code) => {
        const nouveaux = champsDeLaCategorie(categories.find((c) => c.code === code));
        changer('categorie_depense', code);
        if (!nouveaux.vehicule) changer('vehicule', '');
    };

    return (
        <div className="space-y-5">
            {/* ANO-05 : ligne d'information dynamique */}
            <p className="flex items-start gap-2 rounded-md bg-marine-50 px-3 py-2 text-sm text-marine-800">
                <Info className="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Caisse payeuse : {caisses.payeuse?.libelle ?? (['cheque', 'virement'].includes(donnees.mode_paiement) ? 'aucune (paiement hors caisse)' : '—')}
                    {' · '}Plafond de retrait en espèces : {plafond !== null ? formaterMontant(plafond) : 'aucun'}
                    {' · '}Visa du Directeur Pays au-delà de {formaterMontant(seuilDP)}
                </span>
            </p>

            <Champ champ="motif" libelle="Motif de la demande" obligatoire erreur={erreurs.motif} aide={`${compteurMotif(donnees.motif ?? '')} — ce texte est le libellé du rapport journalier.`}>
                <Textarea
                    id="champ-motif"
                    rows={4}
                    maxLength={250}
                    value={donnees.motif ?? ''}
                    onChange={(e) => changer('motif', e.target.value)}
                    placeholder="Ex. Achat de gasoil pour le groupe électrogène de l'atelier"
                />
            </Champ>

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <Champ champ="categorie_depense" libelle="Catégorie de dépense" obligatoire erreur={erreurs.categorie_depense}>
                    <Select value={donnees.categorie_depense || undefined} onValueChange={changerCategorie}>
                        <SelectTrigger id="champ-categorie_depense">
                            <SelectValue placeholder="Choisir la catégorie" />
                        </SelectTrigger>
                        <SelectContent>
                            {categories.map((c) => (
                                <SelectItem key={c.code} value={c.code}>{c.libelle}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Champ>

                <Champ champ="montant" id="champ-montant" libelle="Montant" obligatoire erreur={erreurs.montant}>
                    <MontantInput id="champ-montant" value={donnees.montant} onChange={(valeur) => changer('montant', valeur)} max={999999999} />
                </Champ>
            </div>

            <div>
                <p className="text-sm font-medium text-gray-700">Montant en lettres</p>
                <p className="mt-1 min-h-9 rounded-md border border-dashed border-gray-200 bg-gray-50 px-3 py-2 text-sm italic text-gray-700" aria-live="polite">
                    {lettres || '—'}
                </p>
            </div>

            {visaDpRequis(donnees.montant, seuilDP) && (
                <p className="rounded-md bg-orange-50 px-3 py-2 text-sm text-orange-800">{msg('MSG-BC-020', { seuil: Number(seuilDP) })}</p>
            )}

            <Champ
                champ="mode_paiement"
                libelle="Mode de paiement"
                obligatoire
                erreur={erreurs.mode_paiement ?? (donnees.mode_paiement === 'especes' && !especesPossibles ? messagePlafond : null)}
            >
                <div role="radiogroup" className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {Object.entries(modesPaiement).map(([valeur, libelle]) => {
                        const choisi = donnees.mode_paiement === valeur;
                        const interdit = valeur === 'especes' && !especesPossibles;
                        const bouton = (
                            <button
                                type="button"
                                role="radio"
                                aria-checked={choisi}
                                aria-disabled={interdit}
                                onClick={() => !interdit && changer('mode_paiement', valeur)}
                                className={cn(
                                    'w-full rounded-md border px-3 py-2 text-sm font-medium transition-colors',
                                    choisi && !interdit && 'border-neemba-400 bg-neemba-50 text-neemba-900',
                                    choisi && interdit && 'border-red-400 bg-red-50 text-red-700',
                                    !choisi && !interdit && 'border-gray-200 text-gray-600 hover:bg-gray-50',
                                    !choisi && interdit && 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400',
                                )}
                            >
                                {libelle}
                            </button>
                        );

                        return interdit ? (
                            <Tooltip key={valeur}>
                                <TooltipTrigger asChild>
                                    <span>{bouton}</span>
                                </TooltipTrigger>
                                <TooltipContent className="max-w-xs">{messagePlafond}</TooltipContent>
                            </Tooltip>
                        ) : (
                            <span key={valeur}>{bouton}</span>
                        );
                    })}
                </div>
            </Champ>

            {orangeMoney && (
                <div className="space-y-3">
                    <EstimationFraisOm montant={donnees.montant} />
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <Champ champ="numero_om" libelle="N° Orange Money" obligatoire erreur={erreurs.numero_om}
                            aide={numeroOmReferentiel ? 'Repris du référentiel du bénéficiaire.' : "Le bénéficiaire n'a pas de n° OM au référentiel : saisissez-le."}>
                            <TelephoneInput id="champ-numero_om" value={donnees.numero_om ?? ''} onChange={(valeur) => changer('numero_om', valeur)} placeholder="6XX XX XX XX" />
                        </Champ>
                        {omModifie && (
                            <Champ champ="motif_numero_om" libelle="Motif de la modification" obligatoire erreur={erreurs.motif_numero_om}
                                aide="Le n° diffère de celui du référentiel : la modification est tracée.">
                                <Input id="champ-motif_numero_om" value={donnees.motif_numero_om ?? ''} maxLength={255}
                                    onChange={(e) => changer('motif_numero_om', e.target.value)} placeholder="Ex. nouveau numéro communiqué par le bénéficiaire" />
                            </Champ>
                        )}
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    {champs.vehicule && (
                        <Champ
                            champ="vehicule"
                            libelle="Véhicule / matériel"
                            obligatoire={champs.vehiculeObligatoire}
                            erreur={erreurs.vehicule}
                            aide="Immatriculation (ex. BE 3424, AE-0395-02) ou n° de matériel (ex. 269715)."
                        >
                            <Input
                                id="champ-vehicule"
                                value={donnees.vehicule ?? ''}
                                maxLength={20}
                                onChange={(e) => changer('vehicule', e.target.value.toUpperCase())}
                                className="uppercase"
                            />
                        </Champ>
                    )}
                    <EtiquettesOr
                        valeurs={donnees.references_or ?? []}
                        types={donnees.types_or ?? {}}
                        typesOr={typesOr}
                        onChange={(liste, types) => {
                            changer('references_or', liste);
                            changer('types_or', types);
                        }}
                        erreur={erreurs.references_or ?? erreurs.types_or}
                    />
            </div>

            {donnees.type_bon === 'BP' && (
                <div className="space-y-4 rounded-md border border-gray-200 p-4">
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-neemba-600 focus:ring-neemba-400"
                            checked={Boolean(donnees.lie_mission)}
                            onChange={(e) => {
                                changer('lie_mission', e.target.checked);
                                if (!e.target.checked) changer('date_retour_mission', '');
                            }}
                        />
                        Lié à une mission
                    </label>
                    {donnees.lie_mission && (
                        <Champ
                            champ="date_retour_mission"
                            libelle="Date de retour de mission"
                            obligatoire
                            erreur={erreurs.date_retour_mission}
                            aide="Sert au calcul de la date limite de régularisation. Le choix d'un ordre de mission viendra avec le module Ordres de mission."
                            className="sm:max-w-xs"
                        >
                            <Input
                                id="champ-date_retour_mission"
                                type="date"
                                min={dateMoinsJours(dateDuJour, 30)}
                                value={donnees.date_retour_mission ?? ''}
                                onChange={(e) => changer('date_retour_mission', e.target.value)}
                            />
                        </Champ>
                    )}
                </div>
            )}
        </div>
    );
}
