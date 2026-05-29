/**
 * Page Détail Validation - NEEMBA
 * 
 * Permet au validateur d'examiner un bon de caisse en détail
 * puis de l'approuver ou le rejeter avec un commentaire.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    ArrowLeft,
    CheckCircle2,
    XCircle,
    FileText,
    User,
    MapPin,
    Building,
    Calendar,
    Paperclip,
    Download,
    Eye,
    AlertTriangle,
    MessageSquare,
    ClipboardCheck,
    Hash,
} from 'lucide-react';
import { Combobox } from '@/Components/ui/combobox';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
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
import { nombreEnLettres, formaterMontant as formatMontant } from '@/utils/nombreEnLettres';

export default function Show({ bonCaisse, statutsLabels = {}, codesAnalytiques = [] }) {
    const { auth } = usePage().props;
    const [showRejetDialog, setShowRejetDialog] = useState(false);
    const [showComplementDialog, setShowComplementDialog] = useState(false);
    const [previewPieceId, setPreviewPieceId] = useState(null);

    /* Rôles effectifs de l'utilisateur (natifs + obtenus par délégation) */
    const rolesUtilisateurActuels = auth.user.roles_effectifs || [auth.user.role || 'demandeur'];

    /* Détection rôle CDG pour édition code analytique et ventilations */
    const estEnAttenteCDG = bonCaisse.statut === 'EN_ATTENTE_CDG';
    const estCDG = estEnAttenteCDG && rolesUtilisateurActuels.includes('controle_gestion');

    /* Formulaire pour l'approbation */
    const approuverForm = useForm({
        commentaire: '',
        ...(estCDG ? { code_analytique: bonCaisse.code_analytique || '', ventilations: bonCaisse.ventilations || [] } : {}),
    });

    /* Formulaire pour le rejet */
    const rejeterForm = useForm({ commentaire: '' });

    /* Formulaire pour la demande de complément */
    const complementForm = useForm({ commentaire: '' });

    /** Approuver le bon */
    const handleApprouver = (e) => {
        e.preventDefault();
        approuverForm.post(route('validations.approuver', bonCaisse.id), {
            preserveScroll: true,
        });
    };

    /** Rejeter le bon */
    const handleRejeter = (e) => {
        e.preventDefault();
        rejeterForm.post(route('validations.rejeter', bonCaisse.id), {
            preserveScroll: true,
            onSuccess: () => setShowRejetDialog(false),
        });
    };

    /** Demander un complément */
    const handleComplement = (e) => {
        e.preventDefault();
        complementForm.post(route('validations.demander-complement', bonCaisse.id), {
            preserveScroll: true,
            onSuccess: () => setShowComplementDialog(false),
        });
    };

    return (
        <AuthenticatedLayout header={`Valider ${bonCaisse.numero}`}>
            <Head title={`Valider ${bonCaisse.numero}`} />

            {/* Retour */}
            <div className="mb-6">
                <Link
                    href={route('validations.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour aux validations
                </Link>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Colonne principale - Détails du bon */}
                <div className="lg:col-span-2 space-y-6">
                    {/* En-tête */}
                    <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                        <Card>
                            <CardHeader>
                                <div className="flex items-start justify-between">
                                    <div>
                                        <CardTitle className="text-xl flex items-center gap-2">
                                            <FileText className="h-5 w-5 text-neemba-500" />
                                            {bonCaisse.numero}
                                        </CardTitle>
                                        <CardDescription className="mt-1">
                                            {bonCaisse.type_bon === 'BD' ? 'Bon Définitif' : 'Bon Provisoire'}
                                        </CardDescription>
                                    </div>
                                    <Badge variant="en_attente" className="text-sm px-3 py-1">
                                        {statutsLabels[bonCaisse.statut] || bonCaisse.statut}
                                    </Badge>
                                </div>
                            </CardHeader>
                        </Card>
                    </motion.div>

                    {/* Détails */}
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.1 }}
                    >
                        <Card>
                            <CardContent className="p-6 space-y-4">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div className="flex items-start gap-3">
                                        <User className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Demandeur</p>
                                            <p className="font-medium">
                                                {bonCaisse.demandeur?.prenom
                                                    ? `${bonCaisse.demandeur.prenom} ${bonCaisse.demandeur.name}`
                                                    : bonCaisse.demandeur?.name}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <User className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Bénéficiaire</p>
                                            <p className="font-medium">{bonCaisse.beneficiaire}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <MapPin className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Site</p>
                                            <p className="font-medium">{bonCaisse.site}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Building className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Service</p>
                                            <p className="font-medium">{bonCaisse.service}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Calendar className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-xs text-gray-500">Date de demande</p>
                                            <p className="font-medium">
                                                {new Date(bonCaisse.date_demande).toLocaleDateString('fr-FR')}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <Separator />

                                <div>
                                    <p className="text-xs text-gray-500 mb-1">Motif de la demande</p>
                                    <p className="text-sm bg-gray-50 rounded-lg p-3">{bonCaisse.motif}</p>
                                </div>

                                {/* Pièces jointes */}
                                {bonCaisse.pieces_jointes && bonCaisse.pieces_jointes.length > 0 && (
                                    <>
                                        <Separator />
                                        <div>
                                            <p className="text-xs text-gray-500 mb-2">
                                                Pièces jointes ({bonCaisse.pieces_jointes.length})
                                            </p>
                                            <div className="space-y-2">
                                                {bonCaisse.pieces_jointes.map((piece) => {
                                                    const urlPiece = `/storage/${piece.chemin_fichier}`;
                                                    const isImage = piece.mime_type?.startsWith('image/');
                                                    const isPdf = piece.mime_type?.includes('pdf');
                                                    const isPreviewOpen = previewPieceId === piece.id;
                                                    return (
                                                        <div key={piece.id} className="border rounded-lg overflow-hidden">
                                                            <div className="flex items-center justify-between p-2 text-sm">
                                                                <div className="flex items-center gap-2 min-w-0">
                                                                    <Paperclip className="h-4 w-4 text-gray-400 flex-shrink-0" />
                                                                    <span className="truncate">{piece.nom_fichier}</span>
                                                                </div>
                                                                <div className="flex items-center gap-1 flex-shrink-0">
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-8 w-8"
                                                                        title={isPreviewOpen ? 'Masquer l\'aperçu' : 'Prévisualiser'}
                                                                        onClick={() => setPreviewPieceId(isPreviewOpen ? null : piece.id)}
                                                                    >
                                                                        <Eye className={`h-3.5 w-3.5 ${isPreviewOpen ? 'text-neemba-600' : ''}`} />
                                                                    </Button>
                                                                    <a
                                                                        href={urlPiece}
                                                                        download
                                                                        title="Télécharger"
                                                                    >
                                                                        <Button variant="ghost" size="icon" className="h-8 w-8">
                                                                            <Download className="h-3.5 w-3.5" />
                                                                        </Button>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                            <AnimatePresence>
                                                                {isPreviewOpen && (
                                                                    <motion.div
                                                                        initial={{ height: 0, opacity: 0 }}
                                                                        animate={{ height: 'auto', opacity: 1 }}
                                                                        exit={{ height: 0, opacity: 0 }}
                                                                        transition={{ duration: 0.2 }}
                                                                        className="overflow-hidden"
                                                                    >
                                                                        <div className="bg-gray-50 border-t flex items-center justify-center" style={{ minHeight: '250px' }}>
                                                                            {isImage ? (
                                                                                <div className="p-3 w-full flex items-center justify-center">
                                                                                    <img src={urlPiece} alt={piece.nom_fichier} className="max-h-[350px] max-w-full object-contain rounded shadow-sm border" />
                                                                                </div>
                                                                            ) : isPdf ? (
                                                                                <embed src={urlPiece + '#toolbar=1&navpanes=0'} type="application/pdf" className="w-full" style={{ height: '400px' }} />
                                                                            ) : (
                                                                                <div className="text-center text-gray-400 py-8">
                                                                                    <FileText className="h-10 w-10 mx-auto mb-2 text-gray-300" />
                                                                                    <p className="text-xs mb-2">Aperçu non disponible</p>
                                                                                    <a href={urlPiece} target="_blank" rel="noopener noreferrer">
                                                                                        <Button variant="outline" size="sm" className="text-xs">
                                                                                            Ouvrir dans un nouvel onglet
                                                                                        </Button>
                                                                                    </a>
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    </motion.div>
                                                                )}
                                                            </AnimatePresence>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    </>
                                )}

                                {/* Historique des validations précédentes */}
                                {bonCaisse.validations && bonCaisse.validations.filter(v => v.statut !== 'en_attente').length > 0 && (
                                    <>
                                        <Separator />
                                        <div>
                                            <p className="text-xs text-gray-500 mb-2">Validations précédentes</p>
                                            <div className="space-y-2">
                                                {bonCaisse.validations
                                                    .filter(v => v.statut !== 'en_attente')
                                                    .map((v) => (
                                                        <div key={v.id} className="flex items-center gap-2 text-sm">
                                                            <CheckCircle2 className="h-4 w-4 text-green-500" />
                                                            <span className="font-medium">
                                                                Niveau {v.niveau}
                                                            </span>
                                                            <span className="text-gray-500">
                                                                - Approuvé par {v.validateur?.name || 'N/A'}
                                                            </span>
                                                        </div>
                                                    ))}
                                            </div>
                                        </div>
                                    </>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>

                {/* Colonne latérale - Montant et actions de validation */}
                <div className="space-y-6">
                    {/* Montant */}
                    <motion.div
                        initial={{ opacity: 0, x: 10 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.2 }}
                    >
                        <Card>
                            <CardContent className="p-6 text-center">
                                <p className="text-sm text-gray-500 mb-1">Montant demandé</p>
                                <p className="text-3xl font-bold text-neemba-600">
                                    {formatMontant(bonCaisse.montant)}
                                </p>
                                <p className="text-xs text-gray-500 mt-2 italic">
                                    {bonCaisse.montant_lettres || nombreEnLettres(bonCaisse.montant)}
                                </p>
                                {parseFloat(bonCaisse.montant) >= 5000000 && (
                                    <div className="flex items-center gap-1 justify-center mt-3 text-xs text-neemba-600">
                                        <AlertTriangle className="h-3.5 w-3.5" />
                                        Validation Directeur Pays requise
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Actions de validation */}
                    <motion.div
                        initial={{ opacity: 0, x: 10 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.3 }}
                    >
                        <Card>
                            <CardContent className="p-6 space-y-3">
                                <p className="text-sm font-semibold text-neemba-800 flex items-center gap-2">
                                    <ClipboardCheck className="h-4 w-4" />
                                    Validation requise
                                </p>

                                {/* Commentaire d'approbation */}
                                <form onSubmit={handleApprouver}>
                                    {/* CDG : Modification du code analytique */}
                                    {estCDG && codesAnalytiques?.length > 0 && (
                                        <div className="mb-3 p-2.5 bg-purple-50 rounded-lg border border-purple-200">
                                            <p className="text-xs font-semibold text-purple-700 mb-2 flex items-center gap-1">
                                                <Hash className="h-3.5 w-3.5" /> Modifier le code analytique
                                            </p>
                                            <Combobox
                                                options={codesAnalytiques.map((c) => ({
                                                    value: c.code,
                                                    label: `${c.code} - ${c.libelle}${c.service?.nom ? ` (${c.service.nom})` : ''}`,
                                                }))}
                                                value={approuverForm.data.code_analytique}
                                                onChange={(val) => approuverForm.setData('code_analytique', val)}
                                                placeholder="Sélectionner un code"
                                                searchPlaceholder="Rechercher un code..."
                                            />
                                            <p className="text-[10px] text-purple-500 mt-1">Actuel : {bonCaisse.code_analytique || 'Non défini'}</p>
                                        </div>
                                    )}
                                    <div className="mb-2">
                                        <Label className="text-xs">Commentaire (optionnel)</Label>
                                        <Textarea
                                            value={approuverForm.data.commentaire}
                                            onChange={(e) =>
                                                approuverForm.setData('commentaire', e.target.value)
                                            }
                                            placeholder="Ajouter un commentaire..."
                                            className="mt-1 min-h-[60px] text-sm"
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        className="w-full bg-green-600 hover:bg-green-700 text-white"
                                        disabled={approuverForm.processing}
                                    >
                                        <CheckCircle2 className="mr-2 h-4 w-4" />
                                        Approuver
                                    </Button>
                                </form>

                                <div className="flex gap-2">
                                    {/* Rejeter */}
                                    <Dialog open={showRejetDialog} onOpenChange={setShowRejetDialog}>
                                        <DialogTrigger asChild>
                                            <Button variant="destructive" size="sm" className="flex-1">
                                                <XCircle className="mr-1 h-3.5 w-3.5" />
                                                Rejeter
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Rejeter le bon {bonCaisse.numero}</DialogTitle>
                                                <DialogDescription>
                                                    Veuillez indiquer le motif du rejet (minimum 10 caractères).
                                                    Le demandeur sera informé.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <form onSubmit={handleRejeter}>
                                                <div className="my-4">
                                                    <Label htmlFor="commentaire_rejet">Motif du rejet *</Label>
                                                    <Textarea
                                                        id="commentaire_rejet"
                                                        value={rejeterForm.data.commentaire}
                                                        onChange={(e) =>
                                                            rejeterForm.setData('commentaire', e.target.value)
                                                        }
                                                        placeholder="Expliquez la raison du rejet..."
                                                        className="mt-1 min-h-[100px]"
                                                        required
                                                        minLength={10}
                                                    />
                                                    {rejeterForm.errors.commentaire && (
                                                        <p className="text-sm text-red-500 mt-1">
                                                            {rejeterForm.errors.commentaire}
                                                        </p>
                                                    )}
                                                </div>
                                                <DialogFooter>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        onClick={() => setShowRejetDialog(false)}
                                                    >
                                                        Annuler
                                                    </Button>
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        disabled={rejeterForm.processing}
                                                    >
                                                        Confirmer le rejet
                                                    </Button>
                                                </DialogFooter>
                                            </form>
                                        </DialogContent>
                                    </Dialog>

                                    {/* Demander complément */}
                                    <Dialog open={showComplementDialog} onOpenChange={setShowComplementDialog}>
                                        <DialogTrigger asChild>
                                            <Button variant="outline" size="sm" className="flex-1">
                                                <MessageSquare className="mr-1 h-3.5 w-3.5" />
                                                Complément
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Demander un complément</DialogTitle>
                                                <DialogDescription>
                                                    Demandez des informations ou pièces supplémentaires au demandeur.
                                                    Le bon reste en attente.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <form onSubmit={handleComplement}>
                                                <div className="my-4">
                                                    <Label htmlFor="commentaire_complement">Votre demande *</Label>
                                                    <Textarea
                                                        id="commentaire_complement"
                                                        value={complementForm.data.commentaire}
                                                        onChange={(e) => complementForm.setData('commentaire', e.target.value)}
                                                        placeholder="Décrivez les informations ou documents nécessaires..."
                                                        className="mt-1 min-h-[100px]"
                                                        required
                                                        minLength={10}
                                                    />
                                                    {complementForm.errors.commentaire && (
                                                        <p className="text-sm text-red-500 mt-1">{complementForm.errors.commentaire}</p>
                                                    )}
                                                </div>
                                                <DialogFooter>
                                                    <Button type="button" variant="outline" onClick={() => setShowComplementDialog(false)}>
                                                        Annuler
                                                    </Button>
                                                    <Button type="submit" disabled={complementForm.processing}>
                                                        Envoyer la demande
                                                    </Button>
                                                </DialogFooter>
                                            </form>
                                        </DialogContent>
                                    </Dialog>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
