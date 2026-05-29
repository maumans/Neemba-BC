/**
 * Page Réinitialisation du Mot de Passe - NEEMBA Cash Management
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Réinitialiser le mot de passe" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-gray-900">Nouveau mot de passe</h2>
                <p className="text-sm text-gray-500 mt-1">
                    Choisissez un nouveau mot de passe pour votre compte.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <Label htmlFor="email">Adresse email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        className="mt-1.5"
                        autoComplete="username"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && <p className="text-sm text-red-500 mt-1">{errors.email}</p>}
                </div>

                <div>
                    <Label htmlFor="password">Nouveau mot de passe</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        className="mt-1.5"
                        autoComplete="new-password"
                        autoFocus
                        placeholder="••••••••"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    {errors.password && <p className="text-sm text-red-500 mt-1">{errors.password}</p>}
                </div>

                <div>
                    <Label htmlFor="password_confirmation">Confirmer le mot de passe</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        className="mt-1.5"
                        autoComplete="new-password"
                        placeholder="••••••••"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    {errors.password_confirmation && (
                        <p className="text-sm text-red-500 mt-1">{errors.password_confirmation}</p>
                    )}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    <KeyRound className="mr-2 h-4 w-4" />
                    Réinitialiser le mot de passe
                </Button>
            </form>
        </GuestLayout>
    );
}
