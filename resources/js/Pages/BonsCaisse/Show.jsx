/**
 * Page Détail d'un Bon de Caisse - NEEMBA
 * 
 * Affiche toutes les informations d'un bon de caisse :
 * - Informations générales et bénéficiaire
 * - Détails dépense et paiement
 * - Workflow de validation
 * - Pièces jointes
 * - Historique complet des actions
 * - Actions contextuelles (soumettre, payer, régulariser, archiver)
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    ArrowLeft,
    FileText,
    CheckCircle2,
    XCircle,
    Clock,
    Send,
    Banknote,
    Archive,
    Download,
    Eye,
    Paperclip,
    User,
    MapPin,
    Building,
    Calendar,
    Hash,
    Phone,
    CreditCard,
    Tag,
    AlertTriangle,
    History,
    Upload,
    Activity,
    FilePlus,
    Pencil,
    MessageSquare,
    Bell,
    ClipboardCheck,
    Shield,
    Lock,
    RefreshCw,
    X,
    GitBranch,
    Wallet,
    Timer,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Separator } from '@/Components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
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
    DialogTrigger,
} from '@/Components/ui/dialog';
import { nombreEnLettres, formaterMontant as formatMontant } from '@/utils/nombreEnLettres';
import { Input } from '@/Components/ui/input';
import { Combobox } from '@/Components/ui/combobox';

/** Formate une date/heure */
function formatDateTime(date) {
    if (!date) return '-';
    return new Date(date).toLocaleString('fr-FR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

/** Formate une date */
function formatDate(date) {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('fr-FR');
}

/** Variante de badge selon le statut */
function badgeVariantParStatut(statut) {
    const map = {
        'BROUILLON': 'brouillon',
        'EN_ATTENTE_CHEF_SERVICE': 'en_attente',
        'EN_ATTENTE_CDG': 'en_attente',
        'EN_ATTENTE_DAF': 'en_attente',
        'EN_ATTENTE_DP': 'en_attente',
        'APPROUVE': 'approuve',
        'PAYE': 'paye',
        'REJETE': 'rejete',
        'EN_ATTENTE_REGULARISATION': 'en_attente',
        'REGULARISE': 'regularise',
        'ARCHIVE': 'brouillon',
    };
    return map[statut] || 'default';
}

/** Labels des rôles de validation */
const rolesValidation = {
    'responsable_service': 'Chef de Service',
    'controle_gestion': 'Contrôle de Gestion',
    'daf': 'DAF',
    'directeur_pays': 'Directeur Pays',
};

/** Icône pour chaque type d'action historique */
const iconeAction = {
    'creation': FilePlus,
    'modification': Pencil,
    'soumission': Send,
    'validation_chef_service': CheckCircle2,
    'validation_cdg': CheckCircle2,
    'validation_daf': CheckCircle2,
    'validation_dp': CheckCircle2,
    'rejet': XCircle,
    'demande_complement': MessageSquare,
    'paiement': Banknote,
    'regularisation': ClipboardCheck,
    'archivage': Archive,
    'ajout_piece_jointe': Paperclip,
    'relance_regularisation': Bell,
    'modification_code_analytique': Hash,
    'modification_ventilation': GitBranch,
};

/** Couleur de fond pour chaque type d'action */
const couleurAction = {
    'creation': 'text-blue-500',
    'modification': 'text-gray-500',
    'soumission': 'text-neemba-500',
    'validation_chef_service': 'text-green-500',
    'validation_cdg': 'text-green-500',
    'validation_daf': 'text-green-500',
    'validation_dp': 'text-green-500',
    'rejet': 'text-red-500',
    'demande_complement': 'text-amber-500',
    'paiement': 'text-emerald-600',
    'regularisation': 'text-teal-500',
    'archivage': 'text-gray-400',
    'ajout_piece_jointe': 'text-blue-400',
    'relance_regularisation': 'text-orange-500',
    'modification_code_analytique': 'text-purple-500',
    'modification_ventilation': 'text-purple-500',
};

export default function Show({
    bonCaisse,
    statutsLabels = {},
    categoriesDepense = {},
    typesBeneficiaire = {},
    modesPaiement = {},
    niveauxUrgence = {},
    actionsLabels = {},
    peutValiderCeBon = false,
    validationEnCours = null,
    seuilDP = 1500000,
    roleUtilisateur = '',
    estProprietaire = false,
    delaisValidation = {},
    soldeCaisseSite = null,
    codesAnalytiques = [],
    peutPreRegulariser = false,
    aDesPiecesRegularisation = false,
}) {
    const { auth, flash } = usePage().props;
    const user = auth.user;

    /* État pour le mode de paiement lors du décaissement */
    const [modePaiement, setModePaiement] = useState('especes');
    const [showPaiementForm, setShowPaiementForm] = useState(false);
    const [showRejetDialog, setShowRejetDialog] = useState(false);
    const [showComplementDialog, setShowComplementDialog] = useState(false);
    const [showSoumettreDialog, setShowSoumettreDialog] = useState(false);
    const [showArchiverDialog, setShowArchiverDialog] = useState(false);
    const [showApprouverDialog, setShowApprouverDialog] = useState(false);
    const [showRegulariserDialog, setShowRegulariserDialog] = useState(false);
    const [previewPieceId, setPreviewPieceId] = useState(null);

    /* États pour la validation OTP */
    const [codeOtp, setCodeOtp] = useState('');
    const [otpEnvoye, setOtpEnvoye] = useState(false);
    const [otpVerifie, setOtpVerifie] = useState(false);
    const [envoiEnCours, setEnvoiEnCours] = useState(false);
    const [verificationEnCours, setVerificationEnCours] = useState(false);

    /* Détecter le succès de la vérification OTP via flash messages */
    useEffect(() => {
        if (flash?.success && flash.success.includes('Code OTP vérifié')) {
            setOtpVerifie(true);
            setVerificationEnCours(false);
        }
    }, [flash]);

    /* Rôles effectifs de l'utilisateur (natifs + obtenus par délégation) */
    const rolesUtilisateurActuels = user.roles_effectifs || [user.role || 'demandeur'];

    /* Détection rôle CDG pour édition code analytique et ventilations */
    const estCDG = rolesUtilisateurActuels.includes('controle_gestion');

    /* Formulaires de validation */
    const approuverForm = useForm({
        commentaire: '',
        ...(estCDG ? { code_analytique: bonCaisse.code_analytique || '', ventilations: bonCaisse.ventilations || [] } : {}),
    });
    const rejeterForm = useForm({ commentaire: '' });
    const complementForm = useForm({ commentaire: '' });

    /* Formulaire pour la régularisation avec upload + motif */
    const regularisationForm = useForm({
        pieces_jointes: [],
        motif_regularisation: '',
    });
    const [fichiersRegul, setFichiersRegul] = useState([]);

    /* Formulaire pour la pré-régularisation */
    const preRegularisationForm = useForm({
        pieces_jointes: [],
        motif_regularisation: '',
    });
    const [fichiersPreRegul, setFichiersPreRegul] = useState([]);

    /* Timer live pour les BP : temps écoulé depuis la création */
    const estBPEnCours = bonCaisse.type_bon === 'BP' && !['REGULARISE', 'ARCHIVE'].includes(bonCaisse.statut);
    const [tempsEcoule, setTempsEcoule] = useState('');

    useEffect(() => {
        if (!estBPEnCours) return;

        const calculerTemps = () => {
            const debut = new Date(bonCaisse.created_at);
            const maintenant = new Date();
            const diffMs = maintenant.getTime() - debut.getTime();

            const jours = Math.floor(diffMs / (1000 * 60 * 60 * 24));
            const heures = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diffMs % (1000 * 60 * 60)) / (1000 * 60));
            const secondes = Math.floor((diffMs % (1000 * 60)) / 1000);

            let texte = '';
            if (jours > 0) texte += `${jours}j `;
            if (heures > 0 || jours > 0) texte += `${heures}h `;
            texte += `${minutes}min ${secondes}s`;
            setTempsEcoule(texte.trim());
        };

        calculerTemps();
        const intervalle = setInterval(calculerTemps, 1000);
        return () => clearInterval(intervalle);
    }, [estBPEnCours, bonCaisse.created_at]);

    /** Exécuter une action simple sur le bon */
    const executerAction = (routeName, data = {}) => {
        router.post(route(routeName, bonCaisse.id), data, {
            preserveScroll: true,
        });
    };

    /** Générer et envoyer le code OTP par SMS */
    const genererOtp = () => {
        setEnvoiEnCours(true);
        router.post(route('bons-caisse.otp.generer', bonCaisse.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setOtpEnvoye(true);
                setEnvoiEnCours(false);
            },
            onError: () => {
                setEnvoiEnCours(false);
            },
        });
    };

    /** Vérifier le code OTP saisi */
    const verifierOtp = () => {
        if (!codeOtp || codeOtp.length !== 6) {
            return;
        }
        setVerificationEnCours(true);
        router.post(route('bons-caisse.otp.verifier', bonCaisse.id), { code_otp: codeOtp }, {
            preserveScroll: true,
            onFinish: () => {
                setVerificationEnCours(false);
            },
        });
    };

    /** Effectuer le paiement avec le mode sélectionné */
    const effectuerPaiement = () => {
        if (!otpVerifie) {
            return;
        }
        executerAction('bons-caisse.payer', { mode_paiement_effectif: modePaiement });
    };

    /** Approuver le bon */
    const handleApprouver = (e) => {
        e.preventDefault();
        approuverForm.post(route('validations.approuver', bonCaisse.id), {
            preserveScroll: true,
            onSuccess: () => setShowApprouverDialog(false),
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

    /** Régulariser avec upload de fichiers */
    const regulariser = () => {
        regularisationForm.post(route('bons-caisse.regulariser', bonCaisse.id), {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    /** Pré-régulariser : uploader les justificatifs avant paiement */
    const preRegulariser = () => {
        preRegularisationForm.post(route('bons-caisse.pre-regulariser', bonCaisse.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setFichiersPreRegul([]),
        });
    };

    const estDemandeur = estProprietaire;
    const estCaissier = rolesUtilisateurActuels.includes('caissier');
    const peutArchiver = rolesUtilisateurActuels.some(r => ['daf', 'directeur_pays', 'caissier', 'administrateur'].includes(r));
    const historique = bonCaisse.historique_actions || [];

    return (
        <AuthenticatedLayout header={`Bon ${bonCaisse.numero}`}>
            <Head title={`Bon ${bonCaisse.numero}`} />

            {/* Retour */}
            <div className="mb-6">
                <Link
                    href={route('bons-caisse.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour à la liste
                </Link>
            </div>

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

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Colonne principale */}
                <div className="lg:col-span-2 space-y-6">
                    {/* En-tête du bon */}
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                    >
                        <Card className={`overflow-hidden ${
                            bonCaisse.type_bon === 'BP'
                                ? 'border-l-4 border-l-orange-400'
                                : 'border-l-4 border-l-blue-400'
                        }`}>
                            <CardHeader>
                                <div className="flex items-start justify-between">
                                    <div>
                                        <CardTitle className="text-xl flex items-center gap-2">
                                            <FileText className={`h-5 w-5 ${
                                                bonCaisse.type_bon === 'BP' ? 'text-orange-500' : 'text-blue-500'
                                            }`} />
                                            {bonCaisse.numero}
                                            <Badge className={`ml-2 text-xs ${
                                                bonCaisse.type_bon === 'BP'
                                                    ? 'bg-orange-100 text-orange-700 border-orange-200'
                                                    : 'bg-blue-100 text-blue-700 border-blue-200'
                                            }`}>
                                                {bonCaisse.type_bon === 'BD' ? 'Bon Définitif' : 'Bon Provisoire'}
                                            </Badge>
                                        </CardTitle>
                                        <CardDescription className="mt-1">
                                            Créé le {formatDate(bonCaisse.date_demande)}
                                            {bonCaisse.date_soumission && ` · Soumis le ${formatDateTime(bonCaisse.date_soumission)}`}
                                        </CardDescription>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {bonCaisse.niveau_urgence && bonCaisse.niveau_urgence !== 'normale' && (
                                            <Badge
                                                className={`text-sm px-3 py-1 ${
                                                    bonCaisse.niveau_urgence === 'tres_urgente'
                                                        ? 'bg-red-100 text-red-700 border-red-300'
                                                        : 'bg-orange-100 text-orange-700 border-orange-300'
                                                }`}
                                            >
                                                {bonCaisse.niveau_urgence === 'tres_urgente' ? '🔴 Très urgent' : '🟠 Urgent'}
                                            </Badge>
                                        )}
                                        <Badge
                                            variant={badgeVariantParStatut(bonCaisse.statut)}
                                            className="text-sm px-3 py-1"
                                        >
                                            {statutsLabels[bonCaisse.statut] || bonCaisse.statut}
                                        </Badge>
                                    </div>
                                </div>

                                {/* Barre de délai de régularisation pour les BP */}
                                {bonCaisse.type_bon === 'BP' && bonCaisse.date_limite_regularisation && (
                                    (() => {
                                        const maintenant = new Date();
                                        const limite = new Date(bonCaisse.date_limite_regularisation);
                                        const paiement = bonCaisse.date_paiement ? new Date(bonCaisse.date_paiement) : maintenant;
                                        const totalMs = limite.getTime() - paiement.getTime();
                                        const resteMs = limite.getTime() - maintenant.getTime();
                                        const joursRestants = Math.ceil(resteMs / (1000 * 60 * 60 * 24));
                                        const progression = totalMs > 0 ? Math.max(0, Math.min(100, ((totalMs - resteMs) / totalMs) * 100)) : 100;
                                        const enRetard = joursRestants < 0;
                                        const critique = joursRestants <= 1 && joursRestants >= 0;

                                        return (
                                            <div className={`mt-3 p-3 rounded-lg border ${
                                                enRetard
                                                    ? 'bg-red-50 border-red-200'
                                                    : critique
                                                        ? 'bg-amber-50 border-amber-200'
                                                        : 'bg-green-50 border-green-200'
                                            }`}>
                                                <div className="flex items-center justify-between mb-1.5">
                                                    <span className={`text-xs font-medium flex items-center gap-1 ${
                                                        enRetard ? 'text-red-700' : critique ? 'text-amber-700' : 'text-green-700'
                                                    }`}>
                                                        <Timer className="h-3.5 w-3.5" />
                                                        {enRetard
                                                            ? `Régularisation en retard de ${Math.abs(joursRestants)} jour(s)`
                                                            : joursRestants === 0
                                                                ? 'Dernier jour pour régulariser'
                                                                : `${joursRestants} jour(s) restant(s) pour régulariser`
                                                        }
                                                    </span>
                                                    <span className="text-[10px] text-gray-500">
                                                        Limite : {formatDate(bonCaisse.date_limite_regularisation)}
                                                    </span>
                                                </div>
                                                <div className="w-full bg-gray-200 rounded-full h-1.5">
                                                    <div
                                                        className={`h-1.5 rounded-full transition-all duration-500 ${
                                                            enRetard ? 'bg-red-500' : critique ? 'bg-amber-500' : 'bg-green-500'
                                                        }`}
                                                        style={{ width: `${Math.min(progression, 100)}%` }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })()
                                )}

                                {/* Timer temps écoulé depuis la création pour les BP en cours */}
                                {estBPEnCours && tempsEcoule && (
                                    (() => {
                                        const debut = new Date(bonCaisse.created_at);
                                        const diffMs = Date.now() - debut.getTime();
                                        const joursEcoules = diffMs / (1000 * 60 * 60 * 24);
                                        const estAlerte = joursEcoules >= 7;
                                        const estAttention = joursEcoules >= 3;

                                        return (
                                            <div className={`mt-3 p-3 rounded-lg border flex items-center justify-between ${
                                                estAlerte
                                                    ? 'bg-red-50 border-red-200'
                                                    : estAttention
                                                        ? 'bg-orange-50 border-orange-200'
                                                        : 'bg-blue-50 border-blue-200'
                                            }`}>
                                                <div className="flex items-center gap-2">
                                                    <Activity className={`h-4 w-4 ${
                                                        estAlerte ? 'text-red-600 animate-pulse' : estAttention ? 'text-orange-600' : 'text-blue-600'
                                                    }`} />
                                                    <span className={`text-xs font-medium ${
                                                        estAlerte ? 'text-red-700' : estAttention ? 'text-orange-700' : 'text-blue-700'
                                                    }`}>
                                                        Temps écoulé depuis la création
                                                    </span>
                                                </div>
                                                <span className={`text-sm font-bold font-mono ${
                                                    estAlerte ? 'text-red-700' : estAttention ? 'text-orange-700' : 'text-blue-700'
                                                }`}>
                                                    {tempsEcoule}
                                                </span>
                                            </div>
                                        );
                                    })()
                                )}

                                {/* Indicateur pré-régularisé */}
                                {bonCaisse.type_bon === 'BP' && aDesPiecesRegularisation && !['REGULARISE', 'ARCHIVE'].includes(bonCaisse.statut) && (
                                    <div className="mt-3 p-2.5 rounded-lg bg-emerald-50 border border-emerald-200 flex items-center gap-2">
                                        <CheckCircle2 className="h-4 w-4 text-emerald-600 flex-shrink-0" />
                                        <span className="text-xs text-emerald-700 font-medium">
                                            ✅ Justificatifs de régularisation déjà fournis — la régularisation sera automatique au paiement
                                        </span>
                                    </div>
                                )}
                            </CardHeader>
                        </Card>
                    </motion.div>

                    {/* Onglets : Détails / Validations / Pièces jointes / Historique */}
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.1 }}
                    >
                        <Tabs defaultValue="details">
                            <TabsList>
                                <TabsTrigger value="details">Détails</TabsTrigger>
                                <TabsTrigger value="validations">
                                    Validations ({bonCaisse.validations?.length || 0})
                                </TabsTrigger>
                                <TabsTrigger value="pieces">
                                    Pièces ({bonCaisse.pieces_jointes?.length || 0})
                                </TabsTrigger>
                                <TabsTrigger value="historique">
                                    <History className="h-3.5 w-3.5 mr-1" />
                                    Historique ({historique.length})
                                </TabsTrigger>
                            </TabsList>

                            {/* Onglet Détails */}
                            <TabsContent value="details">
                                <Card>
                                    <CardContent className="p-6 space-y-4">
                                        {/* Identification */}
                                        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Identification</p>
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
                                            {bonCaisse.code_analytique && (
                                                <div className="flex items-start gap-3">
                                                    <Hash className="h-5 w-5 text-gray-400 mt-0.5" />
                                                    <div>
                                                        <p className="text-xs text-gray-500">Code analytique</p>
                                                        <p className="font-medium">{bonCaisse.code_analytique}</p>
                                                    </div>
                                                </div>
                                            )}
                                            <div className="flex items-start gap-3">
                                                <Calendar className="h-5 w-5 text-gray-400 mt-0.5" />
                                                <div>
                                                    <p className="text-xs text-gray-500">Date de demande</p>
                                                    <p className="font-medium">{formatDate(bonCaisse.date_demande)}</p>
                                                </div>
                                            </div>
                                        </div>

                                        <Separator />

                                        {/* Bénéficiaire */}
                                        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Bénéficiaire</p>
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div className="flex items-start gap-3">
                                                <User className="h-5 w-5 text-gray-400 mt-0.5" />
                                                <div>
                                                    <p className="text-xs text-gray-500">Nom</p>
                                                    <p className="font-medium">{bonCaisse.beneficiaire}</p>
                                                </div>
                                            </div>
                                            <div className="flex items-start gap-3">
                                                <Tag className="h-5 w-5 text-gray-400 mt-0.5" />
                                                <div>
                                                    <p className="text-xs text-gray-500">Type</p>
                                                    <p className="font-medium">
                                                        {typesBeneficiaire[bonCaisse.type_beneficiaire] || bonCaisse.type_beneficiaire || '-'}
                                                    </p>
                                                </div>
                                            </div>
                                            {bonCaisse.telephone_beneficiaire && (
                                                <div className="flex items-start gap-3">
                                                    <Phone className="h-5 w-5 text-gray-400 mt-0.5" />
                                                    <div>
                                                        <p className="text-xs text-gray-500">Téléphone</p>
                                                        <p className="font-medium">{bonCaisse.telephone_beneficiaire}</p>
                                                    </div>
                                                </div>
                                            )}
                                            <div className="flex items-start gap-3">
                                                <CreditCard className="h-5 w-5 text-gray-400 mt-0.5" />
                                                <div>
                                                    <p className="text-xs text-gray-500">Mode paiement souhaité</p>
                                                    <p className="font-medium">
                                                        {modesPaiement[bonCaisse.mode_paiement] || bonCaisse.mode_paiement || '-'}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <Separator />

                                        {/* Dépense */}
                                        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Détails de la dépense</p>
                                        <div>
                                            <p className="text-xs text-gray-500 mb-1">Motif</p>
                                            <p className="text-sm bg-gray-50 rounded-lg p-3">{bonCaisse.motif}</p>
                                        </div>
                                        {bonCaisse.categorie_depense && (
                                            <div className="flex items-start gap-3">
                                                <Tag className="h-5 w-5 text-gray-400 mt-0.5" />
                                                <div>
                                                    <p className="text-xs text-gray-500">Catégorie</p>
                                                    <p className="font-medium">
                                                        {categoriesDepense[bonCaisse.categorie_depense] || bonCaisse.categorie_depense}
                                                    </p>
                                                </div>
                                            </div>
                                        )}
                                        {bonCaisse.montant_lettres && (
                                            <div>
                                                <p className="text-xs text-gray-500 mb-1">Montant en lettres</p>
                                                <p className="text-sm italic">{bonCaisse.montant_lettres}</p>
                                            </div>
                                        )}

                                        {/* Justification urgence (Phase 1.1) */}
                                        {bonCaisse.niveau_urgence && bonCaisse.niveau_urgence !== 'normale' && (bonCaisse.motif_urgence || bonCaisse.justification_urgence) && (
                                            <>
                                                <Separator />
                                                <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Justification de l'urgence</p>
                                                <div className="p-3 rounded-lg border border-orange-200 bg-orange-50/50 space-y-2">
                                                    {bonCaisse.motif_urgence && (
                                                        <div>
                                                            <p className="text-xs text-orange-600 font-medium">Motif</p>
                                                            <p className="text-sm">{bonCaisse.motif_urgence}</p>
                                                        </div>
                                                    )}
                                                    {bonCaisse.justification_urgence && (
                                                        <div>
                                                            <p className="text-xs text-orange-600 font-medium">Justification</p>
                                                            <p className="text-sm">{bonCaisse.justification_urgence}</p>
                                                        </div>
                                                    )}
                                                </div>
                                            </>
                                        )}

                                        {/* Ventilations analytiques (Phase 4.2) */}
                                        {bonCaisse.ventilations && bonCaisse.ventilations.length > 0 && (
                                            <>
                                                <Separator />
                                                <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider flex items-center gap-1">
                                                    <GitBranch className="h-3.5 w-3.5" />
                                                    Ventilation analytique
                                                </p>
                                                <div className="space-y-1.5">
                                                    {bonCaisse.ventilations.map((v, i) => (
                                                        <div key={i} className="flex items-center justify-between p-2 rounded border bg-gray-50 text-sm">
                                                            <span className="font-mono text-xs text-gray-500">{v.code_analytique}</span>
                                                            <span className="font-medium">
                                                                {new Intl.NumberFormat('fr-FR').format(v.montant)} GNF
                                                                {v.pourcentage && <span className="text-xs text-gray-400 ml-1">({v.pourcentage}%)</span>}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </>
                                        )}

                                        {/* Commentaire de rejet */}
                                        {bonCaisse.commentaire_rejet && (
                                            <div className="p-3 rounded-lg bg-red-50 border border-red-200">
                                                <p className="text-xs text-red-600 font-medium mb-1">Motif du rejet</p>
                                                <p className="text-sm text-red-700">{bonCaisse.commentaire_rejet}</p>
                                            </div>
                                        )}

                                        {/* Informations de paiement */}
                                        {bonCaisse.date_paiement && (
                                            <>
                                                <Separator />
                                                <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Paiement</p>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                    <div className="flex items-start gap-3">
                                                        <Calendar className="h-5 w-5 text-gray-400 mt-0.5" />
                                                        <div>
                                                            <p className="text-xs text-gray-500">Date de paiement</p>
                                                            <p className="font-medium">{formatDateTime(bonCaisse.date_paiement)}</p>
                                                        </div>
                                                    </div>
                                                    {bonCaisse.caissier && (
                                                        <div className="flex items-start gap-3">
                                                            <User className="h-5 w-5 text-gray-400 mt-0.5" />
                                                            <div>
                                                                <p className="text-xs text-gray-500">Caissier</p>
                                                                <p className="font-medium">
                                                                    {bonCaisse.caissier.prenom
                                                                        ? `${bonCaisse.caissier.prenom} ${bonCaisse.caissier.name}`
                                                                        : bonCaisse.caissier.name}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    )}
                                                    {bonCaisse.mode_paiement_effectif && (
                                                        <div className="flex items-start gap-3">
                                                            <CreditCard className="h-5 w-5 text-gray-400 mt-0.5" />
                                                            <div>
                                                                <p className="text-xs text-gray-500">Mode effectif</p>
                                                                <p className="font-medium">
                                                                    {modesPaiement[bonCaisse.mode_paiement_effectif] || bonCaisse.mode_paiement_effectif}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    )}
                                                    {bonCaisse.date_limite_regularisation && (
                                                        <div className="flex items-start gap-3">
                                                            <AlertTriangle className="h-5 w-5 text-amber-400 mt-0.5" />
                                                            <div>
                                                                <p className="text-xs text-gray-500">Limite régularisation</p>
                                                                <p className="font-medium">{formatDate(bonCaisse.date_limite_regularisation)}</p>
                                                            </div>
                                                        </div>
                                                    )}
                                                    {bonCaisse.motif_regularisation && (
                                                        <div className="flex items-start gap-3 col-span-2">
                                                            <ClipboardCheck className="h-5 w-5 text-teal-500 mt-0.5" />
                                                            <div>
                                                                <p className="text-xs text-gray-500">Motif de régularisation</p>
                                                                <p className="font-medium text-teal-700">{bonCaisse.motif_regularisation}</p>
                                                            </div>
                                                        </div>
                                                    )}
                                                </div>
                                            </>
                                        )}

                                        {/* Ordre de mission */}
                                        {bonCaisse.ordre_mission && (
                                            <>
                                                <Separator />
                                                <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider">Ordre de Mission</p>
                                                <div className="grid grid-cols-2 gap-3 text-sm">
                                                    <div>
                                                        <p className="text-gray-500">Référence</p>
                                                        <p>{bonCaisse.ordre_mission.reference}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-gray-500">Destination</p>
                                                        <p>{bonCaisse.ordre_mission.destination}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-gray-500">Départ</p>
                                                        <p>{formatDate(bonCaisse.ordre_mission.date_depart)}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-gray-500">Retour</p>
                                                        <p>{formatDate(bonCaisse.ordre_mission.date_retour)}</p>
                                                    </div>
                                                </div>
                                            </>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* Onglet Validations */}
                            <TabsContent value="validations">
                                <Card>
                                    <CardContent className="p-6">
                                        {(!bonCaisse.validations || bonCaisse.validations.length === 0) ? (
                                            <p className="text-center text-gray-400 py-8">
                                                Aucune étape de validation enregistrée.
                                                {bonCaisse.statut === 'BROUILLON' && ' Soumettez le bon pour démarrer le workflow.'}
                                            </p>
                                        ) : (
                                            <div className="space-y-4">
                                                {bonCaisse.validations.map((validation, index) => (
                                                    <div key={validation.id} className="flex gap-4">
                                                        {/* Icône de statut */}
                                                        <div className="flex flex-col items-center">
                                                            {validation.statut === 'approuve' ? (
                                                                <CheckCircle2 className="h-8 w-8 text-green-500" />
                                                            ) : validation.statut === 'rejete' ? (
                                                                <XCircle className="h-8 w-8 text-red-500" />
                                                            ) : (
                                                                <Clock className="h-8 w-8 text-neemba-400" />
                                                            )}
                                                            {index < bonCaisse.validations.length - 1 && (
                                                                <div className="w-0.5 h-full bg-gray-200 mt-1" />
                                                            )}
                                                        </div>

                                                        {/* Détails */}
                                                        <div className="flex-1 pb-4">
                                                            <div className="flex items-center justify-between">
                                                                <p className="font-medium text-sm">
                                                                    Niveau {validation.niveau} - {rolesValidation[validation.role] || validation.role}
                                                                </p>
                                                                <Badge
                                                                    variant={
                                                                        validation.statut === 'approuve' ? 'approuve'
                                                                        : validation.statut === 'rejete' ? 'rejete'
                                                                        : 'en_attente'
                                                                    }
                                                                    className="text-[10px]"
                                                                >
                                                                    {validation.statut === 'approuve' ? 'Approuvé'
                                                                        : validation.statut === 'rejete' ? 'Rejeté'
                                                                        : 'En attente'}
                                                                </Badge>
                                                            </div>
                                                            {validation.validateur && (
                                                                <p className="text-xs text-gray-500 mt-1">
                                                                    Par {validation.validateur.prenom
                                                                        ? `${validation.validateur.prenom} ${validation.validateur.name}`
                                                                        : validation.validateur.name}
                                                                    {validation.date_validation &&
                                                                        ` le ${formatDateTime(validation.date_validation)}`}
                                                                </p>
                                                            )}
                                                            {validation.commentaire && (
                                                                <p className="text-sm mt-1 text-gray-600 bg-gray-50 rounded p-2">
                                                                    {validation.commentaire}
                                                                </p>
                                                            )}
                                                            {/* Délai de traitement (Phase 1.3) */}
                                                            {delaisValidation[validation.id] && (
                                                                <p className="text-[10px] text-gray-400 mt-1 flex items-center gap-1">
                                                                    <Timer className="h-3 w-3" />
                                                                    Délai : {delaisValidation[validation.id]}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        {/* Délai total de traitement — visible dès qu'il y a au moins une étape de validation */}
                                        {bonCaisse.validations?.length > 0 && (() => {
                                            // Utiliser la valeur backend si disponible, sinon calculer depuis created_at
                                            const delaiAffiche = bonCaisse.delai_traitement || (() => {
                                                if (!bonCaisse.created_at) return null;
                                                const diffMs  = Date.now() - new Date(bonCaisse.created_at).getTime();
                                                const diffMin = Math.floor(diffMs / 60000);
                                                const diffH   = Math.floor(diffMin / 60);
                                                const diffJ   = Math.floor(diffH / 24);
                                                if (diffJ >= 1) return `${diffJ}j (en cours)`;
                                                if (diffH >= 1) return `${diffH}h (en cours)`;
                                                return `${diffMin}min (en cours)`;
                                            })();
                                            if (!delaiAffiche) return null;
                                            return (
                                                <div className="mt-4 p-3 rounded-lg bg-blue-50 border border-blue-200 flex items-center gap-2">
                                                    <Timer className="h-4 w-4 text-blue-500" />
                                                    <span className="text-sm text-blue-700">
                                                        Délai total de traitement : <span className="font-semibold">{delaiAffiche}</span>
                                                    </span>
                                                </div>
                                            );
                                        })()}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* Onglet Pièces jointes */}
                            <TabsContent value="pieces">
                                <Card>
                                    <CardContent className="p-6">
                                        {(!bonCaisse.pieces_jointes || bonCaisse.pieces_jointes.length === 0) ? (
                                            <div className="text-center py-8 text-gray-400">
                                                <Paperclip className="h-10 w-10 mx-auto mb-2 opacity-50" />
                                                <p>Aucune pièce jointe</p>
                                            </div>
                                        ) : (
                                            <div className="space-y-3">
                                                {bonCaisse.pieces_jointes.map((piece) => {
                                                    const urlPiece = `/storage/${piece.chemin_fichier}`;
                                                    const isImage = piece.mime_type?.startsWith('image/');
                                                    const isPdf = piece.mime_type?.includes('pdf');
                                                    const isPreviewOpen = previewPieceId === piece.id;
                                                    return (
                                                        <div
                                                            key={piece.id}
                                                            className="border rounded-lg overflow-hidden"
                                                        >
                                                            <div className="flex items-center justify-between p-3">
                                                                <div className="flex items-center gap-3 min-w-0">
                                                                    <Paperclip className="h-4 w-4 text-gray-400 flex-shrink-0" />
                                                                    <div className="min-w-0">
                                                                        <p className="text-sm font-medium truncate">{piece.nom_fichier}</p>
                                                                        <p className="text-xs text-gray-500">
                                                                            {piece.type_document} · {piece.taille ? `${(piece.taille / 1024).toFixed(1)} Ko` : ''}
                                                                            {piece.ocr_statut === 'en_cours' && (
                                                                                <span className="ml-2 text-neemba-600">· Analyse en cours...</span>
                                                                            )}
                                                                            {piece.ocr_statut === 'termine' && piece.ocr_data && (
                                                                                <span className="ml-2 text-green-600">· Analysé</span>
                                                                            )}
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                                <div className="flex items-center gap-1 flex-shrink-0">
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        title={isPreviewOpen ? 'Masquer l\'aperçu' : 'Prévisualiser'}
                                                                        onClick={() => setPreviewPieceId(isPreviewOpen ? null : piece.id)}
                                                                    >
                                                                        <Eye className={`h-4 w-4 ${isPreviewOpen ? 'text-neemba-600' : ''}`} />
                                                                    </Button>
                                                                    <a
                                                                        href={urlPiece}
                                                                        download
                                                                        title="Télécharger"
                                                                    >
                                                                        <Button variant="ghost" size="icon">
                                                                            <Download className="h-4 w-4" />
                                                                        </Button>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                            {/* Prévisualisation inline */}
                                                            <AnimatePresence>
                                                                {isPreviewOpen && (
                                                                    <motion.div
                                                                        initial={{ height: 0, opacity: 0 }}
                                                                        animate={{ height: 'auto', opacity: 1 }}
                                                                        exit={{ height: 0, opacity: 0 }}
                                                                        transition={{ duration: 0.2 }}
                                                                        className="overflow-hidden"
                                                                    >
                                                                        <div className="bg-gray-50 border-t flex items-center justify-center" style={{ minHeight: '300px' }}>
                                                                            {isImage ? (
                                                                                <div className="p-4 w-full flex items-center justify-center">
                                                                                    <img src={urlPiece} alt={piece.nom_fichier} className="max-h-[400px] max-w-full object-contain rounded-lg shadow-sm border" />
                                                                                </div>
                                                                            ) : isPdf ? (
                                                                                <embed src={urlPiece + '#toolbar=1&navpanes=0'} type="application/pdf" className="w-full" style={{ height: '500px' }} />
                                                                            ) : (
                                                                                <div className="text-center text-gray-400 py-8">
                                                                                    <FileText className="h-12 w-12 mx-auto mb-2 text-gray-300" />
                                                                                    <p className="text-sm mb-2">Aperçu non disponible pour ce type de fichier</p>
                                                                                    <a href={urlPiece} target="_blank" rel="noopener noreferrer">
                                                                                        <Button variant="outline" size="sm">
                                                                                            <Eye className="h-3.5 w-3.5 mr-1.5" />
                                                                                            Ouvrir dans un nouvel onglet
                                                                                        </Button>
                                                                                    </a>
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    </motion.div>
                                                                )}
                                                            </AnimatePresence>
                                                            {/* Données OCR extraites */}
                                                            {piece.ocr_statut === 'termine' && piece.ocr_data && Object.keys(piece.ocr_data).length > 0 && (
                                                                <div className="px-3 pb-3 pt-0">
                                                                    <div className="p-2.5 rounded bg-neemba-50/60 border border-neemba-100">
                                                                        <p className="text-[10px] uppercase tracking-wider text-neemba-600 font-semibold mb-1.5">Informations extraites</p>
                                                                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-xs text-gray-700">
                                                                            {piece.ocr_data.fournisseur && (
                                                                                <p><span className="font-medium text-gray-500">Fournisseur :</span> {piece.ocr_data.fournisseur}</p>
                                                                            )}
                                                                            {piece.ocr_data.montant && (
                                                                                <p><span className="font-medium text-gray-500">Montant :</span> {new Intl.NumberFormat('fr-FR').format(piece.ocr_data.montant)} {piece.ocr_data.devise || 'GNF'}</p>
                                                                            )}
                                                                            {piece.ocr_data.date_document && (
                                                                                <p><span className="font-medium text-gray-500">Date :</span> {piece.ocr_data.date_document}</p>
                                                                            )}
                                                                            {piece.ocr_data.reference_document && (
                                                                                <p><span className="font-medium text-gray-500">Réf :</span> {piece.ocr_data.reference_document}</p>
                                                                            )}
                                                                            {piece.ocr_data.description && (
                                                                                <p className="col-span-2 sm:col-span-3"><span className="font-medium text-gray-500">Description :</span> {piece.ocr_data.description}</p>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* Onglet Historique */}
                            <TabsContent value="historique">
                                <Card>
                                    <CardContent className="p-6">
                                        {historique.length === 0 ? (
                                            <div className="text-center py-8 text-gray-400">
                                                <History className="h-10 w-10 mx-auto mb-2 opacity-50" />
                                                <p>Aucun événement enregistré</p>
                                            </div>
                                        ) : (
                                            <div className="space-y-0">
                                                {historique.map((action, index) => {
                                                    const IconAction = iconeAction[action.action] || Activity;
                                                    const couleur = couleurAction[action.action] || 'text-gray-400';
                                                    return (
                                                        <div key={action.id} className="flex gap-4">
                                                            {/* Timeline */}
                                                            <div className="flex flex-col items-center">
                                                                <div className={`rounded-full p-1.5 bg-white border-2 ${couleur.replace('text-', 'border-')}`}>
                                                                    <IconAction className={`h-4 w-4 ${couleur}`} />
                                                                </div>
                                                                {index < historique.length - 1 && (
                                                                    <div className="w-0.5 flex-1 bg-gray-200 min-h-[24px]" />
                                                                )}
                                                            </div>

                                                            {/* Détails */}
                                                            <div className="flex-1 pb-4 min-w-0">
                                                                <div className="flex items-center justify-between gap-2">
                                                                    <p className="font-medium text-sm truncate">
                                                                        {actionsLabels[action.action] || action.action}
                                                                    </p>
                                                                    <span className="text-[10px] text-gray-400 whitespace-nowrap">
                                                                        {formatDateTime(action.created_at)}
                                                                    </span>
                                                                </div>
                                                                {action.utilisateur && (
                                                                    <p className="text-xs text-gray-500">
                                                                        {action.utilisateur.prenom
                                                                            ? `${action.utilisateur.prenom} ${action.utilisateur.name}`
                                                                            : action.utilisateur.name}
                                                                    </p>
                                                                )}
                                                                {action.commentaire && (
                                                                    <p className="text-xs text-gray-600 mt-1 bg-gray-50 rounded p-2">
                                                                        {action.commentaire}
                                                                    </p>
                                                                )}
                                                                {action.statut_avant && action.statut_apres && action.statut_avant !== action.statut_apres && (
                                                                    <div className="flex items-center gap-1.5 mt-1">
                                                                        <Badge variant="outline" className="text-[9px] py-0">
                                                                            {statutsLabels[action.statut_avant] || action.statut_avant}
                                                                        </Badge>
                                                                        <span className="text-gray-300">→</span>
                                                                        <Badge variant="outline" className="text-[9px] py-0">
                                                                            {statutsLabels[action.statut_apres] || action.statut_apres}
                                                                        </Badge>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </TabsContent>
                        </Tabs>
                    </motion.div>
                </div>

                {/* Colonne latérale - Montant et actions */}
                <div className="space-y-6">
                    {/* Montant */}
                    <motion.div
                        initial={{ opacity: 0, x: 10 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.2 }}
                    >
                        <Card className={`overflow-hidden ${
                            bonCaisse.type_bon === 'BP'
                                ? 'border-t-4 border-t-orange-400'
                                : 'border-t-4 border-t-blue-400'
                        }`}>
                            <CardContent className="p-6 text-center">
                                <p className="text-sm text-gray-500 mb-1">Montant</p>
                                <p className={`text-3xl font-bold ${
                                    bonCaisse.type_bon === 'BP' ? 'text-orange-600' : 'text-blue-600'
                                }`}>
                                    {formatMontant(bonCaisse.montant)}
                                </p>
                                <Badge
                                    className={`mt-2 ${
                                        bonCaisse.type_bon === 'BD'
                                            ? 'bg-blue-100 text-blue-700 border-blue-200'
                                            : 'bg-orange-100 text-orange-700 border-orange-200'
                                    }`}
                                >
                                    {bonCaisse.type_bon === 'BD' ? '📘 Bon Définitif' : '📙 Bon Provisoire'}
                                </Badge>
                                {/* Montant en lettres */}
                                <p className="text-xs text-gray-500 mt-2 italic">
                                    {bonCaisse.montant_lettres || nombreEnLettres(bonCaisse.montant)}
                                </p>
                                {bonCaisse.categorie_depense && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        {categoriesDepense[bonCaisse.categorie_depense] || bonCaisse.categorie_depense}
                                    </p>
                                )}
                                {parseFloat(bonCaisse.montant) >= seuilDP && (
                                    <p className="text-xs text-neemba-600 mt-2">
                                        Validation Directeur Pays requise
                                    </p>
                                )}

                                {/* Alerte régularisation en retard */}
                                {bonCaisse.statut === 'EN_ATTENTE_REGULARISATION' && bonCaisse.date_limite_regularisation && (
                                    <div className={`mt-3 p-2 rounded text-xs ${
                                        new Date(bonCaisse.date_limite_regularisation) < new Date()
                                            ? 'bg-red-50 text-red-700 border border-red-200'
                                            : 'bg-amber-50 text-amber-700 border border-amber-200'
                                    }`}>
                                        <AlertTriangle className="h-3 w-3 inline mr-1" />
                                        {new Date(bonCaisse.date_limite_regularisation) < new Date()
                                            ? 'Régularisation en retard !'
                                            : `Limite : ${formatDate(bonCaisse.date_limite_regularisation)}`}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Solde caisse site (Phase 2.1) */}
                    {soldeCaisseSite && (
                        <motion.div
                            initial={{ opacity: 0, x: 10 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ delay: 0.25 }}
                        >
                            <Card className={soldeCaisseSite.sous_seuil ? 'border-red-200' : ''}>
                                <CardContent className="p-4">
                                    <div className="flex items-center gap-2 mb-2">
                                        <Wallet className="h-4 w-4 text-gray-400" />
                                        <p className="text-xs text-gray-500">Solde caisse — {bonCaisse.site}</p>
                                    </div>
                                    <p className={`text-lg font-bold ${soldeCaisseSite.sous_seuil ? 'text-red-600' : 'text-gray-900'}`}>
                                        {soldeCaisseSite.solde_format || new Intl.NumberFormat('fr-FR').format(soldeCaisseSite.solde) + ' GNF'}
                                    </p>
                                    {soldeCaisseSite.sous_seuil && (
                                        <p className="text-[10px] text-red-500 flex items-center gap-1 mt-1">
                                            <AlertTriangle className="h-3 w-3" />
                                            Sous le seuil minimum
                                        </p>
                                    )}
                                    {!soldeCaisseSite.peut_payer && bonCaisse.statut === 'APPROUVE' && (
                                        <p className="text-[10px] text-red-600 font-medium mt-1">
                                            Solde insuffisant pour ce paiement
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}

                    {/* Actions */}
                    <motion.div
                        initial={{ opacity: 0, x: 10 }}
                        animate={{ opacity: 1, x: 0 }}
                        transition={{ delay: 0.3 }}
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Actions</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {/* Soumettre un brouillon */}
                                {bonCaisse.statut === 'BROUILLON' && estDemandeur && (
                                    <>
                                        <Link href={route('bons-caisse.edit', bonCaisse.id)} className="block">
                                            <Button variant="outline" className="w-full">
                                                Modifier
                                            </Button>
                                        </Link>
                                        <Dialog open={showSoumettreDialog} onOpenChange={setShowSoumettreDialog}>
                                            <DialogTrigger asChild>
                                                <Button className="w-full">
                                                    <Send className="mr-2 h-4 w-4" />
                                                    Soumettre
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogHeader>
                                                    <DialogTitle>Soumettre le bon</DialogTitle>
                                                    <DialogDescription>Êtes-vous sûr de vouloir soumettre ce bon de caisse pour validation ?</DialogDescription>
                                                </DialogHeader>
                                                <DialogFooter>
                                                    <Button variant="outline" onClick={() => setShowSoumettreDialog(false)}>Annuler</Button>
                                                    <Button onClick={() => { setShowSoumettreDialog(false); executerAction('bons-caisse.soumettre'); }}>Confirmer</Button>
                                                </DialogFooter>
                                            </DialogContent>
                                        </Dialog>
                                    </>
                                )}

                                {/* Payer un bon approuvé (caissier) avec validation OTP */}
                                {bonCaisse.statut === 'APPROUVE' && estCaissier && (
                                    <>
                                        {!showPaiementForm ? (
                                            <Button
                                                className="w-full bg-green-600 hover:bg-green-700 text-white"
                                                onClick={() => setShowPaiementForm(true)}
                                            >
                                                <Banknote className="mr-2 h-4 w-4" />
                                                Effectuer le paiement
                                            </Button>
                                        ) : (
                                            <motion.div 
                                                initial={{ opacity: 0, y: 10 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                className="space-y-4 p-5 bg-gradient-to-br from-emerald-50 to-green-50 rounded-xl border-2 border-emerald-200 shadow-sm"
                                            >
                                                {/* Étape 1 : Envoyer le code OTP */}
                                                {!otpEnvoye && (
                                                    <motion.div 
                                                        initial={{ opacity: 0 }}
                                                        animate={{ opacity: 1 }}
                                                        className="space-y-4"
                                                    >
                                                        <div className="flex items-center gap-3">
                                                            <div className="flex items-center justify-center w-10 h-10 rounded-full bg-emerald-100">
                                                                <MessageSquare className="h-5 w-5 text-emerald-600" />
                                                            </div>
                                                            <div>
                                                                <p className="text-sm font-semibold text-gray-800">
                                                                    Étape 1 : Validation par SMS
                                                                </p>
                                                                <p className="text-xs text-gray-600">
                                                                    Code de sécurité envoyé au demandeur
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <div className="bg-white/60 backdrop-blur-sm rounded-lg p-4 border border-emerald-100">
                                                            <p className="text-sm text-gray-700 mb-3 flex items-center gap-2">
                                                                <Shield className="h-4 w-4 text-emerald-600" />
                                                                Un code à 6 chiffres sera envoyé par SMS au demandeur pour sécuriser cette transaction.
                                                            </p>
                                                            <Button
                                                                className="w-full bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-700 hover:to-green-700 text-white shadow-md"
                                                                onClick={genererOtp}
                                                                disabled={envoiEnCours}
                                                            >
                                                                {envoiEnCours ? (
                                                                    <>
                                                                        <div className="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent mr-2" />
                                                                        Envoi en cours...
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <Send className="mr-2 h-4 w-4" />
                                                                        Envoyer le code OTP
                                                                    </>
                                                                )}
                                                            </Button>
                                                        </div>
                                                    </motion.div>
                                                )}

                                                {/* Étape 2 : Saisir et vérifier le code OTP */}
                                                {otpEnvoye && !otpVerifie && (
                                                    <motion.div 
                                                        initial={{ opacity: 0, scale: 0.95 }}
                                                        animate={{ opacity: 1, scale: 1 }}
                                                        className="space-y-4"
                                                    >
                                                        <div className="flex items-center justify-between">
                                                            <div className="flex items-center gap-3">
                                                                <div className="flex items-center justify-center w-10 h-10 rounded-full bg-blue-100">
                                                                    <Lock className="h-5 w-5 text-blue-600" />
                                                                </div>
                                                                <div>
                                                                    <p className="text-sm font-semibold text-gray-800">
                                                                        Étape 2 : Saisir le code
                                                                    </p>
                                                                    <p className="text-xs text-gray-600">
                                                                        Code valide 5 minutes
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={genererOtp}
                                                                disabled={envoiEnCours}
                                                                className="text-xs text-blue-600 hover:text-blue-700 hover:bg-blue-50"
                                                            >
                                                                <RefreshCw className={`h-3 w-3 mr-1 ${envoiEnCours ? 'animate-spin' : ''}`} />
                                                                {envoiEnCours ? 'Envoi...' : 'Renvoyer'}
                                                            </Button>
                                                        </div>
                                                        <div className="bg-white rounded-lg p-4 border-2 border-blue-100 shadow-sm">
                                                            <Label className="text-xs font-medium text-gray-600 mb-2 block">
                                                                Code de validation
                                                            </Label>
                                                            <Input
                                                                type="text"
                                                                placeholder="• • • • • •"
                                                                value={codeOtp}
                                                                onChange={(e) => setCodeOtp(e.target.value.replace(/\D/g, '').slice(0, 6))}
                                                                maxLength={6}
                                                                className="text-center text-2xl font-bold tracking-[0.5em] h-14 border-2 border-blue-200 focus:border-blue-400 bg-blue-50/30"
                                                                autoFocus
                                                            />
                                                            <Button
                                                                className="w-full mt-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white shadow-md"
                                                                onClick={verifierOtp}
                                                                disabled={codeOtp.length !== 6 || verificationEnCours}
                                                            >
                                                                {verificationEnCours ? (
                                                                    <>
                                                                        <div className="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent mr-2" />
                                                                        Vérification...
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <CheckCircle2 className="mr-2 h-4 w-4" />
                                                                        Vérifier le code
                                                                    </>
                                                                )}
                                                            </Button>
                                                        </div>
                                                    </motion.div>
                                                )}

                                                {/* Étape 3 : Sélectionner le mode de paiement et confirmer */}
                                                {otpVerifie && (
                                                    <motion.div 
                                                        initial={{ opacity: 0, scale: 0.95 }}
                                                        animate={{ opacity: 1, scale: 1 }}
                                                        className="space-y-4"
                                                    >
                                                        <div className="bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg p-4 border-2 border-green-200">
                                                            <div className="flex items-center gap-3 mb-4">
                                                                <div className="flex items-center justify-center w-12 h-12 rounded-full bg-green-100">
                                                                    <CheckCircle2 className="h-6 w-6 text-green-600" />
                                                                </div>
                                                                <div>
                                                                    <p className="text-sm font-semibold text-green-800">
                                                                        Code vérifié avec succès !
                                                                    </p>
                                                                    <p className="text-xs text-green-600">
                                                                        Vous pouvez maintenant confirmer le paiement
                                                                    </p>
                                                                </div>
                                                            </div>
                                                            <div className="space-y-3">
                                                                <div>
                                                                    <Label className="text-sm font-medium text-gray-700 mb-2 block">
                                                                        Mode de paiement effectif
                                                                    </Label>
                                                                    <Select value={modePaiement} onValueChange={setModePaiement}>
                                                                        <SelectTrigger className="bg-white border-2 border-green-200">
                                                                            <SelectValue />
                                                                        </SelectTrigger>
                                                                        <SelectContent>
                                                                            {Object.entries(modesPaiement).map(([key, label]) => (
                                                                                <SelectItem key={key} value={key}>{label}</SelectItem>
                                                                            ))}
                                                                        </SelectContent>
                                                                    </Select>
                                                                </div>
                                                                <div className="flex flex-col gap-2 pt-2">
                                                                    <Button
                                                                        className="w-full bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white shadow-lg text-sm"
                                                                        onClick={effectuerPaiement}
                                                                    >
                                                                        <Banknote className="mr-2 h-4 w-4 flex-shrink-0" />
                                                                        Confirmer le paiement
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        className="w-full border-2 hover:bg-gray-50"
                                                                        onClick={() => {
                                                                            setShowPaiementForm(false);
                                                                            setOtpEnvoye(false);
                                                                            setOtpVerifie(false);
                                                                            setCodeOtp('');
                                                                        }}
                                                                    >
                                                                        <X className="mr-2 h-4 w-4" />
                                                                        Annuler
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </motion.div>
                                                )}
                                            </motion.div>
                                        )}
                                    </>
                                )}

                                {/* Régulariser un BP (statut EN_ATTENTE_REGULARISATION) */}
                                {bonCaisse.statut === 'EN_ATTENTE_REGULARISATION' && estDemandeur && (
                                    <div className="space-y-3">
                                        <div>
                                            <Label htmlFor="motif_regularisation" className="text-sm font-medium">
                                                Motif de régularisation <span className="text-red-500">*</span>
                                            </Label>
                                            <Textarea
                                                id="motif_regularisation"
                                                placeholder="Décrivez le motif de régularisation (ex: achat effectué, justificatifs obtenus, etc.)"
                                                value={regularisationForm.data.motif_regularisation}
                                                onChange={(e) => regularisationForm.setData('motif_regularisation', e.target.value)}
                                                rows={3}
                                                className="mt-1"
                                            />
                                            {regularisationForm.errors.motif_regularisation && (
                                                <p className="text-xs text-red-500 mt-1">{regularisationForm.errors.motif_regularisation}</p>
                                            )}
                                        </div>
                                        <div className="border-2 border-dashed rounded-lg p-4 text-center">
                                            <Upload className="h-6 w-6 mx-auto text-gray-400 mb-1" />
                                            <Label
                                                htmlFor="regul_pieces"
                                                className="cursor-pointer text-neemba-600 hover:text-neemba-700 font-medium text-sm"
                                            >
                                                Ajouter les justificatifs
                                            </Label>
                                            <input
                                                id="regul_pieces"
                                                type="file"
                                                multiple
                                                className="hidden"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                onChange={(e) => {
                                                    const files = Array.from(e.target.files);
                                                    setFichiersRegul(files);
                                                    regularisationForm.setData('pieces_jointes', files);
                                                }}
                                            />
                                            <p className="text-[10px] text-gray-400 mt-1">PDF, JPG, PNG</p>
                                        </div>
                                        {fichiersRegul.length > 0 && (
                                            <div className="space-y-1">
                                                {fichiersRegul.map((f, i) => (
                                                    <p key={i} className="text-xs text-gray-600 truncate">
                                                        {f.name}
                                                    </p>
                                                ))}
                                            </div>
                                        )}
                                        <Dialog open={showRegulariserDialog} onOpenChange={setShowRegulariserDialog}>
                                            <DialogTrigger asChild>
                                                <Button className="w-full" disabled={regularisationForm.processing || fichiersRegul.length === 0 || regularisationForm.data.motif_regularisation.trim().length < 5}>
                                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                                    Régulariser
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogHeader>
                                                    <DialogTitle>Régulariser le bon</DialogTitle>
                                                    <DialogDescription>Confirmez-vous la soumission de ces justificatifs pour régulariser le bon de caisse ?</DialogDescription>
                                                </DialogHeader>
                                                <div className="py-2">
                                                    <p className="text-sm text-gray-600"><span className="font-medium">Motif :</span> {regularisationForm.data.motif_regularisation}</p>
                                                </div>
                                                <DialogFooter>
                                                    <Button variant="outline" onClick={() => setShowRegulariserDialog(false)}>Annuler</Button>
                                                    <Button onClick={() => { setShowRegulariserDialog(false); regulariser(); }}>Confirmer</Button>
                                                </DialogFooter>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                )}

                                {/* Régularisation : uploader les justificatifs + motif avant paiement */}
                                {peutPreRegulariser && !aDesPiecesRegularisation && (
                                    <div className="space-y-3 p-3 bg-gradient-to-br from-orange-50 to-amber-50 rounded-lg border border-orange-200">
                                        <p className="text-sm font-semibold text-orange-800 flex items-center gap-2">
                                            <Upload className="h-4 w-4" />
                                            Régulariser ce bon provisoire
                                        </p>
                                        <p className="text-xs text-orange-600">
                                            Fournissez vos justificatifs et le motif de régularisation.
                                        </p>
                                        <div className="space-y-1">
                                            <Label htmlFor="pre_regul_motif" className="text-xs font-medium text-orange-700">
                                                Motif de régularisation <span className="text-red-500">*</span>
                                            </Label>
                                            <Textarea
                                                id="pre_regul_motif"
                                                placeholder="Décrivez le motif de régularisation (min. 5 caractères)..."
                                                value={preRegularisationForm.data.motif_regularisation}
                                                onChange={(e) => preRegularisationForm.setData('motif_regularisation', e.target.value)}
                                                className="text-sm min-h-[60px] border-orange-200 focus:border-orange-400"
                                            />
                                            {preRegularisationForm.errors.motif_regularisation && (
                                                <p className="text-xs text-red-500">{preRegularisationForm.errors.motif_regularisation}</p>
                                            )}
                                        </div>
                                        <div className="border-2 border-dashed border-orange-200 rounded-lg p-3 text-center bg-white/60">
                                            <Upload className="h-5 w-5 mx-auto text-orange-400 mb-1" />
                                            <Label
                                                htmlFor="pre_regul_pieces"
                                                className="cursor-pointer text-orange-600 hover:text-orange-700 font-medium text-sm"
                                            >
                                                Ajouter les justificatifs
                                            </Label>
                                            <input
                                                id="pre_regul_pieces"
                                                type="file"
                                                multiple
                                                className="hidden"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                onChange={(e) => {
                                                    const files = Array.from(e.target.files);
                                                    setFichiersPreRegul(files);
                                                    preRegularisationForm.setData('pieces_jointes', files);
                                                }}
                                            />
                                            <p className="text-[10px] text-gray-400 mt-1">PDF, JPG, PNG</p>
                                        </div>
                                        {fichiersPreRegul.length > 0 && (
                                            <div className="space-y-1">
                                                {fichiersPreRegul.map((f, i) => (
                                                    <p key={i} className="text-xs text-gray-600 truncate flex items-center gap-1">
                                                        <Paperclip className="h-3 w-3" /> {f.name}
                                                    </p>
                                                ))}
                                            </div>
                                        )}
                                        <Button
                                            className="w-full bg-orange-600 hover:bg-orange-700 text-white"
                                            disabled={preRegularisationForm.processing || fichiersPreRegul.length === 0 || preRegularisationForm.data.motif_regularisation.length < 5}
                                            onClick={preRegulariser}
                                        >
                                            <CheckCircle2 className="mr-2 h-4 w-4" />
                                            Régulariser
                                        </Button>
                                    </div>
                                )}

                                {/* Indicateur déjà pré-régularisé */}
                                {peutPreRegulariser && aDesPiecesRegularisation && (
                                    <div className="p-3 bg-emerald-50 rounded-lg border border-emerald-200 text-center">
                                        <CheckCircle2 className="h-5 w-5 mx-auto text-emerald-600 mb-1" />
                                        <p className="text-xs font-medium text-emerald-700">
                                            Justificatifs de régularisation déjà fournis
                                        </p>
                                        <p className="text-[10px] text-emerald-600 mt-0.5">
                                            La régularisation sera automatique au paiement
                                        </p>
                                    </div>
                                )}

                                {/* Archiver (rôles autorisés uniquement) */}
                                {['PAYE', 'REGULARISE', 'REJETE'].includes(bonCaisse.statut) && peutArchiver && (
                                    <Dialog open={showArchiverDialog} onOpenChange={setShowArchiverDialog}>
                                        <DialogTrigger asChild>
                                            <Button variant="outline" className="w-full">
                                                <Archive className="mr-2 h-4 w-4" />
                                                Archiver
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogHeader>
                                                <DialogTitle>Archiver le bon</DialogTitle>
                                                <DialogDescription>Êtes-vous sûr de vouloir archiver ce bon ? L'action est irréversible.</DialogDescription>
                                            </DialogHeader>
                                            <DialogFooter>
                                                <Button variant="outline" onClick={() => setShowArchiverDialog(false)}>Annuler</Button>
                                                <Button variant="destructive" onClick={() => { setShowArchiverDialog(false); executerAction('bons-caisse.archiver'); }}>Confirmer l'archivage</Button>
                                            </DialogFooter>
                                        </DialogContent>
                                    </Dialog>
                                )}

                                {/* Exporter PDF (toujours visible sauf brouillon) */}
                                {bonCaisse.statut !== 'BROUILLON' && (
                                    <a
                                        href={route('bons-caisse.export-pdf', bonCaisse.id)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="block w-full"
                                    >
                                        <Button variant="outline" className="w-full text-red-700 border-red-300 hover:bg-red-50 mt-2">
                                            <FileText className="mr-2 h-4 w-4" />
                                            Exporter PDF
                                        </Button>
                                    </a>
                                )}

                                {/* Actions de validation (si l'utilisateur est le validateur concerné) */}
                                {peutValiderCeBon && (
                                    <div className="space-y-3 p-3 bg-neemba-50 rounded-lg border border-neemba-200">
                                        <p className="text-sm font-semibold text-neemba-800 flex items-center gap-2">
                                            <ClipboardCheck className="h-4 w-4" />
                                            Validation requise
                                        </p>

                                        {/* Modale d'approbation */}
                                        <Dialog open={showApprouverDialog} onOpenChange={setShowApprouverDialog}>
                                            <DialogTrigger asChild>
                                                <Button className="w-full bg-green-600 hover:bg-green-700 text-white mb-2">
                                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                                    Approuver
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogHeader>
                                                    <DialogTitle>Approuver le bon {bonCaisse.numero}</DialogTitle>
                                                    <DialogDescription>Confirmez-vous l'approbation de ce bon de caisse ?</DialogDescription>
                                                </DialogHeader>
                                                <form onSubmit={handleApprouver}>
                                                    {/* CDG : Modification du code analytique */}
                                                    {estCDG && codesAnalytiques.length > 0 && (
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
                                                            onChange={(e) => approuverForm.setData('commentaire', e.target.value)}
                                                            placeholder="Ajouter un commentaire..."
                                                            className="mt-1 min-h-[60px] text-sm"
                                                        />
                                                    </div>
                                                    <DialogFooter className="mt-4">
                                                        <Button type="button" variant="outline" onClick={() => setShowApprouverDialog(false)}>Annuler</Button>
                                                        <Button type="submit" className="bg-green-600 hover:bg-green-700 text-white" disabled={approuverForm.processing}>
                                                            Confirmer l'approbation
                                                        </Button>
                                                    </DialogFooter>
                                                </form>
                                            </DialogContent>
                                        </Dialog>

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
                                                            <Label htmlFor="commentaire_rejet_show">Motif du rejet *</Label>
                                                            <Textarea
                                                                id="commentaire_rejet_show"
                                                                value={rejeterForm.data.commentaire}
                                                                onChange={(e) => rejeterForm.setData('commentaire', e.target.value)}
                                                                placeholder="Expliquez la raison du rejet..."
                                                                className="mt-1 min-h-[100px]"
                                                                required
                                                                minLength={10}
                                                            />
                                                            {rejeterForm.errors.commentaire && (
                                                                <p className="text-sm text-red-500 mt-1">{rejeterForm.errors.commentaire}</p>
                                                            )}
                                                        </div>
                                                        <DialogFooter>
                                                            <Button type="button" variant="outline" onClick={() => setShowRejetDialog(false)}>
                                                                Annuler
                                                            </Button>
                                                            <Button type="submit" variant="destructive" disabled={rejeterForm.processing}>
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
                                                            <Label htmlFor="commentaire_complement_show">Votre demande *</Label>
                                                            <Textarea
                                                                id="commentaire_complement_show"
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
                                    </div>
                                )}

                                {/* Pas d'action disponible */}
                                {!peutValiderCeBon
                                    && !(bonCaisse.statut === 'BROUILLON' && estDemandeur)
                                    && !(bonCaisse.statut === 'APPROUVE' && estCaissier)
                                    && !(bonCaisse.statut === 'EN_ATTENTE_REGULARISATION' && estDemandeur)
                                    && !(['PAYE', 'REGULARISE', 'REJETE'].includes(bonCaisse.statut) && peutArchiver)
                                    && bonCaisse.statut !== 'ARCHIVE'
                                    && (
                                    <p className="text-sm text-gray-400 text-center py-2">
                                        {['EN_ATTENTE_CHEF_SERVICE', 'EN_ATTENTE_CDG', 'EN_ATTENTE_DAF', 'EN_ATTENTE_DP'].includes(bonCaisse.statut)
                                            ? 'En cours de validation...'
                                            : bonCaisse.statut === 'PAYE'
                                                ? 'Bon payé.'
                                                : 'Aucune action disponible.'
                                        }
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Dates du cycle de vie */}
                    {(bonCaisse.date_soumission || bonCaisse.date_paiement || bonCaisse.date_regularisation) && (
                        <motion.div
                            initial={{ opacity: 0, x: 10 }}
                            animate={{ opacity: 1, x: 0 }}
                            transition={{ delay: 0.4 }}
                        >
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Chronologie</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">Création</span>
                                        <span>{formatDate(bonCaisse.date_demande)}</span>
                                    </div>
                                    {bonCaisse.date_soumission && (
                                        <div className="flex justify-between">
                                            <span className="text-gray-500">Soumission</span>
                                            <span>{formatDateTime(bonCaisse.date_soumission)}</span>
                                        </div>
                                    )}
                                    {bonCaisse.date_paiement && (
                                        <div className="flex justify-between">
                                            <span className="text-gray-500">Paiement</span>
                                            <span>{formatDateTime(bonCaisse.date_paiement)}</span>
                                        </div>
                                    )}
                                    {bonCaisse.date_regularisation && (
                                        <div className="flex justify-between">
                                            <span className="text-gray-500">Régularisation</span>
                                            <span>{formatDateTime(bonCaisse.date_regularisation)}</span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </motion.div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
