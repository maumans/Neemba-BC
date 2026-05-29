/**
 * Page Liste des Délégations - NEEMBA
 * 
 * Affiche les délégations données, reçues et en attente d'acceptation.
 * Permet d'accepter, refuser ou terminer une délégation.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    UserCheck,
    UserX,
    Plus,
    Clock,
    CheckCircle2,
    XCircle,
    ArrowRightLeft,
    Calendar,
    StopCircle,
    Shield,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Separator } from '@/Components/ui/separator';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { useState } from 'react';

const STATUTS_CONFIG = {
    en_attente: { label: 'En attente', couleur: 'bg-amber-100 text-amber-800', icone: Clock },
    acceptee: { label: 'Acceptée', couleur: 'bg-green-100 text-green-800', icone: CheckCircle2 },
    refusee: { label: 'Refusée', couleur: 'bg-red-100 text-red-800', icone: XCircle },
    terminee: { label: 'Terminée', couleur: 'bg-gray-100 text-gray-800', icone: StopCircle },
};

function DelegationCard({ delegation, type, onAction, fonctionnalitesLabels = {} }) {
    const config = STATUTS_CONFIG[delegation.statut] || STATUTS_CONFIG.en_attente;
    const StatusIcon = config.icone;
    const estActive = delegation.statut === 'acceptee' && new Date(delegation.date_fin) >= new Date();

    return (
        <div className="p-4 border rounded-lg bg-white hover:shadow-sm transition-shadow">
            <div className="flex items-start justify-between gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                        <ArrowRightLeft className="h-4 w-4 text-neemba-500 flex-shrink-0" />
                        <span className="font-medium text-sm truncate">
                            {type === 'donnee'
                                ? `→ ${delegation.delegue?.prenom} ${delegation.delegue?.name}`
                                : `← ${delegation.delegant?.prenom} ${delegation.delegant?.name}`
                            }
                        </span>
                        <Badge className={`text-[10px] ${config.couleur}`}>
                            <StatusIcon className="h-3 w-3 mr-1" />
                            {config.label}
                        </Badge>
                        {estActive && (
                            <Badge className="text-[10px] bg-green-500 text-white">Active</Badge>
                        )}
                    </div>
                    <p className="text-sm text-gray-600 mb-1">{delegation.motif}</p>
                    <div className="flex items-center gap-3 text-xs text-gray-400">
                        <span className="flex items-center gap-1">
                            <Calendar className="h-3 w-3" />
                            {new Date(delegation.date_debut).toLocaleDateString('fr-FR')} → {new Date(delegation.date_fin).toLocaleDateString('fr-FR')}
                        </span>
                        {delegation.delegant?.role && (
                            <span>Rôle : {delegation.delegant.role}</span>
                        )}
                    </div>
                    {/* Fonctionnalités déléguées */}
                    {delegation.fonctionnalites && delegation.fonctionnalites.length > 0 && (
                        <div className="flex flex-wrap gap-1 mt-1.5">
                            {delegation.fonctionnalites.map((fonc) => (
                                <span key={fonc} className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] bg-purple-50 text-purple-700 border border-purple-200">
                                    <Shield className="h-2.5 w-2.5" />
                                    {fonctionnalitesLabels[fonc] || fonc}
                                </span>
                            ))}
                        </div>
                    )}
                    {!delegation.fonctionnalites && (
                        <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] bg-gray-50 text-gray-500 border border-gray-200 mt-1.5">
                            <Shield className="h-2.5 w-2.5" />
                            Toutes les fonctionnalités
                        </span>
                    )}
                </div>
                <div className="flex gap-1 flex-shrink-0">
                    {type === 'recue' && delegation.statut === 'en_attente' && (
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                className="text-green-600 border-green-200 hover:bg-green-50"
                                onClick={() => onAction('accepter', delegation.id)}
                            >
                                <CheckCircle2 className="h-3.5 w-3.5 mr-1" />
                                Accepter
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                className="text-red-600 border-red-200 hover:bg-red-50"
                                onClick={() => onAction('refuser', delegation.id)}
                            >
                                <XCircle className="h-3.5 w-3.5 mr-1" />
                                Refuser
                            </Button>
                        </>
                    )}
                    {type === 'donnee' && delegation.statut === 'acceptee' && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="text-gray-600"
                            onClick={() => onAction('terminer', delegation.id)}
                        >
                            <StopCircle className="h-3.5 w-3.5 mr-1" />
                            Terminer
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function Index({
    delegationsDonnees = [],
    delegationsRecues = [],
    enAttenteAcceptation = [],
    peutCreer = false,
    fonctionnalitesLabels = {},
}) {
    const [processing, setProcessing] = useState(false);
    const [actionToConfirm, setActionToConfirm] = useState(null);

    const requestAction = (action, id) => {
        setActionToConfirm({ action, id });
    };

    const confirmAction = () => {
        if (!actionToConfirm || processing) return;
        setProcessing(true);
        router.post(route(`delegations.${actionToConfirm.action}`, actionToConfirm.id), {}, {
            onFinish: () => {
                setProcessing(false);
                setActionToConfirm(null);
            },
        });
    };

    const total = delegationsDonnees.length + delegationsRecues.length;

    return (
        <AuthenticatedLayout header="Délégations de pouvoirs">
            <Head title="Délégations" />

            {/* Actions */}
            <div className="flex items-center justify-between mb-6">
                <p className="text-sm text-gray-500">
                    {total} délégation(s) au total
                    {enAttenteAcceptation.length > 0 && (
                        <Badge className="ml-2 bg-amber-100 text-amber-800 text-[10px]">
                            {enAttenteAcceptation.length} en attente
                        </Badge>
                    )}
                </p>
                {peutCreer && (
                    <Link href={route('delegations.create')}>
                        <Button>
                            <Plus className="h-4 w-4 mr-2" />
                            Nouvelle délégation
                        </Button>
                    </Link>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Délégations en attente d'acceptation */}
                {enAttenteAcceptation.length > 0 && (
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        className="lg:col-span-2"
                    >
                        <Card className="border-amber-200">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-amber-800">
                                    <Clock className="h-5 w-5" />
                                    En attente de votre acceptation
                                </CardTitle>
                                <CardDescription>
                                    Ces délégations vous ont été proposées et attendent votre réponse
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {enAttenteAcceptation.map((d) => (
                                    <DelegationCard
                                        key={d.id}
                                        delegation={d}
                                        type="recue"
                                        onAction={requestAction}
                                        fonctionnalitesLabels={fonctionnalitesLabels}
                                    />
                                ))}
                            </CardContent>
                        </Card>
                    </motion.div>
                )}

                {/* Délégations données */}
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.1 }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <UserCheck className="h-5 w-5 text-neemba-500" />
                                Délégations données
                            </CardTitle>
                            <CardDescription>
                                Pouvoirs que vous avez délégués à d'autres utilisateurs
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {delegationsDonnees.length === 0 ? (
                                <p className="text-sm text-gray-400 text-center py-8">
                                    Aucune délégation donnée
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {delegationsDonnees.map((d) => (
                                        <DelegationCard
                                            key={d.id}
                                            delegation={d}
                                            type="donnee"
                                            onAction={requestAction}
                                            fonctionnalitesLabels={fonctionnalitesLabels}
                                        />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>

                {/* Délégations reçues */}
                <motion.div
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.2 }}
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <UserX className="h-5 w-5 text-purple-500" />
                                Délégations reçues
                            </CardTitle>
                            <CardDescription>
                                Pouvoirs qui vous ont été délégués par d'autres utilisateurs
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {delegationsRecues.length === 0 ? (
                                <p className="text-sm text-gray-400 text-center py-8">
                                    Aucune délégation reçue
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {delegationsRecues.map((d) => (
                                        <DelegationCard
                                            key={d.id}
                                            delegation={d}
                                            type="recue"
                                            onAction={requestAction}
                                            fonctionnalitesLabels={fonctionnalitesLabels}
                                        />
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </motion.div>
            </div>

            {/* Modale de confirmation */}
            <Dialog open={!!actionToConfirm} onOpenChange={(open) => !open && setActionToConfirm(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {actionToConfirm?.action === 'accepter' && "Accepter la délégation"}
                            {actionToConfirm?.action === 'refuser' && "Refuser la délégation"}
                            {actionToConfirm?.action === 'terminer' && "Terminer la délégation"}
                        </DialogTitle>
                        <DialogDescription>
                            {actionToConfirm?.action === 'accepter' && "Êtes-vous sûr de vouloir accepter cette délégation ? Vous assumerez les pouvoirs correspondants pour la période définie."}
                            {actionToConfirm?.action === 'refuser' && "Êtes-vous sûr de vouloir refuser cette délégation ? L'action est irréversible."}
                            {actionToConfirm?.action === 'terminer' && "Êtes-vous sûr de vouloir mettre fin à cette délégation ? Le délégataire perdra ses pouvoirs avec effet immédiat."}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setActionToConfirm(null)}>Annuler</Button>
                        <Button 
                            variant={actionToConfirm?.action === 'accepter' ? 'default' : 'destructive'} 
                            onClick={confirmAction} 
                            disabled={processing}
                            className={actionToConfirm?.action === 'accepter' ? "bg-green-600 hover:bg-green-700 text-white" : ""}
                        >
                            Confirmer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
