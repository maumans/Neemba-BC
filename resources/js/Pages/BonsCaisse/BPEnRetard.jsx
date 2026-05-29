/**
 * Page BP en Retard de Régularisation - NEEMBA
 *
 * Tableau de suivi des bons provisoires dont la date limite
 * de régularisation est dépassée. Accessible aux DAF, DP et Administrateurs.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    AlertTriangle,
    ArrowLeft,
    Calendar,
    Clock,
    Filter,
    MapPin,
    RefreshCcw,
    Users,
    Wallet,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { formaterNombre } from '@/utils/nombreEnLettres';
import { useState } from 'react';

export default function BPEnRetard({
    bonsEnRetard,
    stats = {},
    sites = [],
    services = [],
    filtres = {},
}) {
    const { flash } = usePage().props;

    const [site, setSite]       = useState(filtres.site     || '');
    const [service, setService] = useState(filtres.service  || '');

    const appliquerFiltres = () => {
        const params = {};
        if (site)    params.site    = site;
        if (service) params.service = service;
        router.get(route('bons-caisse.bp-en-retard'), params, { preserveState: true });
    };

    const reinitialiserFiltres = () => {
        setSite('');
        setService('');
        router.get(route('bons-caisse.bp-en-retard'));
    };

    const joursRetard = (dateStr) => {
        const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 86_400_000);
        return diff > 0 ? diff : 0;
    };

    const badgeRetard = (jours) => {
        if (jours >= 14) return 'destructive';
        if (jours >= 7)  return 'urgente';
        return 'outline';
    };

    return (
        <AuthenticatedLayout header="BP en retard de régularisation">
            <Head title="BP en retard — NEEMBA" />

            {/* Retour */}
            <div className="mb-6">
                <Link
                    href={route('bons-caisse.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour aux bons de caisse
                </Link>
            </div>

            {/* Flash */}
            {flash?.success && (
                <div className="mb-4 p-3 rounded-lg bg-green-50 text-green-700 text-sm border border-green-200">
                    {flash.success}
                </div>
            )}

            {/* Bandeau d'alerte */}
            <motion.div
                initial={{ opacity: 0, y: -10 }}
                animate={{ opacity: 1, y: 0 }}
                className="mb-6 flex items-center gap-3 p-4 rounded-xl bg-red-50 border border-red-200"
            >
                <AlertTriangle className="h-5 w-5 text-red-500 flex-shrink-0" />
                <p className="text-sm text-red-700">
                    Ces bons provisoires ont dépassé leur délai de régularisation.
                    Les demandeurs et leurs responsables ont été notifiés automatiquement.
                    Une action est requise pour chaque ligne.
                </p>
            </motion.div>

            {/* KPIs */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                    <Card className="border-red-100">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="h-10 w-10 rounded-lg bg-red-100 flex items-center justify-center">
                                    <AlertTriangle className="h-5 w-5 text-red-600" />
                                </div>
                                <div>
                                    <p className="text-xs text-gray-500">BP en retard</p>
                                    <p className="text-2xl font-bold text-red-600">{stats.total ?? 0}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>

                <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.05 }}>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="h-10 w-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                    <Wallet className="h-5 w-5 text-amber-600" />
                                </div>
                                <div>
                                    <p className="text-xs text-gray-500">Montant total</p>
                                    <p className="text-lg font-bold text-gray-800">
                                        {formaterNombre(stats.montant_total ?? 0)} GNF
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>

                <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="h-10 w-10 rounded-lg bg-orange-100 flex items-center justify-center">
                                    <Clock className="h-5 w-5 text-orange-600" />
                                </div>
                                <div>
                                    <p className="text-xs text-gray-500">Retard moyen</p>
                                    <p className="text-2xl font-bold text-gray-800">
                                        {stats.retard_moyen_j ?? 0}j
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
            </div>

            {/* Filtres */}
            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm flex items-center gap-2">
                        <Filter className="h-4 w-4 text-gray-400" />
                        Filtres
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="flex flex-wrap gap-3 items-end">
                        <div className="min-w-[180px]">
                            <label className="text-xs text-gray-500 mb-1 block">Site</label>
                            <Select value={site || '__all__'} onValueChange={(v) => setSite(v === '__all__' ? '' : v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Tous les sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__all__">Tous les sites</SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="min-w-[180px]">
                            <label className="text-xs text-gray-500 mb-1 block">Service</label>
                            <Select value={service || '__all__'} onValueChange={(v) => setService(v === '__all__' ? '' : v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Tous les services" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__all__">Tous les services</SelectItem>
                                    {services.map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <Button onClick={appliquerFiltres} size="sm">
                            <Filter className="mr-2 h-3.5 w-3.5" />
                            Filtrer
                        </Button>
                        <Button onClick={reinitialiserFiltres} variant="ghost" size="sm">
                            <RefreshCcw className="mr-2 h-3.5 w-3.5" />
                            Réinitialiser
                        </Button>
                    </div>
                </CardContent>
            </Card>

            {/* Tableau */}
            <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}>
                <Card>
                    <CardContent className="p-0">
                        {bonsEnRetard.data.length === 0 ? (
                            <div className="text-center py-16 text-gray-400">
                                <AlertTriangle className="h-10 w-10 mx-auto mb-3 opacity-30" />
                                <p className="text-sm">Aucun bon provisoire en retard 🎉</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Numéro</TableHead>
                                            <TableHead>Demandeur</TableHead>
                                            <TableHead className="flex items-center gap-1">
                                                <MapPin className="h-3 w-3" /> Site / Service
                                            </TableHead>
                                            <TableHead className="text-right">Montant</TableHead>
                                            <TableHead className="text-center">
                                                <Calendar className="h-3 w-3 inline mr-1" />
                                                Date limite
                                            </TableHead>
                                            <TableHead className="text-center">
                                                <Clock className="h-3 w-3 inline mr-1" />
                                                Retard
                                            </TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {bonsEnRetard.data.map((bon) => {
                                            const jours = joursRetard(bon.date_limite_regularisation);
                                            return (
                                                <TableRow key={bon.id} className={jours >= 14 ? 'bg-red-50/50' : ''}>
                                                    <TableCell className="font-mono font-medium text-neemba-600">
                                                        {bon.numero}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-2">
                                                            <div className="h-7 w-7 rounded-full bg-gray-100 flex items-center justify-center">
                                                                <Users className="h-3.5 w-3.5 text-gray-400" />
                                                            </div>
                                                            <span className="text-sm">
                                                                {bon.demandeur?.prenom
                                                                    ? `${bon.demandeur.prenom} ${bon.demandeur.name}`
                                                                    : bon.demandeur?.name ?? '—'}
                                                            </span>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="text-sm">{bon.site}</div>
                                                        <div className="text-xs text-gray-400">{bon.service}</div>
                                                    </TableCell>
                                                    <TableCell className="text-right font-semibold">
                                                        {formaterNombre(bon.montant)} GNF
                                                    </TableCell>
                                                    <TableCell className="text-center text-sm">
                                                        {new Date(bon.date_limite_regularisation).toLocaleDateString('fr-FR')}
                                                    </TableCell>
                                                    <TableCell className="text-center">
                                                        <Badge variant={badgeRetard(jours)}>
                                                            {jours}j
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        <Link href={route('bons-caisse.show', bon.id)}>
                                                            <Button variant="outline" size="sm">
                                                                Voir
                                                            </Button>
                                                        </Link>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </motion.div>

            {/* Pagination */}
            {bonsEnRetard.last_page > 1 && (
                <div className="flex justify-center gap-2 mt-4">
                    {bonsEnRetard.links.map((link, i) => (
                        <Button
                            key={i}
                            variant={link.active ? 'default' : 'outline'}
                            size="sm"
                            disabled={!link.url}
                            onClick={() => link.url && router.get(link.url)}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
