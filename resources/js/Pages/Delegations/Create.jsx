/**
 * Page Création d'une Délégation - NEEMBA
 * 
 * Formulaire pour déléguer ses pouvoirs de validation à un autre utilisateur.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowLeft, Send, UserCheck, Calendar, Loader2, Shield } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Combobox } from '@/Components/ui/combobox';

export default function Create({ deleguesPotentiels = [], fonctionnalitesDisponibles = [], fonctionnalitesLabels = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        delegue_id: '',
        date_debut: '',
        date_fin: '',
        motif: '',
        fonctionnalites: [...fonctionnalitesDisponibles], // Toutes cochées par défaut
    });

    /** Toggle une fonctionnalité dans la liste */
    const toggleFonctionnalite = (fonc) => {
        const current = data.fonctionnalites || [];
        if (current.includes(fonc)) {
            setData('fonctionnalites', current.filter(f => f !== fonc));
        } else {
            setData('fonctionnalites', [...current, fonc]);
        }
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('delegations.store'));
    };

    return (
        <AuthenticatedLayout header="Nouvelle délégation">
            <Head title="Nouvelle délégation" />

            <div className="mb-6">
                <Link
                    href={route('delegations.index')}
                    className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                >
                    <ArrowLeft className="mr-1 h-4 w-4" />
                    Retour aux délégations
                </Link>
            </div>

            <div className="max-w-2xl mx-auto">
                <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <UserCheck className="h-5 w-5 text-neemba-500" />
                                Déléguer mes pouvoirs
                            </CardTitle>
                            <CardDescription>
                                Choisissez les fonctionnalités à déléguer et la personne qui les exercera.
                                Le délégué devra accepter la délégation avant qu'elle ne soit effective.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-5">
                                {/* Délégué */}
                                <div>
                                    <Label htmlFor="delegue_id">Déléguer à *</Label>
                                    <Combobox
                                        options={deleguesPotentiels.map((u) => ({
                                            value: String(u.id),
                                            label: `${u.prenom} ${u.name} — ${u.role} (${u.service})`
                                        }))}
                                        value={data.delegue_id ? String(data.delegue_id) : ''}
                                        onChange={(val) => setData('delegue_id', val)}
                                        placeholder="Sélectionner un utilisateur..."
                                        searchPlaceholder="Rechercher un utilisateur..."
                                        className="mt-1 w-full"
                                        error={errors.delegue_id}
                                    />
                                    {errors.delegue_id && <p className="text-sm text-red-500 mt-1">{errors.delegue_id}</p>}
                                </div>

                                {/* Période */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="date_debut" className="flex items-center gap-1">
                                            <Calendar className="h-3.5 w-3.5" />
                                            Date de début *
                                        </Label>
                                        <Input
                                            id="date_debut"
                                            type="date"
                                            value={data.date_debut}
                                            onChange={(e) => setData('date_debut', e.target.value)}
                                            className="mt-1"
                                            min={new Date().toISOString().split('T')[0]}
                                        />
                                        {errors.date_debut && <p className="text-sm text-red-500 mt-1">{errors.date_debut}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="date_fin" className="flex items-center gap-1">
                                            <Calendar className="h-3.5 w-3.5" />
                                            Date de fin *
                                        </Label>
                                        <Input
                                            id="date_fin"
                                            type="date"
                                            value={data.date_fin}
                                            onChange={(e) => setData('date_fin', e.target.value)}
                                            className="mt-1"
                                            min={data.date_debut || new Date().toISOString().split('T')[0]}
                                        />
                                        {errors.date_fin && <p className="text-sm text-red-500 mt-1">{errors.date_fin}</p>}
                                    </div>
                                </div>

                                {/* Motif */}
                                <div>
                                    <Label htmlFor="motif">Motif de la délégation *</Label>
                                    <Textarea
                                        id="motif"
                                        value={data.motif}
                                        onChange={(e) => setData('motif', e.target.value)}
                                        placeholder="Ex: Congé annuel, déplacement professionnel, formation..."
                                        className="mt-1 min-h-[100px]"
                                    />
                                    {errors.motif && <p className="text-sm text-red-500 mt-1">{errors.motif}</p>}
                                </div>

                                {/* Fonctionnalités à déléguer */}
                                {fonctionnalitesDisponibles.length > 0 && (
                                    <div>
                                        <Label className="flex items-center gap-1 mb-2">
                                            <Shield className="h-3.5 w-3.5" />
                                            Fonctionnalités à déléguer *
                                        </Label>
                                        <div className="space-y-2 p-3 rounded-lg border bg-gray-50/50">
                                            {fonctionnalitesDisponibles.map((fonc) => (
                                                <label
                                                    key={fonc}
                                                    className="flex items-center gap-3 p-2 rounded-md hover:bg-white transition-colors cursor-pointer"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={(data.fonctionnalites || []).includes(fonc)}
                                                        onChange={() => toggleFonctionnalite(fonc)}
                                                        className="h-4 w-4 rounded border-gray-300 text-neemba-600 focus:ring-neemba-500"
                                                    />
                                                    <span className="text-sm text-gray-700">
                                                        {fonctionnalitesLabels[fonc] || fonc}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                        {(data.fonctionnalites || []).length === 0 && (
                                            <p className="text-xs text-red-500 mt-1">Veuillez sélectionner au moins une fonctionnalité</p>
                                        )}
                                        {errors.fonctionnalites && <p className="text-sm text-red-500 mt-1">{errors.fonctionnalites}</p>}
                                    </div>
                                )}

                                {/* Info */}
                                <div className="p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm text-blue-700">
                                    <p className="font-medium mb-1">Comment ça fonctionne :</p>
                                    <ul className="list-disc list-inside text-xs space-y-0.5">
                                        <li>Le délégué recevra une notification pour accepter ou refuser</li>
                                        <li>La délégation ne sera effective qu'après son acceptation</li>
                                        <li>Seules les fonctionnalités sélectionnées seront déléguées</li>
                                        <li>Vous pouvez terminer la délégation à tout moment</li>
                                    </ul>
                                </div>

                                {/* Actions */}
                                <div className="flex gap-3 pt-2">
                                    <Link href={route('delegations.index')} className="flex-1">
                                        <Button type="button" variant="outline" className="w-full">
                                            Annuler
                                        </Button>
                                    </Link>
                                    <Button
                                        type="submit"
                                        className="flex-1"
                                        disabled={processing || (data.fonctionnalites || []).length === 0}
                                    >
                                        {processing ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <Send className="mr-2 h-4 w-4" />
                                        )}
                                        {processing ? 'Envoi en cours…' : 'Envoyer la demande'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </motion.div>
            </div>
        </AuthenticatedLayout>
    );
}
