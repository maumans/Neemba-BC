/**
 * Q108 : première connexion d'un compte au mot de passe initial (import, création ou réinitialisation par
 * l'administrateur) ; son titulaire choisit le sien avant tout autre écran.
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound, LogOut } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function MotDePasseInitial({ nom }) {
    const { data, setData, put, processing, errors, reset } = useForm({ password: '', password_confirmation: '' });

    const submit = (e) => {
        e.preventDefault();
        put(route('mot-de-passe.initial.update'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout>
            <Head title="Choisir votre mot de passe" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-gray-900">Choisissez votre mot de passe</h2>
                <p className="text-sm text-gray-500 mt-1">
                    Bonjour {nom}. Votre mot de passe actuel est le mot de passe initial, communiqué à plusieurs personnes.
                    Choisissez le vôtre pour continuer.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <Label htmlFor="password">Nouveau mot de passe</Label>
                    <Input id="password" type="password" value={data.password} className="mt-1.5" autoComplete="new-password" autoFocus
                        placeholder="••••••••" onChange={(e) => setData('password', e.target.value)} />
                    <p className="text-xs text-gray-500 mt-1">8 caractères au moins.</p>
                    {errors.password && <p className="text-sm text-red-500 mt-1" role="alert">{errors.password}</p>}
                </div>

                <div>
                    <Label htmlFor="password_confirmation">Confirmer le mot de passe</Label>
                    <Input id="password_confirmation" type="password" value={data.password_confirmation} className="mt-1.5"
                        autoComplete="new-password" placeholder="••••••••" onChange={(e) => setData('password_confirmation', e.target.value)} />
                    {errors.password_confirmation && <p className="text-sm text-red-500 mt-1" role="alert">{errors.password_confirmation}</p>}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    <KeyRound className="mr-2 h-4 w-4" />
                    Enregistrer mon mot de passe
                </Button>
                <Button type="button" variant="ghost" className="w-full" onClick={() => router.post(route('logout'))}>
                    <LogOut className="mr-2 h-4 w-4" />
                    Déconnexion
                </Button>
            </form>
        </GuestLayout>
    );
}
