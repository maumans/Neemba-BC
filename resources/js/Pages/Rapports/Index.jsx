/**
 * Page Rapports de Caisse - NEEMBA
 * 
 * Affiche les rapports calculés en temps réel par jour/mois/année
 * à partir des bons de caisse payés.
 * 
 * Mobile-first, tout en français.
 */
import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { motion } from 'framer-motion';
import {
    BarChart3, Search, Wallet, TrendingUp, TrendingDown,
    Download, FileSpreadsheet, Calendar, CalendarDays, CalendarRange,
    Activity, Hash, AlertTriangle, MapPin, Send, Loader2,
    ChevronDown, ChevronRight, User, CreditCard
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
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
import { formaterMontant as formatMontant, formaterNombre } from '@/utils/nombreEnLettres';
import {
    AreaChart, Area,
    XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';

/** Tooltip personnalisé pour le graphe */
function TooltipRapport({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    return (
        <div className="bg-white border rounded-lg shadow-lg p-3 text-xs">
            <p className="font-semibold text-gray-800 mb-1">{label}</p>
            {payload.map((entry, i) => (
                <p key={i} style={{ color: entry.color }}>
                    {entry.name} : {formatMontant(entry.value)}
                </p>
            ))}
        </div>
    );
}

/** Helpers dates */
function aujourdhui() { return new Date().toISOString().split('T')[0]; }
function debutMois() { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().split('T')[0]; }
function debutAnnee() { return `${new Date().getFullYear()}-01-01`; }
function formatDateFr(str) { if (!str) return ''; return new Date(str + 'T00:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }); }

/** Traduction des jours de la semaine anglais → français */
const JOURS_FR = {
    'Monday': 'Lundi', 'Tuesday': 'Mardi', 'Wednesday': 'Mercredi',
    'Thursday': 'Jeudi', 'Friday': 'Vendredi', 'Saturday': 'Samedi', 'Sunday': 'Dimanche',
    'monday': 'lundi', 'tuesday': 'mardi', 'wednesday': 'mercredi',
    'thursday': 'jeudi', 'friday': 'vendredi', 'saturday': 'samedi', 'sunday': 'dimanche',
    'lundi': 'lundi', 'mardi': 'mardi', 'mercredi': 'mercredi',
    'jeudi': 'jeudi', 'vendredi': 'vendredi', 'samedi': 'samedi', 'dimanche': 'dimanche',
};
function jourFr(jour) {
    if (!jour) return '';
    return JOURS_FR[jour] || JOURS_FR[jour.toLowerCase()] || jour;
}

/** KPI configs avec classes statiques (pas de classes dynamiques Tailwind) */
const KPI_CONFIG = [
    { key: 'nombre_jours', label: 'Jours', icon: CalendarDays, bgCls: 'bg-blue-50', iconCls: 'text-blue-600', format: false },
    { key: 'total_bons', label: 'Bons payés', icon: Hash, bgCls: 'bg-indigo-50', iconCls: 'text-indigo-600', format: false },
    { key: 'total_entrees', label: 'Total entrées', icon: TrendingUp, bgCls: 'bg-emerald-50', iconCls: 'text-emerald-600', format: true },
    { key: 'total_sorties', label: 'Total sorties', icon: TrendingDown, bgCls: 'bg-red-50', iconCls: 'text-red-600', format: true },
    { key: 'moyenne_journaliere', label: 'Moy. / jour', icon: Activity, bgCls: 'bg-amber-50', iconCls: 'text-amber-600', format: true },
    { key: 'solde_actuel', label: 'Solde actuel', icon: Wallet, bgCls: 'bg-purple-50', iconCls: 'text-purple-600', format: true },
];

export default function Index({
    lignesRapport = [],
    granularite: granulariteServeur = 'jour',
    topCategories = [],
    categoriesDepense = {},
    sites = [],
    filtres = {},
    statsResume = {},
    evolutionSolde = [],
    soldeCaisseSite = null,
    siteUtilisateur = '',
    roleUtilisateur = '',
}) {
    const estCaissier = roleUtilisateur === 'caissier';
    const { flash } = usePage().props;
    const [envoyerEnCours, setEnvoyerEnCours] = useState(false);
    const [lignesExpansees, setLignesExpansees] = useState({});
    const [envoiParLigne, setEnvoiParLigne] = useState({});

    /** Basculer l'expansion d'une ligne */
    const toggleExpansion = (periode) => {
        setLignesExpansees(prev => ({ ...prev, [periode]: !prev[periode] }));
    };

    /** Envoyer le rapport d'un jour précis */
    const envoyerLigne = (periode, e) => {
        e.stopPropagation();
        setEnvoiParLigne(prev => ({ ...prev, [periode]: true }));
        router.post(route('rapports.envoyer-email'), {
            date: periode,
            site: site || undefined,
        }, {
            preserveScroll: true,
            onFinish: () => setEnvoiParLigne(prev => ({ ...prev, [periode]: false })),
        });
    };

    /* État local initialisé depuis les filtres serveur (source de vérité) */
    const [site, setSite] = useState(filtres.site || '');
    const [dateDebut, setDateDebut] = useState(filtres.date_debut || '');
    const [dateFin, setDateFin] = useState(filtres.date_fin || '');
    const [granularite, setGranularite] = useState(filtres.granularite || granulariteServeur);
    const [categorie, setCategorie] = useState(filtres.categorie || '');
    const [typeBon, setTypeBon] = useState(filtres.type_bon || '');

    /** Envoyer le rapport du jour par email (pour le site sélectionné ou tous les sites) */
    const handleEnvoyerEmail = () => {
        setEnvoyerEnCours(true);
        const payload = { date: dateDebut || aujourdhui() };
        if (site) payload.site = site;
        router.post(route('rapports.envoyer-email'), payload, {
            preserveScroll: true,
            onFinish: () => setEnvoyerEnCours(false),
        });
    };

    /** Navigation cohérente : construit les params à partir des valeurs passées (pas de stale state) */
    const naviguer = (overrides = {}) => {
        const base = {
            site: site || undefined,
            date_debut: dateDebut || undefined,
            date_fin: dateFin || undefined,
            granularite: granularite || undefined,
            categorie: categorie || undefined,
            type_bon: typeBon || undefined,
        };
        const params = { ...base, ...overrides };
        Object.keys(params).forEach(k => { if (!params[k]) delete params[k]; });
        router.get(route('rapports.index'), params, { preserveScroll: true });
    };

    /** Raccourcis de période : met à jour state + navigue en 1 appel cohérent */
    const setPeriode = (type) => {
        let dd, df, gr;
        switch (type) {
            case 'jour':  dd = aujourdhui(); df = aujourdhui(); gr = 'jour'; break;
            case 'mois':  dd = debutMois();  df = aujourdhui(); gr = 'jour'; break;
            case 'annee': dd = debutAnnee(); df = aujourdhui(); gr = 'mois'; break;
            default: return;
        }
        setDateDebut(dd); setDateFin(df); setGranularite(gr);
        naviguer({ date_debut: dd, date_fin: df, granularite: gr });
    };

    /** Changer la granularité en gardant les dates actuelles */
    const changerGranularite = (gr) => {
        setGranularite(gr);
        naviguer({ granularite: gr });
    };

    /** Appliquer les filtres du formulaire */
    const appliquerFiltres = () => naviguer();

    /* Label période affiché */
    const labelPeriode = dateDebut && dateFin
        ? `${formatDateFr(dateDebut)} — ${formatDateFr(dateFin)}`
        : `Mois de ${new Date().toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}`;

    /* Détection période active pour highlight boutons */
    const periodeActive = (() => {
        if (dateDebut === aujourdhui() && dateFin === aujourdhui()) return 'jour';
        if (dateDebut === debutMois() && dateFin === aujourdhui()) return 'mois';
        if (dateDebut === debutAnnee() && dateFin === aujourdhui()) return 'annee';
        return null;
    })();

    return (
        <AuthenticatedLayout header="Rapports de Caisse">
            <Head title="Rapports de Caisse" />

            <div className="max-w-full overflow-hidden">
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
                {flash?.error && (
                    <motion.div
                        initial={{ opacity: 0, y: -10 }}
                        animate={{ opacity: 1, y: 0 }}
                        className="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm border border-red-200"
                    >
                        {flash.error}
                    </motion.div>
                )}

                {/* En-tête */}
                <div className="mb-5">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <h1 className="text-xl sm:text-2xl font-bold text-gray-900">Rapports de Caisse</h1>
                            <p className="text-gray-500 text-xs sm:text-sm mt-0.5 truncate">
                                Calcul en temps réel · {labelPeriode}
                                {soldeCaisseSite && <span className="ml-1">· <MapPin className="h-3 w-3 inline" /> {soldeCaisseSite.nom}</span>}
                            </p>
                        </div>

                        {/* Widget solde caisse du site */}
                        {soldeCaisseSite && (
                            <div className={`flex items-center gap-3 px-4 py-2.5 rounded-lg border ${
                                soldeCaisseSite.sous_seuil ? 'border-red-200 bg-red-50' : 'border-neemba-200 bg-neemba-50'
                            }`}>
                                <Wallet className={`h-5 w-5 ${soldeCaisseSite.sous_seuil ? 'text-red-500' : 'text-neemba-600'}`} />
                                <div>
                                    <p className="text-[10px] text-gray-500 uppercase tracking-wider">Solde caisse {soldeCaisseSite.nom}</p>
                                    <p className={`text-base sm:text-lg font-bold ${soldeCaisseSite.sous_seuil ? 'text-red-600' : 'text-gray-900'}`}>
                                        {soldeCaisseSite.solde_format}
                                    </p>
                                    {soldeCaisseSite.plafond_caisse && (
                                        <div className="mt-1">
                                            <div className="h-1 bg-gray-200 rounded-full w-32">
                                                <div
                                                    className={`h-1 rounded-full ${soldeCaisseSite.sous_seuil ? 'bg-red-400' : 'bg-neemba-400'}`}
                                                    style={{ width: `${Math.min((soldeCaisseSite.solde / soldeCaisseSite.plafond_caisse) * 100, 100)}%` }}
                                                />
                                            </div>
                                            <p className="text-[9px] text-gray-400 mt-0.5">Plafond : {soldeCaisseSite.plafond_format}</p>
                                        </div>
                                    )}
                                    {soldeCaisseSite.sous_seuil && (
                                        <p className="text-[10px] text-red-500 flex items-center gap-0.5 mt-0.5">
                                            <AlertTriangle className="h-2.5 w-2.5" /> Sous le seuil minimum
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>


                {/* Boutons période + granularité */}
                <div className="flex flex-wrap items-center gap-1.5 sm:gap-2 mb-4">
                    <span className="text-xs sm:text-sm text-gray-500">Période :</span>
                    {[
                        { key: 'jour', label: "Aujourd'hui", icon: Calendar },
                        { key: 'mois', label: 'Ce mois', icon: CalendarDays },
                        { key: 'annee', label: 'Cette année', icon: CalendarRange },
                    ].map(({ key, label, icon: Icon }) => (
                        <Button
                            key={key}
                            variant={periodeActive === key ? 'default' : 'outline'}
                            size="sm"
                            className="text-xs sm:text-sm h-7 sm:h-8 px-2 sm:px-3"
                            onClick={() => setPeriode(key)}
                        >
                            <Icon className="h-3 w-3 sm:h-3.5 sm:w-3.5 mr-1" />
                            <span className="hidden xs:inline">{label}</span>
                            <span className="xs:hidden">{key === 'jour' ? 'Jour' : key === 'mois' ? 'Mois' : 'Année'}</span>
                        </Button>
                    ))}

                    <span className="text-gray-300 mx-0.5">|</span>

                    <span className="text-xs sm:text-sm text-gray-500">Vue :</span>
                    {[
                        { key: 'jour', label: 'Jour' },
                        { key: 'mois', label: 'Mois' },
                        { key: 'annee', label: 'Année' },
                    ].map(({ key, label }) => (
                        <Button
                            key={key}
                            variant={granularite === key ? 'default' : 'outline'}
                            size="sm"
                            className="text-xs sm:text-sm h-7 sm:h-8 px-2 sm:px-3"
                            onClick={() => changerGranularite(key)}
                        >
                            {label}
                        </Button>
                    ))}
                </div>

                {/* KPIs Résumé */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 sm:gap-3 mb-5">
                    {KPI_CONFIG.map((kpi, i) => {
                        const Icon = kpi.icon;
                        const val = statsResume[kpi.key] ?? 0;
                        return (
                            <motion.div key={kpi.key} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.04 }}>
                                <Card className="h-full">
                                    <CardContent className="p-2.5 sm:p-3">
                                        <div className={`p-1.5 rounded-lg ${kpi.bgCls} w-fit mb-1`}>
                                            <Icon className={`h-3.5 w-3.5 ${kpi.iconCls}`} />
                                        </div>
                                        <p className={`${kpi.format ? 'text-xs sm:text-sm' : 'text-lg sm:text-xl'} font-bold truncate`}>
                                            {kpi.format ? formatMontant(val) : val}
                                        </p>
                                        <p className="text-[10px] text-muted-foreground">{kpi.label}</p>
                                    </CardContent>
                                </Card>
                            </motion.div>
                        );
                    })}
                </div>

                {/* Filtres détaillés */}
                <Card className="mb-5">
                    <CardContent className="p-3 sm:p-4">
                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 sm:gap-3 items-end">
                            <div className="col-span-2 sm:col-span-1">
                                <label className="text-xs text-gray-500 mb-1 block">Site</label>
                                {estCaissier ? (
                                    <Input value={siteUtilisateur} disabled className="h-9 text-sm bg-gray-50" />
                                ) : sites.length > 0 ? (
                                    <Select value={site || 'tous'} onValueChange={(val) => setSite(val === 'tous' ? '' : val)}>
                                        <SelectTrigger className="h-9 text-sm"><SelectValue placeholder="Tous les sites" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="tous">Tous les sites</SelectItem>
                                            {sites.map((s) => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    <Input placeholder="Site..." value={site} onChange={(e) => setSite(e.target.value)} className="h-9 text-sm" />
                                )}
                            </div>
                            <div>
                                <label className="text-xs text-gray-500 mb-1 block">Début</label>
                                <Input type="date" value={dateDebut} onChange={(e) => setDateDebut(e.target.value)} className="h-9 text-sm" />
                            </div>
                            <div>
                                <label className="text-xs text-gray-500 mb-1 block">Fin</label>
                                <Input type="date" value={dateFin} onChange={(e) => setDateFin(e.target.value)} className="h-9 text-sm" />
                            </div>
                            {Object.keys(categoriesDepense).length > 0 && (
                                <div>
                                    <label className="text-xs text-gray-500 mb-1 block">Catégorie</label>
                                    <Select value={categorie || 'toutes'} onValueChange={(val) => setCategorie(val === 'toutes' ? '' : val)}>
                                        <SelectTrigger className="h-9 text-sm"><SelectValue placeholder="Toutes" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="toutes">Toutes</SelectItem>
                                            {Object.entries(categoriesDepense).map(([k, v]) => (
                                                <SelectItem key={k} value={k}>{v}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}
                            <div>
                                <label className="text-xs text-gray-500 mb-1 block">Type</label>
                                <Select value={typeBon || 'tous'} onValueChange={(val) => setTypeBon(val === 'tous' ? '' : val)}>
                                    <SelectTrigger className="h-9 text-sm"><SelectValue placeholder="Tous" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="tous">Tous</SelectItem>
                                        <SelectItem value="BD">Définitif</SelectItem>
                                        <SelectItem value="BP">Provisoire</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Button onClick={appliquerFiltres} variant="outline" className="w-full h-9 text-sm">
                                    <Search className="h-3.5 w-3.5 mr-1" />
                                    Filtrer
                                </Button>
                            </div>
                        </div>

                        {/* Exports */}
                        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 mt-3 pt-3 border-t">
                            <p className="text-xs sm:text-sm text-gray-500 truncate max-w-full">
                                Période : <strong className="text-gray-700">{labelPeriode}</strong>
                            </p>
                            <div className="flex gap-2 flex-shrink-0 flex-wrap">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="text-neemba-700 border-neemba-300 hover:bg-neemba-50 h-7 text-xs"
                                    onClick={handleEnvoyerEmail}
                                    disabled={envoyerEnCours}
                                >
                                    {envoyerEnCours
                                        ? <Loader2 className="h-3.5 w-3.5 mr-1 animate-spin" />
                                        : <Send className="h-3.5 w-3.5 mr-1" />
                                    }
                                    {envoyerEnCours ? 'Envoi…' : 'Envoyer par email'}
                                </Button>
                                <a
                                    href={route('rapports.temps-reel.export-excel', {
                                        date_debut: dateDebut || debutMois(),
                                        date_fin: dateFin || aujourdhui(),
                                        granularite: granularite || undefined,
                                        site: site || undefined,
                                        categorie: categorie || undefined,
                                        type_bon: typeBon || undefined,
                                    })}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Button variant="outline" size="sm" className="text-green-700 border-green-300 hover:bg-green-50 h-7 text-xs">
                                        <FileSpreadsheet className="h-3.5 w-3.5 mr-1" />
                                        Excel
                                    </Button>
                                </a>
                                <a
                                    href={route('rapports.temps-reel.export-pdf', {
                                        date_debut: dateDebut || debutMois(),
                                        date_fin: dateFin || aujourdhui(),
                                        granularite: granularite || undefined,
                                        site: site || undefined,
                                        categorie: categorie || undefined,
                                        type_bon: typeBon || undefined,
                                    })}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Button variant="outline" size="sm" className="text-red-700 border-red-300 hover:bg-red-50 h-7 text-xs">
                                        <Download className="h-3.5 w-3.5 mr-1" />
                                        PDF
                                    </Button>
                                </a>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <>
                {/* Tableau des lignes calculées en temps réel */}
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3 }}
                    className="mb-5"
                >
                    <Card>
                        <CardHeader className="pb-2 px-3 sm:px-6">
                            <CardTitle className="text-sm sm:text-base flex items-center gap-2">
                                <BarChart3 className="h-4 w-4 text-neemba-500" />
                                Détail {granularite === 'jour' ? 'journalier' : granularite === 'mois' ? 'mensuel' : 'annuel'}
                            </CardTitle>
                            <CardDescription className="text-xs">
                                {lignesRapport.length} {granularite === 'jour' ? 'jour(s)' : granularite === 'mois' ? 'mois' : 'année(s)'}
                                {' · '}Calculé en temps réel
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            {lignesRapport.length === 0 ? (
                                <div className="text-center py-12 px-4">
                                    <BarChart3 className="h-10 w-10 mx-auto text-gray-300 mb-2" />
                                    <p className="text-gray-500 font-medium text-sm">Aucune donnée sur cette période</p>
                                    <p className="text-gray-400 text-xs mt-1">Modifiez les filtres ou la période</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto -mx-px">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="text-xs">Période</TableHead>
                                                {granularite === 'jour' && <TableHead className="text-xs hidden sm:table-cell">Jour</TableHead>}
                                                <TableHead className="text-xs text-center">Bons</TableHead>
                                                <TableHead className="text-xs text-right">Entrées</TableHead>
                                                <TableHead className="text-xs text-right">Sorties</TableHead>
                                                <TableHead className="text-xs text-right">Solde</TableHead>
                                                {granularite === 'jour' && <TableHead className="text-xs text-right">Actions</TableHead>}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {[...lignesRapport].reverse().map((ligne) => {
                                                const aSorties = ligne.sorties > 0;
                                                const aEntrees = ligne.entrees > 0;
                                                const aDesBons = granularite === 'jour' && ligne.bons && ligne.bons.length > 0;
                                                const estExpansee = lignesExpansees[ligne.periode];
                                                const nbCols = granularite === 'jour' ? 7 : 5;
                                                return (
                                                    <React.Fragment key={ligne.periode}>
                                                        <TableRow
                                                            className={`${!aSorties && !aEntrees ? 'opacity-40' : ''} ${aDesBons ? 'cursor-pointer hover:bg-gray-50' : ''}`}
                                                            onClick={() => aDesBons && toggleExpansion(ligne.periode)}
                                                        >
                                                            <TableCell className="font-medium text-xs sm:text-sm py-2">
                                                                <div className="flex items-center gap-1">
                                                                    {aDesBons && (
                                                                        estExpansee
                                                                            ? <ChevronDown className="h-3.5 w-3.5 text-neemba-500 flex-shrink-0" />
                                                                            : <ChevronRight className="h-3.5 w-3.5 text-gray-400 flex-shrink-0" />
                                                                    )}
                                                                    <span>{ligne.label}</span>
                                                                    {granularite === 'jour' && ligne.jour_semaine && (
                                                                        <span className="sm:hidden text-[10px] text-gray-400 ml-1 capitalize">
                                                                            ({jourFr(ligne.jour_semaine).slice(0, 3)})
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </TableCell>
                                                            {granularite === 'jour' && (
                                                                <TableCell className="text-xs text-gray-500 capitalize hidden sm:table-cell py-2">
                                                                    {jourFr(ligne.jour_semaine)}
                                                                </TableCell>
                                                            )}
                                                            <TableCell className="text-center py-2">
                                                                {ligne.nombre_bons > 0 ? (
                                                                    <Badge variant="secondary" className="text-[10px] px-1.5 py-0">
                                                                        {ligne.nombre_bons}
                                                                    </Badge>
                                                                ) : (
                                                                    <span className="text-gray-300 text-xs">-</span>
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-right text-xs sm:text-sm py-2">
                                                                {aEntrees ? (
                                                                    <span className="text-green-600 font-medium">+{formatMontant(ligne.entrees)}</span>
                                                                ) : (
                                                                    <span className="text-gray-300">-</span>
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-right text-xs sm:text-sm py-2">
                                                                {aSorties ? (
                                                                    <span className="text-red-600 font-medium">-{formatMontant(ligne.sorties)}</span>
                                                                ) : (
                                                                    <span className="text-gray-300">-</span>
                                                                )}
                                                            </TableCell>
                                                            <TableCell className="text-right font-semibold text-xs sm:text-sm py-2">
                                                                <span className={ligne.solde < 0 ? 'text-red-600' : ''}>
                                                                    {formatMontant(ligne.solde)}
                                                                </span>
                                                            </TableCell>
                                                            {granularite === 'jour' && (
                                                                <TableCell className="py-2">
                                                                    {aDesBons && (
                                                                        <div className="flex items-center justify-end gap-0.5">
                                                                            <button
                                                                                onClick={(e) => envoyerLigne(ligne.periode, e)}
                                                                                disabled={envoiParLigne[ligne.periode]}
                                                                                className="inline-flex items-center justify-center rounded p-1 text-neemba-600 hover:bg-neemba-50 hover:text-neemba-700 disabled:opacity-50 transition-colors"
                                                                                title={`Envoyer par email`}
                                                                            >
                                                                                {envoiParLigne[ligne.periode]
                                                                                    ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                                                    : <Send className="h-3.5 w-3.5" />
                                                                                }
                                                                            </button>
                                                                            <a
                                                                                href={route('rapports.temps-reel.export-excel', {
                                                                                    date_debut: ligne.periode,
                                                                                    date_fin: ligne.periode,
                                                                                    site: site || undefined,
                                                                                })}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                onClick={(e) => e.stopPropagation()}
                                                                                className="inline-flex items-center justify-center rounded p-1 text-green-600 hover:bg-green-50 hover:text-green-700 transition-colors"
                                                                                title="Exporter en Excel"
                                                                            >
                                                                                <FileSpreadsheet className="h-3.5 w-3.5" />
                                                                            </a>
                                                                            <a
                                                                                href={route('rapports.temps-reel.export-pdf', {
                                                                                    date_debut: ligne.periode,
                                                                                    date_fin: ligne.periode,
                                                                                    site: site || undefined,
                                                                                })}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                onClick={(e) => e.stopPropagation()}
                                                                                className="inline-flex items-center justify-center rounded p-1 text-red-600 hover:bg-red-50 hover:text-red-700 transition-colors"
                                                                                title="Exporter en PDF"
                                                                            >
                                                                                <Download className="h-3.5 w-3.5" />
                                                                            </a>
                                                                        </div>
                                                                    )}
                                                                </TableCell>
                                                            )}
                                                        </TableRow>

                                                        {/* Détail des bons (expansion) */}
                                                        {aDesBons && estExpansee && (
                                                            <TableRow className="bg-amber-50/50">
                                                                <TableCell colSpan={nbCols} className="p-0">
                                                                    <motion.div
                                                                        initial={{ opacity: 0, height: 0 }}
                                                                        animate={{ opacity: 1, height: 'auto' }}
                                                                        exit={{ opacity: 0, height: 0 }}
                                                                        transition={{ duration: 0.2 }}
                                                                        className="px-3 sm:px-6 py-3"
                                                                    >
                                                                        <p className="text-xs font-semibold text-gray-600 mb-2 flex items-center gap-1.5">
                                                                            <CreditCard className="h-3.5 w-3.5 text-neemba-500" />
                                                                            Bons payés le {ligne.label} ({ligne.bons.length})
                                                                        </p>
                                                                        <div className="overflow-x-auto rounded-md border border-amber-200/60">
                                                                            <table className="w-full text-xs">
                                                                                <thead>
                                                                                    <tr className="bg-amber-100/60 text-gray-600">
                                                                                        <th className="text-left px-2.5 py-1.5 font-medium">N°</th>
                                                                                        <th className="text-left px-2.5 py-1.5 font-medium">Bénéficiaire</th>
                                                                                        <th className="text-left px-2.5 py-1.5 font-medium hidden md:table-cell">Motif</th>
                                                                                        <th className="text-left px-2.5 py-1.5 font-medium hidden lg:table-cell">Catégorie</th>
                                                                                        <th className="text-left px-2.5 py-1.5 font-medium hidden lg:table-cell">Type</th>
                                                                                        <th className="text-right px-2.5 py-1.5 font-medium">Montant</th>
                                                                                    </tr>
                                                                                </thead>
                                                                                <tbody>
                                                                                    {ligne.bons.map((bon) => (
                                                                                        <tr key={bon.id} className="border-t border-amber-100 hover:bg-amber-50">
                                                                                            <td className="px-2.5 py-1.5 font-mono text-neemba-700 whitespace-nowrap">{bon.numero}</td>
                                                                                            <td className="px-2.5 py-1.5">
                                                                                                <div className="font-medium text-gray-800">{bon.beneficiaire}</div>
                                                                                                {bon.demandeur && bon.demandeur !== bon.beneficiaire && (
                                                                                                    <div className="text-[10px] text-gray-400 flex items-center gap-0.5">
                                                                                                        <User className="h-2.5 w-2.5" /> {bon.demandeur}
                                                                                                    </div>
                                                                                                )}
                                                                                            </td>
                                                                                            <td className="px-2.5 py-1.5 text-gray-600 hidden md:table-cell max-w-[200px] truncate">{bon.motif}</td>
                                                                                            <td className="px-2.5 py-1.5 hidden lg:table-cell">
                                                                                                <Badge variant="outline" className="text-[10px] px-1.5 py-0">{bon.categorie}</Badge>
                                                                                            </td>
                                                                                            <td className="px-2.5 py-1.5 hidden lg:table-cell">
                                                                                                <Badge variant={bon.type_bon === 'BD' ? 'default' : 'secondary'} className="text-[10px] px-1.5 py-0">
                                                                                                    {bon.type_bon}
                                                                                                </Badge>
                                                                                            </td>
                                                                                            <td className="px-2.5 py-1.5 text-right font-semibold text-red-600 whitespace-nowrap">
                                                                                                -{formatMontant(bon.montant)}
                                                                                            </td>
                                                                                        </tr>
                                                                                    ))}
                                                                                </tbody>
                                                                            </table>
                                                                        </div>
                                                                    </motion.div>
                                                                </TableCell>
                                                            </TableRow>
                                                        )}
                                                    </React.Fragment>
                                                );
                                            })}
                                            {/* Ligne total */}
                                            <TableRow className="bg-gray-50 font-bold border-t-2">
                                                <TableCell className="text-xs sm:text-sm py-2">TOTAL</TableCell>
                                                {granularite === 'jour' && <TableCell className="hidden sm:table-cell py-2" />}
                                                <TableCell className="text-center text-xs sm:text-sm py-2">
                                                    {statsResume.total_bons || 0}
                                                </TableCell>
                                                <TableCell className="text-right text-xs sm:text-sm text-green-700 py-2">
                                                    +{formatMontant(statsResume.total_entrees)}
                                                </TableCell>
                                                <TableCell className="text-right text-xs sm:text-sm text-red-700 py-2">
                                                    -{formatMontant(statsResume.total_sorties)}
                                                </TableCell>
                                                <TableCell className="text-right text-xs sm:text-sm py-2">
                                                    {formatMontant(statsResume.solde_actuel)}
                                                </TableCell>
                                                {granularite === 'jour' && <TableCell className="py-2" />}
                                            </TableRow>
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Graphique évolution */}
                {evolutionSolde.length > 1 && (
                    <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }} className="mb-5">
                        <Card>
                            <CardHeader className="pb-2 px-3 sm:px-6">
                                <CardTitle className="text-sm sm:text-base flex items-center gap-2">
                                    <TrendingUp className="h-4 w-4 text-neemba-500" />
                                    Évolution de la caisse
                                </CardTitle>
                                <CardDescription className="text-xs">Solde, entrées et sorties sur la période</CardDescription>
                            </CardHeader>
                            <CardContent className="px-1 sm:px-6 pb-3">
                                <ResponsiveContainer width="100%" height={220}>
                                    <AreaChart data={evolutionSolde} margin={{ top: 5, right: 5, left: -20, bottom: 0 }}>
                                        <defs>
                                            <linearGradient id="gradSolde" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#fdc911" stopOpacity={0.3} />
                                                <stop offset="95%" stopColor="#fdc911" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                                        <XAxis dataKey="date" tick={{ fontSize: 10 }} />
                                        <YAxis tick={{ fontSize: 9 }} tickFormatter={(v) => v >= 1000000 ? `${(v / 1000000).toFixed(0)}M` : formaterNombre(v)} />
                                        <Tooltip content={<TooltipRapport />} />
                                        <Legend wrapperStyle={{ fontSize: 10 }} />
                                        <Area type="monotone" dataKey="solde" name="Solde" stroke="#fdc911" fill="url(#gradSolde)" strokeWidth={2} />
                                        <Area type="monotone" dataKey="entrees" name="Entrées" stroke="#10b981" fill="none" strokeWidth={1.5} />
                                        <Area type="monotone" dataKey="sorties" name="Sorties" stroke="#ef4444" fill="none" strokeWidth={1.5} strokeDasharray="4 4" />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </CardContent>
                        </Card>
                    </motion.div>
                )}

                {/* Top catégories */}
                {topCategories.length > 0 && (
                    <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.25 }} className="mb-5">
                        <Card>
                            <CardHeader className="pb-2 px-3 sm:px-6">
                                <CardTitle className="text-sm sm:text-base">Top catégories de dépenses</CardTitle>
                                <CardDescription className="text-xs">Sur la période sélectionnée</CardDescription>
                            </CardHeader>
                            <CardContent className="px-3 sm:px-6">
                                <div className="space-y-3">
                                    {topCategories.map((cat, i) => {
                                        const maxTotal = topCategories[0]?.total || 1;
                                        const pourcent = Math.round((cat.total / maxTotal) * 100);
                                        return (
                                            <div key={cat.categorie}>
                                                <div className="flex items-center justify-between text-xs sm:text-sm mb-1 gap-2">
                                                    <span className="font-medium truncate">{cat.label}</span>
                                                    <span className="text-gray-500 flex-shrink-0 text-xs">
                                                        {formatMontant(cat.total)} ({cat.nombre})
                                                    </span>
                                                </div>
                                                <div className="h-1.5 sm:h-2 bg-gray-100 rounded-full overflow-hidden">
                                                    <motion.div
                                                        className="h-full bg-neemba-400 rounded-full"
                                                        initial={{ width: 0 }}
                                                        animate={{ width: `${pourcent}%` }}
                                                        transition={{ duration: 0.5, delay: i * 0.1 }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                )}
                </>
            </div>
        </AuthenticatedLayout>
    );
}
