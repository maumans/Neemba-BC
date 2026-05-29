/**
 * Formulaire de mise à jour du mot de passe - NEEMBA
 */
import { useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { Save, CheckCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function UpdatePasswordForm() {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();

    const { data, setData, errors, put, reset, processing, recentlySuccessful } =
        useForm({
            current_password: '',
            password: '',
            password_confirmation: '',
        });

    const updatePassword = (e) => {
        e.preventDefault();
        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }
                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <form onSubmit={updatePassword} className="space-y-5">
            <div>
                <Label htmlFor="current_password">Mot de passe actuel</Label>
                <Input
                    id="current_password"
                    ref={currentPasswordInput}
                    type="password"
                    value={data.current_password}
                    className="mt-1.5"
                    autoComplete="current-password"
                    placeholder="••••••••"
                    onChange={(e) => setData('current_password', e.target.value)}
                />
                {errors.current_password && (
                    <p className="text-sm text-red-500 mt-1">{errors.current_password}</p>
                )}
            </div>

            <div>
                <Label htmlFor="password">Nouveau mot de passe</Label>
                <Input
                    id="password"
                    ref={passwordInput}
                    type="password"
                    value={data.password}
                    className="mt-1.5"
                    autoComplete="new-password"
                    placeholder="••••••••"
                    onChange={(e) => setData('password', e.target.value)}
                />
                {errors.password && <p className="text-sm text-red-500 mt-1">{errors.password}</p>}
            </div>

            <div>
                <Label htmlFor="password_confirmation">Confirmer le nouveau mot de passe</Label>
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

            <div className="flex items-center gap-3 pt-2">
                <Button type="submit" disabled={processing}>
                    <Save className="mr-2 h-4 w-4" />
                    Modifier le mot de passe
                </Button>

                {recentlySuccessful && (
                    <span className="inline-flex items-center text-sm text-green-600">
                        <CheckCircle className="mr-1 h-4 w-4" />
                        Mot de passe modifié
                    </span>
                )}
            </div>
        </form>
    );
}
