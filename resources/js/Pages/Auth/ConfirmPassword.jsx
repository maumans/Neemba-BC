/**
 * Page Confirmation du Mot de Passe - NEEMBA Cash Management
 * 
 * Zone sécurisée : l'utilisateur doit confirmer son mot de passe avant de continuer.
 */
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Confirmer le mot de passe" />

            <div className="mb-6">
                <h2 className="text-2xl font-bold text-gray-900">Zone sécurisée</h2>
                <p className="text-sm text-gray-500 mt-2">
                    Veuillez confirmer votre mot de passe avant de continuer.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <Label htmlFor="password">Mot de passe</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        className="mt-1.5"
                        autoFocus
                        placeholder="••••••••"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    {errors.password && <p className="text-sm text-red-500 mt-1">{errors.password}</p>}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    <ShieldCheck className="mr-2 h-4 w-4" />
                    Confirmer
                </Button>
            </form>
        </GuestLayout>
    );
}
