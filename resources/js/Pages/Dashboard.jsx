/**
 * Page Tableau de Bord - NEEMBA
 *
 * Dashboard enrichi par rôle avec KPIs, stats du mois, bons à payer,
 * BP en retard, répartition par catégorie, activité récente,
 * graphiques d'évolution, donut statuts, top bénéficiaires, taux d'approbation.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    FileText,
    Clock,
    CheckCircle2,
    Banknote,
    XCircle,
    TrendingUp,
    ArrowRight,
    Plus,
    AlertTriangle,
    CalendarDays,
    Activity,
    Wallet,
    BarChart3,
    Timer,
    ThumbsUp,
    Users,
    ArrowRightLeft,
    MapPin,
    ArrowUpCircle,
    ArrowDownCircle,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { formaterMontant as formatMontant, formaterNombre } from '@/utils/nombreEnLettres';
import {
    BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
    PieChart, Pie, Cell,
    AreaChart, Area,
} from 'recharts';

/* ------------------------------------------------------------------ */
/*  Utilitaires                                                        */
/* ------------------------------------------------------------------ */

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('fr-FR', {
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    });
}

function badgeVariantParStatut(statut) {
    const map = {
        BROUILLON: 'brouillon',
        EN_ATTENTE_CHEF_SERVICE: 'en_attente',
        EN_ATTENTE_CDG: 'en_attente',
        EN_ATTENTE_DAF: 'en_attente',
        EN_ATTENTE_DP: 'en_attente',
        APPROUVE: 'approuve',
        PAYE: 'paye',
        REJETE: 'rejete',
        EN_ATTENTE_REGULARISATION: 'en_attente',
        REGULARISE: 'regularise',
        ARCHIVE: 'brouillon',
    };
    return map[statut] || 'default';
}

/** Animation pour les cartes */
const cardVariants = {
    hidden: { opacity: 0, y: 20 },
    visible: (i) => ({
        opacity: 1, y: 0,
        transition: { delay: i * 0.08, duration: 0.35, ease: 'easeOut' },
    }),
};

const fadeUp = (delay = 0) => ({
    initial: { opacity: 0, y: 20 },
    animate: { opacity: 1, y: 0 },
    transition: { delay, duration: 0.4 },
});

/** Couleurs pour les statuts (PieChart) */
const COULEURS_STATUTS = {
    BROUILLON: '#94a3b8',
    EN_ATTENTE_CHEF_SERVICE: '#f59e0b',
    EN_ATTENTE_CDG: '#f97316',
    EN_ATTENTE_DAF: '#fb923c',
    EN_ATTENTE_DP: '#fbbf24',
    APPROUVE: '#22c55e',
    PAYE: '#10b981',
    REJETE: '#ef4444',
    EN_ATTENTE_REGULARISATION: '#a855f7',
    REGULARISE: '#14b8a6',
    ARCHIVE: '#cbd5e1',
};
const COULEURS_PIE = ['#fdc911', '#22c55e', '#3b82f6', '#ef4444', '#f59e0b', '#10b981', '#a855f7', '#14b8a6', '#94a3b8', '#fb923c', '#cbd5e1'];

/** Tooltip personnalisé pour les graphiques montants */
function TooltipMontant({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    return (
        <div className="bg-white border rounded-lg shadow-lg p-3 text-xs">
            <p className="font-semibold text-gray-800 mb-1">{label}</p>
            {payload.map((entry, i) => (
                <p key={i} style={{ color: entry.color }}>
                    {entry.name} : {entry.name.includes('Montant') ? formatMontant(entry.value) : entry.value}
                </p>
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Composant principal                                                */
/* ------------------------------------------------------------------ */

export default function Dashboard({
    statistiques = {},
    statistiquesMois = {},
    bonsEnAttenteValidation = [],
    mesDerniersBons = [],
    derniersRapports = [],
    bonsAPayer = [],
    bpEnRetard = [],
    repartitionCategories = [],
    activiteRecente = [],
    categoriesLabels = {},
    actionsLabels = {},
    statutsLabels = {},
    evolutionMensuelle = [],
    repartitionStatuts = [],
    topBeneficiaires = [],
    tauxApprobation = 0,
    tauxRejet = 0,
    delaiMoyen = null,
    soldesSites = [],
    mouvementsEnAttente = [],
    delegationsActives = [],
    performancesN1 = [],
}) {
    const { auth } = usePage().props;
    const user = auth.user;
    const moisCourant = new Date().toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });

    /* ---- KPIs principaux ---- */
    const cartes = [
        { titre: 'Total Bons', valeur: statistiques.total_bons || 0, icon: FileText, couleur: 'text-blue-600', bg: 'bg-blue-50' },
        { titre: 'En cours de traitement ', valeur: statistiques.bons_en_attente || 0, icon: Clock, couleur: 'text-neemba-600', bg: 'bg-neemba-50' },
        { titre: 'En attente de paiement', valeur: statistiques.bons_approuves || 0, icon: CheckCircle2, couleur: 'text-green-600', bg: 'bg-green-50' },
        { titre: 'Terminés', valeur: statistiques.bons_termines || 0, icon: Banknote, couleur: 'text-emerald-600', bg: 'bg-emerald-50' },
        { titre: 'Rejetés', valeur: statistiques.bons_rejetes || 0, icon: XCircle, couleur: 'text-red-500', bg: 'bg-red-50' },
        { titre: 'Montant Payé', valeur: formatMontant(statistiques.montant_total_paye), icon: TrendingUp, couleur: 'text-purple-600', bg: 'bg-purple-50', estMontant: true },
    ];

    /* ---- Barres de la répartition catégories ---- */
    const maxMontantCategorie = repartitionCategories.length > 0
        ? Math.max(...repartitionCategories.map(c => Number(c.montant_total)))
        : 1;

    return (
        <AuthenticatedLayout header="Tableau de bord">
            <Head title="Tableau de bord" />

            {/* Message de bienvenue */}
            <div className="mb-5">
                <h1 className="text-xl sm:text-2xl font-bold text-gray-900">
                    Bonjour, {user.prenom || user.name} !
                </h1>
                <p className="text-gray-500 text-xs sm:text-sm mt-0.5">
                    Voici un résumé de l'activité de la caisse.
                </p>
            </div>

            {/* ============================================================ */}
            {/*  KPIs principaux                                             */}
            {/* ============================================================ */}
            <div className="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-2 sm:gap-4 mb-5">
                {cartes.map((carte, index) => {
                    const Icon = carte.icon;
                    return (
                        <motion.div key={carte.titre} custom={index} variants={cardVariants} initial="hidden" animate="visible">
                            <Card className="hover:shadow-md transition-shadow h-full">
                                <CardContent className="p-3 sm:p-4">
                                    <div className={`p-1.5 sm:p-2 rounded-lg ${carte.bg} w-fit mb-2`}>
                                        <Icon className={`h-4 w-4 sm:h-5 sm:w-5 ${carte.couleur}`} />
                                    </div>
                                    <p className={`font-bold truncate ${carte.estMontant ? 'text-xs sm:text-base' : 'text-lg sm:text-2xl'}`}>
                                        {carte.valeur}
                                    </p>
                                    <p className="text-[10px] sm:text-xs text-muted-foreground mt-0.5">{carte.titre}</p>
                                </CardContent>
                            </Card>
                        </motion.div>
                    );
                })}
            </div>

            {/* ============================================================ */}
            {/*  Stats du mois + BP en retard (bandeau)                      */}
            {/* ============================================================ */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4 mb-5">
                <motion.div {...fadeUp(0.4)}>
                    <Card className="border-l-4 border-l-neemba-500 h-full">
                        <CardContent className="p-4 flex items-center gap-4">
                            <div className="p-2.5 rounded-lg bg-neemba-50">
                                <CalendarDays className="h-5 w-5 text-neemba-600" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground capitalize">{moisCourant}</p>
                                <p className="text-lg font-bold">{statistiquesMois.bons_crees || 0} <span className="text-sm font-normal text-gray-500">bons créés</span></p>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
                <motion.div {...fadeUp(0.45)}>
                    <Card className="border-l-4 border-l-emerald-500 h-full">
                        <CardContent className="p-4 flex items-center gap-4">
                            <div className="p-2.5 rounded-lg bg-emerald-50">
                                <Wallet className="h-5 w-5 text-emerald-600" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Payé ce mois</p>
                                <p className="text-lg font-bold">{formatMontant(statistiquesMois.montant_paye)}</p>
                                <p className="text-[10px] text-gray-400">{statistiquesMois.bons_payes || 0} bon(s)</p>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
                <motion.div {...fadeUp(0.5)}>
                    <Card className={`border-l-4 h-full ${bpEnRetard.length > 0 ? 'border-l-red-500' : 'border-l-green-400'}`}>
                        <CardContent className="p-4 flex items-center gap-4">
                            <div className={`p-2.5 rounded-lg ${bpEnRetard.length > 0 ? 'bg-red-50' : 'bg-green-50'}`}>
                                <AlertTriangle className={`h-5 w-5 ${bpEnRetard.length > 0 ? 'text-red-500' : 'text-green-500'}`} />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">BP en retard</p>
                                <p className={`text-lg font-bold ${bpEnRetard.length > 0 ? 'text-red-600' : 'text-green-600'}`}>
                                    {bpEnRetard.length}
                                </p>
                                <p className="text-[10px] text-gray-400">
                                    {bpEnRetard.length > 0 ? 'Régularisation dépassée' : 'Aucun retard'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
            </div>

            {/* ============================================================ */}
            {/*  Indicateurs de performance                                  */}
            {/* ============================================================ */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-5">
                <motion.div {...fadeUp(0.5)}>
                    <Card className="h-full">
                        <CardContent className="p-4 flex items-center gap-4">
                            <div className="p-2.5 rounded-lg bg-green-50">
                                <ThumbsUp className="h-5 w-5 text-green-600" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Taux d'approbation</p>
                                <p className="text-2xl font-bold text-green-600">{tauxApprobation}%</p>
                                <p className="text-[10px] text-gray-400">{tauxRejet}% rejetés</p>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
                <motion.div {...fadeUp(0.55)}>
                    <Card className="h-full">
                        <CardContent className="p-4 flex items-center gap-4">
                            <div className="p-2.5 rounded-lg bg-blue-50">
                                <Timer className="h-5 w-5 text-blue-600" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Délai moyen de traitement</p>
                                <p className="text-2xl font-bold text-blue-600">
                                    {delaiMoyen !== null ? `${delaiMoyen}j` : '—'}
                                </p>
                                <p className="text-[10px] text-gray-400">Soumission → Paiement</p>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
                <motion.div {...fadeUp(0.6)}>
                    <Card className="h-full">
                        <CardContent className="p-4 flex items-center gap-4">
                            <div className="p-2.5 rounded-lg bg-purple-50">
                                <Wallet className="h-5 w-5 text-purple-600" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Montant approuvé</p>
                                <p className="text-lg font-bold text-purple-600">{formatMontant(statistiques.montant_total_approuve)}</p>
                                <p className="text-[10px] text-gray-400">En attente de paiement</p>
                            </div>
                        </CardContent>
                    </Card>
                </motion.div>
            </div>

            {/* ============================================================ */}
            {/*  Soldes caisse par site (Phase 2.1)                          */}
            {/* ============================================================ */}
            {soldesSites.length > 0 && (
                <div className="mb-5">
                    <motion.div {...fadeUp(0.6)}>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <MapPin className="h-4 w-4 text-neemba-500" />
                                    Soldes de caisse par site
                                </CardTitle>
                                <CardDescription>Vue en temps réel des soldes disponibles</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                                    {soldesSites.map((site) => (
                                        <div
                                            key={site.nom}
                                            className={`p-3 rounded-lg border ${site.sous_seuil ? 'border-red-200 bg-red-50/50' : 'bg-gray-50'}`}
                                        >
                                            <p className="text-xs text-gray-500 truncate font-medium">{site.nom}</p>
                                            <p className={`text-base font-bold mt-0.5 ${site.sous_seuil ? 'text-red-600' : 'text-gray-900'}`}>
                                                {formaterNombre(site.solde_caisse)} <span className="text-[10px] font-normal">GNF</span>
                                            </p>
                                            {site.plafond_caisse && (
                                                <div className="mt-1.5">
                                                    <div className="h-1 bg-gray-200 rounded-full">
                                                        <div
                                                            className={`h-1 rounded-full ${site.sous_seuil ? 'bg-red-400' : 'bg-neemba-400'}`}
                                                            style={{ width: `${Math.min((site.solde_caisse / site.plafond_caisse) * 100, 100)}%` }}
                                                        />
                                                    </div>
                                                </div>
                                            )}
                                            {site.sous_seuil && (
                                                <p className="text-[10px] text-red-500 mt-1 flex items-center gap-0.5">
                                                    <AlertTriangle className="h-2.5 w-2.5" /> Sous seuil
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>
            )}

            {/* ============================================================ */}
            {/*  Mouvements en attente + Délégations actives                 */}
            {/* ============================================================ */}
            {(mouvementsEnAttente.length > 0 || delegationsActives.length > 0) && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-5">
                    {/* Mouvements en attente de validation */}
                    {mouvementsEnAttente.length > 0 && (
                        <motion.div {...fadeUp(0.62)}>
                            <Card className="h-full border-amber-200">
                                <CardHeader className="pb-3">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <Wallet className="h-4 w-4 text-amber-500" />
                                                Mouvements à valider
                                            </CardTitle>
                                            <CardDescription>{mouvementsEnAttente.length} mouvement(s) en attente</CardDescription>
                                        </div>
                                        <Link href={route('mouvements-caisse.index')}>
                                            <Button variant="outline" size="sm">
                                                Voir tout <ArrowRight className="ml-1 h-4 w-4" />
                                            </Button>
                                        </Link>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {mouvementsEnAttente.slice(0, 5).map((mvt) => (
                                            <div key={mvt.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-amber-50 transition-colors">
                                                <div className="flex items-center gap-2 min-w-0">
                                                    {mvt.type === 'approvisionnement' ? (
                                                        <ArrowUpCircle className="h-4 w-4 text-green-500 flex-shrink-0" />
                                                    ) : mvt.type === 'retrait' ? (
                                                        <ArrowDownCircle className="h-4 w-4 text-red-500 flex-shrink-0" />
                                                    ) : (
                                                        <Wallet className="h-4 w-4 text-blue-500 flex-shrink-0" />
                                                    )}
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-medium truncate">{mvt.reference} — {mvt.site}</p>
                                                        <p className="text-xs text-gray-500 truncate">{mvt.motif}</p>
                                                    </div>
                                                </div>
                                                <p className="text-sm font-bold text-neemba-600 ml-2 flex-shrink-0">
                                                    {formaterNombre(mvt.montant)} GNF
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}

                    {/* Délégations actives */}
                    {delegationsActives.length > 0 && (
                        <motion.div {...fadeUp(0.65)}>
                            <Card className="h-full">
                                <CardHeader className="pb-3">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="text-base flex items-center gap-2">
                                                <ArrowRightLeft className="h-4 w-4 text-purple-500" />
                                                Délégations actives
                                            </CardTitle>
                                            <CardDescription>{delegationsActives.length} délégation(s) en cours</CardDescription>
                                        </div>
                                        <Link href={route('delegations.index')}>
                                            <Button variant="outline" size="sm">
                                                Gérer <ArrowRight className="ml-1 h-4 w-4" />
                                            </Button>
                                        </Link>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-2">
                                        {delegationsActives.map((d) => (
                                            <div key={d.id} className="p-3 rounded-lg border hover:bg-purple-50 transition-colors">
                                                <div className="flex items-center justify-between">
                                                    <p className="text-sm font-medium">
                                                        {d.delegant?.prenom} {d.delegant?.name}
                                                        <span className="text-gray-400 mx-1">→</span>
                                                        {d.delegue?.prenom} {d.delegue?.name}
                                                    </p>
                                                    <Badge className="text-[10px] bg-green-100 text-green-800">Active</Badge>
                                                </div>
                                                <p className="text-xs text-gray-500 mt-0.5">{d.motif}</p>
                                                <p className="text-[10px] text-gray-400 mt-0.5">
                                                    {formatDate(d.date_debut)} → {formatDate(d.date_fin)}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}
                </div>
            )}

            {/* ============================================================ */}
            {/*  Graphiques : Évolution + Répartition statuts                */}
            {/* ============================================================ */}
            {(evolutionMensuelle.length > 0 || repartitionStatuts.length > 0) && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 mb-5">
                    {/* Évolution mensuelle (AreaChart) */}
                    {evolutionMensuelle.length > 0 && (
                        <motion.div {...fadeUp(0.65)} className="lg:col-span-2">
                            <Card className="h-full">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <TrendingUp className="h-4 w-4 text-neemba-500" />
                                        Évolution sur 6 mois
                                    </CardTitle>
                                    <CardDescription>Bons créés, payés et rejetés par mois</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={260}>
                                        <AreaChart data={evolutionMensuelle} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
                                            <defs>
                                                <linearGradient id="gradCrees" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor="#fdc911" stopOpacity={0.3} />
                                                    <stop offset="95%" stopColor="#fdc911" stopOpacity={0} />
                                                </linearGradient>
                                                <linearGradient id="gradPayes" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor="#10b981" stopOpacity={0.3} />
                                                    <stop offset="95%" stopColor="#10b981" stopOpacity={0} />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                                            <XAxis dataKey="mois" tick={{ fontSize: 11 }} />
                                            <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                                            <Tooltip content={<TooltipMontant />} />
                                            <Legend wrapperStyle={{ fontSize: 11 }} />
                                            <Area type="monotone" dataKey="crees" name="Créés" stroke="#fdc911" fill="url(#gradCrees)" strokeWidth={2} />
                                            <Area type="monotone" dataKey="payes" name="Payés" stroke="#10b981" fill="url(#gradPayes)" strokeWidth={2} />
                                            <Area type="monotone" dataKey="rejetes" name="Rejetés" stroke="#ef4444" fill="none" strokeWidth={1.5} strokeDasharray="4 4" />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}

                    {/* Répartition par statut (PieChart) */}
                    {repartitionStatuts.length > 0 && (
                        <motion.div {...fadeUp(0.7)}>
                            <Card className="h-full">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <BarChart3 className="h-4 w-4 text-blue-500" />
                                        Répartition par statut
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={180}>
                                        <PieChart>
                                            <Pie
                                                data={repartitionStatuts}
                                                dataKey="nombre"
                                                nameKey="label"
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={45}
                                                outerRadius={75}
                                                paddingAngle={2}
                                            >
                                                {repartitionStatuts.map((entry, i) => (
                                                    <Cell key={i} fill={COULEURS_STATUTS[entry.statut] || COULEURS_PIE[i % COULEURS_PIE.length]} />
                                                ))}
                                            </Pie>
                                            <Tooltip formatter={(value, name) => [`${value} bon(s)`, name]} />
                                        </PieChart>
                                    </ResponsiveContainer>
                                    <div className="space-y-1 mt-2">
                                        {repartitionStatuts.map((s, i) => (
                                            <div key={i} className="flex items-center justify-between text-xs">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="w-2.5 h-2.5 rounded-full inline-block" style={{ backgroundColor: COULEURS_STATUTS[s.statut] || COULEURS_PIE[i] }} />
                                                    <span className="text-gray-600 truncate">{s.label}</span>
                                                </div>
                                                <span className="font-medium">{s.nombre}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}
                </div>
            )}

            {/* ============================================================ */}
            {/*  Top bénéficiaires + Évolution montants                      */}
            {/* ============================================================ */}
            {(topBeneficiaires.length > 0 || evolutionMensuelle.length > 0) && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-5">
                    {/* Top bénéficiaires */}
                    {topBeneficiaires.length > 0 && (
                        <motion.div {...fadeUp(0.75)}>
                            <Card className="h-full">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Users className="h-4 w-4 text-violet-500" />
                                        Top 5 bénéficiaires
                                    </CardTitle>
                                    <CardDescription>Par montant total</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={220}>
                                        <BarChart
                                            data={topBeneficiaires.map(b => ({ ...b, nom: b.beneficiaire.length > 15 ? b.beneficiaire.slice(0, 15) + '…' : b.beneficiaire, montant_total: Number(b.montant_total) }))}
                                            layout="vertical"
                                            margin={{ top: 0, right: 10, left: 0, bottom: 0 }}
                                        >
                                            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                                            <XAxis type="number" tick={{ fontSize: 10 }} tickFormatter={(v) => formaterNombre(v)} />
                                            <YAxis dataKey="nom" type="category" width={100} tick={{ fontSize: 10 }} />
                                            <Tooltip content={<TooltipMontant />} />
                                            <Bar dataKey="montant_total" name="Montant total" fill="#fdc911" radius={[0, 4, 4, 0]} />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}

                    {/* Évolution montants payés (BarChart) */}
                    {evolutionMensuelle.length > 0 && (
                        <motion.div {...fadeUp(0.8)}>
                            <Card className="h-full">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Banknote className="h-4 w-4 text-emerald-500" />
                                        Montants payés par mois
                                    </CardTitle>
                                    <CardDescription>Décaissements mensuels</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={220}>
                                        <BarChart data={evolutionMensuelle} margin={{ top: 5, right: 10, left: -15, bottom: 0 }}>
                                            <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                                            <XAxis dataKey="mois" tick={{ fontSize: 10 }} />
                                            <YAxis tick={{ fontSize: 10 }} tickFormatter={(v) => v >= 1000000 ? `${(v / 1000000).toFixed(0)}M` : formaterNombre(v)} />
                                            <Tooltip content={<TooltipMontant />} />
                                            <Bar dataKey="montant_paye" name="Montant payé" fill="#10b981" radius={[4, 4, 0, 0]} />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}
                </div>
            )}

            {/* ============================================================ */}
            {/*  Performances SLA (N+1)                                      */}
            {/* ============================================================ */}
            {performancesN1.length > 0 && (
                <div className="mb-5">
                    <motion.div {...fadeUp(0.72)}>
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Timer className="h-4 w-4 text-orange-500" />
                                    Performances de validation (SLA par N+1)
                                </CardTitle>
                                <CardDescription>Goulots d'étranglement pour les "Chefs de Service" (Heures en moyenne)</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ResponsiveContainer width="100%" height={260}>
                                    <BarChart
                                        data={performancesN1}
                                        layout="vertical"
                                        margin={{ top: 5, right: 30, left: 10, bottom: 5 }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                                        <XAxis type="number" tick={{ fontSize: 10 }} />
                                        <YAxis dataKey="nom" type="category" width={140} tick={{ fontSize: 10 }} />
                                        <Tooltip 
                                            content={({ active, payload, label }) => {
                                                if (active && payload && payload.length) {
                                                    return (
                                                        <div className="bg-white border rounded-lg shadow-lg p-3 text-xs w-48 relative z-10">
                                                            <p className="font-semibold text-gray-800 mb-1 truncate">{label}</p>
                                                            <p className="text-orange-600 font-bold">{payload[0].value} heure(s) en moyenne</p>
                                                            <p className="text-gray-500 mt-1">Total de {payload[0].payload.total} bon(s) validé(s)</p>
                                                        </div>
                                                    );
                                                }
                                                return null;
                                            }}
                                        />
                                        <Bar dataKey="heures" name="Délai Moyen" radius={[0, 4, 4, 0]}>
                                            {performancesN1.map((entry, index) => (
                                                <Cell key={`cell-${index}`} fill={entry.heures > 24 ? '#ef4444' : (entry.heures > 4 ? '#f59e0b' : '#22c55e')} />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>
            )}

            {/* ============================================================ */}
            {/*  Grille principale : colonnes gauche / droite                */}
            {/* ============================================================ */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-5">

                {/* --- Bons en attente de validation (validateurs) --- */}
                {bonsEnAttenteValidation.length > 0 && (
                    <motion.div {...fadeUp(0.55)}>
                        <Card className="h-full">
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="text-base">En attente de votre validation</CardTitle>
                                        <CardDescription>{bonsEnAttenteValidation.length} bon(s) à traiter</CardDescription>
                                    </div>
                                    <Link href={route('validations.index')}>
                                        <Button variant="outline" size="sm">
                                            Voir tout <ArrowRight className="ml-1 h-4 w-4" />
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {bonsEnAttenteValidation.map((bon) => (
                                        <Link key={bon.id} href={route('validations.show', bon.id)}
                                            className="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 transition-colors border">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium truncate">{bon.numero} — {bon.beneficiaire}</p>
                                                <p className="text-xs text-gray-500 truncate">{bon.motif}</p>
                                            </div>
                                            <div className="text-right ml-4 flex-shrink-0">
                                                <p className="text-sm font-semibold text-neemba-600">{formatMontant(bon.montant)}</p>
                                                <Badge variant={bon.type_bon === 'BD' ? 'default' : 'secondary'} className="text-[10px]">{bon.type_bon}</Badge>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                )}

                {/* --- Bons à payer (caissier) --- */}
                {bonsAPayer.length > 0 && (
                    <motion.div {...fadeUp(0.55)}>
                        <Card className="h-full border-emerald-200">
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="text-base flex items-center gap-2">
                                            <Banknote className="h-4 w-4 text-emerald-600" />
                                            Bons à payer
                                        </CardTitle>
                                        <CardDescription>{bonsAPayer.length} bon(s) approuvé(s)</CardDescription>
                                    </div>
                                    <Link href={route('bons-caisse.index')}>
                                        <Button variant="outline" size="sm">
                                            Voir tout <ArrowRight className="ml-1 h-4 w-4" />
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {bonsAPayer.map((bon) => (
                                        <Link key={bon.id} href={route('bons-caisse.show', bon.id)}
                                            className="flex items-center justify-between p-3 rounded-lg hover:bg-emerald-50 transition-colors border">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium truncate">{bon.numero} — {bon.beneficiaire}</p>
                                                <p className="text-xs text-gray-500 truncate">{bon.demandeur?.prenom} {bon.demandeur?.name}</p>
                                            </div>
                                            <div className="text-right ml-4 flex-shrink-0">
                                                <p className="text-sm font-bold text-emerald-600">{formatMontant(bon.montant)}</p>
                                                <Badge variant={bon.type_bon === 'BD' ? 'default' : 'secondary'} className="text-[10px]">{bon.type_bon}</Badge>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                )}

                {/* --- Mes derniers bons de caisse --- */}
                <motion.div {...fadeUp(0.6)}>
                    <Card className="h-full">
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <div>
                                    <CardTitle className="text-base">Mes derniers bons</CardTitle>
                                    <CardDescription>Vos demandes récentes</CardDescription>
                                </div>
                                <Link href={route('bons-caisse.create')}>
                                    <Button size="sm"><Plus className="mr-1 h-4 w-4" /> Nouveau</Button>
                                </Link>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {mesDerniersBons.length === 0 ? (
                                <div className="text-center py-8 text-gray-400">
                                    <FileText className="h-10 w-10 mx-auto mb-2 opacity-50" />
                                    <p className="text-sm">Aucun bon de caisse</p>
                                    <Link href={route('bons-caisse.create')}>
                                        <Button variant="link" size="sm" className="mt-2">Créer votre premier bon</Button>
                                    </Link>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {mesDerniersBons.map((bon) => (
                                        <Link key={bon.id} href={route('bons-caisse.show', bon.id)}
                                            className="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 transition-colors border">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium truncate">{bon.numero}</p>
                                                <p className="text-xs text-gray-500 truncate">{bon.beneficiaire} — {bon.motif}</p>
                                            </div>
                                            <div className="text-right ml-4 flex-shrink-0">
                                                <p className="text-sm font-semibold">{formatMontant(bon.montant)}</p>
                                                <Badge variant={badgeVariantParStatut(bon.statut)} className="text-[10px]">
                                                    {statutsLabels[bon.statut] || bon.statut}
                                                </Badge>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>

                {/* --- Derniers rapports de caisse (caissier, DAF, DP) --- */}
                {derniersRapports.length > 0 && (
                    <motion.div {...fadeUp(0.65)}>
                        <Card className="h-full">
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <CardTitle className="text-base">Rapports récents</CardTitle>
                                        <CardDescription>Derniers rapports de caisse</CardDescription>
                                    </div>
                                    <Link href={route('rapports.index')}>
                                        <Button variant="outline" size="sm">
                                            Voir tout <ArrowRight className="ml-1 h-4 w-4" />
                                        </Button>
                                    </Link>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {derniersRapports.map((rapport) => (
                                        <Link key={rapport.id} href={route('rapports.show', rapport.id)}
                                            className="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 transition-colors border">
                                            <div>
                                                <p className="text-sm font-medium">{formatDate(rapport.date_rapport)}</p>
                                                <p className="text-xs text-gray-500">{rapport.site}</p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-sm font-semibold">{formatMontant(rapport.solde_cloture)}</p>
                                                {rapport.visa_daf_id ? (
                                                    <Badge variant="approuve" className="text-[10px]">Visé DAF</Badge>
                                                ) : (
                                                    <Badge variant="en_attente" className="text-[10px]">En attente visa</Badge>
                                                )}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                )}
            </div>

            {/* ============================================================ */}
            {/*  BP en retard + Répartition catégories + Activité récente    */}
            {/* ============================================================ */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">

                {/* --- BP en retard de régularisation --- */}
                {bpEnRetard.length > 0 && (
                    <motion.div {...fadeUp(0.7)}>
                        <Card className="border-red-200 h-full">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <AlertTriangle className="h-4 w-4 text-red-500" />
                                    BP en retard
                                </CardTitle>
                                <CardDescription>Régularisation dépassée</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {bpEnRetard.map((bon) => {
                                        const joursRetard = bon.date_limite_regularisation
                                            ? Math.ceil((new Date() - new Date(bon.date_limite_regularisation)) / 86400000)
                                            : 0;
                                        return (
                                            <Link key={bon.id} href={route('bons-caisse.show', bon.id)}
                                                className="block p-3 rounded-lg hover:bg-red-50 transition-colors border border-red-100">
                                                <div className="flex items-center justify-between">
                                                    <p className="text-sm font-medium truncate">{bon.numero}</p>
                                                    <Badge variant="destructive" className="text-[10px]">
                                                        {joursRetard}j retard
                                                    </Badge>
                                                </div>
                                                <p className="text-xs text-gray-500 truncate mt-0.5">{bon.beneficiaire}</p>
                                                <p className="text-xs text-red-400 mt-0.5">
                                                    Limite : {formatDate(bon.date_limite_regularisation)}
                                                </p>
                                            </Link>
                                        );
                                    })}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                )}

                {/* --- Répartition par catégorie de dépense --- */}
                {repartitionCategories.length > 0 && (
                    <motion.div {...fadeUp(0.75)}>
                        <Card className="h-full">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <BarChart3 className="h-4 w-4 text-blue-500" />
                                    Répartition dépenses
                                </CardTitle>
                                <CardDescription>Par catégorie</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    {repartitionCategories.map((cat) => {
                                        const pct = Math.round((Number(cat.montant_total) / maxMontantCategorie) * 100);
                                        return (
                                            <div key={cat.categorie_depense}>
                                                <div className="flex items-center justify-between mb-1">
                                                    <span className="text-xs font-medium text-gray-700 truncate">
                                                        {categoriesLabels[cat.categorie_depense] || cat.categorie_depense}
                                                    </span>
                                                    <span className="text-xs text-gray-500 ml-2 whitespace-nowrap">
                                                        {cat.nombre} — {formatMontant(cat.montant_total)}
                                                    </span>
                                                </div>
                                                <div className="w-full bg-gray-100 rounded-full h-2">
                                                    <motion.div
                                                        className="bg-neemba-500 h-2 rounded-full"
                                                        initial={{ width: 0 }}
                                                        animate={{ width: `${pct}%` }}
                                                        transition={{ duration: 0.6, delay: 0.8 }}
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

                {/* --- Activité récente --- */}
                {activiteRecente.length > 0 && (
                    <motion.div {...fadeUp(0.8)}>
                        <Card className="h-full">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base flex items-center gap-2">
                                    <Activity className="h-4 w-4 text-violet-500" />
                                    Activité récente
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    {activiteRecente.map((action) => (
                                        <div key={action.id} className="flex gap-3">
                                            <div className="w-1.5 rounded-full bg-violet-200 flex-shrink-0" />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-xs font-medium text-gray-800 truncate">
                                                    {actionsLabels[action.action] || action.action}
                                                </p>
                                                {action.bon_caisse && (
                                                    <p className="text-[10px] text-gray-500 truncate">
                                                        {action.bon_caisse.numero}
                                                    </p>
                                                )}
                                                <div className="flex items-center justify-between mt-0.5">
                                                    <p className="text-[10px] text-gray-400 truncate">
                                                        {action.utilisateur
                                                            ? `${action.utilisateur.prenom || ''} ${action.utilisateur.name}`.trim()
                                                            : 'Système'}
                                                    </p>
                                                    <span className="text-[10px] text-gray-300 whitespace-nowrap ml-2">
                                                        {formatDateTime(action.created_at)}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
