/**
 * Page d'Inscription - NEEMBA Cash Management
 * 
 * DÉSACTIVÉE : Les comptes utilisateurs sont créés par les administrateurs
 * via le module Gestion des Utilisateurs.
 * Cette page redirige vers la connexion avec un message explicatif.
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link } from '@inertiajs/react';
import { ShieldAlert, ArrowLeft } from 'lucide-react';
import { Button } from '@/Components/ui/button';

export default function Register() {
    return (
        <GuestLayout>
            <Head title="Inscription désactivée" />

            <div className="text-center py-4">
                <div className="w-16 h-16 rounded-full bg-neemba-50 flex items-center justify-center mx-auto mb-5">
                    <ShieldAlert className="h-8 w-8 text-neemba-600" />
                </div>

                <h2 className="text-2xl font-bold text-gray-900 mb-2">
                    Inscription désactivée
                </h2>
                <p className="text-sm text-gray-500 leading-relaxed max-w-sm mx-auto">
                    L'inscription publique n'est pas disponible.
                    Les comptes utilisateurs sont créés par votre administrateur.
                    Contactez votre DAF ou Directeur Pays pour obtenir un accès.
                </p>

                <div className="mt-8">
                    <Link href={route('login')}>
                        <Button variant="outline" className="w-full">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Retour à la connexion
                        </Button>
                    </Link>
                </div>
            </div>
        </GuestLayout>
    );
}
