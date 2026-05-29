/**
 * Page Création d'un Utilisateur - NEEMBA
 * 
 * Formulaire complet pour créer un nouveau compte utilisateur.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowLeft, Save } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Combobox } from '@/Components/ui/combobox';

export default function Create({ sites = [], services = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        prenom: '',
        email: '',
        password: '',
        password_confirmation: '',
        matricule: '',
        telephone: '',
        role: 'demandeur',
        service: '',
        site: '',
        poste: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('utilisateurs.store'));
    };

    return (
        <AuthenticatedLayout header="Nouvel Utilisateur">
            <Head title="Nouvel Utilisateur" />

            <div className="mb-6">
                <Link
                    href={route('utilisateurs.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour à la liste
                </Link>
            </div>

            <form onSubmit={handleSubmit}>
                <div className="max-w-3xl space-y-6">
                    {/* Identité */}
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>Identité</CardTitle>
                                <CardDescription>Informations personnelles de l'utilisateur</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="prenom">Prénom *</Label>
                                        <Input
                                            id="prenom"
                                            value={data.prenom}
                                            onChange={(e) => setData('prenom', e.target.value)}
                                            className="mt-1"
                                            required
                                        />
                                        {errors.prenom && <p className="text-sm text-red-500 mt-1">{errors.prenom}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="name">Nom *</Label>
                                        <Input
                                            id="name"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className="mt-1"
                                            required
                                        />
                                        {errors.name && <p className="text-sm text-red-500 mt-1">{errors.name}</p>}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="email">Email *</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            className="mt-1"
                                            required
                                        />
                                        {errors.email && <p className="text-sm text-red-500 mt-1">{errors.email}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="telephone">Téléphone</Label>
                                        <Input
                                            id="telephone"
                                            value={data.telephone}
                                            onChange={(e) => setData('telephone', e.target.value)}
                                            placeholder="+224 XXX XXX XXX"
                                            className="mt-1"
                                        />
                                        {errors.telephone && <p className="text-sm text-red-500 mt-1">{errors.telephone}</p>}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="password">Mot de passe *</Label>
                                        <Input
                                            id="password"
                                            type="password"
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            className="mt-1"
                                            required
                                        />
                                        {errors.password && <p className="text-sm text-red-500 mt-1">{errors.password}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="password_confirmation">Confirmer le mot de passe *</Label>
                                        <Input
                                            id="password_confirmation"
                                            type="password"
                                            value={data.password_confirmation}
                                            onChange={(e) => setData('password_confirmation', e.target.value)}
                                            className="mt-1"
                                            required
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Informations professionnelles */}
                    <motion.div
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: 0.1 }}
                    >
                        <Card>
                            <CardHeader>
                                <CardTitle>Informations professionnelles</CardTitle>
                                <CardDescription>Rôle et affectation dans l'organisation</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="matricule">Matricule *</Label>
                                        <Input
                                            id="matricule"
                                            value={data.matricule}
                                            onChange={(e) => setData('matricule', e.target.value)}
                                            placeholder="Ex: NMB-001"
                                            className="mt-1"
                                            required
                                        />
                                        {errors.matricule && <p className="text-sm text-red-500 mt-1">{errors.matricule}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="role">Rôle *</Label>
                                        <Select
                                            value={data.role}
                                            onValueChange={(val) => setData('role', val)}
                                        >
                                            <SelectTrigger className="mt-1">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="demandeur">Demandeur</SelectItem>
                                                <SelectItem value="responsable_service">Responsable Service</SelectItem>
                                                <SelectItem value="controle_gestion">Contrôle de Gestion</SelectItem>
                                                <SelectItem value="daf">DAF</SelectItem>
                                                <SelectItem value="directeur_pays">Directeur Pays</SelectItem>
                                                <SelectItem value="caissier">Caissier</SelectItem>
                                                <SelectItem value="administrateur">Administrateur</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {errors.role && <p className="text-sm text-red-500 mt-1">{errors.role}</p>}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div>
                                        <Label>Service *</Label>
                                        <Combobox
                                            options={services}
                                            value={data.service}
                                            onChange={(val) => setData('service', val)}
                                            placeholder="Sélectionner un service"
                                            searchPlaceholder="Rechercher un service..."
                                            className="mt-1"
                                            error={errors.service}
                                        />
                                        {errors.service && <p className="text-sm text-red-500 mt-1">{errors.service}</p>}
                                    </div>
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
                                        {errors.site && <p className="text-sm text-red-500 mt-1">{errors.site}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="poste">Poste</Label>
                                        <Input
                                            id="poste"
                                            value={data.poste}
                                            onChange={(e) => setData('poste', e.target.value)}
                                            placeholder="Ex: Développeur"
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Bouton de soumission */}
                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            <Save className="mr-2 h-4 w-4" />
                            Créer l'utilisateur
                        </Button>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
