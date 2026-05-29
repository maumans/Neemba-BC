/**
 * Page Liste des Validations - NEEMBA
 * 
 * Affiche les bons de caisse en attente de validation pour l'utilisateur connecté.
 * Chaque validateur ne voit que les bons correspondant à son niveau de validation.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { CheckSquare, Eye, FileText, Clock } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { formaterMontant as formatMontant } from '@/utils/nombreEnLettres';

/** Labels des rôles validateurs */
const rolesLabels = {
    'responsable_service': 'Chef de Service',
    'controle_gestion': 'Contrôle de Gestion',
    'daf': 'DAF',
    'directeur_pays': 'Directeur Pays',
};

export default function Index({ bonsEnAttente, roleValidateur }) {
    return (
        <AuthenticatedLayout header="Validations">
            <Head title="Validations" />

            <div className="mb-5">
                <h1 className="text-xl sm:text-2xl font-bold text-gray-900">Validations en attente</h1>
                <p className="text-gray-500 text-xs sm:text-sm mt-0.5">
                    Bons en attente de votre validation en tant que {rolesLabels[roleValidateur] || roleValidateur}
                </p>
            </div>

            <motion.div
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
            >
                <Card>
                    <CardContent className="p-0">
                        {bonsEnAttente.data.length === 0 ? (
                            <div className="text-center py-16">
                                <CheckSquare className="h-12 w-12 mx-auto text-green-300 mb-3" />
                                <p className="text-gray-500 font-medium">Aucun bon en attente</p>
                                <p className="text-gray-400 text-sm mt-1">
                                    Tous les bons ont été traités.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="text-xs">Numéro</TableHead>
                                            <TableHead className="text-xs hidden sm:table-cell">Type</TableHead>
                                            <TableHead className="text-xs hidden md:table-cell">Demandeur</TableHead>
                                            <TableHead className="text-xs hidden lg:table-cell">Bénéficiaire</TableHead>
                                            <TableHead className="text-xs hidden lg:table-cell">Motif</TableHead>
                                            <TableHead className="text-xs text-right">Montant</TableHead>
                                            <TableHead className="text-xs hidden sm:table-cell">Date</TableHead>
                                            <TableHead className="text-xs text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {bonsEnAttente.data.map((bon) => (
                                            <TableRow key={bon.id}>
                                                <TableCell className="font-medium font-mono text-xs sm:text-sm py-2">
                                                    {bon.numero}
                                                    <div className="sm:hidden text-[10px] text-gray-400 mt-0.5 font-sans">
                                                        {bon.type_bon} · {new Date(bon.date_demande).toLocaleDateString('fr-FR')}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="hidden sm:table-cell py-2">
                                                    <Badge variant={bon.type_bon === 'BD' ? 'default' : 'secondary'} className="text-[10px]">
                                                        {bon.type_bon}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="hidden md:table-cell text-sm py-2">
                                                    {bon.demandeur?.prenom
                                                        ? `${bon.demandeur.prenom} ${bon.demandeur.name}`
                                                        : bon.demandeur?.name}
                                                </TableCell>
                                                <TableCell className="hidden lg:table-cell text-sm py-2">{bon.beneficiaire}</TableCell>
                                                <TableCell className="hidden lg:table-cell max-w-[200px] truncate text-sm py-2">
                                                    {bon.motif}
                                                </TableCell>
                                                <TableCell className="text-right font-semibold text-xs sm:text-sm py-2">
                                                    {formatMontant(bon.montant)}
                                                </TableCell>
                                                <TableCell className="text-xs text-gray-500 hidden sm:table-cell py-2">
                                                    {new Date(bon.date_demande).toLocaleDateString('fr-FR')}
                                                </TableCell>
                                                <TableCell className="text-right py-2">
                                                    <Link href={route('validations.show', bon.id)}>
                                                        <Button size="sm" className="h-7 text-xs px-2 sm:px-3">
                                                            <Eye className="mr-0.5 sm:mr-1 h-3.5 w-3.5" />
                                                            <span className="hidden sm:inline">Examiner</span>
                                                        </Button>
                                                    </Link>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                </div>

                                {/* Pagination */}
                                {bonsEnAttente.last_page > 1 && (
                                    <div className="flex flex-col sm:flex-row items-center justify-between gap-2 px-3 sm:px-4 py-2 sm:py-3 border-t">
                                        <p className="text-xs sm:text-sm text-gray-500">
                                            {bonsEnAttente.from} à {bonsEnAttente.to} sur {bonsEnAttente.total}
                                        </p>
                                        <div className="flex flex-wrap gap-1 justify-center">
                                            {bonsEnAttente.links.map((link, index) => (
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
