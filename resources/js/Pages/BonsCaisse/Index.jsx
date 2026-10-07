/**
 * Page Liste des Bons de Caisse - NEEMBA
 * 
 * Affiche tous les bons de caisse avec filtrage par statut, type et recherche.
 * Les demandeurs ne voient que leurs propres bons.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import {
    Plus,
    Search,
    Filter,
    Eye,
    FileText,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    TrendingUp,
    Banknote,
    Clock,
    AlertTriangle,
    CheckCircle2,
    XCircle,
    Activity,
    Pencil,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
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
import { formaterMontant as formatMontant } from '@/utils/nombreEnLettres';
import { cn } from '@/lib/utils';
import BadgeStatut from '@/Components/BadgeStatut';

/** Couleurs d'urgence pour les lignes du tableau */
const URGENCE_CONFIG = {
    tres_urgente: {
        rowCls: 'bg-red-50/60',
        badgeCls: 'bg-red-100 text-red-700 animate-urgence-blink',
        label: 'TRÈS URGENT',
        dotCls: 'bg-red-500 animate-pulse',
    },
    urgente: {
        rowCls: 'bg-orange-50/40',
        badgeCls: 'bg-orange-100 text-orange-700',
        label: 'URGENT',
        dotCls: 'bg-orange-500',
    },
};

/** Mapping rôle → statut en attente de ce rôle */
const ROLE_STATUT_MAP = {
    'responsable_service': 'EN_ATTENTE_CHEF_SERVICE',
    'controle_gestion': 'EN_ATTENTE_CDG',
    'daf': 'EN_ATTENTE_DAF',
    'directeur_pays': 'EN_ATTENTE_DP',
};

/** Formater le temps écoulé depuis une date (statique, sans secondes) */
function formaterTempsEcoule(dateStr) {
    if (!dateStr) return null;
    const diffMs = Date.now() - new Date(dateStr).getTime();
    if (diffMs < 0) return null;
    const jours = Math.floor(diffMs / (1000 * 60 * 60 * 24));
    const heures = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
    let texte = '';
    if (jours > 0) texte += `${jours}j `;
    if (heures > 0 || jours > 0) texte += `${heures}h `;
    texte += `${minutes}min`;
    return { texte: texte.trim(), jours };
}

export default function Index({ bonsCaisse, filtres = {}, statuts = {}, peutValider = false, roleUtilisateur = '', statsIndex = {}, niveauxUrgence = {} }) {
    const { auth } = usePage().props;
    const [recherche, setRecherche] = useState(filtres.recherche || '');
    const [, forceUpdate] = useState(0);

    /* Rafraîchir les timers BP toutes les 60 secondes */
    useEffect(() => {
        const intervalle = setInterval(() => forceUpdate(n => n + 1), 60000);
        return () => clearInterval(intervalle);
    }, []);

    /** Appliquer les filtres via Inertia */
    const filtrer = (params) => {
        router.get(route('bons-caisse.index'), {
            ...filtres,
            ...params,
            page: 1,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    /** Soumettre la recherche */
    const handleRecherche = (e) => {
        e.preventDefault();
        filtrer({ recherche });
    };

    /* Recherche à la frappe, 300 ms après la dernière touche (E-03.1) */
    useEffect(() => {
        if ((recherche || '') === (filtres.recherche || '')) return undefined;
        const minuterie = setTimeout(() => filtrer({ recherche }), 300);
        return () => clearTimeout(minuterie);
    }, [recherche]);

    const filtresActifs = ['statut', 'type_bon', 'niveau_urgence', 'du', 'au', 'recherche'].some((cle) => filtres[cle]);
    const reinitialiser = () => {
        setRecherche('');
        router.get(route('bons-caisse.index'), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Bons de Caisse">
            <Head title="Bons de Caisse" />

            {/* En-tête avec bouton de création */}
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-5">
                <div className="min-w-0">
                    <h1 className="text-xl sm:text-2xl font-bold text-gray-900">Bons de Caisse</h1>
                    <p className="text-gray-500 text-xs sm:text-sm mt-0.5">
                        Gérez vos demandes de fonds
                    </p>
                </div>
                {auth.user?.peut_initier_bon && (
                    <Link href={route('bons-caisse.create')} className="flex-shrink-0">
                        <Button size="sm" className="sm:size-default">
                            <Plus className="mr-1.5 h-4 w-4" />
                            Nouveau Bon
                        </Button>
                    </Link>
                )}
            </div>

            {/* Cartes (E-03.1, RG-BC-33) : calculées sur le périmètre et les filtres de la liste */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                {[
                    { cle: 'total', titre: 'Total bons', icone: FileText, bordure: 'border-t-neemba-400', couleur: 'text-gray-900', sous: 'Montant total' },
                    { cle: 'a_valider', titre: 'À valider', icone: Clock, bordure: 'border-t-amber-400', couleur: 'text-amber-600', sous: 'Montant en validation' },
                    { cle: 'payes', titre: 'Payés', icone: CheckCircle2, bordure: 'border-t-emerald-400', couleur: 'text-emerald-600', sous: 'Montant total payé' },
                    { cle: 'rejetes', titre: 'Rejetés', icone: XCircle, bordure: 'border-t-red-400', couleur: 'text-red-600', sous: 'Montant total rejeté' },
                ].map(({ cle, titre, icone: Icone, bordure, couleur, sous }, index) => (
                    <motion.div key={cle} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: index * 0.05 }}>
                        <Card className={`overflow-hidden border-t-2 ${bordure} hover:shadow-md transition-shadow`}>
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">{titre}</p>
                                        <p className={`text-2xl font-bold mt-0.5 ${couleur}`}>{statsIndex[cle]?.nombre ?? 0}</p>
                                    </div>
                                    <Icone className={`h-5 w-5 ${couleur}`} />
                                </div>
                                <p className="mt-2 pt-2 border-t border-gray-100 text-[11px] text-gray-500">
                                    {sous} : {formatMontant(statsIndex[cle]?.montant ?? 0)}
                                </p>
                            </CardContent>
                        </Card>
                    </motion.div>
                ))}
            </div>

            {/* Filtres */}
            <Card className="mb-5">
                <CardContent className="p-3 sm:p-4">
                    <div className="flex flex-col sm:flex-row gap-2 sm:gap-3">
                        {/* Recherche */}
                        <form onSubmit={handleRecherche} className="flex-1 flex gap-2">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                                <Input
                                    placeholder="Rechercher par numéro, bénéficiaire, motif…"
                                    value={recherche}
                                    onChange={(e) => setRecherche(e.target.value)}
                                    className="pl-9"
                                />
                            </div>
                            <Button type="submit" variant="outline">
                                <Search className="h-4 w-4" />
                            </Button>
                        </form>

                        {/* Filtre par statut */}
                        <Select
                            value={filtres.statut || 'tous'}
                            onValueChange={(val) => filtrer({ statut: val === 'tous' ? '' : val })}
                        >
                            <SelectTrigger className="w-full sm:w-[200px]">
                                <SelectValue placeholder="Tous les statuts" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="tous">Tous les statuts</SelectItem>
                                {Object.entries(statuts).map(([key, label]) => (
                                    <SelectItem key={key} value={key}>{label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {/* Filtre par type */}
                        <Select
                            value={filtres.type_bon || 'tous'}
                            onValueChange={(val) => filtrer({ type_bon: val === 'tous' ? '' : val })}
                        >
                            <SelectTrigger className="w-full sm:w-[150px]">
                                <SelectValue placeholder="Tous les types" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="tous">Tous les types</SelectItem>
                                <SelectItem value="BD">Bon Définitif</SelectItem>
                                <SelectItem value="BP">Bon Provisoire</SelectItem>
                            </SelectContent>
                        </Select>

                        {/* Filtre par urgence */}
                        <Select
                            value={filtres.niveau_urgence || 'toutes'}
                            onValueChange={(val) => filtrer({ niveau_urgence: val === 'toutes' ? '' : val })}
                        >
                            <SelectTrigger className="w-full sm:w-[160px]">
                                <SelectValue placeholder="Toutes urgences" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="toutes">Toutes urgences</SelectItem>
                                {Object.entries(niveauxUrgence).map(([cle, libelle]) => (
                                    <SelectItem key={cle} value={cle}>{libelle}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Période (date de la demande) */}
                    <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                        <span>Période du</span>
                        <Input type="date" value={filtres.du || ''} onChange={(e) => filtrer({ du: e.target.value })} className="h-9 w-auto" aria-label="Du" />
                        <span>au</span>
                        <Input type="date" value={filtres.au || ''} onChange={(e) => filtrer({ au: e.target.value })} className="h-9 w-auto" aria-label="Au" />
                        {filtresActifs && (
                            <Button type="button" variant="ghost" size="sm" onClick={reinitialiser}>
                                Réinitialiser les filtres
                            </Button>
                        )}
                        <span className="ml-auto text-xs text-gray-500">{bonsCaisse.total} bon(s)</span>
                    </div>
                </CardContent>
            </Card>

            {/* Tableau des bons */}
            <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
            >
                <Card>
                    <CardContent className="p-0">
                        {bonsCaisse.data.length === 0 ? (
                            <div className="text-center py-16">
                                <FileText className="h-12 w-12 mx-auto text-gray-300 mb-3" />
                                <p className="text-gray-500 font-medium">Aucun bon de caisse trouvé</p>
                                <p className="text-gray-400 text-sm mt-1">
                                    {filtres.recherche || filtres.statut || filtres.type_bon
                                        ? 'Essayez de modifier vos filtres'
                                        : 'Créez votre premier bon de caisse'}
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                <Table className="[&_th]:px-3 [&_td]:px-3">
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="text-xs">Numéro</TableHead>
                                            <TableHead className="text-xs hidden sm:table-cell">Type</TableHead>
                                            <TableHead className="text-xs hidden md:table-cell">Bénéficiaire</TableHead>
                                            <TableHead className="text-xs hidden lg:table-cell">Motif</TableHead>
                                            <TableHead className="text-xs text-right">Montant</TableHead>
                                            <TableHead className="text-xs">Statut</TableHead>
                                            <TableHead className="text-xs hidden sm:table-cell">Date</TableHead>
                                            <TableHead className="text-xs hidden md:table-cell" title="Depuis la soumission ; en rouge au-delà de 2 × le délai de l'étape en cours">Âge</TableHead>
                                            <TableHead className="text-xs text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {bonsCaisse.data.map((bon) => {
                                            const urgConf = URGENCE_CONFIG[bon.niveau_urgence] || null;
                                            return (
                                            <TableRow key={bon.id} className={cn(urgConf?.rowCls)}>
                                                <TableCell className="font-medium text-xs sm:text-sm py-2">
                                                    <div className="flex items-center gap-1.5">
                                                        {urgConf && (
                                                            <span title={urgConf.label} className={cn('inline-block w-2 h-2 rounded-full flex-shrink-0', urgConf.dotCls)} />
                                                        )}
                                                        {/* Numéro attribué à la soumission : « — » pour un brouillon (E-03.1) */}
                                                        <span className="truncate">{bon.numero ?? '—'}</span>
                                                        {urgConf && (
                                                            <span className={cn(
                                                                'hidden sm:inline-flex items-center rounded px-1 py-0.5 text-[8px] font-bold tracking-wide leading-none flex-shrink-0',
                                                                urgConf.badgeCls
                                                            )}>
                                                                {urgConf.label}
                                                            </span>
                                                        )}
                                                    </div>
                                                    {/* Info compacte visible uniquement sur mobile */}
                                                    <div className="sm:hidden text-[10px] text-gray-400 mt-0.5 flex items-center gap-1">
                                                        {bon.type_bon} · {new Date(bon.date_demande).toLocaleDateString('fr-FR')}
                                                        {urgConf && (
                                                            <span className={cn('rounded px-1 py-0 text-[8px] font-bold leading-none', urgConf.badgeCls)}>
                                                                {urgConf.label}
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell py-2">
                                                    <Badge variant={bon.type_bon === 'BD' ? 'default' : 'secondary'} className="text-[10px]">
                                                        {bon.type_bon}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="hidden md:table-cell text-sm py-2">{bon.beneficiaire}</TableCell>
                                                <TableCell className="hidden lg:table-cell max-w-[160px] truncate text-sm py-2">
                                                    {bon.motif}
                                                </TableCell>
                                                <TableCell className="text-right font-semibold text-xs sm:text-sm py-2">
                                                    {formatMontant(bon.montant)}
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    <BadgeStatut statut={bon.statut} className="text-[10px]" />
                                                </TableCell>
                                                <TableCell className="text-xs text-gray-500 hidden sm:table-cell py-2">
                                                    {new Date(bon.date_demande).toLocaleDateString('fr-FR')}
                                                    {bon.type_bon === 'BP' && !['REGULARISE', 'ARCHIVE'].includes(bon.statut) && (() => {
                                                        const info = formaterTempsEcoule(bon.created_at);
                                                        if (!info) return null;
                                                        const couleur = info.jours >= 7 ? 'text-red-600' : info.jours >= 3 ? 'text-orange-600' : 'text-blue-600';
                                                        return (
                                                            <div className={`flex items-center gap-1 mt-0.5 ${couleur}`}>
                                                                <Activity className="h-3 w-3" />
                                                                <span className="text-[10px] font-medium">{info.texte}</span>
                                                            </div>
                                                        );
                                                    })()}
                                                </TableCell>
                                                <TableCell className={cn('text-xs hidden md:table-cell py-2 whitespace-nowrap', bon.age_alerte ? 'font-semibold text-red-600' : 'text-gray-500')}>
                                                    {bon.age ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right py-2">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {peutValider && bon.statut === ROLE_STATUT_MAP[roleUtilisateur] && (
                                                            <Link href={route('bons-caisse.show', bon.id)}>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="text-[10px] sm:text-xs border-neemba-300 text-neemba-700 hover:bg-neemba-50 h-7 px-2"
                                                                >
                                                                    <ClipboardCheck className="mr-0.5 sm:mr-1 h-3 w-3 sm:h-3.5 sm:w-3.5" />
                                                                    <span className="hidden sm:inline">Valider</span>
                                                                </Button>
                                                            </Link>
                                                        )}
                                                        <Link href={route('bons-caisse.show', bon.id)} title="Ouvrir">
                                                            <Button variant="ghost" size="icon" className="h-7 w-7 sm:h-8 sm:w-8">
                                                                <Eye className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
                                                            </Button>
                                                        </Link>
                                                        {/* Crayon : reprendre un brouillon ou corriger un bon rejeté (auteur seulement) */}
                                                        {['BROUILLON', 'REJETE'].includes(bon.statut)
                                                            && [bon.demandeur_id, bon.initiateur_id].includes(auth.user?.id) && (
                                                            <Link href={route('bons-caisse.edit', bon.id)} title={bon.statut === 'REJETE' ? 'Corriger et resoumettre' : 'Reprendre'}>
                                                                <Button variant="ghost" size="icon" className="h-7 w-7 sm:h-8 sm:w-8">
                                                                    <Pencil className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
                                                                </Button>
                                                            </Link>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                                </div>

                                {/* Pagination */}
                                {bonsCaisse.last_page > 1 && (
                                    <div className="flex flex-col sm:flex-row items-center justify-between gap-2 px-3 sm:px-4 py-2 sm:py-3 border-t">
                                        <p className="text-xs sm:text-sm text-gray-500">
                                            {bonsCaisse.from} à {bonsCaisse.to} sur {bonsCaisse.total}
                                        </p>
                                        <div className="flex flex-wrap gap-1 justify-center">
                                            {bonsCaisse.links.map((link, index) => (
                                                <Link
                                                    key={index}
                                                    href={link.url || '#'}
                                                    className={`px-2 sm:px-3 py-1 sm:py-1.5 text-xs sm:text-sm rounded-md transition-colors ${
                                                        link.active
                                                            ? 'bg-neemba-400 text-marine-950 font-semibold'
                                                            : link.url
                                                                ? 'text-gray-600 hover:bg-gray-100'
                                                                : 'text-gray-300 cursor-not-allowed'
                                                    }`}
                                                    preserveScroll
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </motion.div>
        </AuthenticatedLayout>
    );
}
