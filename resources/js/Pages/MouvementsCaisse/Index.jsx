/**
 * Page Liste des Mouvements de Caisse - NEEMBA
 * 
 * Affiche les approvisionnements, retraits et ajustements de caisse par site.
 * DAF/DP peuvent valider ou rejeter les mouvements en attente.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Wallet,
    Plus,
    ArrowUpCircle,
    ArrowDownCircle,
    Settings2,
    CheckCircle2,
    XCircle,
    Clock,
    TrendingUp,
    AlertTriangle,
    Filter,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Separator } from '@/Components/ui/separator';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { useState } from 'react';

const TYPES_CONFIG = {
    approvisionnement: { label: 'Approvisionnement', icone: ArrowUpCircle, couleur: 'text-green-600' },
    retrait: { label: 'Retrait', icone: ArrowDownCircle, couleur: 'text-red-600' },
    ajustement: { label: 'Ajustement', icone: Settings2, couleur: 'text-blue-600' },
};

const STATUTS_CONFIG = {
    en_attente: { label: 'En attente', couleur: 'bg-amber-100 text-amber-800' },
    valide: { label: 'Validé', couleur: 'bg-green-100 text-green-800' },
    rejete: { label: 'Rejeté', couleur: 'bg-red-100 text-red-800' },
};

function formaterMontant(montant) {
    return new Intl.NumberFormat('fr-FR').format(montant) + ' GNF';
}

export default function Index({
    mouvements = { data: [] },
    soldesSites = [],
    filtres = {},
    sites = [],
    peutCreer = false,
    peutValider = false,
}) {
    const { auth } = usePage().props;
    const [processing, setProcessing] = useState(false);
    const [mvtToValider, setMvtToValider] = useState(null);
    const [mvtToRejeter, setMvtToRejeter] = useState(null);
    const [commentaireRejet, setCommentaireRejet] = useState('');

    const handleAction = (action, id, commentaire = '') => {
        if (processing) return;
        setProcessing(true);
        router.post(route(`mouvements-caisse.${action}`, id), { commentaire }, {
            onFinish: () => setProcessing(false),
        });
    };

    const handleFiltre = (key, value) => {
        router.get(route('mouvements-caisse.index'), {
            ...filtres,
            [key]: value === '__all__' ? undefined : (value || undefined),
        }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout header="Mouvements de caisse">
            <Head title="Mouvements de caisse" />

            {/* KPIs — Solde par site */}
            {soldesSites.length > 0 && (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
                    {soldesSites.map((site) => (
                        <motion.div
                            key={site.nom}
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                        >
                            <Card className={site.sous_seuil ? 'border-red-200 bg-red-50/50' : ''}>
                                <CardContent className="p-4">
                                    <p className="text-xs text-gray-500 truncate">{site.nom}</p>
                                    <p className={`text-lg font-bold ${site.sous_seuil ? 'text-red-600' : 'text-gray-900'}`}>
                                        {formaterMontant(site.solde_caisse)}
                                    </p>
                                    {site.sous_seuil && (
                                        <p className="text-[10px] text-red-500 flex items-center gap-1 mt-0.5">
                                            <AlertTriangle className="h-3 w-3" />
                                            Sous le seuil minimum
                                        </p>
                                    )}
                                    {site.plafond_caisse && (
                                        <div className="mt-1.5">
                                            <div className="h-1.5 bg-gray-200 rounded-full">
                                                <div
                                                    className={`h-1.5 rounded-full ${site.sous_seuil ? 'bg-red-400' : 'bg-neemba-400'}`}
                                                    style={{ width: `${Math.min((site.solde_caisse / site.plafond_caisse) * 100, 100)}%` }}
                                                />
                                            </div>
                                            <p className="text-[10px] text-gray-400 mt-0.5">
                                                Plafond : {formaterMontant(site.plafond_caisse)}
                                            </p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </motion.div>
                    ))}
                </div>
            )}

            {/* Filtres + Actions */}
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-2">
                    <Filter className="h-4 w-4 text-gray-400" />
                    <Select
                        value={filtres.site || '__all__'}
                        onValueChange={(val) => handleFiltre('site', val)}
                    >
                        <SelectTrigger className="w-[160px] h-9 text-sm">
                            <SelectValue placeholder="Tous les sites" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">Tous les sites</SelectItem>
                            {sites.map((s) => (
                                <SelectItem key={s} value={s}>{s}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filtres.statut || '__all__'}
                        onValueChange={(val) => handleFiltre('statut', val)}
                    >
                        <SelectTrigger className="w-[140px] h-9 text-sm">
                            <SelectValue placeholder="Tous statuts" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">Tous statuts</SelectItem>
                            <SelectItem value="en_attente">En attente</SelectItem>
                            <SelectItem value="valide">Validé</SelectItem>
                            <SelectItem value="rejete">Rejeté</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                {peutCreer && (
                    <Link href={route('mouvements-caisse.create')}>
                        <Button>
                            <Plus className="h-4 w-4 mr-2" />
                            Nouveau mouvement
                        </Button>
                    </Link>
                )}
            </div>

            {/* Liste des mouvements */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Wallet className="h-5 w-5 text-neemba-500" />
                        Mouvements de caisse
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {(mouvements.data || []).length === 0 ? (
                        <p className="text-sm text-gray-400 text-center py-12">
                            Aucun mouvement de caisse trouvé
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {(mouvements.data || []).map((mvt) => {
                                const typeConfig = TYPES_CONFIG[mvt.type] || TYPES_CONFIG.approvisionnement;
                                const statutConfig = STATUTS_CONFIG[mvt.statut] || STATUTS_CONFIG.en_attente;
                                const TypeIcon = typeConfig.icone;

                                return (
                                    <div key={mvt.id} className="p-4 border rounded-lg bg-white hover:shadow-sm transition-shadow">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex items-start gap-3 flex-1 min-w-0">
                                                <TypeIcon className={`h-5 w-5 mt-0.5 flex-shrink-0 ${typeConfig.couleur}`} />
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2 mb-1">
                                                        <span className="font-mono text-xs text-gray-400">{mvt.reference}</span>
                                                        <Badge className={`text-[10px] ${statutConfig.couleur}`}>
                                                            {statutConfig.label}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-sm font-medium">{typeConfig.label} — {mvt.site}</p>
                                                    <p className="text-sm text-gray-600 truncate">{mvt.motif}</p>
                                                    <div className="flex items-center gap-3 text-xs text-gray-400 mt-1">
                                                        <span>Par : {mvt.effectue_par_nom}</span>
                                                        <span>{new Date(mvt.date_mouvement).toLocaleDateString('fr-FR')}</span>
                                                        {mvt.valide_par_nom && (
                                                            <span>Validé par : {mvt.valide_par_nom}</span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="text-right flex-shrink-0">
                                                <p className={`text-lg font-bold ${typeConfig.couleur}`}>
                                                    {mvt.type === 'retrait' ? '−' : '+'}{formaterMontant(mvt.montant)}
                                                </p>
                                                {peutValider && mvt.statut === 'en_attente' && (
                                                    <div className="flex gap-1 mt-2">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-green-600 border-green-200 hover:bg-green-50"
                                                            disabled={processing}
                                                            onClick={() => setMvtToValider(mvt.id)}
                                                        >
                                                            <CheckCircle2 className="h-3.5 w-3.5 mr-1" />
                                                            Valider
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-red-600 border-red-200 hover:bg-red-50"
                                                            disabled={processing}
                                                            onClick={() => setMvtToRejeter(mvt.id)}
                                                        >
                                                            <XCircle className="h-3.5 w-3.5 mr-1" />
                                                            Rejeter
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Pagination simple */}
                    {mouvements.last_page > 1 && (
                        <div className="flex justify-center gap-2 mt-6">
                            {mouvements.links?.filter(l => l.url).map((link, i) => (
                                <Link
                                    key={i}
                                    href={link.url}
                                    className={`px-3 py-1.5 rounded text-sm ${
                                        link.active
                                            ? 'bg-neemba-500 text-white'
                                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>
            {/* Modales de confirmation */}
            <Dialog open={!!mvtToValider} onOpenChange={(open) => !open && setMvtToValider(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Valider le mouvement</DialogTitle>
                        <DialogDescription>Êtes-vous sûr de vouloir valider ce mouvement de caisse ?</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setMvtToValider(null)}>Annuler</Button>
                        <Button onClick={() => { handleAction('valider', mvtToValider); setMvtToValider(null); }} className="bg-green-600 hover:bg-green-700 text-white">Confirmer</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!mvtToRejeter} onOpenChange={(open) => { if(!open) { setMvtToRejeter(null); setCommentaireRejet(''); }}}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Rejeter le mouvement</DialogTitle>
                        <DialogDescription>Veuillez indiquer le motif de ce rejet.</DialogDescription>
                    </DialogHeader>
                    <div className="py-2">
                        <Label htmlFor="commentaire">Motif du rejet *</Label>
                        <Textarea 
                            id="commentaire"
                            value={commentaireRejet} 
                            onChange={e => setCommentaireRejet(e.target.value)} 
                            className="mt-2"
                            placeholder="Saisissez le motif..."
                            required 
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => { setMvtToRejeter(null); setCommentaireRejet(''); }}>Annuler</Button>
                        <Button variant="destructive" onClick={() => { handleAction('rejeter', mvtToRejeter, commentaireRejet); setMvtToRejeter(null); setCommentaireRejet(''); }} disabled={!commentaireRejet.trim() || processing}>Confirmer le rejet</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
