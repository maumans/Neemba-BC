/**
 * Page Mon Profil - NEEMBA Cash Management
 * 
 * Permet à l'utilisateur de modifier ses informations et son mot de passe.
 * La suppression de compte est désactivée (gérée par l'admin).
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <AuthenticatedLayout header="Mon Profil">
            <Head title="Mon Profil" />

            <div className="max-w-3xl space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Informations personnelles</CardTitle>
                        <CardDescription>
                            Mettez à jour votre nom et adresse email.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Modifier le mot de passe</CardTitle>
                        <CardDescription>
                            Utilisez un mot de passe long et unique pour sécuriser votre compte.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <UpdatePasswordForm />
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
