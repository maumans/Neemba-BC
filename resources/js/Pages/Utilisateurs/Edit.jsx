/**
 * Page Édition d'un Utilisateur - NEEMBA
 * 
 * Formulaire d'édition d'un compte utilisateur existant.
 * Le mot de passe est optionnel (laissé vide = pas de changement).
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

export default function Edit({ utilisateur, sites = [], services = [], roles = {}, rolesComplementaires = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        name: utilisateur.name || '',
        prenom: utilisateur.prenom || '',
        email: utilisateur.email || '',
        password: '',
        matricule: utilisateur.matricule || '',
        telephone: utilisateur.telephone || '',
        numero_om: utilisateur.numero_om || '',
        statut_cadre: utilisateur.statut_cadre || '',
        role: utilisateur.role || 'demandeur',
        roles_complementaires: rolesComplementaires,
        service: utilisateur.service || '',
        site: utilisateur.site || '',
        poste: utilisateur.poste || '',
        actif: utilisateur.actif ?? true,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('utilisateurs.update', utilisateur.id));
    };

    /* Rôles complémentaires : tous les rôles sauf le rôle principal choisi */
    const basculerRole = (role) => {
        const liste = data.roles_complementaires.includes(role)
            ? data.roles_complementaires.filter((r) => r !== role)
            : [...data.roles_complementaires, role];
        setData('roles_complementaires', liste);
    };

    return (
        <AuthenticatedLayout header={`Modifier ${utilisateur.prenom || ''} ${utilisateur.name}`}>
            <Head title={`Modifier ${utilisateur.name}`} />

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
                                <CardDescription>Informations personnelles</CardDescription>
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
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="numero_om">N° Orange Money</Label>
                                        <Input
                                            id="numero_om"
                                            value={data.numero_om}
                                            onChange={(e) => setData('numero_om', e.target.value)}
                                            placeholder="6XX XX XX XX"
                                            inputMode="numeric"
                                            className="mt-1"
                                        />
                                        {errors.numero_om && <p className="text-sm text-red-500 mt-1">{errors.numero_om}</p>}
                                    </div>
                                    <div>
                                        <Label>Statut</Label>
                                        <Select
                                            value={data.statut_cadre || 'non_renseigne'}
                                            onValueChange={(val) => setData('statut_cadre', val === 'non_renseigne' ? '' : val)}
                                        >
                                            <SelectTrigger className="mt-1">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="non_renseigne">Non renseigné</SelectItem>
                                                <SelectItem value="cadre">Cadre</SelectItem>
                                                <SelectItem value="non_cadre">Non-cadre</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <p className="text-xs text-gray-500 mt-1">Renseigné par les RH ; obligatoire pour un ordre de mission à l'étranger.</p>
                                        {errors.statut_cadre && <p className="text-sm text-red-500 mt-1">{errors.statut_cadre}</p>}
                                    </div>
                                </div>

                                {/* Mot de passe optionnel */}
                                <div>
                                    <Label htmlFor="password">
                                        Nouveau mot de passe
                                        <span className="text-gray-400 text-xs ml-1">(laisser vide pour ne pas modifier)</span>
                                    </Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="mt-1"
                                    />
                                    {errors.password && <p className="text-sm text-red-500 mt-1">{errors.password}</p>}
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
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="matricule">Matricule</Label>
                                        <Input
                                            id="matricule"
                                            value={data.matricule}
                                            onChange={(e) => setData('matricule', e.target.value)}
                                            className="mt-1"
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
                                            className="mt-1"
                                        />
                                    </div>
                                </div>

                                <fieldset>
                                    <legend className="text-sm font-medium">Rôles complémentaires</legend>
                                    <p className="text-xs text-gray-500 mt-0.5">
                                        En plus du rôle principal (chef d'atelier, DP adjoint, Trésorerie…). Toute modification est soumise à une double validation.
                                    </p>
                                    <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                                        {Object.entries(roles).filter(([role]) => role !== data.role).map(([role, libelle]) => (
                                            <label key={role} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-gray-50">
                                                <input
                                                    type="checkbox"
                                                    className="h-4 w-4 rounded border-gray-300 text-neemba-600 focus:ring-neemba-500"
                                                    checked={data.roles_complementaires.includes(role)}
                                                    onChange={() => basculerRole(role)}
                                                />
                                                {libelle}
                                            </label>
                                        ))}
                                    </div>
                                    {errors.roles_complementaires && <p className="text-sm text-red-500 mt-1">{errors.roles_complementaires}</p>}
                                </fieldset>
                            </CardContent>
                        </Card>
                    </motion.div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            <Save className="mr-2 h-4 w-4" />
                            Enregistrer les modifications
                        </Button>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
