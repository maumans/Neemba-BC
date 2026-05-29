/**
 * Page Admin — Modifications en Attente de Double Validation - NEEMBA
 *
 * Tableau de bord permettant à un administrateur de consulter, approuver
 * ou refuser les modifications critiques qui nécessitent une double validation
 * (paramètres système, rôles utilisateurs, seuils caisse, codes analytiques).
 *
 * Règle : un administrateur ne peut pas approuver sa propre modification.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    Filter,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import React, { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';

const STATUT_BADGE = {
    en_attente: { label: 'En attente',  variant: 'outline',     icon: Clock },
    approuvee:  { label: 'Approuvée',   variant: 'approuve',    icon: CheckCircle2 },
    refusee:    { label: 'Refusée',     variant: 'destructive', icon: XCircle },
};

export default function ModificationsEnAttente({
    modifications,
    stats = {},
    types  = {},
}) {
    const { auth, flash } = usePage().props;
    const userId = auth.user?.id;

    /* Filtre statut côté client */
    const [filtreStatut, setFiltreStatut] = useState('en_attente');

    /* Formulaire commentaire inline pour Approuver/Refuser */
    const [actionEnCours, setActionEnCours] = useState(null); // { id, type: 'approuver'|'refuser' }
    const commentaireForm = useForm({ commentaire: '' });

    const donneesAffichees = filtreStatut === 'tous'
        ? modifications.data
        : modifications.data.filter(m => m.statut === filtreStatut);

    const formatDate = (d) => d ? new Date(d).toLocaleDateString('fr-FR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }) : '—';

    const soumettre = (modifId, type) => {
        const routeName = type === 'approuver'
            ? 'admin.modifications-en-attente.approuver'
            : 'admin.modifications-en-attente.refuser';

        commentaireForm.post(route(routeName, modifId), {
            onSuccess: () => {
                setActionEnCours(null);
                commentaireForm.reset();
            },
        });
    };

    return (
        <AuthenticatedLayout header="Modifications en attente de validation">
            <Head title="Double validation — Admin NEEMBA" />

            {/* Retour */}
            <div className="mb-6">
                <Link
                    href={route('parametrage.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour au paramétrage
                </Link>
            </div>

            {/* Flash */}
            {flash?.success && (
                <div className="mb-4 p-3 rounded-lg bg-green-50 text-green-700 text-sm border border-green-200">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm border border-red-200">
                    {flash.error}
                </div>
            )}

            {/* Bandeau informatif */}
            <motion.div
                initial={{ opacity: 0, y: -10 }}
                animate={{ opacity: 1, y: 0 }}
                className="mb-6 flex items-start gap-3 p-4 rounded-xl bg-amber-50 border border-amber-200"
            >
                <ShieldAlert className="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" />
                <div>
                    <p className="text-sm font-medium text-amber-800">Principe de double validation</p>
                    <p className="text-xs text-amber-700 mt-0.5">
                        Les modifications critiques (paramètres, rôles, seuils) requièrent l'approbation
                        d'un second administrateur. Vous ne pouvez pas approuver votre propre modification.
                    </p>
                </div>
            </motion.div>

            {/* KPIs */}
            <div className="grid grid-cols-3 gap-4 mb-6">
                {[
                    { label: 'En attente', value: stats.en_attente ?? 0, color: 'text-amber-600', bg: 'bg-amber-50', icon: Clock },
                    { label: 'Approuvées', value: stats.approuvees ?? 0, color: 'text-green-600', bg: 'bg-green-50', icon: ShieldCheck },
                    { label: 'Refusées',  value: stats.refusees  ?? 0, color: 'text-red-600',   bg: 'bg-red-50',   icon: XCircle },
                ].map(({ label, value, color, bg, icon: Icon }) => (
                    <motion.div key={label} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                        <Card>
                            <CardContent className="p-4">
                                <div className="flex items-center gap-3">
                                    <div className={`h-10 w-10 rounded-lg ${bg} flex items-center justify-center`}>
                                        <Icon className={`h-5 w-5 ${color}`} />
                                    </div>
                                    <div>
                                        <p className="text-xs text-gray-500">{label}</p>
                                        <p className={`text-2xl font-bold ${color}`}>{value}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                ))}
            </div>

            {/* Filtre */}
            <Card className="mb-6">
                <CardContent className="p-4">
                    <div className="flex items-center gap-3">
                        <Filter className="h-4 w-4 text-gray-400" />
                        <Label className="text-sm">Afficher :</Label>
                        <Select value={filtreStatut} onValueChange={setFiltreStatut}>
                            <SelectTrigger className="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="en_attente">En attente</SelectItem>
                                <SelectItem value="approuvee">Approuvées</SelectItem>
                                <SelectItem value="refusee">Refusées</SelectItem>
                                <SelectItem value="tous">Toutes</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            {/* Tableau */}
            <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base flex items-center gap-2">
                            <ShieldAlert className="h-4 w-4 text-amber-500" />
                            Modifications critiques
                            {stats.en_attente > 0 && (
                                <Badge variant="destructive" className="text-xs ml-2">
                                    {stats.en_attente} en attente
                                </Badge>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {donneesAffichees.length === 0 ? (
                            <div className="text-center py-16 text-gray-400">
                                <ShieldCheck className="h-10 w-10 mx-auto mb-3 opacity-30" />
                                <p className="text-sm">Aucune modification dans cette catégorie 🎉</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Type</TableHead>
                                            <TableHead>Champ modifié</TableHead>
                                            <TableHead>Ancienne valeur</TableHead>
                                            <TableHead>Nouvelle valeur</TableHead>
                                            <TableHead>Demandeur</TableHead>
                                            <TableHead>Date</TableHead>
                                            <TableHead className="text-center">Statut</TableHead>
                                            <TableHead />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {donneesAffichees.map((modif) => {
                                            const statutInfo = STATUT_BADGE[modif.statut] || STATUT_BADGE.en_attente;
                                            const StatutIcon = statutInfo.icon;
                                            const estMonAction = modif.demandeur_id === userId;
                                            const peutAgir = modif.statut === 'en_attente' && !estMonAction;
                                            const estEnAction = actionEnCours?.id === modif.id;

                                            return (
                                                <React.Fragment key={modif.id}>
                                                    <TableRow className={modif.statut === 'en_attente' ? 'bg-amber-50/30' : ''}>
                                                        <TableCell>
                                                            <Badge variant="outline" className="text-xs">
                                                                {types[modif.type_entite] || modif.type_entite}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="font-mono text-sm">{modif.champ}</TableCell>
                                                        <TableCell>
                                                            <span className="text-red-600 text-sm line-through">
                                                                {modif.ancienne_valeur ?? '—'}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell>
                                                            <span className="text-green-700 font-medium text-sm">
                                                                {modif.nouvelle_valeur ?? '—'}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="text-sm">
                                                            {modif.demandeur
                                                                ? (modif.demandeur.prenom
                                                                    ? `${modif.demandeur.prenom} ${modif.demandeur.name}`
                                                                    : modif.demandeur.name)
                                                                : '—'}
                                                            {estMonAction && (
                                                                <span className="ml-1 text-[10px] text-gray-400">(vous)</span>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-xs text-gray-500 whitespace-nowrap">
                                                            {formatDate(modif.created_at)}
                                                        </TableCell>
                                                        <TableCell className="text-center">
                                                            <div className="flex items-center justify-center gap-1">
                                                                <StatutIcon className={`h-3.5 w-3.5 ${
                                                                    modif.statut === 'approuvee' ? 'text-green-500'
                                                                    : modif.statut === 'refusee' ? 'text-red-500'
                                                                    : 'text-amber-500'
                                                                }`} />
                                                                <span className="text-xs">{statutInfo.label}</span>
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            {peutAgir && !estEnAction && (
                                                                <div className="flex gap-1">
                                                                    <Button
                                                                        size="sm"
                                                                        className="bg-green-600 hover:bg-green-700 text-white h-7 text-xs"
                                                                        onClick={() => setActionEnCours({ id: modif.id, type: 'approuver' })}
                                                                    >
                                                                        <CheckCircle2 className="h-3 w-3 mr-1" />
                                                                        Approuver
                                                                    </Button>
                                                                    <Button
                                                                        size="sm"
                                                                        variant="destructive"
                                                                        className="h-7 text-xs"
                                                                        onClick={() => setActionEnCours({ id: modif.id, type: 'refuser' })}
                                                                    >
                                                                        <XCircle className="h-3 w-3 mr-1" />
                                                                        Refuser
                                                                    </Button>
                                                                </div>
                                                            )}
                                                            {estMonAction && modif.statut === 'en_attente' && (
                                                                <span className="text-xs text-gray-400 italic">En attente d'un autre admin</span>
                                                            )}
                                                            {estEnAction && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    className="h-7 text-xs"
                                                                    onClick={() => { setActionEnCours(null); commentaireForm.reset(); }}
                                                                >
                                                                    <RotateCcw className="h-3 w-3 mr-1" />
                                                                    Annuler
                                                                </Button>
                                                            )}
                                                        </TableCell>
                                                    </TableRow>

                                                    {/* Panel de commentaire inline */}
                                                    <AnimatePresence>
                                                        {estEnAction && (
                                                            <TableRow key={`action-${modif.id}`}>
                                                                <TableCell colSpan={8} className="p-0">
                                                                    <motion.div
                                                                        initial={{ height: 0, opacity: 0 }}
                                                                        animate={{ height: 'auto', opacity: 1 }}
                                                                        exit={{ height: 0, opacity: 0 }}
                                                                        className={`overflow-hidden px-4 py-3 ${
                                                                            actionEnCours.type === 'approuver'
                                                                                ? 'bg-green-50 border-t border-green-100'
                                                                                : 'bg-red-50 border-t border-red-100'
                                                                        }`}
                                                                    >
                                                                        <p className={`text-sm font-medium mb-2 ${
                                                                            actionEnCours.type === 'approuver' ? 'text-green-800' : 'text-red-800'
                                                                        }`}>
                                                                            {actionEnCours.type === 'approuver'
                                                                                ? '✅ Confirmer l\'approbation de cette modification'
                                                                                : '❌ Confirmer le refus de cette modification'}
                                                                        </p>
                                                                        <Textarea
                                                                            placeholder="Commentaire optionnel..."
                                                                            value={commentaireForm.data.commentaire}
                                                                            onChange={(e) => commentaireForm.setData('commentaire', e.target.value)}
                                                                            className="bg-white min-h-[60px] text-sm mb-2"
                                                                        />
                                                                        <Button
                                                                            size="sm"
                                                                            className={actionEnCours.type === 'approuver'
                                                                                ? 'bg-green-600 hover:bg-green-700 text-white'
                                                                                : 'bg-red-600 hover:bg-red-700 text-white'}
                                                                            onClick={() => soumettre(modif.id, actionEnCours.type)}
                                                                            disabled={commentaireForm.processing}
                                                                        >
                                                                            {commentaireForm.processing ? 'En cours...' : 'Confirmer'}
                                                                        </Button>
                                                                    </motion.div>
                                                                </TableCell>
                                                            </TableRow>
                                                        )}
                                                    </AnimatePresence>
                                                </React.Fragment>
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
            {modifications.last_page > 1 && (
                <div className="flex justify-center gap-2 mt-4">
                    {modifications.links.map((link, i) => (
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
