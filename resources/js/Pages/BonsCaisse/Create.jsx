/**
 * Page Création d'un Bon de Caisse - NEEMBA
 * 
 * Formulaire intelligent mobile-first en 5 sections :
 * 1. Identification (type, numéro, site, service, code analytique)
 * 2. Bénéficiaire (nom, type, téléphone, mode de paiement)
 * 3. Détails de la dépense (motif, catégorie, montant, devise)
 * 4. Pièces justificatives (upload avec validation format/taille)
 * 5. Contrôle automatique (résumé, alertes, actions)
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState, useCallback, useEffect } from 'react';
import axios from 'axios';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Save,
    Send,
    ArrowLeft,
    ArrowRight,
    AlertTriangle,
    Upload,
    CheckCircle2,
    FileText,
    User,
    Banknote,
    Paperclip,
    ShieldCheck,
    Trash2,
    Info,
    Loader2,
    Sparkles,
    Eye,
    Plus,
    Minus,
    GitBranch,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Separator } from '@/Components/ui/separator';
import { Badge } from '@/Components/ui/badge';
import { Combobox } from '@/Components/ui/combobox';
import { Link } from '@inertiajs/react';
import { nombreEnLettres, formaterNombre } from '@/utils/nombreEnLettres';
import MontantInput from '@/Components/MontantInput';
import TelephoneInput from '@/Components/TelephoneInput';

/** Définition des 5 sections du formulaire */
const SECTIONS = [
    { id: 'identification', label: 'Identification', icon: FileText },
    { id: 'beneficiaire', label: 'Bénéficiaire', icon: User },
    { id: 'depense', label: 'Dépense', icon: Banknote },
    { id: 'pieces', label: 'Pièces', icon: Paperclip },
    { id: 'controle', label: 'Contrôle', icon: ShieldCheck },
];

export default function Create({
    numero,
    sites = [],
    services = [],
    codesAnalytiques = [],
    categoriesDepense = {},
    typesBeneficiaire = {},
    modesPaiement = {},
    niveauxUrgence = {},
    motifsUrgence = [],
    montantMax: MONTANT_MAX = 20000000,
    seuilDP: SEUIL_DP = 5000000,
}) {
    const { auth, errors = {} } = usePage().props;
    const user = auth.user;

    /* Section active du formulaire */
    const [sectionActive, setSectionActive] = useState(0);

    /* Erreurs de validation locales (par étape, avant soumission serveur) */
    const [erreursLocales, setErreursLocales] = useState({});

    /* Suivi de l'action en cours pour afficher le bon loading state */
    const [actionEnCours, setActionEnCours] = useState(null);

    /* Formulaire Inertia avec valeurs initiales */
    const { data, setData } = useForm({
        type_bon: 'BD',
        site: user.site || '',
        service: user.service || '',
        code_analytique: '',
        beneficiaire: user.prenom ? `${user.prenom} ${user.name}` : user.name,
        type_beneficiaire: 'employe',
        telephone_beneficiaire: user.telephone || '',
        mode_paiement: 'especes',
        motif: '',
        categorie_depense: '',
        montant: '',
        montant_lettres: '',
        devise: 'GNF',
        niveau_urgence: 'normale',
        motif_urgence: '',
        justification_urgence: '',
        soumettre: false,
        pieces_jointes: [],
        types_documents: [],
    });

    /* Ventilations analytiques (Phase 4.2) */
    const [ventilations, setVentilations] = useState([]);

    /**
     * Pré-remplissage automatique du code analytique selon la catégorie de dépense.
     * Utilise le champ `categorie_depense_defaut` du modèle CodeAnalytique.
     * Le pré-remplissage n'écrase jamais un code déjà saisi par l'utilisateur.
     */
    useEffect(() => {
        if (!data.categorie_depense) return;
        // Ne pas écraser un code déjà saisi
        if (data.code_analytique) return;
        // Chercher le premier code dont la catégorie par défaut correspond
        const match = codesAnalytiques.find(
            (c) => c.categorie_depense_defaut === data.categorie_depense
        );
        if (match) {
            setData('code_analytique', match.code);
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.categorie_depense]);

    /* Liste des fichiers sélectionnés (gérée séparément pour pouvoir supprimer) */
    const [fichiers, setFichiers] = useState([]);
    const [typesDoc, setTypesDoc] = useState([]);

    /* OCR : état d'analyse par fichier et données agrégées */
    const [ocrStatuts, setOcrStatuts] = useState({}); // { index: 'en_cours'|'termine'|'erreur' }
    const [ocrResultats, setOcrResultats] = useState({}); // { index: { fournisseur, montant, ... } }
    const [ocrDonneesAgregees, setOcrDonneesAgregees] = useState(null);
    const [ocrApplique, setOcrApplique] = useState(false);

    /** Lancer l'analyse OCR d'un fichier */
    const analyserFichierOcr = useCallback(async (fichier, index) => {
        setOcrStatuts(prev => ({ ...prev, [index]: 'en_cours' }));
        try {
            const formData = new FormData();
            formData.append('fichier', fichier);
            const response = await axios.post(route('api.ocr.analyser'), formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            if (response.data.success && response.data.data && Object.keys(response.data.data).length > 0) {
                setOcrStatuts(prev => ({ ...prev, [index]: 'termine' }));
                setOcrResultats(prev => {
                    const updated = { ...prev, [index]: response.data.data };
                    /* Agréger toutes les données OCR */
                    const agrege = {};
                    Object.values(updated).forEach(d => {
                        if (d) Object.entries(d).forEach(([k, v]) => { if (!agrege[k] && v) agrege[k] = v; });
                    });
                    setOcrDonneesAgregees(Object.keys(agrege).length > 0 ? agrege : null);
                    return updated;
                });
            } else {
                setOcrStatuts(prev => ({ ...prev, [index]: 'termine' }));
            }
        } catch {
            setOcrStatuts(prev => ({ ...prev, [index]: 'erreur' }));
        }
    }, []);

    /** Appliquer les suggestions OCR aux champs du formulaire */
    const appliquerOcr = () => {
        if (!ocrDonneesAgregees) return;
        const d = ocrDonneesAgregees;

        /* Bénéficiaire : station-service ou fournisseur */
        if (!data.beneficiaire.trim()) {
            if (d.station) setData('beneficiaire', d.station);
            else if (d.fournisseur) setData('beneficiaire', d.fournisseur);
        }

        /* Motif : construire une description riche pour le carburant */
        if (!data.motif || data.motif.length < 10) {
            if (d.litrage || d.prix_unitaire || d.immatriculation) {
                const parts = ['Achat carburant'];
                if (d.litrage) parts.push(`${d.litrage} L`);
                if (d.prix_unitaire) parts.push(`à ${formaterNombre(d.prix_unitaire)} GNF/L`);
                if (d.station) parts.push(`- ${d.station}`);
                if (d.immatriculation) parts.push(`(véhicule ${d.immatriculation})`);
                setData('motif', parts.join(' '));
            } else if (d.description) {
                setData('motif', d.description);
            }
        }

        /* Montant */
        if (d.montant && !data.montant) {
            setData('montant', String(d.montant));
        }

        /* Catégorie : auto-sélectionner carburant si données carburant détectées */
        if ((d.litrage || d.station) && !data.categorie_depense) {
            setData('categorie_depense', 'carburant');
        }

        setOcrApplique(true);
    };

    /** Ajouter des fichiers */
    const ajouterFichiers = (e) => {
        const newFiles = Array.from(e.target.files);
        const updated = [...fichiers, ...newFiles];
        const updatedTypes = [...typesDoc, ...newFiles.map(() => 'autre')];
        setFichiers(updated);
        setTypesDoc(updatedTypes);
        setData('pieces_jointes', updated);
        setData('types_documents', updatedTypes);
        /* L'OCR automatique ne se déclenche plus ici — uniquement pour les justificatifs carburant */
        e.target.value = '';
    };

    /** Supprimer un fichier */
    const supprimerFichier = (index) => {
        const updated = fichiers.filter((_, i) => i !== index);
        const updatedTypes = typesDoc.filter((_, i) => i !== index);
        setFichiers(updated);
        setTypesDoc(updatedTypes);
        setData('pieces_jointes', updated);
        setData('types_documents', updatedTypes);
        /* Nettoyer les résultats OCR de ce fichier */
        setOcrStatuts(prev => { const n = { ...prev }; delete n[index]; return n; });
        setOcrResultats(prev => { const n = { ...prev }; delete n[index]; return n; });
    };

    /** Changer le type de document d'un fichier */
    const changerTypeDoc = (index, type) => {
        const updatedTypes = [...typesDoc];
        updatedTypes[index] = type;
        setTypesDoc(updatedTypes);
        setData('types_documents', updatedTypes);
        /* Déclencher l'OCR automatiquement pour les justificatifs carburant */
        if (type === 'recu_carburant' && !ocrStatuts[index]) {
            analyserFichierOcr(fichiers[index], index);
        }
    };

    /** Soumettre le formulaire */
    const handleSubmit = (e, doitSoumettre = false) => {
        e.preventDefault();
        setActionEnCours(doitSoumettre ? 'soumettre' : 'brouillon');

        /* Mettre à jour les données avant l'envoi */
        const formData = new FormData();
        Object.entries(data).forEach(([key, value]) => {
            if (key === 'pieces_jointes' || key === 'types_documents' || key === 'soumettre') return;
            if (value !== null && value !== undefined) {
                formData.append(key, value);
            }
        });
        formData.append('soumettre', doitSoumettre ? '1' : '0');
        fichiers.forEach((f, i) => {
            formData.append(`pieces_jointes[${i}]`, f);
            formData.append(`types_documents[${i}]`, typesDoc[i] || 'autre');
        });
        ventilations.forEach((v, i) => {
            if (v.code_analytique && v.montant) {
                formData.append(`ventilations[${i}][code_analytique]`, v.code_analytique);
                formData.append(`ventilations[${i}][montant]`, v.montant);
                formData.append(`ventilations[${i}][pourcentage]`, v.pourcentage || '');
            }
        });

        router.post(route('bons-caisse.store'), formData, {
            forceFormData: true,
            onFinish: () => setActionEnCours(null),
            onError: (erreurs) => {
                /* Naviguer automatiquement vers la première section contenant des erreurs */
                const champsSections = [
                    ['type_bon', 'site', 'service', 'code_analytique', 'niveau_urgence', 'motif_urgence', 'justification_urgence'],
                    ['beneficiaire', 'type_beneficiaire', 'telephone_beneficiaire', 'mode_paiement'],
                    ['motif', 'categorie_depense', 'montant', 'montant_lettres', 'devise'],
                    ['pieces_jointes'],
                ];
                for (let i = 0; i < champsSections.length; i++) {
                    if (champsSections[i].some(champ => erreurs[champ] || Object.keys(erreurs).some(k => k.startsWith(champ + '.')))) {
                        setSectionActive(i);
                        return;
                    }
                }
            },
        });
    };

    /** Vérifie si le montant nécessite la validation du DP */
    const necessiteDP = parseFloat(data.montant) >= SEUIL_DP;
    /** Vérifie si le montant dépasse le maximum */
    const montantExcede = parseFloat(data.montant) > MONTANT_MAX;
    /** Vérifie si c'est un bon provisoire */
    const estBP = data.type_bon === 'BP';

    /** Points de contrôle automatique */
    const controles = [
        {
            label: 'Montant dans les limites',
            ok: data.montant && !montantExcede,
            erreur: montantExcede,
            detail: montantExcede
                ? `Le montant dépasse ${formaterNombre(MONTANT_MAX)} GNF`
                : data.montant ? `${new Intl.NumberFormat('fr-FR').format(data.montant)} GNF` : 'Non renseigné',
        },
        {
            label: 'Pièces justificatives',
            ok: data.type_bon === 'BP' || fichiers.length > 0,
            erreur: data.type_bon === 'BD' && fichiers.length === 0,
            detail: data.type_bon === 'BD'
                ? fichiers.length > 0 ? `${fichiers.length} fichier(s)` : 'Obligatoire pour un BD'
                : 'Optionnel pour un BP (à fournir après paiement)',
        },
        {
            label: 'Champs obligatoires',
            ok: data.site && data.service && data.beneficiaire && data.motif?.length >= 10 && data.montant && data.categorie_depense,
            erreur: false,
            detail: !data.site || !data.service || !data.beneficiaire || !data.motif || !data.montant || !data.categorie_depense
                ? 'Certains champs requis ne sont pas remplis'
                : 'Tous les champs requis sont remplis',
        },
        {
            label: 'Validation Directeur Pays',
            ok: true,
            erreur: false,
            detail: necessiteDP ? `Requise (montant ≥ ${formaterNombre(SEUIL_DP)} GNF)` : 'Non requise',
            info: necessiteDP,
        },
        {
            label: 'Suivi post-paiement',
            ok: true,
            erreur: false,
            detail: estBP
                ? `BP détecté : régularisation requise sous ${data.categorie_depense === 'frais_mission' ? '3' : '2'} jours après paiement`
                : 'BD : pas de régularisation nécessaire',
            info: estBP,
        },
    ];

    const tousControlesOk = controles.every((c) => c.ok && !c.erreur);

    /** Valider la section courante avant de passer à la suivante */
    const validerSection = (index) => {
        const erreurs = {};

        if (index === 0) {
            /* Section 1 : Identification */
            if (!data.type_bon) erreurs.type_bon = 'Le type de bon est obligatoire.';
            if (!data.site) erreurs.site = 'Le site est obligatoire.';
            if (!data.service) erreurs.service = 'Le service est obligatoire.';
            if ((data.niveau_urgence === 'urgente' || data.niveau_urgence === 'tres_urgente')) {
                if (!data.motif_urgence) erreurs.motif_urgence = 'Le motif d\'urgence est obligatoire.';
                if (!data.justification_urgence || data.justification_urgence.trim().length < 10) erreurs.justification_urgence = 'La justification de l\'urgence doit contenir au moins 10 caractères.';
            }
        } else if (index === 1) {
            /* Section 2 : Bénéficiaire */
            if (!data.beneficiaire || data.beneficiaire.trim() === '') erreurs.beneficiaire = 'Le nom du bénéficiaire est obligatoire.';
            if (!data.type_beneficiaire) erreurs.type_beneficiaire = 'Le type de bénéficiaire est obligatoire.';
            if (!data.mode_paiement) erreurs.mode_paiement = 'Le mode de paiement est obligatoire.';
        } else if (index === 2) {
            /* Section 3 : Dépense */
            if (!data.motif || data.motif.trim().length < 10) erreurs.motif = 'Le motif doit contenir au moins 10 caractères.';
            if (!data.categorie_depense) erreurs.categorie_depense = 'La catégorie de dépense est obligatoire.';
            if (!data.montant || parseFloat(data.montant) <= 0) erreurs.montant = 'Le montant doit être supérieur à 0.';
            if (parseFloat(data.montant) > MONTANT_MAX) erreurs.montant = `Le montant ne doit pas dépasser ${formaterNombre(MONTANT_MAX)} GNF.`;
        } else if (index === 3) {
            /* Section 4 : Pièces — BD nécessite au moins 1 fichier */
            if (data.type_bon === 'BD' && fichiers.length === 0) {
                erreurs.pieces_jointes = 'Un Bon Définitif nécessite au moins une pièce justificative.';
            }
        }

        setErreursLocales(erreurs);
        return Object.keys(erreurs).length === 0;
    };

    /** Navigation entre sections avec validation */
    const allerSection = (dir) => {
        const next = sectionActive + dir;
        if (next < 0 || next >= SECTIONS.length) return;

        /* Valider la section courante avant d'avancer */
        if (dir > 0 && !validerSection(sectionActive)) return;

        /* Nettoyer les erreurs locales en avançant */
        if (dir > 0) setErreursLocales({});

        setSectionActive(next);
    };

    return (
        <AuthenticatedLayout header="Nouveau Bon de Caisse">
            <Head title="Nouveau Bon de Caisse" />

            {/* Retour à la liste */}
            <div className="mb-6">
                <Link
                    href={route('bons-caisse.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour à la liste
                </Link>
            </div>

            {/* Indicateur de progression - sections */}
            <div className="mb-6">
                <div className="flex items-center justify-between overflow-x-auto pb-2">
                    {SECTIONS.map((section, index) => {
                        const Icon = section.icon;
                        const isActive = index === sectionActive;
                        const isDone = index < sectionActive;
                        return (
                            <button
                                key={section.id}
                                type="button"
                                onClick={() => {
                                    /* Pour naviguer en avant, valider les sections intermédiaires */
                                    if (index > sectionActive) {
                                        for (let i = sectionActive; i < index; i++) {
                                            if (!validerSection(i)) {
                                                setSectionActive(i);
                                                return;
                                            }
                                        }
                                        setErreursLocales({});
                                    }
                                    setSectionActive(index);
                                }}
                                className={`flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-all whitespace-nowrap ${
                                    isActive
                                        ? 'bg-neemba-100 text-neemba-800 ring-1 ring-neemba-300'
                                        : isDone
                                        ? 'text-green-700 bg-green-50'
                                        : 'text-gray-400 hover:text-gray-600'
                                }`}
                            >
                                {isDone ? (
                                    <CheckCircle2 className="h-4 w-4 text-green-500" />
                                ) : (
                                    <Icon className="h-4 w-4" />
                                )}
                                <span className="hidden sm:inline">{section.label}</span>
                                <span className="sm:hidden">{index + 1}</span>
                            </button>
                        );
                    })}
                </div>
                {/* Barre de progression */}
                <div className="mt-2 h-1 bg-gray-200 rounded-full">
                    <motion.div
                        className="h-1 bg-neemba-500 rounded-full"
                        initial={{ width: 0 }}
                        animate={{ width: `${((sectionActive + 1) / SECTIONS.length) * 100}%` }}
                        transition={{ duration: 0.3 }}
                    />
                </div>
            </div>

            <form onSubmit={(e) => handleSubmit(e, false)}>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Colonne principale - Section active */}
                    <div className="lg:col-span-2">
                        <AnimatePresence mode="wait">
                            {/* Section 1 : Identification */}
                            {sectionActive === 0 && (
                                <motion.div
                                    key="identification"
                                    initial={{ opacity: 0, x: 20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -20 }}
                                    transition={{ duration: 0.2 }}
                                >
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <FileText className="h-5 w-5 text-neemba-500" />
                                                Section 1 — Identification
                                            </CardTitle>
                                            <CardDescription>
                                                Numéro auto : <span className="font-mono font-semibold">{numero}</span>
                                                {' · '}Date : {new Date().toLocaleDateString('fr-FR')}
                                                {' · '}Demandeur : {user.prenom} {user.name}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {/* Type de bon */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div>
                                                    <Label htmlFor="type_bon">Type de bon *</Label>
                                                    <Select
                                                        value={data.type_bon}
                                                        onValueChange={(val) => setData('type_bon', val)}
                                                    >
                                                        <SelectTrigger className="mt-1">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="BD">
                                                                Bon Définitif (BD) — Facture existante
                                                            </SelectItem>
                                                            <SelectItem value="BP">
                                                                Bon Provisoire (BP) — Facture à fournir
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    {(errors.type_bon || erreursLocales.type_bon) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.type_bon || erreursLocales.type_bon}</p>
                                                    )}
                                                    {estBP && (
                                                        <p className="text-xs text-amber-600 mt-1 flex items-center gap-1">
                                                            <Info className="h-3 w-3" />
                                                            Régularisation requise après paiement
                                                        </p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Label>Code analytique</Label>
                                                    <Combobox
                                                        options={codesAnalytiques.map((c) => ({
                                                            value: c.code,
                                                            label: `${c.code} - ${c.libelle}`,
                                                        }))}
                                                        value={data.code_analytique}
                                                        onChange={(val) => setData('code_analytique', val)}
                                                        placeholder="Sélectionner un code"
                                                        searchPlaceholder="Rechercher un code..."
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>

                                            {/* Site et Service */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div>
                                                    <Label>Site *</Label>
                                                    <Combobox
                                                        options={sites}
                                                        value={data.site}
                                                        onChange={(val) => setData('site', val)}
                                                        placeholder="Sélectionner un site"
                                                        searchPlaceholder="Rechercher un site..."
                                                        className="mt-1"
                                                        error={errors.site}
                                                    />
                                                    {(errors.site || erreursLocales.site) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.site || erreursLocales.site}</p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Label>Service *</Label>
                                                    <Combobox
                                                        options={services}
                                                        value={data.service}
                                                        onChange={(val) => setData('service', val)}
                                                        placeholder="Sélectionner un service"
                                                        searchPlaceholder="Rechercher un service..."
                                                        className="mt-1"
                                                        error={errors.service || erreursLocales.service}
                                                    />
                                                    {(errors.service || erreursLocales.service) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.service || erreursLocales.service}</p>
                                                    )}
                                                </div>
                                            </div>

                                            {/* Niveau d'urgence */}
                                            <div>
                                                <Label>Niveau d'urgence</Label>
                                                <div className="grid grid-cols-3 gap-3 mt-1">
                                                    {Object.entries(niveauxUrgence).map(([key, label]) => (
                                                        <button
                                                            key={key}
                                                            type="button"
                                                            onClick={() => setData('niveau_urgence', key)}
                                                            className={`flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border text-sm font-medium transition-all ${
                                                                data.niveau_urgence === key
                                                                    ? key === 'normale'
                                                                        ? 'border-green-400 bg-green-50 text-green-700 ring-1 ring-green-300'
                                                                        : key === 'urgente'
                                                                        ? 'border-orange-400 bg-orange-50 text-orange-700 ring-1 ring-orange-300'
                                                                        : 'border-red-400 bg-red-50 text-red-700 ring-1 ring-red-300'
                                                                    : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:bg-gray-50'
                                                            }`}
                                                        >
                                                            {key === 'normale' && '🟢'}
                                                            {key === 'urgente' && '🟠'}
                                                            {key === 'tres_urgente' && '🔴'}
                                                            {label}
                                                        </button>
                                                    ))}
                                                </div>
                                                {data.niveau_urgence === 'urgente' && (
                                                    <p className="text-xs text-orange-600 mt-1.5 flex items-center gap-1">
                                                        <Info className="h-3 w-3" />
                                                        Le validateur suivant recevra un email en plus de la notification
                                                    </p>
                                                )}
                                                {data.niveau_urgence === 'tres_urgente' && (
                                                    <p className="text-xs text-red-600 mt-1.5 flex items-center gap-1">
                                                        <AlertTriangle className="h-3 w-3" />
                                                        Le validateur suivant recevra un email ET un SMS à chaque étape
                                                    </p>
                                                )}
                                            </div>

                                            {/* Justification urgence (Phase 1.1) — conditionnel */}
                                            {(data.niveau_urgence === 'urgente' || data.niveau_urgence === 'tres_urgente') && (
                                                <div className="space-y-3 p-4 rounded-lg border border-orange-200 bg-orange-50/50">
                                                    <p className="text-sm font-medium text-orange-800 flex items-center gap-2">
                                                        <AlertTriangle className="h-4 w-4" />
                                                        Justification de l'urgence obligatoire
                                                    </p>
                                                    <div>
                                                        <Label>Motif d'urgence *</Label>
                                                        {motifsUrgence.length > 0 ? (
                                                            <Select
                                                                value={data.motif_urgence}
                                                                onValueChange={(val) => setData('motif_urgence', val)}
                                                            >
                                                                <SelectTrigger className="mt-1 bg-white">
                                                                    <SelectValue placeholder="Sélectionner un motif" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    {motifsUrgence.map((motif) => (
                                                                        <SelectItem key={motif} value={motif}>{motif}</SelectItem>
                                                                    ))}
                                                                    <SelectItem value="autre">Autre motif</SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        ) : (
                                                            <Input
                                                                value={data.motif_urgence}
                                                                onChange={(e) => setData('motif_urgence', e.target.value)}
                                                                placeholder="Précisez le motif d'urgence"
                                                                className="mt-1 bg-white"
                                                            />
                                                        )}
                                                        {(errors.motif_urgence || erreursLocales.motif_urgence) && (
                                                            <p className="text-sm text-red-500 mt-1">{errors.motif_urgence || erreursLocales.motif_urgence}</p>
                                                        )}
                                                    </div>
                                                    <div>
                                                        <Label>Justification détaillée *</Label>
                                                        <Textarea
                                                            value={data.justification_urgence}
                                                            onChange={(e) => setData('justification_urgence', e.target.value)}
                                                            placeholder="Expliquez pourquoi ce bon nécessite un traitement urgent (minimum 10 caractères)..."
                                                            className="mt-1 min-h-[80px] bg-white"
                                                        />
                                                        <div className="flex justify-between mt-1">
                                                            {(errors.justification_urgence || erreursLocales.justification_urgence) && (
                                                                <p className="text-sm text-red-500">{errors.justification_urgence || erreursLocales.justification_urgence}</p>
                                                            )}
                                                            <p className={`text-xs ml-auto ${(data.justification_urgence?.length || 0) < 10 ? 'text-gray-400' : 'text-green-600'}`}>
                                                                {data.justification_urgence?.length || 0}/10 min
                                                            </p>
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </motion.div>
                            )}

                            {/* Section 2 : Bénéficiaire */}
                            {sectionActive === 1 && (
                                <motion.div
                                    key="beneficiaire"
                                    initial={{ opacity: 0, x: 20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -20 }}
                                    transition={{ duration: 0.2 }}
                                >
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <User className="h-5 w-5 text-neemba-500" />
                                                Section 2 — Bénéficiaire
                                            </CardTitle>
                                            <CardDescription>
                                                Informations sur la personne ou l'entité qui recevra le paiement
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div>
                                                    <Label htmlFor="beneficiaire">Nom du bénéficiaire *</Label>
                                                    <Input
                                                        id="beneficiaire"
                                                        value={data.beneficiaire}
                                                        onChange={(e) => setData('beneficiaire', e.target.value)}
                                                        placeholder="Nom complet du bénéficiaire"
                                                        className="mt-1"
                                                        required
                                                    />
                                                    {(errors.beneficiaire || erreursLocales.beneficiaire) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.beneficiaire || erreursLocales.beneficiaire}</p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Label htmlFor="type_beneficiaire">Type de bénéficiaire *</Label>
                                                    <Select
                                                        value={data.type_beneficiaire}
                                                        onValueChange={(val) => setData('type_beneficiaire', val)}
                                                    >
                                                        <SelectTrigger className="mt-1">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(typesBeneficiaire).map(([key, label]) => (
                                                                <SelectItem key={key} value={key}>{label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {(errors.type_beneficiaire || erreursLocales.type_beneficiaire) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.type_beneficiaire || erreursLocales.type_beneficiaire}</p>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div>
                                                    <Label htmlFor="telephone_beneficiaire">Téléphone</Label>
                                                    <TelephoneInput
                                                        id="telephone_beneficiaire"
                                                        value={data.telephone_beneficiaire}
                                                        onChange={(val) => setData('telephone_beneficiaire', val)}
                                                        className="mt-1"
                                                    />
                                                    {(errors.telephone_beneficiaire || erreursLocales.telephone_beneficiaire) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.telephone_beneficiaire || erreursLocales.telephone_beneficiaire}</p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Label htmlFor="mode_paiement">Mode de paiement souhaité *</Label>
                                                    <Select
                                                        value={data.mode_paiement}
                                                        onValueChange={(val) => setData('mode_paiement', val)}
                                                    >
                                                        <SelectTrigger className="mt-1">
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {Object.entries(modesPaiement).map(([key, label]) => (
                                                                <SelectItem key={key} value={key}>{label}</SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                    {(errors.mode_paiement || erreursLocales.mode_paiement) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.mode_paiement || erreursLocales.mode_paiement}</p>
                                                    )}
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </motion.div>
                            )}

                            {/* Section 3 : Détails de la dépense */}
                            {sectionActive === 2 && (
                                <motion.div
                                    key="depense"
                                    initial={{ opacity: 0, x: 20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -20 }}
                                    transition={{ duration: 0.2 }}
                                >
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <Banknote className="h-5 w-5 text-neemba-500" />
                                                Section 3 — Détails de la dépense
                                            </CardTitle>
                                            <CardDescription>
                                                Maximum autorisé : {formaterNombre(MONTANT_MAX)} GNF · Validation DP à partir de {formaterNombre(SEUIL_DP)} GNF
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {/* Motif */}
                                            <div>
                                                <Label htmlFor="motif">Motif de la demande *</Label>
                                                <Textarea
                                                    id="motif"
                                                    value={data.motif}
                                                    onChange={(e) => setData('motif', e.target.value)}
                                                    placeholder="Décrivez le motif de la dépense en détail (minimum 10 caractères)..."
                                                    className="mt-1 min-h-[100px]"
                                                    required
                                                />
                                                <div className="flex justify-between mt-1">
                                                    {(errors.motif || erreursLocales.motif) && (
                                                        <p className="text-sm text-red-500">{errors.motif || erreursLocales.motif}</p>
                                                    )}
                                                    <p className={`text-xs ml-auto ${data.motif.length < 10 ? 'text-gray-400' : 'text-green-600'}`}>
                                                        {data.motif.length}/10 min
                                                    </p>
                                                </div>
                                            </div>

                                            {/* Catégorie */}
                                            <div>
                                                <Label htmlFor="categorie_depense">Catégorie de dépense *</Label>
                                                <Select
                                                    value={data.categorie_depense}
                                                    onValueChange={(val) => setData('categorie_depense', val)}
                                                >
                                                    <SelectTrigger className="mt-1">
                                                        <SelectValue placeholder="Sélectionner une catégorie" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {Object.entries(categoriesDepense).map(([key, label]) => (
                                                            <SelectItem key={key} value={key}>{label}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {(errors.categorie_depense || erreursLocales.categorie_depense) && (
                                                    <p className="text-sm text-red-500 mt-1">{errors.categorie_depense || erreursLocales.categorie_depense}</p>
                                                )}
                                            </div>

                                            {/* Montant */}
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                <div>
                                                    <Label htmlFor="montant">Montant *</Label>
                                                    <MontantInput
                                                        id="montant"
                                                        value={data.montant}
                                                        onChange={(val) => {
                                                            setData(prev => ({
                                                                ...prev,
                                                                montant: val,
                                                                montant_lettres: val ? nombreEnLettres(val) : '',
                                                            }));
                                                        }}
                                                        max={MONTANT_MAX}
                                                        className="mt-1"
                                                        required
                                                    />
                                                    {(errors.montant || erreursLocales.montant) && (
                                                        <p className="text-sm text-red-500 mt-1">{errors.montant || erreursLocales.montant}</p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Label htmlFor="montant_lettres">Montant en lettres</Label>
                                                    <Input
                                                        id="montant_lettres"
                                                        value={data.montant_lettres}
                                                        onChange={(e) => setData('montant_lettres', e.target.value)}
                                                        placeholder="Ex: Cinq millions de francs guinéens"
                                                        className="mt-1"
                                                    />
                                                </div>
                                            </div>

                                            {/* Alertes sur le montant */}
                                            {montantExcede && (
                                                <div className="flex items-center gap-2 p-3 rounded-lg bg-red-50 text-red-700 text-sm border border-red-200">
                                                    <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                                                    Le montant dépasse le maximum autorisé de {formaterNombre(MONTANT_MAX)} GNF.
                                                </div>
                                            )}
                                            {necessiteDP && !montantExcede && (
                                                <div className="flex items-center gap-2 p-3 rounded-lg bg-neemba-50 text-neemba-800 text-sm border border-neemba-200">
                                                    <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                                                    Ce montant nécessite la validation du Directeur Pays (niveau 4).
                                                </div>
                                            )}

                                            {/* Affichage du montant formaté */}
                                            {data.montant && !montantExcede && (
                                                <p className="text-2xl font-bold text-neemba-600 text-center py-2">
                                                    {new Intl.NumberFormat('fr-FR').format(data.montant)} GNF
                                                </p>
                                            )}

                                            {/* Ventilation analytique (Phase 4.2) */}
                                            {data.montant && parseFloat(data.montant) > 0 && (
                                                <div className="space-y-3 pt-2">
                                                    <Separator />
                                                    <div className="flex items-center justify-between">
                                                        <Label className="flex items-center gap-2">
                                                            <GitBranch className="h-4 w-4 text-neemba-500" />
                                                            Ventilation analytique
                                                        </Label>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => setVentilations([...ventilations, { code_analytique: '', montant: '', pourcentage: '' }])}
                                                        >
                                                            <Plus className="h-3.5 w-3.5 mr-1" />
                                                            Ajouter
                                                        </Button>
                                                    </div>
                                                    {ventilations.length === 0 && (
                                                        <p className="text-xs text-gray-400">
                                                            Optionnel — Répartir la dépense sur plusieurs codes analytiques
                                                        </p>
                                                    )}
                                                    {ventilations.map((v, i) => (
                                                        <div key={i} className="flex items-end gap-2 p-3 rounded-lg border bg-gray-50">
                                                            <div className="flex-1">
                                                                <Label className="text-xs">Code analytique</Label>
                                                                <Combobox
                                                                    options={codesAnalytiques.map((c) => ({
                                                                        value: c.code,
                                                                        label: `${c.code} - ${c.libelle}${c.service?.nom ? ` (${c.service.nom})` : ''}`,
                                                                    }))}
                                                                    value={v.code_analytique}
                                                                    onChange={(val) => {
                                                                        const updated = [...ventilations];
                                                                        updated[i].code_analytique = val;
                                                                        setVentilations(updated);
                                                                    }}
                                                                    placeholder="Code..."
                                                                    searchPlaceholder="Rechercher..."
                                                                    className="mt-0.5"
                                                                />
                                                            </div>
                                                            <div className="w-32">
                                                                <Label className="text-xs">Montant</Label>
                                                                <Input
                                                                    type="number"
                                                                    value={v.montant}
                                                                    onChange={(e) => {
                                                                        const updated = [...ventilations];
                                                                        updated[i].montant = e.target.value;
                                                                        if (data.montant && parseFloat(data.montant) > 0) {
                                                                            updated[i].pourcentage = ((parseFloat(e.target.value) / parseFloat(data.montant)) * 100).toFixed(1);
                                                                        }
                                                                        setVentilations(updated);
                                                                    }}
                                                                    placeholder="0"
                                                                    className="mt-0.5"
                                                                />
                                                            </div>
                                                            <div className="w-20">
                                                                <Label className="text-xs">%</Label>
                                                                <Input
                                                                    type="number"
                                                                    value={v.pourcentage}
                                                                    onChange={(e) => {
                                                                        const updated = [...ventilations];
                                                                        updated[i].pourcentage = e.target.value;
                                                                        if (data.montant && parseFloat(data.montant) > 0) {
                                                                            updated[i].montant = ((parseFloat(e.target.value) / 100) * parseFloat(data.montant)).toFixed(0);
                                                                        }
                                                                        setVentilations(updated);
                                                                    }}
                                                                    placeholder="0"
                                                                    className="mt-0.5"
                                                                />
                                                            </div>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                className="h-9 w-9 text-red-500 hover:text-red-700 flex-shrink-0"
                                                                onClick={() => setVentilations(ventilations.filter((_, j) => j !== i))}
                                                            >
                                                                <Minus className="h-4 w-4" />
                                                            </Button>
                                                        </div>
                                                    ))}
                                                    {ventilations.length > 0 && (() => {
                                                        const totalVentile = ventilations.reduce((s, v) => s + (parseFloat(v.montant) || 0), 0);
                                                        const montantTotal = parseFloat(data.montant) || 0;
                                                        const reste = montantTotal - totalVentile;
                                                        return (
                                                            <div className={`text-xs flex justify-between p-2 rounded ${Math.abs(reste) < 1 ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'}`}>
                                                                <span>Total ventilé : {new Intl.NumberFormat('fr-FR').format(totalVentile)} GNF</span>
                                                                <span>{Math.abs(reste) < 1 ? '✓ Équilibré' : `Reste : ${new Intl.NumberFormat('fr-FR').format(reste)} GNF`}</span>
                                                            </div>
                                                        );
                                                    })()}
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </motion.div>
                            )}

                            {/* Section 4 : Pièces justificatives */}
                            {sectionActive === 3 && (
                                <motion.div
                                    key="pieces"
                                    initial={{ opacity: 0, x: 20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -20 }}
                                    transition={{ duration: 0.2 }}
                                >
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <Paperclip className="h-5 w-5 text-neemba-500" />
                                                Section 4 — Pièces justificatives
                                            </CardTitle>
                                            <CardDescription>
                                                {data.type_bon === 'BD'
                                                    ? 'Au moins un justificatif est obligatoire pour un Bon Définitif'
                                                    : 'Optionnel pour un Bon Provisoire — à fournir lors de la régularisation'}
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            {/* Zone de drop/upload */}
                                            <div className="border-2 border-dashed rounded-lg p-6 text-center hover:border-neemba-400 transition-colors">
                                                <Upload className="h-8 w-8 mx-auto text-gray-400 mb-2" />
                                                <Label
                                                    htmlFor="pieces_jointes"
                                                    className="cursor-pointer text-neemba-600 hover:text-neemba-700 font-medium"
                                                >
                                                    Cliquez pour sélectionner des fichiers
                                                </Label>
                                                <input
                                                    id="pieces_jointes"
                                                    type="file"
                                                    multiple
                                                    className="hidden"
                                                    accept=".pdf,.jpg,.jpeg,.png"
                                                    onChange={ajouterFichiers}
                                                />
                                                <p className="text-xs text-gray-400 mt-1">
                                                    Formats : PDF, JPG, PNG · Max 10 Mo par fichier
                                                </p>
                                            </div>

                                            {/* Alerte BD sans pièce */}
                                            {data.type_bon === 'BD' && fichiers.length === 0 && (
                                                <div className="flex items-center gap-2 p-3 rounded-lg bg-amber-50 text-amber-700 text-sm border border-amber-200">
                                                    <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                                                    Un Bon Définitif nécessite au moins une pièce justificative pour être soumis.
                                                </div>
                                            )}

                                            {/* Liste des fichiers */}
                                            {fichiers.length > 0 && (
                                                <div className="space-y-2">
                                                    {fichiers.map((file, i) => (
                                                        <div
                                                            key={i}
                                                            className="flex flex-col gap-1 p-3 border rounded-lg bg-gray-50"
                                                        >
                                                            <div className="flex items-center gap-3">
                                                                <Paperclip className="h-4 w-4 text-gray-400 flex-shrink-0" />
                                                                <div className="flex-1 min-w-0">
                                                                    <p className="text-sm font-medium truncate">{file.name}</p>
                                                                    <p className="text-xs text-gray-500">
                                                                        {(file.size / 1024).toFixed(1)} Ko
                                                                    </p>
                                                                </div>
                                                                {/* Indicateur OCR */}
                                                                {ocrStatuts[i] === 'en_cours' && (
                                                                    <span className="flex items-center gap-1 text-xs text-neemba-600">
                                                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                                        Analyse...
                                                                    </span>
                                                                )}
                                                                {ocrStatuts[i] === 'termine' && ocrResultats[i] && (
                                                                    <span className="flex items-center gap-1 text-xs text-green-600">
                                                                        <Sparkles className="h-3.5 w-3.5" />
                                                                        Analysé
                                                                    </span>
                                                                )}
                                                                {ocrStatuts[i] === 'termine' && !ocrResultats[i] && (
                                                                    <span className="text-xs text-gray-400">Aucune info</span>
                                                                )}
                                                                {ocrStatuts[i] === 'erreur' && (
                                                                    <span className="text-xs text-gray-400">—</span>
                                                                )}
                                                                <Select
                                                                    value={typesDoc[i] || 'autre'}
                                                                    onValueChange={(val) => changerTypeDoc(i, val)}
                                                                >
                                                                    <SelectTrigger className="w-[140px] h-8 text-xs">
                                                                        <SelectValue />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="facture">Facture</SelectItem>
                                                                        <SelectItem value="proforma">Proforma</SelectItem>
                                                                        <SelectItem value="email">Email justif.</SelectItem>
                                                                        <SelectItem value="ordre_mission">Ordre mission</SelectItem>
                                                                        <SelectItem value="recu_carburant">Reçu carburant</SelectItem>
                                                                        <SelectItem value="devis">Devis</SelectItem>
                                                                        <SelectItem value="autre">Autre</SelectItem>
                                                                    </SelectContent>
                                                                </Select>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="h-8 w-8 text-red-500 hover:text-red-700 flex-shrink-0"
                                                                    onClick={() => supprimerFichier(i)}
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}

                                            {(errors.pieces_jointes || erreursLocales.pieces_jointes) && (
                                                <p className="text-sm text-red-500">{errors.pieces_jointes || erreursLocales.pieces_jointes}</p>
                                            )}

                                            {/* Bannière de suggestions OCR */}
                                            {ocrDonneesAgregees && !ocrApplique && (
                                                <motion.div
                                                    initial={{ opacity: 0, y: 10 }}
                                                    animate={{ opacity: 1, y: 0 }}
                                                    className="p-4 rounded-lg bg-gradient-to-r from-neemba-50 to-amber-50 border border-neemba-200"
                                                >
                                                    <div className="flex items-start gap-3">
                                                        <Sparkles className="h-5 w-5 text-neemba-600 mt-0.5 flex-shrink-0" />
                                                        <div className="flex-1">
                                                            <p className="text-sm font-semibold text-neemba-800 mb-2">
                                                                Informations détectées dans le document
                                                            </p>
                                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-1 text-xs text-gray-700 mb-3">
                                                                {ocrDonneesAgregees.fournisseur && (
                                                                    <p><span className="font-medium">Fournisseur :</span> {ocrDonneesAgregees.fournisseur}</p>
                                                                )}
                                                                {ocrDonneesAgregees.montant && (
                                                                    <p><span className="font-medium">Montant :</span> {new Intl.NumberFormat('fr-FR').format(ocrDonneesAgregees.montant)} {ocrDonneesAgregees.devise || 'GNF'}</p>
                                                                )}
                                                                {ocrDonneesAgregees.date_document && (
                                                                    <p><span className="font-medium">Date :</span> {ocrDonneesAgregees.date_document}</p>
                                                                )}
                                                                {ocrDonneesAgregees.reference_document && (
                                                                    <p><span className="font-medium">Référence :</span> {ocrDonneesAgregees.reference_document}</p>
                                                                )}
                                                                {ocrDonneesAgregees.description && (
                                                                    <p className="sm:col-span-2"><span className="font-medium">Description :</span> {ocrDonneesAgregees.description}</p>
                                                                )}
                                                            </div>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                onClick={appliquerOcr}
                                                                className="bg-neemba-500 hover:bg-neemba-600 text-white"
                                                            >
                                                                <Sparkles className="mr-1.5 h-3.5 w-3.5" />
                                                                Pré-remplir le formulaire
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </motion.div>
                                            )}

                                            {ocrApplique && (
                                                <div className="flex items-center gap-2 p-3 rounded-lg bg-green-50 text-green-700 text-sm border border-green-200">
                                                    <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                                                    Les informations détectées ont été appliquées aux champs du formulaire. Vous pouvez les modifier librement.
                                                </div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </motion.div>
                            )}

                            {/* Section 5 : Contrôle automatique */}
                            {sectionActive === 4 && (
                                <motion.div
                                    key="controle"
                                    initial={{ opacity: 0, x: 20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    exit={{ opacity: 0, x: -20 }}
                                    transition={{ duration: 0.2 }}
                                >
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2">
                                                <ShieldCheck className="h-5 w-5 text-neemba-500" />
                                                Section 5 — Contrôle automatique
                                            </CardTitle>
                                            <CardDescription>
                                                Vérification des règles métier avant soumission
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            {controles.map((ctrl, i) => (
                                                <div
                                                    key={i}
                                                    className={`flex items-start gap-3 p-3 rounded-lg border ${
                                                        ctrl.erreur
                                                            ? 'bg-red-50 border-red-200'
                                                            : ctrl.ok
                                                            ? 'bg-green-50 border-green-200'
                                                            : 'bg-gray-50 border-gray-200'
                                                    }`}
                                                >
                                                    {ctrl.erreur ? (
                                                        <AlertTriangle className="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" />
                                                    ) : ctrl.ok ? (
                                                        <CheckCircle2 className="h-5 w-5 text-green-500 mt-0.5 flex-shrink-0" />
                                                    ) : (
                                                        <Info className="h-5 w-5 text-gray-400 mt-0.5 flex-shrink-0" />
                                                    )}
                                                    <div>
                                                        <p className="text-sm font-medium">{ctrl.label}</p>
                                                        <p className="text-xs text-gray-600 mt-0.5">{ctrl.detail}</p>
                                                    </div>
                                                    {ctrl.info && (
                                                        <Badge variant="secondary" className="ml-auto text-[10px] flex-shrink-0">
                                                            Info
                                                        </Badge>
                                                    )}
                                                </div>
                                            ))}

                                            <Separator className="my-4" />

                                            {/* Résumé récapitulatif */}
                                            <div className="bg-gray-50 rounded-lg p-4 space-y-2">
                                                <p className="font-medium text-sm">Récapitulatif</p>
                                                <div className="grid grid-cols-2 gap-2 text-sm">
                                                    <span className="text-gray-500">Numéro</span>
                                                    <span className="font-mono font-semibold text-right">{numero}</span>
                                                    <span className="text-gray-500">Type</span>
                                                    <span className="text-right">{data.type_bon === 'BD' ? 'Définitif' : 'Provisoire'}</span>
                                                    <span className="text-gray-500">Site / Service</span>
                                                    <span className="text-right truncate">{data.site || '-'} / {data.service || '-'}</span>
                                                    <span className="text-gray-500">Bénéficiaire</span>
                                                    <span className="text-right truncate">{data.beneficiaire || '-'}</span>
                                                    <span className="text-gray-500">Catégorie</span>
                                                    <span className="text-right">{categoriesDepense[data.categorie_depense] || '-'}</span>
                                                    <span className="text-gray-500">Montant</span>
                                                    <span className="text-right font-bold text-neemba-600">
                                                        {data.montant ? new Intl.NumberFormat('fr-FR').format(data.montant) + ' GNF' : '-'}
                                                    </span>
                                                    <span className="text-gray-500">Fichiers</span>
                                                    <span className="text-right">{fichiers.length} pièce(s)</span>
                                                </div>
                                            </div>

                                            {/* Actions finales */}
                                            <div className="flex flex-col sm:flex-row gap-3 pt-4">
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    className="flex-1"
                                                    disabled={actionEnCours !== null}
                                                >
                                                    {actionEnCours === 'brouillon' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                                                    {actionEnCours === 'brouillon' ? 'Enregistrement…' : 'Sauvegarder en brouillon'}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    className="flex-1"
                                                    disabled={actionEnCours !== null || !tousControlesOk}
                                                    onClick={(e) => handleSubmit(e, true)}
                                                >
                                                    {actionEnCours === 'soumettre' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                                                    {actionEnCours === 'soumettre' ? 'Soumission en cours…' : 'Soumettre pour validation'}
                                                </Button>
                                            </div>

                                            {!tousControlesOk && (
                                                <p className="text-xs text-red-500 text-center">
                                                    Corrigez les points de contrôle en erreur avant de soumettre.
                                                </p>
                                            )}
                                        </CardContent>
                                    </Card>
                                </motion.div>
                            )}
                        </AnimatePresence>

                        {/* Navigation entre sections */}
                        <div className="flex justify-between mt-4">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => allerSection(-1)}
                                disabled={sectionActive === 0}
                            >
                                <ArrowLeft className="mr-1 h-4 w-4" />
                                Précédent
                            </Button>
                            {sectionActive < SECTIONS.length - 1 ? (
                                <Button
                                    type="button"
                                    onClick={() => allerSection(1)}
                                >
                                    Suivant
                                    <ArrowRight className="ml-1 h-4 w-4" />
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={actionEnCours !== null}
                                    onClick={(e) => handleSubmit(e, false)}
                                >
                                    <Save className="mr-2 h-4 w-4" />
                                    Brouillon
                                </Button>
                            )}
                        </div>
                    </div>

                    {/* Colonne latérale - Résumé sticky */}
                    <div className="hidden lg:block">
                        <Card className="sticky top-24">
                            <CardHeader>
                                <CardTitle className="text-base">Résumé</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Numéro</span>
                                    <span className="font-mono font-semibold">{numero}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Type</span>
                                    <Badge variant={data.type_bon === 'BD' ? 'default' : 'secondary'}>
                                        {data.type_bon === 'BD' ? 'Définitif' : 'Provisoire'}
                                    </Badge>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Site</span>
                                    <span>{data.site || '-'}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Service</span>
                                    <span>{data.service || '-'}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Bénéficiaire</span>
                                    <span className="truncate ml-2">{data.beneficiaire || '-'}</span>
                                </div>
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Catégorie</span>
                                    <span>{categoriesDepense[data.categorie_depense] || '-'}</span>
                                </div>
                                <Separator />
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Montant</span>
                                    <span className="font-bold text-neemba-600">
                                        {data.montant
                                            ? new Intl.NumberFormat('fr-FR').format(data.montant) + ' GNF'
                                            : '-'}
                                    </span>
                                </div>
                                {necessiteDP && (
                                    <p className="text-xs text-neemba-600 bg-neemba-50 rounded p-2">
                                        Validation Directeur Pays requise
                                    </p>
                                )}
                                {estBP && (
                                    <p className="text-xs text-amber-600 bg-amber-50 rounded p-2">
                                        BP : régularisation après paiement
                                    </p>
                                )}
                                <div className="flex justify-between text-sm">
                                    <span className="text-gray-500">Fichiers</span>
                                    <span>{fichiers.length} pièce(s)</span>
                                </div>

                                <Separator />

                                {/* Actions rapides */}
                                <div className="space-y-2 pt-2">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        className="w-full"
                                        disabled={actionEnCours !== null}
                                        size="sm"
                                    >
                                        {actionEnCours === 'brouillon' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                                        {actionEnCours === 'brouillon' ? 'En cours…' : 'Brouillon'}
                                    </Button>
                                    <Button
                                        type="button"
                                        className="w-full"
                                        disabled={actionEnCours !== null || !tousControlesOk}
                                        onClick={(e) => handleSubmit(e, true)}
                                        size="sm"
                                    >
                                        {actionEnCours === 'soumettre' ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                                        {actionEnCours === 'soumettre' ? 'En cours…' : 'Soumettre'}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
