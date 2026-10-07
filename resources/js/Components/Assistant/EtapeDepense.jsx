/**
 * Étape 3 « Dépense » (E-03.5, US-BC-05 à US-BC-07) : motif, catégorie, montant, mode de paiement,
 * caisse payeuse, véhicule / OR selon la catégorie, lien avec une mission pour un BP.
 */
import { useState } from 'react';
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

/** N° d'OR sous forme d'étiquettes « OR 11022219 » (RG-BC-13) */
function EtiquettesOr({ valeurs = [], onChange, erreur }) {
    const [saisie, setSaisie] = useState('');
    const [erreurSaisie, setErreurSaisie] = useState(null);

    const valider = () => {
        const { liste, erreur: cle } = ajouterOr(valeurs, saisie);
        setErreurSaisie(cle ? msg(cle) : null);
        if (!cle) {
            onChange(liste);
            setSaisie('');
        }
    };

    return (
        <Champ champ="references_or" libelle="N° d'OR" erreur={erreurSaisie ?? erreur} aide="8 chiffres ; Entrée pour ajouter. Plusieurs OR possibles.">
            <div className="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input px-2 py-1">
                {valeurs.map((numero) => (
                    <span key={numero} className="inline-flex items-center gap-1 rounded bg-marine-50 px-2 py-0.5 text-xs font-medium text-marine-800">
                        OR {numero}
                        <button type="button" onClick={() => onChange(valeurs.filter((v) => v !== numero))} aria-label={`Retirer l'OR ${numero}`}>
                            <X className="h-3 w-3" />
                        </button>
                    </span>
                ))}
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
                    className="min-w-[8rem] flex-1 border-0 p-1 text-sm focus:outline-none focus:ring-0"
                />
            </div>
        </Champ>
    );
}

export default function EtapeDepense({ donnees, changer, erreurs, categories, modesPaiement, caisses, seuilDP, dateDuJour }) {
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
        if (!nouveaux.or) changer('references_or', []);
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
                    maxLength={200}
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

            {(champs.vehicule || champs.or) && (
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
                    {champs.or && (
                        <EtiquettesOr valeurs={donnees.references_or ?? []} onChange={(liste) => changer('references_or', liste)} erreur={erreurs.references_or} />
                    )}
                </div>
            )}

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
