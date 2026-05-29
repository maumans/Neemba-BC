/**
 * Page Édition d'un Bon de Caisse - NEEMBA
 * 
 * Permet de modifier un bon en statut BROUILLON uniquement.
 * Seul le demandeur peut éditer ses propres brouillons.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { Save, Send, ArrowLeft, AlertTriangle, Upload, Trash2, Loader2, Info, Plus, Minus, GitBranch } from 'lucide-react';
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
import { Separator } from '@/Components/ui/separator';
import { Combobox } from '@/Components/ui/combobox';
import { useState } from 'react';
import { nombreEnLettres, formaterNombre } from '@/utils/nombreEnLettres';
import MontantInput from '@/Components/MontantInput';

export default function Edit({ bonCaisse, sites = [], services = [], codesAnalytiques = [], niveauxUrgence = {}, motifsUrgence = [], montantMax: MONTANT_MAX = 20000000, seuilDP: SEUIL_DP = 5000000 }) {
    const { data, setData, post, processing, errors } = useForm({
        _method: 'PUT',
        type_bon: bonCaisse.type_bon || 'BD',
        site: bonCaisse.site || '',
        service: bonCaisse.service || '',
        code_analytique: bonCaisse.code_analytique || '',
        beneficiaire: bonCaisse.beneficiaire || '',
        motif: bonCaisse.motif || '',
        montant: bonCaisse.montant || '',
        montant_lettres: bonCaisse.montant_lettres || '',
        niveau_urgence: bonCaisse.niveau_urgence || 'normale',
        motif_urgence: bonCaisse.motif_urgence || '',
        justification_urgence: bonCaisse.justification_urgence || '',
        soumettre: false,
        pieces_jointes: [],
    });

    const [actionEnCours, setActionEnCours] = useState(null);
    const [ventilations, setVentilations] = useState(
        bonCaisse.ventilations?.map(v => ({ code_analytique: v.code_analytique, montant: String(v.montant), pourcentage: String(v.pourcentage || '') })) || []
    );

    const handleSubmit = (e, doitSoumettre = false) => {
        e.preventDefault();
        setActionEnCours(doitSoumettre ? 'soumettre' : 'brouillon');

        const formData = new FormData();
        Object.entries(data).forEach(([key, value]) => {
            if (key === 'pieces_jointes' || key === 'soumettre') return;
            if (value !== null && value !== undefined) {
                formData.append(key, value);
            }
        });
        formData.append('soumettre', doitSoumettre ? '1' : '0');
        if (data.pieces_jointes) {
            Array.from(data.pieces_jointes).forEach((f, i) => {
                formData.append(`pieces_jointes[${i}]`, f);
            });
        }
        ventilations.forEach((v, i) => {
            if (v.code_analytique && v.montant) {
                formData.append(`ventilations[${i}][code_analytique]`, v.code_analytique);
                formData.append(`ventilations[${i}][montant]`, v.montant);
                formData.append(`ventilations[${i}][pourcentage]`, v.pourcentage || '');
            }
        });

        router.post(route('bons-caisse.update', bonCaisse.id), formData, {
            forceFormData: true,
            onFinish: () => setActionEnCours(null),
        });
    };

    const necessiteDP = parseFloat(data.montant) >= SEUIL_DP;
    const montantExcede = parseFloat(data.montant) > MONTANT_MAX;

    return (
        <AuthenticatedLayout header={`Modifier ${bonCaisse.numero}`}>
            <Head title={`Modifier ${bonCaisse.numero}`} />

            <div className="mb-6">
                <Link
                    href={route('bons-caisse.show', bonCaisse.id)}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour au détail
                </Link>
            </div>

            <form onSubmit={(e) => handleSubmit(e, false)}>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 space-y-6">
                        {/* Informations générales */}
                        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Informations générales</CardTitle>
                                    <CardDescription>
                                        Numéro : <span className="font-mono font-semibold">{bonCaisse.numero}</span>
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <Label htmlFor="type_bon">Type de bon *</Label>
                                            <Select
                                                value={data.type_bon}
                                                onValueChange={(val) => setData('type_bon', val)}
                                            >
                                                <SelectTrigger className="mt-1">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="BD">Bon Définitif (BD)</SelectItem>
                                                    <SelectItem value="BP">Bon Provisoire (BP)</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {errors.type_bon && <p className="text-sm text-red-500 mt-1">{errors.type_bon}</p>}
                                        </div>
                                        <div>
                                            <Label>Code analytique</Label>
                                            <Combobox
                                                options={codesAnalytiques.map((c) => ({
                                                    value: c.code,
                                                    label: `${c.code} - ${c.libelle}${c.service?.nom ? ` (${c.service.nom})` : ''}`,
                                                }))}
                                                value={data.code_analytique}
                                                onChange={(val) => setData('code_analytique', val)}
                                                placeholder="Sélectionner un code"
                                                searchPlaceholder="Rechercher un code..."
                                                className="mt-1"
                                            />
                                        </div>
                                    </div>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <Label>Site *</Label>
                                            <Combobox
                                                options={sites}
                                                value={data.site}
                                                onChange={(val) => setData('site', val)}
                                                placeholder="Sélectionner un site"
                                                searchPlaceholder="Rechercher un site..."
                                                className="mt-1"
                                                error={errors.site}
                                            />
                                            {errors.site && <p className="text-sm text-red-500 mt-1">{errors.site}</p>}
                                        </div>
                                        <div>
                                            <Label>Service *</Label>
                                            <Combobox
                                                options={services}
                                                value={data.service}
                                                onChange={(val) => setData('service', val)}
                                                placeholder="Sélectionner un service"
                                                searchPlaceholder="Rechercher un service..."
                                                className="mt-1"
                                                error={errors.service}
                                            />
                                            {errors.service && <p className="text-sm text-red-500 mt-1">{errors.service}</p>}
                                        </div>
                                    </div>

                                    {/* Niveau d'urgence */}
                                    <div>
                                        <Label>Niveau d'urgence</Label>
                                        <div className="grid grid-cols-3 gap-3 mt-1">
                                            {Object.entries(niveauxUrgence).map(([key, label]) => (
                                                <button
                                                    key={key}
                                                    type="button"
                                                    onClick={() => setData('niveau_urgence', key)}
                                                    className={`flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border text-sm font-medium transition-all ${
                                                        data.niveau_urgence === key
                                                            ? key === 'normale'
                                                                ? 'border-green-400 bg-green-50 text-green-700 ring-1 ring-green-300'
                                                                : key === 'urgente'
                                                                ? 'border-orange-400 bg-orange-50 text-orange-700 ring-1 ring-orange-300'
                                                                : 'border-red-400 bg-red-50 text-red-700 ring-1 ring-red-300'
                                                            : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:bg-gray-50'
                                                    }`}
                                                >
                                                    {key === 'normale' && '🟢'}
                                                    {key === 'urgente' && '🟠'}
                                                    {key === 'tres_urgente' && '🔴'}
                                                    {label}
                                                </button>
                                            ))}
                                        </div>
                                        {data.niveau_urgence === 'urgente' && (
                                            <p className="text-xs text-orange-600 mt-1.5 flex items-center gap-1">
                                                <Info className="h-3 w-3" />
                                                Le validateur suivant recevra un email en plus de la notification
                                            </p>
                                        )}
                                        {data.niveau_urgence === 'tres_urgente' && (
                                            <p className="text-xs text-red-600 mt-1.5 flex items-center gap-1">
                                                <AlertTriangle className="h-3 w-3" />
                                                Le validateur suivant recevra un email ET un SMS à chaque étape
                                            </p>
                                        )}
                                    </div>

                                    {/* Justification urgence (Phase 1.1) */}
                                    {(data.niveau_urgence === 'urgente' || data.niveau_urgence === 'tres_urgente') && (
                                        <div className="space-y-3 p-4 rounded-lg border border-orange-200 bg-orange-50/50">
                                            <p className="text-sm font-medium text-orange-800 flex items-center gap-2">
                                                <AlertTriangle className="h-4 w-4" />
                                                Justification de l'urgence obligatoire
                                            </p>
                                            <div>
                                                <Label>Motif d'urgence *</Label>
                                                {motifsUrgence.length > 0 ? (
                                                    <Select
                                                        value={data.motif_urgence}
                                                        onValueChange={(val) => setData('motif_urgence', val)}
                                                    >
                                                        <SelectTrigger className="mt-1 bg-white">
                                                            <SelectValue placeholder="Sélectionner un motif" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {motifsUrgence.map((motif) => (
                                                                <SelectItem key={motif} value={motif}>{motif}</SelectItem>
                                                            ))}
                                                            <SelectItem value="autre">Autre motif</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <Input
                                                        value={data.motif_urgence}
                                                        onChange={(e) => setData('motif_urgence', e.target.value)}
                                                        placeholder="Précisez le motif d'urgence"
                                                        className="mt-1 bg-white"
                                                    />
                                                )}
                                                {errors.motif_urgence && <p className="text-sm text-red-500 mt-1">{errors.motif_urgence}</p>}
                                            </div>
                                            <div>
                                                <Label>Justification détaillée *</Label>
                                                <Textarea
                                                    value={data.justification_urgence}
                                                    onChange={(e) => setData('justification_urgence', e.target.value)}
                                                    placeholder="Expliquez pourquoi ce bon nécessite un traitement urgent..."
                                                    className="mt-1 min-h-[80px] bg-white"
                                                />
                                                {errors.justification_urgence && <p className="text-sm text-red-500 mt-1">{errors.justification_urgence}</p>}
                                            </div>
                                        </div>
                                    )}

                                    <div>
                                        <Label htmlFor="beneficiaire">Bénéficiaire *</Label>
                                        <Input
                                            id="beneficiaire"
                                            value={data.beneficiaire}
                                            onChange={(e) => setData('beneficiaire', e.target.value)}
                                            className="mt-1"
                                            required
                                        />
                                        {errors.beneficiaire && <p className="text-sm text-red-500 mt-1">{errors.beneficiaire}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="motif">Motif de la demande *</Label>
                                        <Textarea
                                            id="motif"
                                            value={data.motif}
                                            onChange={(e) => setData('motif', e.target.value)}
                                            className="mt-1 min-h-[100px]"
                                            required
                                        />
                                        {errors.motif && <p className="text-sm text-red-500 mt-1">{errors.motif}</p>}
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>

                        {/* Montant */}
                        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Montant</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <Label htmlFor="montant">Montant *</Label>
                                            <MontantInput
                                                id="montant"
                                                value={data.montant}
                                                onChange={(val) => {
                                                    setData(prev => ({
                                                        ...prev,
                                                        montant: val,
                                                        montant_lettres: val ? nombreEnLettres(val) : '',
                                                    }));
                                                }}
                                                max={MONTANT_MAX}
                                                className="mt-1"
                                                required
                                            />
                                            {errors.montant && <p className="text-sm text-red-500 mt-1">{errors.montant}</p>}
                                        </div>
                                        <div>
                                            <Label htmlFor="montant_lettres">Montant en lettres</Label>
                                            <Input
                                                id="montant_lettres"
                                                value={data.montant_lettres}
                                                onChange={(e) => setData('montant_lettres', e.target.value)}
                                                className="mt-1"
                                            />
                                        </div>
                                    </div>

                                    {montantExcede && (
                                        <div className="flex items-center gap-2 p-3 rounded-lg bg-red-50 text-red-700 text-sm">
                                            <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                                            Le montant dépasse le maximum autorisé de {formaterNombre(MONTANT_MAX)} GNF.
                                        </div>
                                    )}
                                    {necessiteDP && !montantExcede && (
                                        <div className="flex items-center gap-2 p-3 rounded-lg bg-neemba-50 text-neemba-800 text-sm">
                                            <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                                            Ce montant nécessite la validation du Directeur Pays.
                                        </div>
                                    )}

                                    {/* Ventilation analytique (Phase 4.2) */}
                                    {data.montant && parseFloat(data.montant) > 0 && (
                                        <div className="space-y-3 pt-2">
                                            <Separator />
                                            <div className="flex items-center justify-between">
                                                <Label className="flex items-center gap-2">
                                                    <GitBranch className="h-4 w-4 text-neemba-500" />
                                                    Ventilation analytique
                                                </Label>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => setVentilations([...ventilations, { code_analytique: '', montant: '', pourcentage: '' }])}
                                                >
                                                    <Plus className="h-3.5 w-3.5 mr-1" />
                                                    Ajouter
                                                </Button>
                                            </div>
                                            {ventilations.length === 0 && (
                                                <p className="text-xs text-gray-400">Optionnel — Répartir la dépense sur plusieurs codes analytiques</p>
                                            )}
                                            {ventilations.map((v, i) => (
                                                <div key={i} className="flex items-end gap-2 p-3 rounded-lg border bg-gray-50">
                                                    <div className="flex-1">
                                                        <Label className="text-xs">Code analytique</Label>
                                                        <Combobox
                                                            options={codesAnalytiques.map((c) => ({
                                                                value: c.code,
                                                                label: `${c.code} - ${c.libelle}${c.service?.nom ? ` (${c.service.nom})` : ''}`,
                                                            }))}
                                                            value={v.code_analytique}
                                                            onChange={(val) => {
                                                                const updated = [...ventilations];
                                                                updated[i].code_analytique = val;
                                                                setVentilations(updated);
                                                            }}
                                                            placeholder="Code..."
                                                            searchPlaceholder="Rechercher..."
                                                            className="mt-0.5"
                                                        />
                                                    </div>
                                                    <div className="w-32">
                                                        <Label className="text-xs">Montant</Label>
                                                        <Input
                                                            type="number"
                                                            value={v.montant}
                                                            onChange={(e) => {
                                                                const updated = [...ventilations];
                                                                updated[i].montant = e.target.value;
                                                                if (data.montant && parseFloat(data.montant) > 0) {
                                                                    updated[i].pourcentage = ((parseFloat(e.target.value) / parseFloat(data.montant)) * 100).toFixed(1);
                                                                }
                                                                setVentilations(updated);
                                                            }}
                                                            placeholder="0"
                                                            className="mt-0.5"
                                                        />
                                                    </div>
                                                    <div className="w-20">
                                                        <Label className="text-xs">%</Label>
                                                        <Input
                                                            type="number"
                                                            value={v.pourcentage}
                                                            onChange={(e) => {
                                                                const updated = [...ventilations];
                                                                updated[i].pourcentage = e.target.value;
                                                                if (data.montant && parseFloat(data.montant) > 0) {
                                                                    updated[i].montant = ((parseFloat(e.target.value) / 100) * parseFloat(data.montant)).toFixed(0);
                                                                }
                                                                setVentilations(updated);
                                                            }}
                                                            placeholder="0"
                                                            className="mt-0.5"
                                                        />
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-9 w-9 text-red-500 hover:text-red-700 flex-shrink-0"
                                                        onClick={() => setVentilations(ventilations.filter((_, j) => j !== i))}
                                                    >
                                                        <Minus className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            ))}
                                            {ventilations.length > 0 && (() => {
                                                const totalVentile = ventilations.reduce((s, v) => s + (parseFloat(v.montant) || 0), 0);
                                                const montantTotal = parseFloat(data.montant) || 0;
                                                const reste = montantTotal - totalVentile;
                                                return (
                                                    <div className={`text-xs flex justify-between p-2 rounded ${Math.abs(reste) < 1 ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'}`}>
                                                        <span>Total ventilé : {new Intl.NumberFormat('fr-FR').format(totalVentile)} GNF</span>
                                                        <span>{Math.abs(reste) < 1 ? '✓ Équilibré' : `Reste : ${new Intl.NumberFormat('fr-FR').format(reste)} GNF`}</span>
                                                    </div>
                                                );
                                            })()}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </motion.div>

                        {/* Pièces jointes existantes + nouvelles */}
                        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.2 }}>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Pièces justificatives</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {/* Pièces existantes */}
                                    {bonCaisse.pieces_jointes && bonCaisse.pieces_jointes.length > 0 && (
                                        <div className="mb-4 space-y-2">
                                            <p className="text-sm text-gray-500 mb-2">Fichiers existants :</p>
                                            {bonCaisse.pieces_jointes.map((piece) => (
                                                <div key={piece.id} className="flex items-center justify-between p-2 border rounded-lg text-sm">
                                                    <span>{piece.nom_fichier}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {/* Ajouter de nouvelles pièces */}
                                    <div className="border-2 border-dashed rounded-lg p-6 text-center">
                                        <Upload className="h-8 w-8 mx-auto text-gray-400 mb-2" />
                                        <Label
                                            htmlFor="pieces_jointes"
                                            className="cursor-pointer text-neemba-600 hover:text-neemba-700 font-medium"
                                        >
                                            Ajouter des fichiers
                                        </Label>
                                        <input
                                            id="pieces_jointes"
                                            type="file"
                                            multiple
                                            className="hidden"
                                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
                                            onChange={(e) => setData('pieces_jointes', e.target.files)}
                                        />
                                        <p className="text-xs text-gray-400 mt-1">PDF, Images, Word, Excel</p>
                                        {data.pieces_jointes.length > 0 && (
                                            <div className="mt-3 space-y-1">
                                                {Array.from(data.pieces_jointes).map((file, i) => (
                                                    <p key={i} className="text-sm text-gray-600">
                                                        {file.name} ({(file.size / 1024).toFixed(1)} Ko)
                                                    </p>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    </div>

                    {/* Colonne latérale */}
                    <div>
                        <motion.div initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.3 }}>
                            <Card className="sticky top-24">
                                <CardHeader>
                                    <CardTitle>Résumé</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">Numéro</span>
                                        <span className="font-mono font-semibold">{bonCaisse.numero}</span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">Type</span>
                                        <span>{data.type_bon === 'BD' ? 'Définitif' : 'Provisoire'}</span>
                                    </div>
                                    <Separator />
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">Montant</span>
                                        <span className="font-bold text-neemba-600">
                                            {data.montant
                                                ? new Intl.NumberFormat('fr-FR').format(data.montant) + ' GNF'
                                                : '-'}
                                        </span>
                                    </div>

                                    <Separator />

                                    <div className="space-y-2 pt-2">
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            className="w-full"
                                            disabled={processing}
                                        >
                                            {actionEnCours === 'brouillon' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                                            {actionEnCours === 'brouillon' ? 'Enregistrement…' : 'Sauvegarder'}
                                        </Button>
                                        <Button
                                            type="button"
                                            className="w-full"
                                            disabled={processing || montantExcede}
                                            onClick={(e) => handleSubmit(e, true)}
                                        >
                                            {actionEnCours === 'soumettre' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                                            {actionEnCours === 'soumettre' ? 'Soumission en cours…' : 'Soumettre pour validation'}
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
