/**
 * Page Mot de Passe Oublié - NEEMBA Cash Management
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Mail, ArrowLeft } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Mot de passe oublié" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-gray-900">Mot de passe oublié ?</h2>
                <p className="text-sm text-gray-500 mt-2">
                    Pas de souci. Indiquez votre adresse email et nous vous enverrons un lien
                    pour réinitialiser votre mot de passe.
                </p>
            </div>

            {status && (
                <div className="mb-4 rounded-lg bg-green-50 border border-green-200 p-3 text-sm font-medium text-green-700">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <Label htmlFor="email">Adresse email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        className="mt-1.5"
                        autoFocus
                        placeholder="prenom.nom@neemba.com"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && <p className="text-sm text-red-500 mt-1">{errors.email}</p>}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    <Mail className="mr-2 h-4 w-4" />
                    Envoyer le lien de réinitialisation
                </Button>

                <div className="text-center">
                    <Link
                        href={route('login')}
                        className="inline-flex items-center text-sm text-gray-500 hover:text-gray-700"
                    >
                        <ArrowLeft className="mr-1 h-3.5 w-3.5" />
                        Retour à la connexion
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
