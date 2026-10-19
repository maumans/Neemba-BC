/**
 * Second temps de la connexion (spec v2.4, RG-M01-07) : code à 6 chiffres reçu par email.
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function CodeConnexion({ email, minutes, status }) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ code: '' });
    /* Erreurs du renvoi (router.post) : portées par la page, pas par le formulaire */
    const erreursPage = usePage().props.errors ?? {};
    const erreur = erreursPage.code ?? erreursPage.email ?? errors.code ?? errors.email;

    const valider = (e) => {
        e.preventDefault();
        post(route('login.code'), { onError: () => reset('code') });
    };

    return (
        <GuestLayout>
            <Head title="Code de connexion" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-gray-900">Code de connexion</h2>
                <p className="text-sm text-gray-500 mt-1">
                    Un code à 6 chiffres a été envoyé à <strong>{email}</strong>. Il est valable {minutes} minutes.
                </p>
            </div>

            {status && (
                <div className="mb-4 rounded-lg bg-green-50 border border-green-200 p-3 text-sm font-medium text-green-700">{status}</div>
            )}

            <form onSubmit={valider} className="space-y-5">
                <div>
                    <Label htmlFor="code">Code reçu par email</Label>
                    <Input
                        id="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        autoFocus
                        maxLength={6}
                        value={data.code}
                        onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                        className="mt-1.5 text-center text-xl tracking-[0.5em]"
                        placeholder="••••••"
                    />
                    {erreur && <p className="text-sm text-red-500 mt-1" role="alert">{erreur}</p>}
                </div>

                <Button type="submit" className="w-full" disabled={processing || data.code.length !== 6}>
                    <ShieldCheck className="mr-2 h-4 w-4" />
                    Valider le code
                </Button>
            </form>

            <div className="mt-4 flex items-center justify-between text-sm">
                <button type="button" className="font-medium text-neemba-600 hover:text-neemba-700" onClick={() => { clearErrors(); router.post(route('login.code.renvoyer')); }}>
                    Renvoyer un code
                </button>
                <Link href={route('login')} className="text-gray-500 hover:text-gray-700">Revenir à la connexion</Link>
            </div>
        </GuestLayout>
    );
}
