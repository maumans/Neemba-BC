/**
 * Page Détail Document Archivé - NEEMBA
 * 
 * Affiche les détails d'un document avec :
 * - Classification IA et confiance
 * - Lien traçable vers le bon de caisse et l'ordre de mission
 * - Contrôle qualité DPI
 * - Historique de versionnement
 * - Actions : archiver, reclassifier, télécharger
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    ArrowLeft,
    FileText,
    Archive,
    CheckCircle,
    AlertTriangle,
    Download,
    Eye,
    RefreshCw,
    Link2,
    Shield,
    Clock,
    Hash,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { formaterMontant } from '@/utils/nombreEnLettres';
import { useState } from 'react';

export default function Show({ piece, classificationsIa = {}, typesDocuments = {} }) {
    const [nouvelleClassif, setNouvelleClassif] = useState(piece.classification_ia || '');
    const bon = piece.bon_caisse;
    const ordreMission = bon?.ordre_mission;

    /** URL de prévisualisation */
    const urlPreview = piece.chemin_fichier ? `/storage/${piece.chemin_fichier}` : null;
    const estImage = piece.mime_type?.startsWith('image/');
    const estPdf = piece.mime_type?.includes('pdf');

    const handleReclassifier = () => {
        if (nouvelleClassif && nouvelleClassif !== piece.classification_ia) {
            router.post(route('archivage.reclassifier', piece.id), {
                classification: nouvelleClassif,
            }, { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header="Détail du document">
            <Head title={`Document — ${piece.nom_fichier}`} />

            <div className="mb-6">
                <Link
                    href={route('archivage.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour à l'archivage
                </Link>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Colonne principale */}
                <div className="lg:col-span-2 space-y-6">
                    {/* Infos document */}
                    <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base flex items-center gap-2">
                                    <FileText className="h-4 w-4 text-neemba-500" />
                                    {piece.nom_fichier}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <p className="text-gray-500">Type document</p>
                                        <p className="font-medium">{typesDocuments[piece.type_document] || piece.type_document}</p>
                                    </div>
                                    <div>
                                        <p className="text-gray-500">Classification IA</p>
                                        <div className="flex items-center gap-2">
                                            {piece.classification_ia ? (
                                                <>
                                                    <Badge variant="secondary">
                                                        {classificationsIa[piece.classification_ia] || piece.classification_ia}
                                                    </Badge>
                                                    <span className="text-xs text-gray-400">
                                                        {piece.confiance_classification}%
                                                    </span>
                                                </>
                                            ) : (
                                                <span className="text-gray-400">Non classifié</span>
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <p className="text-gray-500">Taille</p>
                                        <p className="font-medium">
                                            {piece.taille ? (piece.taille < 1048576
                                                ? `${Math.round(piece.taille / 1024)} Ko`
                                                : `${(piece.taille / 1048576).toFixed(1)} Mo`
                                            ) : '—'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-gray-500">Type MIME</p>
                                        <p className="font-medium font-mono text-xs">{piece.mime_type || '—'}</p>
                                    </div>
                                    <div>
                                        <p className="text-gray-500">Version</p>
                                        <p className="font-medium font-mono">v{piece.version || 1}</p>
                                    </div>
                                    <div>
                                        <p className="text-gray-500">Identifiant unique</p>
                                        <p className="font-mono text-xs truncate">{piece.identifiant_unique || '—'}</p>
                                    </div>
                                </div>

                                {/* Contrôle qualité DPI */}
                                {piece.qualite_ok === false && (
                                    <div className="flex items-center gap-2 p-3 rounded-lg bg-red-50 text-red-700 text-sm border border-red-200">
                                        <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                                        Qualité insuffisante : {piece.dpi_detecte ? `${piece.dpi_detecte} DPI détecté` : 'résolution faible'} (minimum requis : 300 DPI)
                                    </div>
                                )}

                                {/* Checksum */}
                                {piece.checksum && (
                                    <div className="text-xs text-gray-400 font-mono bg-gray-50 rounded p-2">
                                        <Shield className="inline h-3 w-3 mr-1" />
                                        SHA-256 : {piece.checksum}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Lien traçable : Bon de Caisse */}
                    {bon && (
                        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.05 }}>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Link2 className="h-4 w-4 text-neemba-500" />
                                        Bon de caisse associé
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <p className="text-gray-500">Numéro</p>
                                            <Link
                                                href={route('bons-caisse.show', bon.id)}
                                                className="font-mono font-bold text-neemba-600 hover:underline"
                                            >
                                                {bon.numero}
                                            </Link>
                                        </div>
                                        <div>
                                            <p className="text-gray-500">Bénéficiaire</p>
                                            <p className="font-medium">{bon.beneficiaire}</p>
                                        </div>
                                        <div>
                                            <p className="text-gray-500">Montant</p>
                                            <p className="font-bold text-neemba-700">
                                                {formaterMontant(bon.montant)} GNF
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-gray-500">Site</p>
                                            <p className="font-medium">{bon.site}</p>
                                        </div>
                                    </div>

                                    {/* Autres pièces du même bon */}
                                    {bon.pieces_jointes && bon.pieces_jointes.length > 1 && (
                                        <>
                                            <Separator className="my-3" />
                                            <p className="text-xs text-gray-500 mb-2">
                                                Autres justificatifs de ce bon ({bon.pieces_jointes.length - 1})
                                            </p>
                                            <div className="space-y-1">
                                                {bon.pieces_jointes
                                                    .filter((p) => p.id !== piece.id)
                                                    .map((p) => (
                                                        <Link
                                                            key={p.id}
                                                            href={route('archivage.show', p.id)}
                                                            className="flex items-center gap-2 text-sm text-gray-600 hover:text-neemba-600"
                                                        >
                                                            <FileText className="h-3 w-3" />
                                                            {p.nom_fichier}
                                                        </Link>
                                                    ))}
                                            </div>
                                        </>
                                    )}
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}

                    {/* Lien traçable : Ordre de Mission */}
                    {ordreMission && (
                        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.1 }}>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Link2 className="h-4 w-4 text-purple-500" />
                                        Ordre de mission associé
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <p className="text-gray-500">Destination</p>
                                            <p className="font-medium">{ordreMission.destination || '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-gray-500">Objet</p>
                                            <p className="font-medium">{ordreMission.objet || '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-gray-500">Date départ</p>
                                            <p>{ordreMission.date_depart ? new Date(ordreMission.date_depart).toLocaleDateString('fr-FR') : '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-gray-500">Date retour</p>
                                            <p>{ordreMission.date_retour ? new Date(ordreMission.date_retour).toLocaleDateString('fr-FR') : '—'}</p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}

                    {/* Prévisualisation inline du document */}
                    {urlPreview && (
                        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.15 }}>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base flex items-center gap-2">
                                        <Eye className="h-4 w-4 text-neemba-500" />
                                        Prévisualisation
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <div className="bg-gray-50 flex items-center justify-center rounded-b-lg overflow-hidden" style={{ minHeight: '400px' }}>
                                        {estImage ? (
                                            <div className="p-4 w-full flex items-center justify-center">
                                                <img
                                                    src={urlPreview}
                                                    alt={piece.nom_fichier}
                                                    className="max-h-[500px] max-w-full object-contain rounded-lg shadow-sm border"
                                                />
                                            </div>
                                        ) : estPdf ? (
                                            <embed
                                                src={urlPreview + '#toolbar=1&navpanes=0'}
                                                type="application/pdf"
                                                className="w-full rounded-b-lg"
                                                style={{ height: '600px' }}
                                            />
                                        ) : (
                                            <div className="text-center text-gray-400 py-12">
                                                <FileText className="h-16 w-16 mx-auto mb-3 text-gray-300" />
                                                <p className="text-sm font-medium mb-2">Prévisualisation non disponible pour ce type de fichier</p>
                                                <p className="text-xs text-gray-400 mb-4">({piece.mime_type || 'type inconnu'})</p>
                                                <a href={urlPreview} target="_blank" rel="noopener noreferrer">
                                                    <Button variant="outline" size="sm">
                                                        <Eye className="h-3.5 w-3.5 mr-1.5" />
                                                        Ouvrir dans un nouvel onglet
                                                    </Button>
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}
                </div>

                {/* Sidebar */}
                <div>
                    <motion.div initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} transition={{ delay: 0.15 }}>
                        <Card className="sticky top-24">
                            <CardHeader>
                                <CardTitle className="text-base">Actions</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {/* Statut archivage */}
                                {piece.date_archivage ? (
                                    <div className="bg-green-50 rounded-lg p-3 text-sm">
                                        <div className="flex items-center gap-2 text-green-700 font-medium mb-1">
                                            <CheckCircle className="h-4 w-4" />
                                            Document archivé
                                        </div>
                                        <p className="text-green-600 text-xs">
                                            Archivé le {new Date(piece.date_archivage).toLocaleDateString('fr-FR')}
                                        </p>
                                        {piece.date_expiration_retention && (
                                            <p className="text-green-600 text-xs">
                                                Rétention jusqu'au {new Date(piece.date_expiration_retention).toLocaleDateString('fr-FR')}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <Button
                                        className="w-full"
                                        onClick={() => router.post(route('archivage.archiver', piece.id), {}, { preserveScroll: true })}
                                    >
                                        <Archive className="mr-2 h-4 w-4" />
                                        Archiver (rétention 5 ans)
                                    </Button>
                                )}

                                <Separator />

                                {/* Reclassification */}
                                <div className="space-y-2">
                                    <p className="text-xs text-gray-500 font-medium uppercase tracking-wide">Reclassifier</p>
                                    <Select value={nouvelleClassif} onValueChange={setNouvelleClassif}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Classification" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(classificationsIa).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>{label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="w-full"
                                        onClick={handleReclassifier}
                                        disabled={!nouvelleClassif || nouvelleClassif === piece.classification_ia}
                                    >
                                        Appliquer
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="w-full"
                                        onClick={() => router.post(route('archivage.relancer-classification', piece.id), {}, { preserveScroll: true })}
                                    >
                                        <RefreshCw className="mr-2 h-3 w-3" />
                                        Relancer la classification IA
                                    </Button>
                                </div>

                                <Separator />

                                {/* Visualisation et téléchargement */}
                                {piece.chemin_fichier && (
                                    <div className="space-y-2">
                                        <a
                                            href={`/storage/${piece.chemin_fichier}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="block"
                                        >
                                            <Button variant="outline" className="w-full" size="sm">
                                                <Eye className="mr-2 h-4 w-4" />
                                                Ouvrir dans un nouvel onglet
                                            </Button>
                                        </a>
                                        <a
                                            href={`/storage/${piece.chemin_fichier}`}
                                            download
                                            className="block"
                                        >
                                            <Button variant="outline" className="w-full" size="sm">
                                                <Download className="mr-2 h-4 w-4" />
                                                Télécharger
                                            </Button>
                                        </a>
                                    </div>
                                )}

                                {/* Horodatage */}
                                <div className="text-xs text-gray-400 space-y-1 pt-2">
                                    <div className="flex items-center gap-1">
                                        <Clock className="h-3 w-3" />
                                        Créé le {new Date(piece.created_at).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <Hash className="h-3 w-3" />
                                        UUID : {piece.identifiant_unique ? piece.identifiant_unique.slice(0, 8) + '...' : '—'}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
