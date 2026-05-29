/**
 * Page Détail d'un Rapport de Caisse - NEEMBA
 * 
 * Affiche le détail d'un rapport journalier avec les bons payés ce jour.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    ArrowLeft,
    BarChart3,
    Calendar,
    MapPin,
    User,
    CreditCard,
    CheckCircle,
    FileSpreadsheet,
    FileText,
    Download,
    Timer,
    Hash,
    AlertTriangle,
    Wallet,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { formaterMontant as formatMontant } from '@/utils/nombreEnLettres';
import { useState } from 'react';

export default function Show({ rapport, bonsPaye = [], detailsBons = [], soldeCaisseSite = null }) {
    const { flash, auth } = usePage().props;
    const userRole = auth?.user?.role;
    const [showViserDialog, setShowViserDialog] = useState(false);

    const handleViserDaf = () => {
        router.post(route('rapports.viser-daf', rapport.id), {}, {
            preserveScroll: true,
            onFinish: () => setShowViserDialog(false),
        });
    };

    return (
        <AuthenticatedLayout header={`Rapport du ${new Date(rapport.date_rapport).toLocaleDateString('fr-FR')}`}>
            <Head title={`Rapport de Caisse - ${new Date(rapport.date_rapport).toLocaleDateString('fr-FR')}`} />

            <div className="mb-6">
                <Link
                    href={route('rapports.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour aux rapports
                </Link>
            </div>

            {/* Messages flash */}
            {flash?.success && (
                <motion.div
                    initial={{ opacity: 0, y: -10 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="mb-4 p-3 rounded-lg bg-green-50 text-green-700 text-sm border border-green-200"
                >
                    {flash.success}
                </motion.div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Détails du rapport */}
                <div className="lg:col-span-2 space-y-6">
                    <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                        <Card>
                            <CardHeader>
                                <div className="flex items-start justify-between">
                                    <div>
                                        <CardTitle className="flex items-center gap-2">
                                            <BarChart3 className="h-5 w-5 text-neemba-500" />
                                            Rapport de Caisse
                                        </CardTitle>
                                        <CardDescription className="mt-1">
                                            Détail du rapport journalier
                                        </CardDescription>
                                    </div>
                                    {rapport.visa_daf_id && (
                                        <Badge variant="approuve" className="text-sm">
                                            Visé DAF
                                        </Badge>
                                    )}
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div className="flex items-start gap-3">
                                        <Calendar className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Date</p>
                                            <p className="font-medium">
                                                {new Date(rapport.date_rapport).toLocaleDateString('fr-FR', {
                                                    weekday: 'long',
                                                    year: 'numeric',
                                                    month: 'long',
                                                    day: 'numeric',
                                                })}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <MapPin className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Site</p>
                                            <p className="font-medium">{rapport.site}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <User className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Caissier</p>
                                            <p className="font-medium">
                                                {rapport.caissier?.prenom
                                                    ? `${rapport.caissier.prenom} ${rapport.caissier.name}`
                                                    : rapport.caissier?.name}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <Separator />

                                {/* Tableau des montants */}
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                                    <div className="bg-blue-50 rounded-lg p-4 text-center">
                                        <p className="text-xs text-blue-600 mb-1">Solde ouverture</p>
                                        <p className="text-lg font-bold text-blue-700">
                                            {formatMontant(rapport.solde_ouverture)}
                                        </p>
                                    </div>
                                    <div className="bg-green-50 rounded-lg p-4 text-center">
                                        <p className="text-xs text-green-600 mb-1">Entrées</p>
                                        <p className="text-lg font-bold text-green-700">
                                            +{formatMontant(rapport.total_entrees)}
                                        </p>
                                    </div>
                                    <div className="bg-red-50 rounded-lg p-4 text-center">
                                        <p className="text-xs text-red-600 mb-1">Sorties</p>
                                        <p className="text-lg font-bold text-red-700">
                                            -{formatMontant(rapport.total_sorties)}
                                        </p>
                                    </div>
                                    <div className="bg-neemba-50 rounded-lg p-4 text-center">
                                        <p className="text-xs text-neemba-700 mb-1">Solde fin de journée</p>
                                        <p className="text-lg font-bold text-neemba-700">
                                            {formatMontant(rapport.solde_cloture)}
                                        </p>
                                    </div>
                                </div>

                                {/* Observations */}
                                {rapport.observations && (
                                    <>
                                        <Separator />
                                        <div>
                                            <p className="text-xs text-gray-500 mb-1">Observations</p>
                                            <p className="text-sm bg-gray-50 rounded-lg p-3">
                                                {rapport.observations}
                                            </p>
                                        </div>
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Ventilation par catégorie */}
                    {rapport.detail_par_categorie?.length > 0 && (
                        <motion.div
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 0.05 }}
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
                                            {rapport.detail_par_categorie.map((item) => (
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
                    {rapport.detail_par_mode_paiement?.length > 0 && (
                        <motion.div
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 0.08 }}
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
                                            {rapport.detail_par_mode_paiement.map((item) => (
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

                    {/* Détail des bons payés (enrichi Phase 5) */}
                    {(detailsBons.length > 0 || bonsPaye.length > 0) && (
                        <motion.div
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 0.1 }}
                        >
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Détail des bons payés ({detailsBons.length || bonsPaye.length})
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>Numéro</TableHead>
                                                    <TableHead>Bénéficiaire</TableHead>
                                                    <TableHead>Motif</TableHead>
                                                    <TableHead className="text-right">Montant</TableHead>
                                                    <TableHead className="text-center">Délai</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {(detailsBons.length > 0 ? detailsBons : bonsPaye).map((bon) => (
                                                    <TableRow key={bon.id}>
                                                        <TableCell className="font-mono font-medium">
                                                            <Link
                                                                href={route('bons-caisse.show', bon.id)}
                                                                className="text-neemba-600 hover:underline"
                                                            >
                                                                {bon.numero}
                                                            </Link>
                                                        </TableCell>
                                                        <TableCell>{bon.beneficiaire}</TableCell>
                                                        <TableCell className="max-w-[200px] truncate">
                                                            {bon.motif}
                                                        </TableCell>
                                                        <TableCell className="text-right font-semibold">
                                                            {formatMontant(bon.montant)}
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            {bon.delai_traitement ? (
                                                                <span className="inline-flex items-center gap-1 text-xs text-gray-500">
                                                                    <Timer className="h-3 w-3" />
                                                                    {bon.delai_traitement}
                                                                </span>
                                                            ) : (
                                                                <span className="text-xs text-gray-300">—</span>
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}
                </div>

                {/* Actions & Infos */}
                <div className="space-y-6">
                    {/* Solde caisse site */}
                    {soldeCaisseSite && (
                        <motion.div
                            initial={{ opacity: 0, x: 10 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ delay: 0.15 }}
                        >
                            <Card className={soldeCaisseSite.sous_seuil ? 'border-red-200' : ''}>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-2 mb-2">
                                        <Wallet className="h-4 w-4 text-gray-400" />
                                        <p className="text-xs text-gray-500">Solde caisse — {rapport.site}</p>
                                    </div>
                                    <p className={`text-lg font-bold ${soldeCaisseSite.sous_seuil ? 'text-red-600' : 'text-gray-900'}`}>
                                        {formatMontant(soldeCaisseSite.solde)}
                                    </p>
                                    {soldeCaisseSite.sous_seuil && (
                                        <p className="text-[10px] text-red-500 flex items-center gap-1 mt-1">
                                            <AlertTriangle className="h-3 w-3" />
                                            Sous le seuil minimum
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}

                    <motion.div
                        initial={{ opacity: 0, x: 10 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.2 }}
                    >
                        <Card className="sticky top-24">
                            <CardHeader>
                                <CardTitle className="text-base">Informations</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {/* Nombre de bons */}
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Bons payés</span>
                                    <Badge variant="secondary">{rapport.nombre_bons ?? bonsPaye.length}</Badge>
                                </div>

                                {/* Visa DAF */}
                                {rapport.visa_daf && (
                                    <div className="bg-green-50 rounded-lg p-3 text-sm">
                                        <div className="flex items-center gap-2 text-green-700 font-medium mb-1">
                                            <CheckCircle className="h-4 w-4" />
                                            Visé par le DAF
                                        </div>
                                        <p className="text-green-600 text-xs">
                                            {rapport.visa_daf.prenom} {rapport.visa_daf.name}
                                            {rapport.date_visa_daf && (
                                                <> — {new Date(rapport.date_visa_daf).toLocaleDateString('fr-FR')}</>
                                            )}
                                        </p>
                                    </div>
                                )}

                                <Separator />

                                {/* Actions */}
                                {!rapport.visa_daf_id && ['daf', 'administrateur'].includes(userRole) && (
                                    <Dialog open={showViserDialog} onOpenChange={setShowViserDialog}>
                                        <DialogTrigger asChild>
                                            <Button className="w-full" variant="outline">
                                                <CheckCircle className="mr-2 h-4 w-4" />
                                                Apposer le visa DAF
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Apposer le visa DAF</DialogTitle>
                                                <DialogDescription>
                                                    Confirmez-vous la vérification comptable de ce rapport ? Votre nom apparaîtra sur les documents officiels.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter>
                                                <Button variant="outline" onClick={() => setShowViserDialog(false)}>Annuler</Button>
                                                <Button onClick={handleViserDaf} className="bg-green-600 hover:bg-green-700 text-white">Confirmer le visa</Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                )}

                                <Separator />

                                {/* Exports */}
                                <div className="space-y-2">
                                    <p className="text-xs text-gray-500 font-medium uppercase tracking-wide">Exporter</p>
                                    <a href={route('rapports.export-excel', rapport.id)} target="_blank" rel="noopener noreferrer" className="block">
                                        <Button variant="outline" className="w-full" size="sm">
                                            <FileSpreadsheet className="mr-2 h-4 w-4 text-green-600" />
                                            Excel
                                        </Button>
                                    </a>
                                    <a href={route('rapports.export-pdf', rapport.id)} target="_blank" rel="noopener noreferrer" className="block">
                                        <Button variant="outline" className="w-full" size="sm">
                                            <FileText className="mr-2 h-4 w-4 text-red-600" />
                                            PDF
                                        </Button>
                                    </a>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
