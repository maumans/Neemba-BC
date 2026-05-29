/**
 * Page Création d'un Rapport de Caisse - NEEMBA
 * 
 * Formulaire de saisie d'un rapport journalier avec calcul automatique du solde.
 * Inclut la ventilation des sorties par catégorie et par mode de paiement.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowLeft, Save, Loader2, BarChart3, CreditCard } from 'lucide-react';
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
    soldeOuverture = 0,
    totalSorties = 0,
    nombreBons = 0,
    bonsPayeDuJour = [],
    detailParCategorie = [],
    detailParMode = [],
    dateRapport,
    site,
}) {
    const { data, setData, post, processing, errors } = useForm({
        date_rapport: dateRapport || new Date().toISOString().split('T')[0],
        site: site || '',
        solde_ouverture: soldeOuverture,
        total_entrees: 0,
        total_sorties: totalSorties,
        observations: '',
    });

    /** Calculer le solde de fin de journée en temps réel */
    const soldeCloture = parseFloat(data.solde_ouverture || 0)
        + parseFloat(data.total_entrees || 0)
        - parseFloat(data.total_sorties || 0);

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

                                    {/* Montants */}
                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                        <div>
                                            <Label htmlFor="solde_ouverture">Solde d'ouverture (GNF) *</Label>
                                            <Input
                                                id="solde_ouverture"
                                                type="number"
                                                value={data.solde_ouverture}
                                                onChange={(e) => setData('solde_ouverture', e.target.value)}
                                                className="mt-1"
                                                min="0"
                                                required
                                            />
                                            {errors.solde_ouverture && (
                                                <p className="text-sm text-red-500 mt-1">{errors.solde_ouverture}</p>
                                            )}
                                        </div>
                                        <div>
                                            <Label htmlFor="total_entrees">Total entrées (GNF) *</Label>
                                            <Input
                                                id="total_entrees"
                                                type="number"
                                                value={data.total_entrees}
                                                onChange={(e) => setData('total_entrees', e.target.value)}
                                                className="mt-1"
                                                min="0"
                                                required
                                            />
                                            {errors.total_entrees && (
                                                <p className="text-sm text-red-500 mt-1">{errors.total_entrees}</p>
                                            )}
                                        </div>
                                        <div>
                                            <Label htmlFor="total_sorties">Total sorties (GNF) *</Label>
                                            <Input
                                                id="total_sorties"
                                                type="number"
                                                value={data.total_sorties}
                                                onChange={(e) => setData('total_sorties', e.target.value)}
                                                className="mt-1"
                                                min="0"
                                                required
                                            />
                                            {errors.total_sorties && (
                                                <p className="text-sm text-red-500 mt-1">{errors.total_sorties}</p>
                                            )}
                                        </div>
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
                                        <span>{formatMontant(data.solde_ouverture)}</span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">+ Entrées</span>
                                        <span className="text-green-600">
                                            +{formatMontant(data.total_entrees)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between text-sm">
                                        <span className="text-gray-500">- Sorties</span>
                                        <span className="text-red-600">
                                            -{formatMontant(data.total_sorties)}
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
