/**
 * Page de Connexion - NEEMBA Cash Management
 * 
 * Design NEEMBA avec composants shadcn/ui, textes en français.
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Connexion" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-gray-900">Connexion</h2>
                <p className="text-sm text-gray-500 mt-1">
                    Accédez à votre espace de gestion de caisse
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
                        autoComplete="username"
                        autoFocus
                        placeholder="prenom.nom@neemba.com"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && <p className="text-sm text-red-500 mt-1">{errors.email}</p>}
                </div>

                <div>
                    <Label htmlFor="password">Mot de passe</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        className="mt-1.5"
                        autoComplete="current-password"
                        placeholder="••••••••"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    {errors.password && <p className="text-sm text-red-500 mt-1">{errors.password}</p>}
                </div>

                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="rounded border-gray-300 text-neemba-500 focus:ring-neemba-400 h-4 w-4"
                        />
                        <span className="text-sm text-gray-600">Se souvenir de moi</span>
                    </label>

                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="text-sm text-neemba-600 hover:text-neemba-700 font-medium"
                        >
                            Mot de passe oublié ?
                        </Link>
                    )}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    <LogIn className="mr-2 h-4 w-4" />
                    Se connecter
                </Button>
            </form>
        </GuestLayout>
    );
}
