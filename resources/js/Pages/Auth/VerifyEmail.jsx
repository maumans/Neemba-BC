/**
 * Page Vérification Email - NEEMBA Cash Management
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Mail, LogOut } from 'lucide-react';
import { Button } from '@/Components/ui/button';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Vérification de l'email" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-gray-900">Vérifiez votre email</h2>
                <p className="text-sm text-gray-500 mt-2">
                    Merci pour votre inscription ! Avant de commencer, veuillez vérifier votre
                    adresse email en cliquant sur le lien que nous venons de vous envoyer.
                    Si vous n'avez pas reçu l'email, nous pouvons vous en envoyer un autre.
                </p>
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 rounded-lg bg-green-50 border border-green-200 p-3 text-sm font-medium text-green-700">
                    Un nouveau lien de vérification a été envoyé à votre adresse email.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Button type="submit" className="w-full" disabled={processing}>
                    <Mail className="mr-2 h-4 w-4" />
                    Renvoyer l'email de vérification
                </Button>

                <div className="text-center">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                    >
                        <LogOut className="mr-1 h-3.5 w-3.5" />
                        Se déconnecter
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
