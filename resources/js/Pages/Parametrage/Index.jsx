/**
 * Page Paramétrage - NEEMBA
 * 
 * Gestion des tables de référence par l'administrateur (DAF/Directeur Pays).
 * Permet d'ajouter, modifier et activer/désactiver les données de référence :
 * Sites, Services, Codes analytiques, Types de document.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { motion } from 'framer-motion';
import { Plus, Pencil, ToggleLeft, ToggleRight, Settings2, MapPin, Building2, Hash, FileText, SlidersHorizontal, Save, Check, Wallet, Briefcase, AlertTriangle } from 'lucide-react';
import { formaterNombre } from '@/utils/nombreEnLettres';
import { Switch } from '@/Components/ui/switch';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/Components/ui/dialog';

/* ─── Composant générique pour une table de paramétrage ─── */
function ParametrageTable({ items, columns, onAdd, onEdit, onToggle, addLabel }) {
    const [itemToToggle, setItemToToggle] = useState(null);

    return (
        <div className="space-y-4">
            <div className="flex justify-between items-center">
                <p className="text-sm text-gray-500">{items.length} élément(s)</p>
                <Button size="sm" onClick={onAdd}>
                    <Plus className="mr-1 h-4 w-4" />
                    {addLabel}
                </Button>
            </div>

            <div className="border rounded-lg overflow-hidden">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 border-b">
                        <tr>
                            {columns.map((col) => (
                                <th key={col.key} className="px-4 py-2 text-left font-medium text-gray-600">
                                    {col.label}
                                </th>
                            ))}
                            <th className="px-4 py-2 text-left font-medium text-gray-600">Statut</th>
                            <th className="px-4 py-2 text-right font-medium text-gray-600">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {items.map((item) => (
                            <tr key={item.id} className={!item.actif ? 'bg-gray-50 opacity-60' : ''}>
                                {columns.map((col) => (
                                    <td key={col.key} className="px-4 py-2">
                                        {item[col.key] || '-'}
                                    </td>
                                ))}
                                <td className="px-4 py-2">
                                    <Badge variant={item.actif ? 'default' : 'secondary'}>
                                        {item.actif ? 'Actif' : 'Inactif'}
                                    </Badge>
                                </td>
                                <td className="px-4 py-2 text-right space-x-1">
                                    <Button variant="ghost" size="sm" onClick={() => onEdit(item)}>
                                        <Pencil className="h-3.5 w-3.5" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setItemToToggle(item)}
                                        title={item.actif ? 'Désactiver' : 'Activer'}
                                    >
                                        {item.actif
                                            ? <ToggleRight className="h-4 w-4 text-green-600" />
                                            : <ToggleLeft className="h-4 w-4 text-gray-400" />
                                        }
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {items.length === 0 && (
                            <tr>
                                <td colSpan={columns.length + 2} className="px-4 py-8 text-center text-gray-400">
                                    Aucun élément. Cliquez sur « {addLabel} » pour commencer.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <Dialog open={!!itemToToggle} onOpenChange={(open) => !open && setItemToToggle(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{itemToToggle?.actif ? 'Désactiver' : 'Activer'} cet élément ?</DialogTitle>
                        <DialogDescription>
                            Êtes-vous sûr de vouloir {itemToToggle?.actif ? 'désactiver' : 'activer'} cet élément ? 
                            {itemToToggle?.actif && " Ceci pourrait impacter les futures sélections dans les formulaires."}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setItemToToggle(null)}>Annuler</Button>
                        <Button 
                            variant={itemToToggle?.actif ? "destructive" : "default"} 
                            onClick={() => { onToggle(itemToToggle); setItemToToggle(null); }}
                            className={!itemToToggle?.actif ? "bg-green-600 hover:bg-green-700 text-white" : ""}
                        >
                            Confirmer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/* ─── Dialogue de formulaire générique ─── */
function FormDialog({ open, onClose, title, children, onSubmit, processing }) {
    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>
                <form onSubmit={onSubmit}>
                    <div className="space-y-4 py-4">{children}</div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>Annuler</Button>
                        <Button type="submit" disabled={processing}>Enregistrer</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ─── Composant pour un paramètre éditable inline ─── */
function ParametreRow({ parametre }) {
    const form = useForm({ valeur: parametre.valeur });
    const [editing, setEditing] = useState(false);
    const [confirmToggle, setConfirmToggle] = useState(false);

    const estBoolean = parametre.type === 'boolean';
    const estActif = form.data.valeur === '1' || form.data.valeur === 'true' || form.data.valeur === true;

    const submit = (e) => {
        e?.preventDefault?.();
        form.put(route('parametrage.parametres.update', parametre.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    /** Toggle boolean et sauvegarder immédiatement */
    const confirmToggleBoolean = () => {
        const nouvelleValeur = estActif ? '0' : '1';
        form.setData('valeur', nouvelleValeur);
        router.put(route('parametrage.parametres.update', parametre.id), {
            valeur: nouvelleValeur,
        }, { preserveScroll: true, onFinish: () => setConfirmToggle(false) });
    };

    const renderValeur = (val, type) => {
        if (type === 'number' && !isNaN(val)) {
            return <span className="text-sm font-mono bg-gray-100 px-2 py-1 rounded">{formaterNombre(val)}</span>;
        }
        if (type === 'boolean') {
            return <span className="text-sm font-mono bg-gray-100 px-2 py-1 rounded">{val === '1' || val === 'true' || val === true ? 'Oui' : 'Non'}</span>;
        }

        // Essayer d'afficher les tableaux JSON sous forme de badges
        if (typeof val === 'string' && val.startsWith('[') && val.endsWith(']')) {
            try {
                const parsed = JSON.parse(val);
                if (Array.isArray(parsed)) {
                    return (
                        <div className="flex flex-wrap gap-1 justify-end">
                            {parsed.map((item, idx) => (
                                <Badge key={idx} variant="secondary" className="text-[10px] font-normal leading-tight px-1.5 py-0.5">
                                    {item}
                                </Badge>
                            ))}
                        </div>
                    );
                }
            } catch (e) {
                // Ignore JSON parse err
            }
        }

        return (
            <span className="text-xs font-mono bg-gray-100 px-2 py-1 rounded max-w-sm break-words whitespace-pre-wrap text-right inline-block">
                {val}
            </span>
        );
    };

    return (
        <div className="flex items-start justify-between p-3 border rounded-lg hover:bg-gray-50 transition-colors">
            <div className="flex-1 min-w-0 mr-4 pt-1">
                <p className="text-sm font-medium text-gray-900">{parametre.libelle}</p>
                {parametre.description && (
                    <p className="text-xs text-gray-500 mt-0.5 max-w-lg">{parametre.description}</p>
                )}
            </div>

            {/* Boolean : Switch toggle au lieu d'un input texte */}
            {estBoolean ? (
                <div className="flex items-center gap-2">
                    <span className={`text-xs font-medium ${estActif ? 'text-green-600' : 'text-gray-400'}`}>
                        {estActif ? 'Oui' : 'Non'}
                    </span>
                    <Switch
                        checked={estActif}
                        onCheckedChange={() => setConfirmToggle(true)}
                        disabled={form.processing}
                    />
                </div>
            ) : editing ? (
                <form onSubmit={submit} className="flex items-start gap-2 max-w-[60%]">
                    {parametre.valeur && parametre.valeur.length > 50 ? (
                        <textarea
                            value={form.data.valeur}
                            onChange={(e) => form.setData('valeur', e.target.value)}
                            className="w-64 text-xs font-mono p-2 border rounded-md"
                            rows={4}
                        />
                    ) : (
                        <Input
                            value={form.data.valeur}
                            onChange={(e) => form.setData('valeur', e.target.value)}
                            className="w-48 h-8 text-sm"
                            type={parametre.type === 'number' ? 'number' : 'text'}
                        />
                    )}
                    <Button type="submit" size="sm" variant="ghost" disabled={form.processing}>
                        <Check className="h-4 w-4 text-green-600" />
                    </Button>
                    <Button type="button" size="sm" variant="ghost" onClick={() => { setEditing(false); form.setData('valeur', parametre.valeur); }}>
                        &times;
                    </Button>
                </form>
            ) : (
                <div className="flex items-start gap-2 max-w-[50%] justify-end">
                    {renderValeur(parametre.valeur, parametre.type)}
                    <Button variant="ghost" size="sm" onClick={() => setEditing(true)} className="flex-shrink-0 -mt-1 h-8 w-8 p-0">
                        <Pencil className="h-3.5 w-3.5" />
                    </Button>
                </div>
            )}

            <Dialog open={confirmToggle} onOpenChange={setConfirmToggle}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{estActif ? 'Désactiver' : 'Activer'} ce paramètre ?</DialogTitle>
                        <DialogDescription>
                            Êtes-vous sûr de vouloir {estActif ? 'désactiver' : 'activer'} ce paramètre système ?
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmToggle(false)}>Annuler</Button>
                        <Button 
                            variant={estActif ? "destructive" : "default"} 
                            onClick={confirmToggleBoolean}
                            className={!estActif ? "bg-green-600 hover:bg-green-700 text-white" : ""}
                        >
                            Confirmer
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function Index({ sites, services, codesAnalytiques, typesDocument, motifsUrgence = [], parametres = [] }) {
    /* ─── SITES ─── */
    const [siteDialog, setSiteDialog] = useState({ open: false, item: null });
    const siteForm = useForm({ code: '', nom: '', ville: '', adresse: '', solde_caisse: '', plafond_caisse: '', seuil_minimum_caisse: '' });

    const openSiteAdd = () => {
        siteForm.reset();
        setSiteDialog({ open: true, item: null });
    };
    const openSiteEdit = (item) => {
        siteForm.setData({ code: item.code || '', nom: item.nom, ville: item.ville || '', adresse: item.adresse || '', solde_caisse: item.solde_caisse ?? '', plafond_caisse: item.plafond_caisse ?? '', seuil_minimum_caisse: item.seuil_minimum_caisse ?? '' });
        setSiteDialog({ open: true, item });
    };
    const submitSite = (e) => {
        e.preventDefault();
        if (siteDialog.item) {
            siteForm.put(route('parametrage.sites.update', siteDialog.item.id), {
                onSuccess: () => setSiteDialog({ open: false, item: null }),
            });
        } else {
            siteForm.post(route('parametrage.sites.store'), {
                onSuccess: () => setSiteDialog({ open: false, item: null }),
            });
        }
    };
    const toggleSite = (item) => router.post(route('parametrage.sites.toggle', item.id));

    /* ─── SERVICES ─── */
    const [serviceDialog, setServiceDialog] = useState({ open: false, item: null });
    const serviceForm = useForm({ nom: '', code: '' });

    const openServiceAdd = () => {
        serviceForm.reset();
        setServiceDialog({ open: true, item: null });
    };
    const openServiceEdit = (item) => {
        serviceForm.setData({ nom: item.nom, code: item.code || '' });
        setServiceDialog({ open: true, item });
    };
    const submitService = (e) => {
        e.preventDefault();
        if (serviceDialog.item) {
            serviceForm.put(route('parametrage.services.update', serviceDialog.item.id), {
                onSuccess: () => setServiceDialog({ open: false, item: null }),
            });
        } else {
            serviceForm.post(route('parametrage.services.store'), {
                onSuccess: () => setServiceDialog({ open: false, item: null }),
            });
        }
    };
    const toggleService = (item) => router.post(route('parametrage.services.toggle', item.id));

    /* ─── CODES ANALYTIQUES ─── */
    const [codeDialog, setCodeDialog] = useState({ open: false, item: null });
    const codeForm = useForm({ code: '', libelle: '', service_id: '' });

    const codesAnalytiquesFormat = codesAnalytiques.map(c => ({
        ...c,
        service_code: c.service?.code || '',
        service_nom: c.service?.nom || ''
    }));

    const openCodeAdd = () => {
        codeForm.reset();
        setCodeDialog({ open: true, item: null });
    };
    const openCodeEdit = (item) => {
        codeForm.setData({ code: item.code, libelle: item.libelle, service_id: item.service_id || '' });
        setCodeDialog({ open: true, item });
    };
    const submitCode = (e) => {
        e.preventDefault();
        if (codeDialog.item) {
            codeForm.put(route('parametrage.codes-analytiques.update', codeDialog.item.id), {
                onSuccess: () => setCodeDialog({ open: false, item: null }),
            });
        } else {
            codeForm.post(route('parametrage.codes-analytiques.store'), {
                onSuccess: () => setCodeDialog({ open: false, item: null }),
            });
        }
    };
    const toggleCode = (item) => router.post(route('parametrage.codes-analytiques.toggle', item.id));

    /* ─── TYPES DOCUMENT ─── */
    const [typeDialog, setTypeDialog] = useState({ open: false, item: null });
    const typeForm = useForm({ nom: '' });

    const openTypeAdd = () => {
        typeForm.reset();
        setTypeDialog({ open: true, item: null });
    };
    const openTypeEdit = (item) => {
        typeForm.setData({ nom: item.nom });
        setTypeDialog({ open: true, item });
    };
    const submitType = (e) => {
        e.preventDefault();
        if (typeDialog.item) {
            typeForm.put(route('parametrage.types-document.update', typeDialog.item.id), {
                onSuccess: () => setTypeDialog({ open: false, item: null }),
            });
        } else {
            typeForm.post(route('parametrage.types-document.store'), {
                onSuccess: () => setTypeDialog({ open: false, item: null }),
            });
        }
    };
    const toggleType = (item) => router.post(route('parametrage.types-document.toggle', item.id));

    /* ─── MOTIFS D'URGENCE ─── */
    const [motifDialog, setMotifDialog] = useState({ open: false, item: null });
    const motifForm = useForm({ libelle: '' });

    const openMotifAdd = () => {
        motifForm.reset();
        setMotifDialog({ open: true, item: null });
    };
    const openMotifEdit = (item) => {
        motifForm.setData({ libelle: item.libelle });
        setMotifDialog({ open: true, item });
    };
    const submitMotif = (e) => {
        e.preventDefault();
        if (motifDialog.item) {
            motifForm.put(route('parametrage.motifs-urgence.update', motifDialog.item.id), {
                onSuccess: () => setMotifDialog({ open: false, item: null }),
            });
        } else {
            motifForm.post(route('parametrage.motifs-urgence.store'), {
                onSuccess: () => setMotifDialog({ open: false, item: null }),
            });
        }
    };
    const toggleMotif = (item) => router.post(route('parametrage.motifs-urgence.toggle', item.id));

    return (
        <AuthenticatedLayout header="Paramétrage">
            <Head title="Paramétrage" />

            <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Settings2 className="h-5 w-5 text-neemba-500" />
                            Tables de référence
                        </CardTitle>
                        <CardDescription>
                            Gérez les données de référence utilisées dans les formulaires de l'application.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Tabs defaultValue="sites">
                            <TabsList className="flex flex-wrap h-auto w-full justify-start gap-1 mb-4 p-1">
                                <TabsTrigger value="sites" className="gap-1 px-2 text-xs sm:text-sm">
                                    <MapPin className="h-3.5 w-3.5" /> Sites
                                </TabsTrigger>
                                <TabsTrigger value="services" className="gap-1 px-2 text-xs sm:text-sm">
                                    <Building2 className="h-3.5 w-3.5" /> Services
                                </TabsTrigger>
                                <TabsTrigger value="codes" className="gap-1 px-2 text-xs sm:text-sm">
                                    <Hash className="h-3.5 w-3.5" /> Codes analytiques
                                </TabsTrigger>
                                <TabsTrigger value="types" className="gap-1 px-2 text-xs sm:text-sm">
                                    <FileText className="h-3.5 w-3.5" /> Types document
                                </TabsTrigger>
                                <TabsTrigger value="motifs" className="gap-1 px-2 text-xs sm:text-sm">
                                    <AlertTriangle className="h-3.5 w-3.5" /> Motifs d'urgence
                                </TabsTrigger>
                                <TabsTrigger value="parametres" className="gap-1 px-2 text-xs sm:text-sm">
                                    <SlidersHorizontal className="h-3.5 w-3.5" /> Seuils
                                </TabsTrigger>
                            </TabsList>

                            {/* Sites */}
                            <TabsContent value="sites" className="mt-4">
                                <ParametrageTable
                                    items={sites}
                                    columns={[
                                        { key: 'code', label: 'Code Site' },
                                        { key: 'nom', label: 'Nom du site' },
                                        { key: 'ville', label: 'Ville' },
                                        { key: 'solde_caisse_format', label: 'Solde caisse' },
                                        { key: 'plafond_caisse_format', label: 'Plafond' },
                                    ]}
                                    onAdd={openSiteAdd}
                                    onEdit={openSiteEdit}
                                    onToggle={toggleSite}
                                    addLabel="Ajouter un site"
                                />
                            </TabsContent>

                            {/* Services */}
                            <TabsContent value="services" className="mt-4">
                                <ParametrageTable
                                    items={services}
                                    columns={[
                                        { key: 'nom', label: 'Nom du service' },
                                        { key: 'code', label: 'Code' },
                                    ]}
                                    onAdd={openServiceAdd}
                                    onEdit={openServiceEdit}
                                    onToggle={toggleService}
                                    addLabel="Ajouter un service"
                                />
                            </TabsContent>

                            {/* Codes analytiques */}
                            <TabsContent value="codes" className="mt-4">
                                <ParametrageTable
                                    items={codesAnalytiquesFormat}
                                    columns={[
                                        { key: 'code', label: 'Compte' },
                                        { key: 'libelle', label: 'Libellé' },
                                        { key: 'service_code', label: 'Code Service' },
                                        { key: 'service_nom', label: 'Service / Business Unit' },
                                    ]}
                                    onAdd={openCodeAdd}
                                    onEdit={openCodeEdit}
                                    onToggle={toggleCode}
                                    addLabel="Ajouter un code"
                                />
                            </TabsContent>

                            {/* Types document */}
                            <TabsContent value="types" className="mt-4">
                                <ParametrageTable
                                    items={typesDocument}
                                    columns={[
                                        { key: 'nom', label: 'Nom du type' },
                                    ]}
                                    onAdd={openTypeAdd}
                                    onEdit={openTypeEdit}
                                    onToggle={toggleType}
                                    addLabel="Ajouter un type"
                                />
                            </TabsContent>

                            {/* Motifs d'urgence */}
                            <TabsContent value="motifs" className="mt-4">
                                <div className="mb-4 p-4 border border-orange-200 bg-orange-50/50 rounded-lg">
                                    <p className="text-sm text-gray-700">
                                        Gérez ici les choix possibles pour les motifs justifiant une demande "urgente" ou "très urgente". Ces motifs alimentent le menu déroulant sur le formulaire de création de bons de caisse.
                                    </p>
                                </div>
                                <ParametrageTable
                                    items={motifsUrgence}
                                    columns={[
                                        { key: 'libelle', label: 'Libellé du motif' },
                                    ]}
                                    onAdd={openMotifAdd}
                                    onEdit={openMotifEdit}
                                    onToggle={toggleMotif}
                                    addLabel="Ajouter un motif"
                                />
                            </TabsContent>

                            {/* Paramètres système */}
                            <TabsContent value="parametres" className="mt-4">
                                <div className="space-y-4">
                                    <p className="text-sm text-gray-500">
                                        Configurez les seuils et limites métier de l'application.
                                    </p>

                                    {/* Grouper par groupe */}
                                    {Object.entries(
                                        parametres.reduce((acc, p) => {
                                            const g = p.groupe || 'general';
                                            if (!acc[g]) acc[g] = [];
                                            acc[g].push(p);
                                            return acc;
                                        }, {})
                                    ).map(([groupe, params]) => (
                                        <div key={groupe}>
                                            <h4 className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">
                                                {groupe === 'seuils' ? 'Seuils de montant' :
                                                 groupe === 'delais' ? 'Délais' :
                                                 groupe === 'fichiers' ? 'Fichiers' : 'Général'}
                                            </h4>
                                            <div className="space-y-2">
                                                {params.map((p) => (
                                                    <ParametreRow key={p.id} parametre={p} />
                                                ))}
                                            </div>
                                        </div>
                                    ))}

                                    {parametres.length === 0 && (
                                        <p className="text-center text-gray-400 py-8">
                                            Aucun paramètre configurable trouvé. Exécutez la migration.
                                        </p>
                                    )}
                                </div>
                            </TabsContent>
                        </Tabs>
                    </CardContent>
                </Card>
            </motion.div>

            {/* ─── Dialogues de formulaire ─── */}

            {/* Site */}
            <FormDialog
                open={siteDialog.open}
                onClose={() => setSiteDialog({ open: false, item: null })}
                title={siteDialog.item ? 'Modifier le site' : 'Ajouter un site'}
                onSubmit={submitSite}
                processing={siteForm.processing}
            >
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <Label>Code Site</Label>
                        <Input value={siteForm.data.code} onChange={(e) => siteForm.setData('code', e.target.value)} placeholder="Ex: 01, 31" className="mt-1" />
                        {siteForm.errors.code && <p className="text-sm text-red-500 mt-1">{siteForm.errors.code}</p>}
                    </div>
                    <div>
                        <Label>Nom du site *</Label>
                        <Input value={siteForm.data.nom} onChange={(e) => siteForm.setData('nom', e.target.value)} className="mt-1" required />
                        {siteForm.errors.nom && <p className="text-sm text-red-500 mt-1">{siteForm.errors.nom}</p>}
                    </div>
                </div>
                <div>
                    <Label>Ville</Label>
                    <Input value={siteForm.data.ville} onChange={(e) => siteForm.setData('ville', e.target.value)} className="mt-1" />
                </div>
                <div>
                    <Label>Adresse</Label>
                    <Input value={siteForm.data.adresse} onChange={(e) => siteForm.setData('adresse', e.target.value)} className="mt-1" />
                </div>
                <div className="pt-2 border-t">
                    <p className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3 flex items-center gap-1">
                        <Wallet className="h-3.5 w-3.5" /> Paramètres de caisse
                    </p>
                    <div className="space-y-3">
                        <div>
                            <Label>Solde de caisse actuel (GNF)</Label>
                            <Input type="number" value={siteForm.data.solde_caisse} onChange={(e) => siteForm.setData('solde_caisse', e.target.value)} className="mt-1" placeholder="0" />
                            {siteForm.errors.solde_caisse && <p className="text-sm text-red-500 mt-1">{siteForm.errors.solde_caisse}</p>}
                        </div>
                        <div>
                            <Label>Plafond de caisse (GNF)</Label>
                            <Input type="number" value={siteForm.data.plafond_caisse} onChange={(e) => siteForm.setData('plafond_caisse', e.target.value)} className="mt-1" placeholder="Ex: 50000000" />
                            {siteForm.errors.plafond_caisse && <p className="text-sm text-red-500 mt-1">{siteForm.errors.plafond_caisse}</p>}
                        </div>
                        <div>
                            <Label>Seuil minimum d'alerte (GNF)</Label>
                            <Input type="number" value={siteForm.data.seuil_minimum_caisse} onChange={(e) => siteForm.setData('seuil_minimum_caisse', e.target.value)} className="mt-1" placeholder="Ex: 5000000" />
                            {siteForm.errors.seuil_minimum_caisse && <p className="text-sm text-red-500 mt-1">{siteForm.errors.seuil_minimum_caisse}</p>}
                        </div>
                    </div>
                </div>
            </FormDialog>

            {/* Service */}
            <FormDialog
                open={serviceDialog.open}
                onClose={() => setServiceDialog({ open: false, item: null })}
                title={serviceDialog.item ? 'Modifier le service' : 'Ajouter un service'}
                onSubmit={submitService}
                processing={serviceForm.processing}
            >
                <div>
                    <Label>Nom du service *</Label>
                    <Input value={serviceForm.data.nom} onChange={(e) => serviceForm.setData('nom', e.target.value)} className="mt-1" required />
                    {serviceForm.errors.nom && <p className="text-sm text-red-500 mt-1">{serviceForm.errors.nom}</p>}
                </div>
                <div>
                    <Label>Code</Label>
                    <Input value={serviceForm.data.code} onChange={(e) => serviceForm.setData('code', e.target.value)} placeholder="Ex: IT, FIN, RH" className="mt-1" />
                    {serviceForm.errors.code && <p className="text-sm text-red-500 mt-1">{serviceForm.errors.code}</p>}
                </div>
            </FormDialog>

            {/* Code analytique */}
            <FormDialog
                open={codeDialog.open}
                onClose={() => setCodeDialog({ open: false, item: null })}
                title={codeDialog.item ? 'Modifier le code analytique' : 'Ajouter un code analytique'}
                onSubmit={submitCode}
                processing={codeForm.processing}
            >
                <div>
                    <Label>Code *</Label>
                    <Input value={codeForm.data.code} onChange={(e) => codeForm.setData('code', e.target.value)} placeholder="Ex: ANA-001" className="mt-1" required />
                    {codeForm.errors.code && <p className="text-sm text-red-500 mt-1">{codeForm.errors.code}</p>}
                </div>
                <div>
                    <Label>Libellé *</Label>
                    <Input value={codeForm.data.libelle} onChange={(e) => codeForm.setData('libelle', e.target.value)} placeholder="Ex: Fournitures de bureau" className="mt-1" required />
                    {codeForm.errors.libelle && <p className="text-sm text-red-500 mt-1">{codeForm.errors.libelle}</p>}
                </div>
                <div>
                    <Label className="flex items-center gap-1"><Briefcase className="h-3.5 w-3.5" /> Service d'appartenance</Label>
                    <select
                        value={codeForm.data.service_id}
                        onChange={(e) => codeForm.setData('service_id', e.target.value)}
                        className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-neemba-500 focus:border-neemba-500 sm:text-sm rounded-md"
                    >
                        <option value="">-- Aucun --</option>
                        {services.map((service) => (
                            <option key={service.id} value={service.id}>
                                {service.nom} {service.code ? `(${service.code})` : ''}
                            </option>
                        ))}
                    </select>
                    {codeForm.errors.service_id && <p className="text-sm text-red-500 mt-1">{codeForm.errors.service_id}</p>}
                </div>
            </FormDialog>

            {/* Type document */}
            <FormDialog
                open={typeDialog.open}
                onClose={() => setTypeDialog({ open: false, item: null })}
                title={typeDialog.item ? 'Modifier le type de document' : 'Ajouter un type de document'}
                onSubmit={submitType}
                processing={typeForm.processing}
            >
                <div>
                    <Label>Nom du type *</Label>
                    <Input value={typeForm.data.nom} onChange={(e) => typeForm.setData('nom', e.target.value)} placeholder="Ex: Facture, Devis..." className="mt-1" required />
                    {typeForm.errors.nom && <p className="text-sm text-red-500 mt-1">{typeForm.errors.nom}</p>}
                </div>
            </FormDialog>

            {/* Motif d'urgence */}
            <FormDialog
                open={motifDialog.open}
                onClose={() => setMotifDialog({ open: false, item: null })}
                title={motifDialog.item ? "Modifier le motif d'urgence" : "Ajouter un motif d'urgence"}
                onSubmit={submitMotif}
                processing={motifForm.processing}
            >
                <div>
                    <Label>Libellé du motif *</Label>
                    <Input value={motifForm.data.libelle} onChange={(e) => motifForm.setData('libelle', e.target.value)} placeholder="Ex: Panne machine..." className="mt-1" required />
                    {motifForm.errors.libelle && <p className="text-sm text-red-500 mt-1">{motifForm.errors.libelle}</p>}
                </div>
            </FormDialog>
        </AuthenticatedLayout>
    );
}
