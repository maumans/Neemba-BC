/**
 * Page Création d'un Mouvement de Caisse - NEEMBA
 *
 * Formulaire pour créer un approvisionnement, retrait ou ajustement de caisse.
 * Le caissier choisit le type de caisse concerné : Espèces ou OM.
 * Le site est pré-sélectionné et verrouillé pour les caissiers.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    ArrowLeft,
    Send,
    Wallet,
    ArrowUpCircle,
    ArrowDownCircle,
    Settings2,
    Loader2,
    AlertTriangle,
    Paperclip,
    X,
    Banknote,
    Smartphone,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import MontantInput from '@/Components/MontantInput';
import { formaterNombre } from '@/utils/nombreEnLettres';
import { useRef } from 'react';

const TYPES = [
    { value: 'approvisionnement', label: 'Approvisionnement', description: 'Alimenter la caisse', icone: ArrowUpCircle, couleur: 'text-green-600 border-green-200 bg-green-50' },
    { value: 'retrait', label: 'Retrait', description: 'Retirer des fonds', icone: ArrowDownCircle, couleur: 'text-red-600 border-red-200 bg-red-50' },
    { value: 'ajustement', label: 'Ajustement', description: 'Corriger le solde', icone: Settings2, couleur: 'text-blue-600 border-blue-200 bg-blue-50' },
];

const TYPES_CAISSE = [
    { value: 'especes', label: 'Espèces', icone: Banknote, couleur: 'text-amber-700 border-amber-300 bg-amber-50' },
    { value: 'om', label: 'OM (Mobile Money)', icone: Smartphone, couleur: 'text-violet-600 border-violet-200 bg-violet-50' },
];

export default function Create({ sites = [], siteUtilisateur = null }) {
    const caissierSiteUnique = sites.length === 1;

    const { data, setData, post, processing, errors } = useForm({
        type: 'approvisionnement',
        type_caisse: 'especes',
        site: caissierSiteUnique ? sites[0]?.nom : '',
        montant: '',
        motif: '',
        piece_justificative: null,
    });

    const fileInputRef = useRef(null);

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('mouvements-caisse.store'), { forceFormData: true });
    };

    const handleFichier = (e) => setData('piece_justificative', e.target.files[0] || null);
    const supprimerFichier = () => {
        setData('piece_justificative', null);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const siteObj   = data.site ? sites.find((s) => s.nom === data.site) : null;
    const soldeAffiche = siteObj
        ? (data.type_caisse === 'om' ? (siteObj.solde_om ?? 0) : (siteObj.solde_especes ?? 0))
        : null;

    return (
        <AuthenticatedLayout header="Nouveau mouvement de caisse">
            <Head title="Nouveau mouvement de caisse" />

            <div className="mb-6">
                <Link
                    href={route('mouvements-caisse.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour aux mouvements
                </Link>
            </div>

            <div className="max-w-2xl mx-auto">
                <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Wallet className="h-5 w-5 text-neemba-500" />
                                Nouveau mouvement de caisse
                            </CardTitle>
                            <CardDescription>
                                Le mouvement devra être validé par le DAF ou le Directeur Pays avant d'impacter le solde.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-5">

                                {/* Type de mouvement */}
                                <div>
                                    <Label>Type de mouvement *</Label>
                                    <div className="grid grid-cols-3 gap-3 mt-2">
                                        {TYPES.map((t) => {
                                            const Icon = t.icone;
                                            const isSelected = data.type === t.value;
                                            return (
                                                <button
                                                    key={t.value}
                                                    type="button"
                                                    onClick={() => setData('type', t.value)}
                                                    className={`flex flex-col items-center gap-1.5 p-3 rounded-lg border text-sm font-medium transition-all ${
                                                        isSelected
                                                            ? `${t.couleur} ring-1 ring-current`
                                                            : 'border-gray-200 text-gray-500 hover:border-gray-300'
                                                    }`}
                                                >
                                                    <Icon className="h-5 w-5" />
                                                    <span>{t.label}</span>
                                                    <span className="text-[10px] font-normal opacity-70">{t.description}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors.type && <p className="text-sm text-red-500 mt-1">{errors.type}</p>}
                                </div>

                                {/* Type de caisse (Espèces / OM) */}
                                <div>
                                    <Label>Type de caisse *</Label>
                                    <div className="grid grid-cols-2 gap-3 mt-2">
                                        {TYPES_CAISSE.map((tc) => {
                                            const Icon = tc.icone;
                                            const isSelected = data.type_caisse === tc.value;
                                            return (
                                                <button
                                                    key={tc.value}
                                                    type="button"
                                                    onClick={() => setData('type_caisse', tc.value)}
                                                    className={`flex items-center gap-2.5 p-3 rounded-lg border text-sm font-medium transition-all ${
                                                        isSelected
                                                            ? `${tc.couleur} ring-1 ring-current`
                                                            : 'border-gray-200 text-gray-500 hover:border-gray-300'
                                                    }`}
                                                >
                                                    <Icon className="h-5 w-5 flex-shrink-0" />
                                                    <span>{tc.label}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {errors.type_caisse && <p className="text-sm text-red-500 mt-1">{errors.type_caisse}</p>}
                                </div>

                                {/* Site — verrouillé pour le caissier */}
                                <div>
                                    <Label htmlFor="site">Site *</Label>
                                    {caissierSiteUnique ? (
                                        <div className="mt-1 flex items-center gap-2 p-2.5 rounded-lg border bg-gray-50 text-sm text-gray-700">
                                            <Wallet className="h-4 w-4 text-gray-400 flex-shrink-0" />
                                            <span className="font-medium">{sites[0]?.nom}</span>
                                            <span className="text-xs text-gray-400 ml-auto">Site affecté</span>
                                        </div>
                                    ) : (
                                        <Select
                                            value={data.site}
                                            onValueChange={(val) => setData('site', val)}
                                        >
                                            <SelectTrigger className="mt-1">
                                                <SelectValue placeholder="Sélectionner un site" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {sites.map((s) => (
                                                    <SelectItem key={s.id ?? s.nom} value={s.nom}>
                                                        {s.nom}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                    {errors.site && <p className="text-sm text-red-500 mt-1">{errors.site}</p>}

                                    {/* Solde de la balance sélectionnée */}
                                    {soldeAffiche !== null && (
                                        <div className="mt-2 flex gap-3 text-xs">
                                            <span className={`px-2 py-1 rounded-full font-medium ${data.type_caisse === 'om' ? 'bg-violet-100 text-violet-700' : 'bg-amber-100 text-amber-700'}`}>
                                                {data.type_caisse === 'om' ? 'Solde OM' : 'Solde Espèces'} : {formaterNombre(soldeAffiche)} GNF
                                            </span>
                                            {siteObj && (
                                                <span className="px-2 py-1 rounded-full bg-gray-100 text-gray-500">
                                                    Total caisse : {formaterNombre((siteObj.solde_especes ?? 0) + (siteObj.solde_om ?? 0))} GNF
                                                </span>
                                            )}
                                        </div>
                                    )}
                                </div>

                                {/* Montant */}
                                <div>
                                    <Label htmlFor="montant">Montant *</Label>
                                    <MontantInput
                                        id="montant"
                                        value={data.montant}
                                        onChange={(val) => setData('montant', val)}
                                        className="mt-1"
                                        required
                                    />
                                    {errors.montant && <p className="text-sm text-red-500 mt-1">{errors.montant}</p>}
                                    {data.montant && data.type === 'retrait' && soldeAffiche !== null && parseFloat(data.montant) > soldeAffiche && (
                                        <div className="flex items-center gap-2 p-2 rounded-lg bg-red-50 text-red-700 text-xs mt-2 border border-red-200">
                                            <AlertTriangle className="h-3.5 w-3.5 flex-shrink-0" />
                                            Le montant dépasse le solde {data.type_caisse === 'om' ? 'OM' : 'Espèces'} disponible
                                        </div>
                                    )}
                                </div>

                                {/* Motif */}
                                <div>
                                    <Label htmlFor="motif">Motif *</Label>
                                    <Textarea
                                        id="motif"
                                        value={data.motif}
                                        onChange={(e) => setData('motif', e.target.value)}
                                        placeholder="Décrivez la raison de ce mouvement de caisse..."
                                        className="mt-1 min-h-[100px]"
                                    />
                                    {errors.motif && <p className="text-sm text-red-500 mt-1">{errors.motif}</p>}
                                </div>

                                {/* Pièce justificative */}
                                <div>
                                    <Label htmlFor="piece_justificative">Pièce justificative <span className="text-gray-400 font-normal">(optionnel)</span></Label>
                                    {data.piece_justificative ? (
                                        <div className="mt-1 flex items-center gap-2 p-2.5 rounded-lg border border-neemba-200 bg-neemba-50">
                                            <Paperclip className="h-4 w-4 text-neemba-600 flex-shrink-0" />
                                            <span className="text-sm text-neemba-800 flex-1 truncate">{data.piece_justificative.name}</span>
                                            <button type="button" onClick={supprimerFichier} className="text-gray-400 hover:text-red-500 transition-colors">
                                                <X className="h-4 w-4" />
                                            </button>
                                        </div>
                                    ) : (
                                        <label
                                            htmlFor="piece_justificative"
                                            className="mt-1 flex flex-col items-center gap-1.5 p-4 rounded-lg border-2 border-dashed border-gray-200 cursor-pointer hover:border-neemba-300 hover:bg-neemba-50/40 transition-colors"
                                        >
                                            <Paperclip className="h-5 w-5 text-gray-400" />
                                            <span className="text-sm text-gray-500">Cliquer pour joindre un fichier</span>
                                            <span className="text-xs text-gray-400">PDF, JPG, PNG — max 10 Mo</span>
                                            <input
                                                ref={fileInputRef}
                                                id="piece_justificative"
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                className="hidden"
                                                onChange={handleFichier}
                                            />
                                        </label>
                                    )}
                                    {errors.piece_justificative && <p className="text-sm text-red-500 mt-1">{errors.piece_justificative}</p>}
                                </div>

                                {/* Résumé */}
                                {data.montant && data.site && (
                                    <div className="p-3 rounded-lg bg-gray-50 border text-sm">
                                        <p className="font-medium mb-1">Résumé du mouvement</p>
                                        <div className="grid grid-cols-2 gap-1 text-xs">
                                            <span className="text-gray-500">Type</span>
                                            <span className="text-right font-medium">{TYPES.find(t => t.value === data.type)?.label}</span>
                                            <span className="text-gray-500">Caisse</span>
                                            <span className="text-right font-medium">{data.type_caisse === 'om' ? 'OM (Mobile Money)' : 'Espèces'}</span>
                                            <span className="text-gray-500">Site</span>
                                            <span className="text-right">{data.site}</span>
                                            <span className="text-gray-500">Montant</span>
                                            <span className="text-right font-bold text-neemba-600">
                                                {formaterNombre(data.montant)} GNF
                                            </span>
                                            {soldeAffiche !== null && (
                                                <>
                                                    <span className="text-gray-500">Solde {data.type_caisse === 'om' ? 'OM' : 'Espèces'} après</span>
                                                    <span className="text-right font-medium">
                                                        {formaterNombre(
                                                            data.type === 'retrait'
                                                                ? soldeAffiche - parseFloat(data.montant)
                                                                : soldeAffiche + parseFloat(data.montant)
                                                        )} GNF
                                                    </span>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Actions */}
                                <div className="flex gap-3 pt-2">
                                    <Link href={route('mouvements-caisse.index')} className="flex-1">
                                        <Button type="button" variant="outline" className="w-full">
                                            Annuler
                                        </Button>
                                    </Link>
                                    <Button type="submit" className="flex-1" disabled={processing}>
                                        {processing ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <Send className="mr-2 h-4 w-4" />
                                        )}
                                        {processing ? 'Envoi en cours…' : 'Soumettre pour validation'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </motion.div>
            </div>
        </AuthenticatedLayout>
    );
}
