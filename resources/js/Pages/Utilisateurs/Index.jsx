/**
 * Page Liste des Utilisateurs - NEEMBA
 * 
 * Permet aux administrateurs (DAF, Directeur Pays) de gérer les comptes utilisateurs.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { motion } from 'framer-motion';
import { Plus, Search, Users, Edit, ToggleLeft, ToggleRight } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';

/** Labels des rôles */
const rolesLabels = {
    demandeur: 'Demandeur',
    responsable_service: 'Chef de Service',
    controle_gestion: 'Contrôle de Gestion',
    daf: 'DAF',
    directeur_pays: 'Directeur Pays',
    caissier: 'Caissier',
};

export default function Index({ utilisateurs, filtres = {}, roles = {} }) {
    const [recherche, setRecherche] = useState(filtres.recherche || '');

    /** Appliquer les filtres */
    const filtrer = (params) => {
        router.get(route('utilisateurs.index'), {
            ...filtres,
            ...params,
            page: 1,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleRecherche = (e) => {
        e.preventDefault();
        filtrer({ recherche });
    };

    /** Activer/Désactiver un utilisateur */
    const toggleActif = (utilisateurId) => {
        router.post(route('utilisateurs.toggle-actif', utilisateurId), {}, {
            preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout header="Gestion des Utilisateurs">
            <Head title="Utilisateurs" />

            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-5">
                <div className="min-w-0">
                    <h1 className="text-xl sm:text-2xl font-bold text-gray-900">Utilisateurs</h1>
                    <p className="text-gray-500 text-xs sm:text-sm mt-0.5">
                        Gérez les comptes utilisateurs de l'application
                    </p>
                </div>
                <Link href={route('utilisateurs.create')} className="flex-shrink-0">
                    <Button size="sm" className="sm:size-default">
                        <Plus className="mr-1.5 h-4 w-4" />
                        Nouvel Utilisateur
                    </Button>
                </Link>
            </div>

            {/* Filtres */}
            <Card className="mb-5">
                <CardContent className="p-3 sm:p-4">
                    <div className="flex flex-col sm:flex-row gap-2 sm:gap-3">
                        <form onSubmit={handleRecherche} className="flex-1 flex gap-2">
                            <div className="relative flex-1">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                                <Input
                                    placeholder="Rechercher par nom, email, matricule..."
                                    value={recherche}
                                    onChange={(e) => setRecherche(e.target.value)}
                                    className="pl-9"
                                />
                            </div>
                            <Button type="submit" variant="outline">
                                <Search className="h-4 w-4" />
                            </Button>
                        </form>

                        <Select
                            value={filtres.role || 'tous'}
                            onValueChange={(val) => filtrer({ role: val === 'tous' ? '' : val })}
                        >
                            <SelectTrigger className="w-full sm:w-[200px]">
                                <SelectValue placeholder="Tous les rôles" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="tous">Tous les rôles</SelectItem>
                                {Object.entries(roles).map(([key, label]) => (
                                    <SelectItem key={key} value={key}>{label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            {/* Tableau */}
            <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
            >
                <Card>
                    <CardContent className="p-0">
                        {utilisateurs.data.length === 0 ? (
                            <div className="text-center py-16">
                                <Users className="h-12 w-12 mx-auto text-gray-300 mb-3" />
                                <p className="text-gray-500 font-medium">Aucun utilisateur trouvé</p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="text-xs">Nom</TableHead>
                                            <TableHead className="text-xs hidden md:table-cell">Email</TableHead>
                                            <TableHead className="text-xs hidden lg:table-cell">Matricule</TableHead>
                                            <TableHead className="text-xs">Rôle</TableHead>
                                            <TableHead className="text-xs hidden lg:table-cell">Service</TableHead>
                                            <TableHead className="text-xs hidden md:table-cell">Site</TableHead>
                                            <TableHead className="text-xs hidden sm:table-cell">Statut</TableHead>
                                            <TableHead className="text-xs text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {utilisateurs.data.map((utilisateur) => (
                                            <TableRow key={utilisateur.id}>
                                                <TableCell className="font-medium text-xs sm:text-sm py-2">
                                                    {utilisateur.prenom
                                                        ? `${utilisateur.prenom} ${utilisateur.name}`
                                                        : utilisateur.name}
                                                    <div className="sm:hidden text-[10px] text-gray-400 mt-0.5">
                                                        {utilisateur.actif ? '✅' : '❌'} {rolesLabels[utilisateur.role] || utilisateur.role}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-xs text-gray-500 hidden md:table-cell py-2">
                                                    {utilisateur.email}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs hidden lg:table-cell py-2">
                                                    {utilisateur.matricule || '-'}
                                                </TableCell>
                                                <TableCell className="py-2">
                                                    <Badge variant="outline" className="text-[10px]">
                                                        {rolesLabels[utilisateur.role] || utilisateur.role || 'N/A'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-sm hidden lg:table-cell py-2">{utilisateur.service || '-'}</TableCell>
                                                <TableCell className="text-sm hidden md:table-cell py-2">{utilisateur.site || '-'}</TableCell>
                                                <TableCell className="hidden sm:table-cell py-2">
                                                    <Badge variant={utilisateur.actif ? 'approuve' : 'rejete'} className="text-[10px]">
                                                        {utilisateur.actif ? 'Actif' : 'Inactif'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="text-right py-2">
                                                    <div className="flex items-center justify-end gap-1">
                                                        <Link href={route('utilisateurs.edit', utilisateur.id)}>
                                                            <Button variant="ghost" size="icon" className="h-7 w-7 sm:h-8 sm:w-8">
                                                                <Edit className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
                                                            </Button>
                                                        </Link>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-7 w-7 sm:h-8 sm:w-8"
                                                            onClick={() => toggleActif(utilisateur.id)}
                                                            title={utilisateur.actif ? 'Désactiver' : 'Activer'}
                                                        >
                                                            {utilisateur.actif ? (
                                                                <ToggleRight className="h-3.5 w-3.5 sm:h-4 sm:w-4 text-green-500" />
                                                            ) : (
                                                                <ToggleLeft className="h-3.5 w-3.5 sm:h-4 sm:w-4 text-gray-400" />
                                                            )}
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                </div>

                                {/* Pagination */}
                                {utilisateurs.last_page > 1 && (
                                    <div className="flex flex-col sm:flex-row items-center justify-between gap-2 px-3 sm:px-4 py-2 sm:py-3 border-t">
                                        <p className="text-xs sm:text-sm text-gray-500">
                                            {utilisateurs.from} à {utilisateurs.to} sur {utilisateurs.total}
                                        </p>
                                        <div className="flex flex-wrap gap-1 justify-center">
                                            {utilisateurs.links.map((link, index) => (
                                                <Link
                                                    key={index}
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
                            </>
                        )}
                    </CardContent>
                </Card>
            </motion.div>
        </AuthenticatedLayout>
    );
}
