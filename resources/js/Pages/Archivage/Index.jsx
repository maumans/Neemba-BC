/**
 * Page Archivage Centralisé - NEEMBA
 * 
 * Navigation arborescente des documents avec prévisualisation,
 * recherche full-text, classification IA, archivage et contrôle qualité.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Search,
    Archive,
    FileText,
    AlertTriangle,
    Eye,
    CheckCircle,
    XCircle,
    Folder,
    FolderOpen,
    ChevronRight,
    ChevronDown,
    Image as ImageIcon,
    File,
    FileSpreadsheet,
    Download,
    Tag,
    Calendar,
    HardDrive,
    Info,
    Filter,
    SlidersHorizontal,
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';

/** Formater la taille d'un fichier */
function formatTaille(octets) {
    if (!octets) return '—';
    if (octets < 1024) return octets + ' o';
    if (octets < 1048576) return (octets / 1024).toFixed(1) + ' Ko';
    return (octets / 1048576).toFixed(1) + ' Mo';
}

/** Icône selon le type de fichier */
function IconeFichier({ mime, className = 'h-4 w-4' }) {
    if (!mime) return <File className={className} />;
    if (mime.startsWith('image/')) return <ImageIcon className={`${className} text-blue-500`} />;
    if (mime.includes('pdf')) return <FileText className={`${className} text-red-500`} />;
    if (mime.includes('sheet') || mime.includes('excel')) return <FileSpreadsheet className={`${className} text-green-600`} />;
    return <File className={`${className} text-gray-400`} />;
}

/** Noeud de l'arbre (récursif) */
function NoeudArbre({ noeud, niveau = 0, onSelectFichier, fichierActif, chemin = [] }) {
    const [ouvert, setOuvert] = useState(niveau === 0);
    const aDesEnfants = noeud.enfants && noeud.enfants.length > 0;
    const aDesFichiers = noeud.fichiers && noeud.fichiers.length > 0;
    const cheminActuel = [...chemin, noeud.label];

    const iconeNiveau = () => {
        if (aDesFichiers) return ouvert ? <FolderOpen className="h-4 w-4 text-amber-500" /> : <Folder className="h-4 w-4 text-amber-500" />;
        return ouvert ? <FolderOpen className="h-4 w-4 text-sky-500" /> : <Folder className="h-4 w-4 text-sky-500" />;
    };

    return (
        <div>
            <button
                onClick={() => setOuvert(!ouvert)}
                className={`w-full flex items-center gap-1.5 py-1.5 px-2 rounded-md text-sm hover:bg-gray-100 transition-colors group ${
                    ouvert ? 'bg-gray-50' : ''
                }`}
                style={{ paddingLeft: `${niveau * 16 + 8}px` }}
            >
                {(aDesEnfants || aDesFichiers) ? (
                    ouvert ? <ChevronDown className="h-3.5 w-3.5 text-gray-400 flex-shrink-0" /> : <ChevronRight className="h-3.5 w-3.5 text-gray-400 flex-shrink-0" />
                ) : (
                    <span className="w-3.5" />
                )}
                {iconeNiveau()}
                <span className="truncate font-medium text-gray-700 group-hover:text-gray-900">{noeud.label}</span>
                <Badge variant="secondary" className="ml-auto text-[10px] px-1.5 py-0 h-5 bg-gray-100 text-gray-500 flex-shrink-0">
                    {noeud.count}
                </Badge>
            </button>

            <AnimatePresence>
                {ouvert && (
                    <motion.div
                        initial={{ height: 0, opacity: 0 }}
                        animate={{ height: 'auto', opacity: 1 }}
                        exit={{ height: 0, opacity: 0 }}
                        transition={{ duration: 0.15 }}
                        className="overflow-hidden"
                    >
                        {aDesEnfants && noeud.enfants.map((enfant, i) => (
                            <NoeudArbre
                                key={enfant.label + i}
                                noeud={enfant}
                                niveau={niveau + 1}
                                onSelectFichier={onSelectFichier}
                                fichierActif={fichierActif}
                                chemin={cheminActuel}
                            />
                        ))}

                        {aDesFichiers && noeud.fichiers.map((fichier) => (
                            <button
                                key={fichier.id}
                                onClick={() => onSelectFichier(fichier, cheminActuel, noeud)}
                                className={`w-full flex items-center gap-2 py-1.5 px-2 rounded-md text-sm transition-colors ${
                                    fichierActif?.id === fichier.id
                                        ? 'bg-sky-50 text-sky-800 border-l-2 border-sky-500'
                                        : 'hover:bg-gray-50 text-gray-600'
                                }`}
                                style={{ paddingLeft: `${(niveau + 1) * 16 + 8}px` }}
                            >
                                <IconeFichier mime={fichier.mime_type} className="h-4 w-4 flex-shrink-0" />
                                <span className="truncate text-xs">{fichier.nom_fichier}</span>
                                <span className="ml-auto text-[10px] text-gray-400 flex-shrink-0">{formatTaille(fichier.taille)}</span>
                                {fichier.date_archivage && (
                                    <span className="text-[10px] text-gray-400 flex-shrink-0">{fichier.created_at}</span>
                                )}
                            </button>
                        ))}
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

export default function Index({
    arborescence = [],
    documents,
    filtres = {},
    classificationsIa = {},
    typesDocuments = {},
    sites = [],
}) {
    const [recherche, setRecherche] = useState(filtres.recherche || '');
    const [classification, setClassification] = useState(filtres.classification || '');
    const [typeDocument, setTypeDocument] = useState(filtres.type_document || '');
    const [site, setSite] = useState(filtres.site || '');
    const [dateDebut, setDateDebut] = useState(filtres.date_debut || '');
    const [dateFin, setDateFin] = useState(filtres.date_fin || '');
    const [archive, setArchive] = useState(filtres.archive || '');
    const [qualite, setQualite] = useState(filtres.qualite || '');
    const [fichierSelectionne, setFichierSelectionne] = useState(null);
    const [cheminSelectionne, setCheminSelectionne] = useState([]);
    const [noeudParent, setNoeudParent] = useState(null);
    const [docToArchive, setDocToArchive] = useState(null);
    const [showFiltres, setShowFiltres] = useState(
        !!(filtres.classification || filtres.type_document || filtres.site || filtres.date_debut || filtres.date_fin || filtres.archive || filtres.qualite)
    );

    const aDesFiltresActifs = !!(filtres.recherche || filtres.classification || filtres.type_document || filtres.site || filtres.date_debut || filtres.date_fin || filtres.archive || filtres.qualite);
    const modeRecherche = aDesFiltresActifs;

    const handleSelectFichier = (fichier, chemin, parent) => {
        setFichierSelectionne(fichier);
        setCheminSelectionne(chemin);
        setNoeudParent(parent);
    };

    const confirmArchiver = () => {
        if (!docToArchive) return;
        router.post(route('archivage.archiver', docToArchive), {}, {
            preserveScroll: true,
            onFinish: () => setDocToArchive(null),
        });
    };

    const construireParamsFiltres = () => {
        const params = {};
        if (recherche.trim()) params.recherche = recherche.trim();
        if (classification && classification !== '__all__') params.classification = classification;
        if (typeDocument && typeDocument !== '__all__') params.type_document = typeDocument;
        if (site && site !== '__all__') params.site = site;
        if (dateDebut) params.date_debut = dateDebut;
        if (dateFin) params.date_fin = dateFin;
        if (archive && archive !== '__all__') params.archive = archive;
        if (qualite && qualite !== '__all__') params.qualite = qualite;
        return params;
    };

    const handleRecherche = (e) => {
        e.preventDefault();
        const params = construireParamsFiltres();
        router.get(route('archivage.index'), params, { preserveState: true, preserveScroll: true });
    };

    const resetRecherche = () => {
        setRecherche('');
        setClassification('');
        setTypeDocument('');
        setSite('');
        setDateDebut('');
        setDateFin('');
        setArchive('');
        setQualite('');
        router.get(route('archivage.index'), {}, { preserveState: true, preserveScroll: true });
    };

    /** Nombre total de fichiers */
    const totalFichiers = useMemo(() => {
        return arborescence.reduce((sum, a) => sum + a.count, 0);
    }, [arborescence]);

    /** URL de prévisualisation */
    const urlPreview = fichierSelectionne?.chemin_fichier
        ? `/storage/${fichierSelectionne.chemin_fichier}`
        : null;

    const estImage = fichierSelectionne?.mime_type?.startsWith('image/');
    const estPdf = fichierSelectionne?.mime_type?.includes('pdf');

    return (
        <AuthenticatedLayout header="Archivage Centralisé">
            <Head title="Archivage Centralisé" />

            {/* Fil d'ariane du plan de classement */}
            <div className="flex items-center gap-2 text-sm text-gray-500 mb-4 flex-wrap">
                <Archive className="h-4 w-4 text-neemba-500" />
                <span className="font-medium text-gray-700">Plan de classement :</span>
                {['Année', 'Service', 'Code Analytique', 'Référence'].map((label, i) => (
                    <span key={label} className="flex items-center gap-1.5">
                        {i > 0 && <ChevronRight className="h-3 w-3" />}
                        <span className="text-gray-600">{label}</span>
                    </span>
                ))}
            </div>

            {/* Barre de recherche + filtres avancés */}
            <Card className="mb-4">
                <CardContent className="py-3 px-3 sm:px-4">
                    <form onSubmit={handleRecherche}>
                        <div className="flex flex-col sm:flex-row gap-2 sm:gap-3 items-stretch sm:items-center">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                                <Input
                                    placeholder="Rechercher par N° BC, bénéficiaire, montant, site..."
                                    value={recherche}
                                    onChange={(e) => setRecherche(e.target.value)}
                                    className="pl-10 h-9"
                                />
                            </div>
                            <Button
                                type="button"
                                variant={showFiltres ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => setShowFiltres(!showFiltres)}
                                className={showFiltres ? 'bg-neemba-400 text-marine-950 hover:bg-neemba-500' : ''}
                            >
                                <SlidersHorizontal className="mr-1.5 h-3.5 w-3.5" />
                                Filtres
                                {aDesFiltresActifs && !filtres.recherche ? (
                                    <Badge className="ml-1.5 h-4 px-1 text-[10px] bg-white/30">actifs</Badge>
                                ) : null}
                            </Button>
                            <Button type="submit" size="sm">
                                <Search className="mr-1.5 h-3.5 w-3.5" />
                                Rechercher
                            </Button>
                            {aDesFiltresActifs && (
                                <Button type="button" variant="ghost" size="sm" onClick={resetRecherche}>
                                    <XCircle className="mr-1 h-3.5 w-3.5" />
                                    Effacer
                                </Button>
                            )}
                        </div>

                        {/* Panneau filtres avancés */}
                        <AnimatePresence>
                            {showFiltres && (
                                <motion.div
                                    initial={{ height: 0, opacity: 0 }}
                                    animate={{ height: 'auto', opacity: 1 }}
                                    exit={{ height: 0, opacity: 0 }}
                                    transition={{ duration: 0.2 }}
                                    className="overflow-hidden"
                                >
                                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mt-3 pt-3 border-t">
                                        {/* Classification IA */}
                                        <div>
                                            <label className="text-xs font-medium text-gray-500 mb-1 block">Classification IA</label>
                                            <Select value={classification} onValueChange={setClassification}>
                                                <SelectTrigger className="h-8 text-xs">
                                                    <SelectValue placeholder="Toutes" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__all__">Toutes</SelectItem>
                                                    {Object.entries(classificationsIa).map(([key, label]) => (
                                                        <SelectItem key={key} value={key}>{label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        {/* Type de document */}
                                        <div>
                                            <label className="text-xs font-medium text-gray-500 mb-1 block">Type de document</label>
                                            <Select value={typeDocument} onValueChange={setTypeDocument}>
                                                <SelectTrigger className="h-8 text-xs">
                                                    <SelectValue placeholder="Tous" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__all__">Tous</SelectItem>
                                                    {Object.entries(typesDocuments).map(([key, label]) => (
                                                        <SelectItem key={key} value={key}>{label}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        {/* Site */}
                                        <div>
                                            <label className="text-xs font-medium text-gray-500 mb-1 block">Site</label>
                                            <Select value={site} onValueChange={setSite}>
                                                <SelectTrigger className="h-8 text-xs">
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

                                        {/* Archivé */}
                                        <div>
                                            <label className="text-xs font-medium text-gray-500 mb-1 block">Statut archivage</label>
                                            <Select value={archive} onValueChange={setArchive}>
                                                <SelectTrigger className="h-8 text-xs">
                                                    <SelectValue placeholder="Tous" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__all__">Tous</SelectItem>
                                                    <SelectItem value="oui">Archivés</SelectItem>
                                                    <SelectItem value="non">Non archivés</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        {/* Date début */}
                                        <div>
                                            <label className="text-xs font-medium text-gray-500 mb-1 block">Date début</label>
                                            <Input
                                                type="date"
                                                value={dateDebut}
                                                onChange={(e) => setDateDebut(e.target.value)}
                                                className="h-8 text-xs"
                                            />
                                        </div>

                                        {/* Date fin */}
                                        <div>
                                            <label className="text-xs font-medium text-gray-500 mb-1 block">Date fin</label>
                                            <Input
                                                type="date"
                                                value={dateFin}
                                                onChange={(e) => setDateFin(e.target.value)}
                                                className="h-8 text-xs"
                                            />
                                        </div>

                                        {/* Qualité */}
                                        <div>
                                            <label className="text-xs font-medium text-gray-500 mb-1 block">Qualité scan</label>
                                            <Select value={qualite} onValueChange={setQualite}>
                                                <SelectTrigger className="h-8 text-xs">
                                                    <SelectValue placeholder="Toutes" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__all__">Toutes</SelectItem>
                                                    <SelectItem value="ok">Qualité OK</SelectItem>
                                                    <SelectItem value="faible">Qualité faible</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </form>
                </CardContent>
            </Card>

            {/* Mode recherche : tableau classique */}
            {modeRecherche && documents ? (
                <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }}>
                    <Card>
                        <CardHeader className="py-3">
                            <CardTitle className="text-sm flex items-center gap-2">
                                <Search className="h-4 w-4 text-neemba-500" />
                                Résultats de recherche ({documents.total ?? 0})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-xs">Document</TableHead>
                                        <TableHead className="text-xs hidden md:table-cell">Classification</TableHead>
                                        <TableHead className="text-xs hidden sm:table-cell">Bon associé</TableHead>
                                        <TableHead className="text-xs hidden lg:table-cell">Bénéficiaire</TableHead>
                                        <TableHead className="text-xs text-center hidden sm:table-cell">Qualité</TableHead>
                                        <TableHead className="text-xs text-center">Archivé</TableHead>
                                        <TableHead className="text-xs"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {(documents.data || []).length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center py-8 text-gray-400">
                                                Aucun document trouvé.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        (documents.data || []).map((doc) => (
                                            <TableRow key={doc.id}>
                                                <TableCell className="py-2">
                                                    <div className="flex items-center gap-2">
                                                        <FileText className="h-4 w-4 text-gray-400 flex-shrink-0" />
                                                        <div className="min-w-0">
                                                            <p className="font-medium text-xs sm:text-sm truncate max-w-[180px]">{doc.nom_fichier}</p>
                                                            <p className="text-[10px] text-gray-400">
                                                                {typesDocuments[doc.type_document] || doc.type_document}
                                                            </p>
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="hidden md:table-cell py-2">
                                                    {doc.classification_ia ? (
                                                        <Badge variant="secondary" className="text-[10px]">
                                                            {classificationsIa[doc.classification_ia] || doc.classification_ia}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-[10px] text-gray-400">Non classifié</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell py-2">
                                                    {doc.bon_caisse ? (
                                                        <Link href={route('bons-caisse.show', doc.bon_caisse.id)} className="text-xs text-neemba-600 hover:underline font-mono">
                                                            {doc.bon_caisse.numero}
                                                        </Link>
                                                    ) : '—'}
                                                </TableCell>
                                                <TableCell className="text-xs hidden lg:table-cell py-2">{doc.bon_caisse?.beneficiaire || '—'}</TableCell>
                                                <TableCell className="text-center hidden sm:table-cell py-2">
                                                    {doc.qualite_ok === false ? (
                                                        <Badge variant="destructive" className="text-[10px]">
                                                            <AlertTriangle className="mr-0.5 h-3 w-3" />
                                                            Faible
                                                        </Badge>
                                                    ) : (
                                                        <CheckCircle className="h-3.5 w-3.5 text-green-500 mx-auto" />
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-center py-2">
                                                    {doc.date_archivage ? (
                                                        <Badge className="text-[10px] bg-green-100 text-green-700">Archivé</Badge>
                                                    ) : (
                                                        <XCircle className="h-3.5 w-3.5 text-gray-300 mx-auto" />
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    <div className="flex gap-1">
                                                        <Link href={route('archivage.show', doc.id)}>
                                                            <Button variant="ghost" size="sm" className="h-7 w-7 p-0"><Eye className="h-3.5 w-3.5" /></Button>
                                                        </Link>
                                                        {!doc.date_archivage && (
                                                            <Button variant="ghost" size="sm" className="h-7 w-7 p-0" onClick={() => setDocToArchive(doc.id)}>
                                                                <Archive className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Pagination recherche */}
                    {documents?.links && documents.links.length > 3 && (
                        <div className="flex flex-col sm:flex-row items-center justify-between gap-2 mt-4">
                            <p className="text-xs sm:text-sm text-gray-500">
                                {documents.from} à {documents.to} sur {documents.total}
                            </p>
                            <div className="flex flex-wrap gap-1 justify-center">
                                {documents.links.map((link, i) => (
                                    <Link
                                        key={i}
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
                </motion.div>
            ) : (
                /* Mode arborescence */
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4" style={{ minHeight: '65vh' }}>
                    {/* Panneau gauche : arborescence des dossiers */}
                    <motion.div
                        initial={{ opacity: 0, x: -10 }}
                        animate={{ opacity: 1, x: 0 }}
                        className="lg:col-span-5 xl:col-span-4"
                    >
                        <Card className="h-full">
                            <CardHeader className="py-3 px-4 border-b bg-gray-50/50">
                                <CardTitle className="text-xs font-semibold text-gray-500 uppercase tracking-wider flex items-center justify-between">
                                    <span>Dossiers</span>
                                    <Badge variant="secondary" className="text-[10px]">{totalFichiers}</Badge>
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-2 overflow-y-auto" style={{ maxHeight: 'calc(65vh - 50px)' }}>
                                {arborescence.length === 0 ? (
                                    <div className="text-center py-12">
                                        <Folder className="h-10 w-10 mx-auto text-gray-300 mb-2" />
                                        <p className="text-sm text-gray-400">Aucun document archivé</p>
                                    </div>
                                ) : (
                                    arborescence.map((annee, i) => (
                                        <NoeudArbre
                                            key={annee.label + i}
                                            noeud={annee}
                                            niveau={0}
                                            onSelectFichier={handleSelectFichier}
                                            fichierActif={fichierSelectionne}
                                        />
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Panneau droit : prévisualisation */}
                    <motion.div
                        initial={{ opacity: 0, x: 10 }}
                        animate={{ opacity: 1, x: 0 }}
                        className="lg:col-span-7 xl:col-span-8"
                    >
                        <Card className="h-full">
                            <CardHeader className="py-3 px-4 border-b bg-gray-50/50">
                                <CardTitle className="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                    Prévisualisation
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                {!fichierSelectionne ? (
                                    <div className="flex flex-col items-center justify-center py-24 text-gray-400">
                                        <Info className="h-12 w-12 mb-3 text-gray-300" />
                                        <p className="text-sm font-medium">Sélectionnez un fichier dans l'arborescence</p>
                                        <p className="text-xs mt-1">pour afficher son aperçu et ses détails</p>
                                    </div>
                                ) : (
                                    <div>
                                        {/* En-tête du fichier */}
                                        <div className="p-4 border-b">
                                            <div className="flex items-start gap-3">
                                                <div className="p-2.5 rounded-lg bg-sky-50">
                                                    <IconeFichier mime={fichierSelectionne.mime_type} className="h-6 w-6" />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <h3 className="font-semibold text-gray-900 truncate">{fichierSelectionne.nom_fichier}</h3>
                                                    <p className="text-xs text-gray-500 mt-0.5">
                                                        {typesDocuments[fichierSelectionne.type_document] || fichierSelectionne.type_document || 'Pièce justificative'}
                                                    </p>
                                                </div>
                                            </div>

                                            {/* Tags */}
                                            <div className="flex flex-wrap gap-1.5 mt-3">
                                                {fichierSelectionne.classification_ia && (
                                                    <Badge variant="secondary" className="text-xs">
                                                        <Tag className="h-3 w-3 mr-1" />
                                                        {classificationsIa[fichierSelectionne.classification_ia] || fichierSelectionne.classification_ia}
                                                    </Badge>
                                                )}
                                                {typesDocuments[fichierSelectionne.type_document] && (
                                                    <Badge variant="outline" className="text-xs">
                                                        {typesDocuments[fichierSelectionne.type_document]}
                                                    </Badge>
                                                )}
                                            </div>

                                            {/* Chemin de classement */}
                                            <div className="flex items-center gap-1 mt-3 text-xs text-gray-500 bg-gray-50 rounded-md px-3 py-2">
                                                <span className="font-medium text-gray-600">Classement :</span>
                                                {cheminSelectionne.map((c, i) => (
                                                    <span key={i} className="flex items-center gap-1">
                                                        {i > 0 && <span className="text-gray-300">/</span>}
                                                        <span>{c}</span>
                                                    </span>
                                                ))}
                                                <span className="text-gray-300">/</span>
                                                <span className="font-medium text-gray-700">{fichierSelectionne.nom_fichier}</span>
                                            </div>

                                            {/* Métadonnées */}
                                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 text-xs">
                                                <div className="flex items-center gap-1.5 text-gray-500">
                                                    <HardDrive className="h-3 w-3" />
                                                    <span>Taille : {formatTaille(fichierSelectionne.taille)}</span>
                                                </div>
                                                <div className="flex items-center gap-1.5 text-gray-500">
                                                    <FileText className="h-3 w-3" />
                                                    <span>Ext : {fichierSelectionne.mime_type?.split('/').pop()?.toUpperCase()}</span>
                                                </div>
                                                <div className="flex items-center gap-1.5 text-gray-500">
                                                    <Calendar className="h-3 w-3" />
                                                    <span>{fichierSelectionne.created_at}</span>
                                                </div>
                                                <div className="flex items-center gap-1.5">
                                                    {fichierSelectionne.qualite_ok === false ? (
                                                        <span className="text-red-600 flex items-center gap-1">
                                                            <AlertTriangle className="h-3 w-3" />
                                                            {fichierSelectionne.dpi_detecte ? `${fichierSelectionne.dpi_detecte} DPI` : 'Qualité faible'}
                                                        </span>
                                                    ) : (
                                                        <span className="text-green-600 flex items-center gap-1">
                                                            <CheckCircle className="h-3 w-3" />
                                                            Qualité OK
                                                        </span>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Actions */}
                                            <div className="flex gap-2 mt-3">
                                                <Link href={route('archivage.show', fichierSelectionne.id)}>
                                                    <Button size="sm" variant="outline">
                                                        <Eye className="h-3.5 w-3.5 mr-1.5" />
                                                        Détails
                                                    </Button>
                                                </Link>
                                                <a href={urlPreview} target="_blank" rel="noopener noreferrer">
                                                    <Button size="sm" variant="outline">
                                                        <Eye className="h-3.5 w-3.5 mr-1.5" />
                                                        Ouvrir le document
                                                    </Button>
                                                </a>
                                                <a href={urlPreview} download>
                                                    <Button size="sm" variant="outline">
                                                        <Download className="h-3.5 w-3.5 mr-1.5" />
                                                        Télécharger
                                                    </Button>
                                                </a>
                                                {!fichierSelectionne.date_archivage && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="text-green-700 border-green-300 hover:bg-green-50"
                                                        onClick={() => setDocToArchive(fichierSelectionne.id)}
                                                    >
                                                        <Archive className="h-3.5 w-3.5 mr-1.5" />
                                                        Archiver
                                                    </Button>
                                                )}
                                            </div>
                                        </div>

                                        {/* Zone de prévisualisation */}
                                        <div className="bg-gray-50 flex items-center justify-center" style={{ minHeight: '400px' }}>
                                            {estImage ? (
                                                <div className="p-4 w-full flex items-center justify-center">
                                                    <img
                                                        src={urlPreview}
                                                        alt={fichierSelectionne.nom_fichier}
                                                        className="max-h-[500px] max-w-full object-contain rounded-lg shadow-sm border"
                                                    />
                                                </div>
                                            ) : estPdf ? (
                                                <embed
                                                    src={urlPreview + '#toolbar=1&navpanes=0'}
                                                    type="application/pdf"
                                                    className="w-full"
                                                    style={{ height: '500px' }}
                                                />
                                            ) : (
                                                <div className="text-center text-gray-400 py-12">
                                                    <FileText className="h-16 w-16 mx-auto mb-3 text-gray-300" />
                                                    <p className="text-sm font-medium mb-2">Prévisualisation non disponible pour ce type de fichier</p>
                                                    <a href={urlPreview} target="_blank" rel="noopener noreferrer">
                                                        <Button variant="outline" size="sm">
                                                            <Eye className="h-3.5 w-3.5 mr-1.5" />
                                                            Ouvrir dans un nouvel onglet
                                                        </Button>
                                                    </a>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>
            )}

            {/* Modale de confirmation */}
            <Dialog open={!!docToArchive} onOpenChange={(open) => !open && setDocToArchive(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Archiver le document</DialogTitle>
                        <DialogDescription>
                            Êtes-vous sûr de vouloir archiver ce document ? Cette action gèlera ses métadonnées et le marquera comme vérifié.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDocToArchive(null)}>Annuler</Button>
                        <Button onClick={confirmArchiver} className="bg-green-600 hover:bg-green-700 text-white">Confirmer l'archivage</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
