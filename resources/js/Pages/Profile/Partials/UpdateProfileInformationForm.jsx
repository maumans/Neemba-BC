/**
 * Formulaire de mise à jour des informations de profil - NEEMBA
 */
import { Link, useForm, usePage } from '@inertiajs/react';
import { Save, CheckCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function UpdateProfileInformation({ mustVerifyEmail, status }) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
        });

    const submit = (e) => {
        e.preventDefault();
        patch(route('profile.update'));
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <div>
                <Label htmlFor="name">Nom</Label>
                <Input
                    id="name"
                    value={data.name}
                    className="mt-1.5"
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    autoFocus
                    autoComplete="name"
                />
                {errors.name && <p className="text-sm text-red-500 mt-1">{errors.name}</p>}
            </div>

            <div>
                <Label htmlFor="email">Adresse email</Label>
                <Input
                    id="email"
                    type="email"
                    value={data.email}
                    className="mt-1.5"
                    onChange={(e) => setData('email', e.target.value)}
                    required
                    autoComplete="username"
                />
                {errors.email && <p className="text-sm text-red-500 mt-1">{errors.email}</p>}
            </div>

            {/* Informations métier en lecture seule */}
            {user.matricule && (
                <div className="grid grid-cols-2 gap-4 pt-2 border-t">
                    <div>
                        <Label className="text-gray-400 text-xs">Matricule</Label>
                        <p className="text-sm font-mono mt-0.5">{user.matricule}</p>
                    </div>
                    <div>
                        <Label className="text-gray-400 text-xs">Rôle</Label>
                        <p className="text-sm mt-0.5 capitalize">{user.role?.replace('_', ' ')}</p>
                    </div>
                    <div>
                        <Label className="text-gray-400 text-xs">Service</Label>
                        <p className="text-sm mt-0.5">{user.service || '-'}</p>
                    </div>
                    <div>
                        <Label className="text-gray-400 text-xs">Site</Label>
                        <p className="text-sm mt-0.5">{user.site || '-'}</p>
                    </div>
                </div>
            )}

            {mustVerifyEmail && user.email_verified_at === null && (
                <div className="rounded-lg bg-amber-50 border border-amber-200 p-3">
                    <p className="text-sm text-amber-800">
                        Votre adresse email n'est pas vérifiée.{' '}
                        <Link
                            href={route('verification.send')}
                            method="post"
                            as="button"
                            className="text-neemba-600 underline hover:text-neemba-700 font-medium"
                        >
                            Cliquez ici pour renvoyer l'email de vérification.
                        </Link>
                    </p>
                    {status === 'verification-link-sent' && (
                        <p className="mt-2 text-sm font-medium text-green-600">
                            Un nouveau lien de vérification a été envoyé.
                        </p>
                    )}
                </div>
            )}

            <div className="flex items-center gap-3 pt-2">
                <Button type="submit" disabled={processing}>
                    <Save className="mr-2 h-4 w-4" />
                    Enregistrer
                </Button>

                {recentlySuccessful && (
                    <span className="inline-flex items-center text-sm text-green-600">
                        <CheckCircle className="mr-1 h-4 w-4" />
                        Enregistré
                    </span>
                )}
            </div>
        </form>
    );
}
