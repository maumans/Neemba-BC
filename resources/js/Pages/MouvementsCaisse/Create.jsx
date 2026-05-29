/**
 * Page Création d'un Mouvement de Caisse - NEEMBA
 * 
 * Formulaire pour créer un approvisionnement, retrait ou ajustement de caisse.
 * Accessible aux caissiers principalement.
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
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
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

const TYPES = [
    { value: 'approvisionnement', label: 'Approvisionnement', description: 'Alimenter la caisse du site', icone: ArrowUpCircle, couleur: 'text-green-600 border-green-200 bg-green-50' },
    { value: 'retrait', label: 'Retrait', description: 'Retirer des fonds de la caisse', icone: ArrowDownCircle, couleur: 'text-red-600 border-red-200 bg-red-50' },
    { value: 'ajustement', label: 'Ajustement', description: 'Corriger le solde de la caisse', icone: Settings2, couleur: 'text-blue-600 border-blue-200 bg-blue-50' },
];

export default function Create({ sites = [], types = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        type: 'approvisionnement',
        site: '',
        montant: '',
        motif: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('mouvements-caisse.store'));
    };

    /* Trouver le site sélectionné pour afficher son solde */
    const siteObj  = data.site ? sites.find((s) => s.nom === data.site) : null;
    const soldeSite = siteObj ? (siteObj.solde_caisse ?? 0) : null;

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

                                {/* Site */}
                                <div>
                                    <Label htmlFor="site">Site *</Label>
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
                                    {errors.site && <p className="text-sm text-red-500 mt-1">{errors.site}</p>}
                                    {soldeSite !== null && (
                                        <p className="text-xs text-gray-500 mt-1">
                                            Solde actuel : <span className="font-semibold">{formaterNombre(soldeSite)} GNF</span>
                                        </p>
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
                                    {data.montant && data.type === 'retrait' && soldeSite !== null && parseFloat(data.montant) > soldeSite && (
                                        <div className="flex items-center gap-2 p-2 rounded-lg bg-red-50 text-red-700 text-xs mt-2 border border-red-200">
                                            <AlertTriangle className="h-3.5 w-3.5 flex-shrink-0" />
                                            Le montant dépasse le solde disponible de la caisse
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

                                {/* Résumé */}
                                {data.montant && data.site && (
                                    <div className="p-3 rounded-lg bg-gray-50 border text-sm">
                                        <p className="font-medium mb-1">Résumé du mouvement</p>
                                        <div className="grid grid-cols-2 gap-1 text-xs">
                                            <span className="text-gray-500">Type</span>
                                            <span className="text-right font-medium">{TYPES.find(t => t.value === data.type)?.label}</span>
                                            <span className="text-gray-500">Site</span>
                                            <span className="text-right">{data.site}</span>
                                            <span className="text-gray-500">Montant</span>
                                            <span className="text-right font-bold text-neemba-600">
                                                {formaterNombre(data.montant)} GNF
                                            </span>
                                            {soldeSite !== null && (
                                                <>
                                                    <span className="text-gray-500">Solde après</span>
                                                    <span className="text-right font-medium">
                                                        {formaterNombre(
                                                            data.type === 'retrait'
                                                                ? soldeSite - parseFloat(data.montant)
                                                                : soldeSite + parseFloat(data.montant)
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
