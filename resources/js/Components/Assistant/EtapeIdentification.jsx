/**
 * Étape 1 « Identification » (E-03.3, US-BC-02, US-BC-03) : type, code analytique, site, service, urgence.
 */
import { Combobox } from '@/Components/ui/combobox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import { codesDuService } from '@/utils/assistant';
import Champ from './Champ';

const NIVEAUX_URGENCE = [
    { valeur: 'normale', libelle: 'Normale' },
    { valeur: 'urgente', libelle: 'Urgente' },
    { valeur: 'tres_urgente', libelle: 'Très urgente' },
];

export default function EtapeIdentification({ donnees, changer, erreurs, sites, services, codesAnalytiques, motifsUrgence }) {
    const service = services.find((s) => s.nom === donnees.service);
    const codes = codesDuService(codesAnalytiques, service?.id ?? null);
    const urgent = donnees.niveau_urgence !== 'normale';

    /* RG-BC-04 : un code rattaché à un autre service n'est plus proposé quand le service change */
    const changerService = (nom) => {
        const nouveau = services.find((s) => s.nom === nom);
        const code = codesAnalytiques.find((c) => c.code === donnees.code_analytique);
        changer('service', nom);
        if (code?.service_id && nouveau && code.service_id !== nouveau.id) {
            changer('code_analytique', '');
        }
    };

    /* Revenir à « Normale » efface le motif et la justification */
    const changerUrgence = (niveau) => {
        changer('niveau_urgence', niveau);
        if (niveau === 'normale') {
            changer('motif_urgence', '');
            changer('justification_urgence', '');
        }
    };

    return (
        <div className="space-y-5">
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <Champ champ="type_bon" libelle="Type de bon" obligatoire erreur={erreurs.type_bon}>
                    <Select value={donnees.type_bon || undefined} onValueChange={(valeur) => changer('type_bon', valeur)}>
                        <SelectTrigger id="champ-type_bon">
                            <SelectValue placeholder="Choisir le type de bon" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="BD">Bon Définitif (BD) — Facture existante</SelectItem>
                            <SelectItem value="BP">Bon Provisoire (BP) — À régulariser après la dépense</SelectItem>
                        </SelectContent>
                    </Select>
                </Champ>

                <Champ champ="code_analytique" libelle="Code analytique" obligatoire erreur={erreurs.code_analytique}>
                    <Combobox
                        options={codes.map((c) => ({ value: c.code, label: `${c.code} — ${c.libelle}` }))}
                        value={donnees.code_analytique}
                        onChange={(valeur) => changer('code_analytique', valeur)}
                        placeholder="Choisir le code analytique"
                        searchPlaceholder="Rechercher un code…"
                        error={Boolean(erreurs.code_analytique)}
                    />
                </Champ>

                <Champ champ="site" libelle="Site" obligatoire erreur={erreurs.site}>
                    <Select value={donnees.site || undefined} onValueChange={(valeur) => changer('site', valeur)}>
                        <SelectTrigger id="champ-site">
                            <SelectValue placeholder="Choisir le site" />
                        </SelectTrigger>
                        <SelectContent>
                            {sites.map((site) => (
                                <SelectItem key={site} value={site}>{site}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Champ>

                <Champ champ="service" libelle="Service" obligatoire erreur={erreurs.service}>
                    <Select value={donnees.service || undefined} onValueChange={changerService}>
                        <SelectTrigger id="champ-service">
                            <SelectValue placeholder="Choisir le service" />
                        </SelectTrigger>
                        <SelectContent>
                            {services.map((s) => (
                                <SelectItem key={s.id} value={s.nom}>{s.nom}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Champ>
            </div>

            <Champ champ="niveau_urgence" libelle="Niveau d'urgence" obligatoire erreur={erreurs.niveau_urgence}>
                <div role="radiogroup" className="grid grid-cols-3 gap-2">
                    {NIVEAUX_URGENCE.map(({ valeur, libelle }) => {
                        const choisi = donnees.niveau_urgence === valeur;
                        return (
                            <button
                                key={valeur}
                                type="button"
                                role="radio"
                                aria-checked={choisi}
                                onClick={() => changerUrgence(valeur)}
                                className={cn(
                                    'rounded-md border px-3 py-2 text-sm font-medium transition-colors',
                                    choisi && valeur === 'normale' && 'border-neemba-400 bg-neemba-50 text-neemba-900',
                                    choisi && valeur === 'urgente' && 'border-orange-400 bg-orange-50 text-orange-800',
                                    choisi && valeur === 'tres_urgente' && 'border-red-500 bg-red-50 text-red-700',
                                    !choisi && 'border-gray-200 text-gray-600 hover:bg-gray-50',
                                )}
                            >
                                {libelle}
                            </button>
                        );
                    })}
                </div>
            </Champ>

            {donnees.niveau_urgence === 'tres_urgente' && (
                <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                    Les valideurs seront prévenus par SMS et les délais de validation sont divisés par 2.
                </p>
            )}

            {urgent && (
                <div className="grid grid-cols-1 gap-5">
                    <Champ champ="motif_urgence" libelle="Motif d'urgence" obligatoire erreur={erreurs.motif_urgence}>
                        <Select value={donnees.motif_urgence || undefined} onValueChange={(valeur) => changer('motif_urgence', valeur)}>
                            <SelectTrigger id="champ-motif_urgence">
                                <SelectValue placeholder="Choisir le motif" />
                            </SelectTrigger>
                            <SelectContent>
                                {motifsUrgence.map((motif) => (
                                    <SelectItem key={motif} value={motif}>{motif}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Champ>

                    <Champ
                        champ="justification_urgence"
                        libelle="Justification"
                        obligatoire
                        erreur={erreurs.justification_urgence}
                        aide={`${(donnees.justification_urgence ?? '').trim().length}/300 — 10 caractères minimum`}
                    >
                        <Textarea
                            id="champ-justification_urgence"
                            rows={3}
                            maxLength={300}
                            value={donnees.justification_urgence ?? ''}
                            onChange={(e) => changer('justification_urgence', e.target.value)}
                            placeholder="Pourquoi ce bon ne peut-il pas suivre le délai normal ?"
                        />
                    </Champ>
                </div>
            )}
        </div>
    );
}
