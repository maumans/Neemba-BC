/**
 * Page Création d'un Rapport de Caisse - NEEMBA
 * 
 * Formulaire de saisie d'un rapport journalier avec calcul automatique du solde.
 * Inclut la ventilation des sorties par catégorie et par mode de paiement.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowLeft, Save, Loader2, BarChart3, CreditCard, Banknote, Smartphone, Calculator, AlertTriangle, CheckCircle2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { Badge } from '@/Components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { formaterMontant as formatMontant } from '@/utils/nombreEnLettres';

export default function Create({
    soldeOuvertureEspeces = 0,
    soldeOuvertureOm = 0,
    totalSortiesEspeces = 0,
    totalSortiesOm = 0,
    nombreBons = 0,
    bonsPayeDuJour = [],
    detailParCategorie = [],
    detailParMode = [],
    dateRapport,
    site,
    coupures = [20000, 10000, 5000, 2000, 1000, 500, 100, 50],
}) {
    /* Les soldes et écarts sont recalculés par le serveur à l'enregistrement ;
     * ici ils sont dérivés de la saisie pour l'affichage. */
    const { data, setData, post, processing, errors } = useForm({
        date_rapport: dateRapport || new Date().toISOString().split('T')[0],
        site: site || '',
        solde_ouverture_especes: soldeOuvertureEspeces,
        solde_ouverture_om: soldeOuvertureOm,
        total_entrees_especes: 0,
        total_entrees_om: 0,
        total_sorties_especes: totalSortiesEspeces,
        total_sorties_om: totalSortiesOm,
        observations: '',
        billetage: coupures.reduce((acc, val) => ({ ...acc, [val]: 0 }), {}),
        solde_physique_especes: '',   // '' = comptage non saisi
        solde_physique_om: '',
        motif_ecart: '',
    });

    const nombre = (valeur) => parseFloat(valeur || 0);

    /** Soldes comptables */
    const soldeOuverture = nombre(data.solde_ouverture_especes) + nombre(data.solde_ouverture_om);
    const totalEntrees = nombre(data.total_entrees_especes) + nombre(data.total_entrees_om);
    const totalSorties = nombre(data.total_sorties_especes) + nombre(data.total_sorties_om);
    const soldeClotureEspeces = nombre(data.solde_ouverture_especes) + nombre(data.total_entrees_especes) - nombre(data.total_sorties_especes);
    const soldeClotureOm = nombre(data.solde_ouverture_om) + nombre(data.total_entrees_om) - nombre(data.total_sorties_om);
    const soldeCloture = soldeClotureEspeces + soldeClotureOm;

    /** Écarts compté − comptable, seulement une fois le comptage saisi */
    const ecartEspeces = data.solde_physique_especes === '' ? null : nombre(data.solde_physique_especes) - soldeClotureEspeces;
    const ecartOm = data.solde_physique_om === '' ? null : nombre(data.solde_physique_om) - soldeClotureOm;
    const aUnEcart = (ecartEspeces ?? 0) !== 0 || (ecartOm ?? 0) !== 0;

    // Gestion des modifications de billetage : le comptage espèces est la somme des coupures
    const handleBilletageChange = (coupure, valeur) => {
        const nouveauBilletage = { ...data.billetage, [coupure]: parseInt(valeur) || 0 };
        const quantites = Object.values(nouveauBilletage);
        const total = Object.entries(nouveauBilletage)
            .reduce((somme, [c, quantite]) => somme + parseInt(c) * (parseInt(quantite) || 0), 0);

        setData(d => ({
            ...d,
            billetage: nouveauBilletage,
            solde_physique_especes: quantites.some(q => q > 0) ? total : '',
        }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('rapports.store'));
    };

    return (
        <AuthenticatedLayout header="Nouveau Rapport de Caisse">
            <Head title="Nouveau Rapport de Caisse" />

            <div className="mb-6">
                <Link
                    href={route('rapports.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour aux rapports
                </Link>
            </div>

            <form onSubmit={handleSubmit}>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Formulaire principal */}
                    <div className="lg:col-span-2 space-y-6">
                        <motion.div
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                        >
                            <Card>
                                <CardHeader>
                                    <CardTitle>Informations du rapport</CardTitle>
                                    <CardDescription>
                                        Saisissez les données de la journée de caisse
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <Label htmlFor="date_rapport">Date du rapport *</Label>
                                            <Input
                                                id="date_rapport"
                                                type="date"
                                                value={data.date_rapport}
                                                onChange={(e) => setData('date_rapport', e.target.value)}
                                                className="mt-1"
                                                required
                                            />
                                            {errors.date_rapport && (
                                                <p className="text-sm text-red-500 mt-1">{errors.date_rapport}</p>
                                            )}
                                        </div>
                                        <div>
                                            <Label htmlFor="site">Site *</Label>
                                            <Input
                                                id="site"
                                                value={data.site}
                                                onChange={(e) => setData('site', e.target.value)}
                                                placeholder="Ex: Conakry"
                                                className="mt-1"
                                                required
                                            />
                                            {errors.site && (
                                                <p className="text-sm text-red-500 mt-1">{errors.site}</p>
                                            )}
                                        </div>
                                    </div>

                                    <Separator />

                                    {/* Montants Ventilé Espèces */}
                                    <div className="space-y-3">
                                        <h3 className="text-sm font-medium flex items-center gap-2 text-amber-700">
                                            <Banknote className="h-4 w-4" /> Comptabilité Espèces
                                        </h3>
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <Label htmlFor="solde_ouverture_especes">Ouverture (GNF)</Label>
                                                <Input
                                                    id="solde_ouverture_especes"
                                                    type="number"
                                                    value={data.solde_ouverture_especes}
                                                    onChange={(e) => setData('solde_ouverture_especes', e.target.value)}
                                                    className="mt-1 bg-gray-50"
                                                    readOnly
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="total_entrees_especes">Entrées (GNF) *</Label>
                                                <Input
                                                    id="total_entrees_especes"
                                                    type="number"
                                                    value={data.total_entrees_especes}
                                                    onChange={(e) => setData('total_entrees_especes', e.target.value)}
                                                    className="mt-1"
                                                    min="0"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="total_sorties_especes">Sorties (GNF) *</Label>
                                                <Input
                                                    id="total_sorties_especes"
                                                    type="number"
                                                    value={data.total_sorties_especes}
                                                    onChange={(e) => setData('total_sorties_especes', e.target.value)}
                                                    className="mt-1"
                                                    min="0"
                                                    required
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <Separator />

                                    {/* Montants Ventilé OM */}
                                    <div className="space-y-3">
                                        <h3 className="text-sm font-medium flex items-center gap-2 text-violet-700">
                                            <Smartphone className="h-4 w-4" /> Comptabilité OM
                                        </h3>
                                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <Label htmlFor="solde_ouverture_om">Ouverture (GNF)</Label>
                                                <Input
                                                    id="solde_ouverture_om"
                                                    type="number"
                                                    value={data.solde_ouverture_om}
                                                    onChange={(e) => setData('solde_ouverture_om', e.target.value)}
                                                    className="mt-1 bg-gray-50"
                                                    readOnly
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="total_entrees_om">Entrées (GNF) *</Label>
                                                <Input
                                                    id="total_entrees_om"
                                                    type="number"
                                                    value={data.total_entrees_om}
                                                    onChange={(e) => setData('total_entrees_om', e.target.value)}
                                                    className="mt-1"
                                                    min="0"
                                                    required
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="total_sorties_om">Sorties (GNF) *</Label>
                                                <Input
                                                    id="total_sorties_om"
                                                    type="number"
                                                    value={data.total_sorties_om}
                                                    onChange={(e) => setData('total_sorties_om', e.target.value)}
                                                    className="mt-1"
                                                    min="0"
                                                    required
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    <Separator />

                                    {/* Billetage et Réconciliation */}
                                    <div className="space-y-4 bg-gray-50/50 p-4 -mx-6 px-6">
                                        <div className="flex items-center justify-between">
                                            <h3 className="text-sm font-medium flex items-center gap-2 text-gray-900">
                                                <Calculator className="h-4 w-4" /> Billetage & Réconciliation
                                            </h3>
                                        </div>

                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                                            {/* Colonne Espèces */}
                                            <div className="space-y-4">
                                                <h4 className="text-xs font-semibold text-amber-700 uppercase tracking-wider flex items-center gap-1.5 border-b pb-2">
                                                    <Banknote className="h-3.5 w-3.5" /> Physique Espèces
                                                </h4>
                                                
                                                <div className="grid grid-cols-2 gap-3">
                                                    {coupures.map(coupure => (
                                                        <div key={coupure} className="flex items-center gap-2">
                                                            <Label className="w-16 text-xs text-gray-500 whitespace-nowrap">{coupure}</Label>
                                                            <Input
                                                                type="number"
                                                                min="0"
                                                                value={data.billetage[coupure] || ''}
                                                                onChange={e => handleBilletageChange(coupure, e.target.value)}
                                                                className="h-8 text-right bg-white"
                                                                placeholder="0"
                                                            />
                                                        </div>
                                                    ))}
                                                </div>
                                                
                                                <div className="bg-white p-3 rounded-md border mt-2">
                                                    <div className="flex justify-between items-center text-sm mb-1">
                                                        <span className="text-gray-500">Total Saisi</span>
                                                        <span className="font-semibold">{formatMontant(data.solde_physique_especes || 0)}</span>
                                                    </div>
                                                    <div className="flex justify-between items-center text-sm mb-1">
                                                        <span className="text-gray-500">Solde Comptable</span>
                                                        <span className="font-semibold">{formatMontant(soldeClotureEspeces)}</span>
                                                    </div>
                                                    <Separator className="my-2" />
                                                    <LigneEcart libelle="Écart Espèces" ecart={ecartEspeces} />
                                                </div>
                                            </div>

                                            {/* Colonne OM */}
                                            <div className="space-y-4">
                                                <h4 className="text-xs font-semibold text-violet-700 uppercase tracking-wider flex items-center gap-1.5 border-b pb-2">
                                                    <Smartphone className="h-3.5 w-3.5" /> Physique OM
                                                </h4>
                                                
                                                <div>
                                                    <Label className="text-xs text-gray-500 mb-1.5 block">Solde lu sur le téléphone / portail OM (GNF)</Label>
                                                    <Input
                                                        type="number"
                                                        value={data.solde_physique_om}
                                                        onChange={(e) => setData('solde_physique_om', e.target.value)}
                                                        className="bg-white text-right font-medium"
                                                        placeholder="Saisir le solde final OM"
                                                    />
                                                </div>

                                                <div className="bg-white p-3 rounded-md border mt-6">
                                                    <div className="flex justify-between items-center text-sm mb-1">
                                                        <span className="text-gray-500">Solde Saisi</span>
                                                        <span className="font-semibold">{formatMontant(data.solde_physique_om || 0)}</span>
                                                    </div>
                                                    <div className="flex justify-between items-center text-sm mb-1">
                                                        <span className="text-gray-500">Solde Comptable</span>
                                                        <span className="font-semibold">{formatMontant(soldeClotureOm)}</span>
                                                    </div>
                                                    <Separator className="my-2" />
                                                    <LigneEcart libelle="Écart OM" ecart={ecartOm} />
                                                </div>
                                            </div>
                                        </div>

                                        {aUnEcart && (
                                            <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
                                                <Label htmlFor="motif_ecart" className="text-red-800 flex items-center gap-1.5 mb-2">
                                                    <AlertTriangle className="h-4 w-4" /> Justification des écarts constatés *
                                                </Label>
                                                <Textarea
                                                    id="motif_ecart"
                                                    value={data.motif_ecart}
                                                    onChange={(e) => setData('motif_ecart', e.target.value)}
                                                    placeholder="Veuillez expliquer la raison de l'écart (ex: monnaie manquante, frais de retrait imprévus, etc.)"
                                                    className="bg-white border-red-200 focus-visible:ring-red-500"
                                                    required
                                                />
                                                {errors.motif_ecart && (
                                                    <p className="text-sm text-red-600 mt-1">{errors.motif_ecart}</p>
                                                )}
                                            </div>
                                        )}
                                    </div>

                                    <Separator />

                                    {/* Observations */}
                                    <div>
                                        <Label htmlFor="observations">Observations</Label>
                                        <Textarea
                                            id="observations"
                                            value={data.observations}
                                            onChange={(e) => setData('observations', e.target.value)}
                                            placeholder="Observations ou remarques de la journée..."
                                            className="mt-1 min-h-[100px]"
                                        />
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>

                        {/* Ventilation par catégorie */}
                        {detailParCategorie.length > 0 && (
                            <motion.div
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.1 }}
                            >
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <BarChart3 className="h-4 w-4 text-neemba-500" />
                                            Ventilation par catégorie
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Catégorie</TableHead>
                                                    <TableHead className="text-center">Bons</TableHead>
                                                    <TableHead className="text-right">Montant</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {detailParCategorie.map((item) => (
                                                    <TableRow key={item.categorie}>
                                                        <TableCell>{item.label}</TableCell>
                                                        <TableCell className="text-center">
                                                            <Badge variant="secondary">{item.nombre}</Badge>
                                                        </TableCell>
                                                        <TableCell className="text-right font-semibold">
                                                            {formatMontant(item.montant)}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                            </motion.div>
                        )}

                        {/* Ventilation par mode de paiement */}
                        {detailParMode.length > 0 && (
                            <motion.div
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.15 }}
                            >
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <CreditCard className="h-4 w-4 text-neemba-500" />
                                            Ventilation par mode de paiement
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Mode</TableHead>
                                                    <TableHead className="text-center">Bons</TableHead>
                                                    <TableHead className="text-right">Montant</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {detailParMode.map((item) => (
                                                    <TableRow key={item.mode}>
                                                        <TableCell>{item.label}</TableCell>
                                                        <TableCell className="text-center">
                                                            <Badge variant="secondary">{item.nombre}</Badge>
                                                        </TableCell>
                                                        <TableCell className="text-right font-semibold">
                                                            {formatMontant(item.montant)}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                            </motion.div>
                        )}

                        {/* Liste des bons payés du jour */}
                        {bonsPayeDuJour.length > 0 && (
                            <motion.div
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ delay: 0.2 }}
                            >
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Bons payés ce jour ({nombreBons})
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="p-0">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Numéro</TableHead>
                                                    <TableHead>Bénéficiaire</TableHead>
                                                    <TableHead className="text-right">Montant</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {bonsPayeDuJour.map((bon) => (
                                                    <TableRow key={bon.id}>
                                                        <TableCell className="font-mono text-sm">{bon.numero}</TableCell>
                                                        <TableCell>{bon.beneficiaire}</TableCell>
                                                        <TableCell className="text-right font-semibold">
                                                            {formatMontant(bon.montant)}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </CardContent>
                                </Card>
                            </motion.div>
                        )}
                    </div>

                    {/* Colonne latérale - Résumé */}
                    <div>
                        <motion.div
                            initial={{ opacity: 0, x: 10 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ delay: 0.2 }}
                        >
                            <Card className="sticky top-24">
                                <CardHeader>
                                    <CardTitle>Résumé</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">Solde ouverture</span>
                                        <span>{formatMontant(soldeOuverture)}</span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">+ Entrées</span>
                                        <span className="text-green-600">
                                            +{formatMontant(totalEntrees)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">- Sorties</span>
                                        <span className="text-red-600">
                                            -{formatMontant(totalSorties)}
                                        </span>
                                    </div>
                                    <Separator />
                                    <div className="flex justify-between">
                                        <span className="font-medium">Solde fin de journée</span>
                                        <span className={`text-xl font-bold ${soldeCloture >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                            {formatMontant(soldeCloture)}
                                        </span>
                                    </div>

                                    <div className="flex justify-between text-sm pt-1">
                                        <span className="text-gray-500">Bons payés</span>
                                        <Badge variant="secondary">{nombreBons}</Badge>
                                    </div>

                                    <Separator />

                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing}
                                        onClick={handleSubmit}
                                    >
                                        {processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                                        {processing ? 'Enregistrement…' : 'Enregistrer le rapport'}
                                    </Button>
                                </CardContent>
                            </Card>
                        </motion.div>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

/** Ligne « Écart » du billetage : non compté, équilibré (vert) ou en écart (rouge) */
function LigneEcart({ libelle, ecart }) {
    if (ecart === null) {
        return (
            <div className="flex justify-between items-center">
                <span className="text-xs font-medium">{libelle}</span>
                <span className="text-xs text-gray-400">Non compté</span>
            </div>
        );
    }

    const equilibre = ecart === 0;
    return (
        <div className="flex justify-between items-center">
            <span className="text-xs font-medium">{libelle}</span>
            <span className={`font-bold ${equilibre ? 'text-green-600' : 'text-red-600'}`}>
                {equilibre
                    ? <CheckCircle2 className="h-4 w-4 inline mr-1" />
                    : <AlertTriangle className="h-4 w-4 inline mr-1" />}
                {formatMontant(ecart)}
            </span>
        </div>
    );
}
