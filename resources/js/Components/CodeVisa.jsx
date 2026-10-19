/**
 * Visa d'une demande urgente ou très urgente (spec v2.4, RG-M01-07) : le valideur demande un code par SMS et le saisit
 * avant d'approuver. Partagé par l'écran de visa, la fiche du bon et la fiche de l'ODM.
 */
import { useState } from 'react';
import axios from 'axios';
import { Loader2, MessageSquareLock } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';

export default function CodeVisa({ url, valeur, onChange, erreur }) {
    const [envoi, setEnvoi] = useState(false);
    const [message, setMessage] = useState(null);
    const [echec, setEchec] = useState(null);

    const demander = async () => {
        setEnvoi(true);
        setEchec(null);
        try {
            const { data } = await axios.post(url);
            setMessage(data.message);
        } catch (e) {
            setMessage(null);
            setEchec(e.response?.data?.message ?? 'Envoi du code impossible.');
        } finally {
            setEnvoi(false);
        }
    };

    return (
        <div className="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3" data-testid="code-visa">
            <p className="flex items-center gap-1.5 text-xs font-semibold text-amber-900">
                <MessageSquareLock className="h-4 w-4" /> Demande urgente : votre visa est protégé par un code reçu par SMS
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    aria-label="Code de visa"
                    value={valeur}
                    onChange={(e) => onChange(e.target.value.replace(/\D/g, ''))}
                    placeholder="Code à 6 chiffres"
                    className="w-40 bg-white tracking-widest"
                />
                <Button type="button" variant="outline" size="sm" className="bg-white" onClick={demander} disabled={envoi}>
                    {envoi && <Loader2 className="mr-1 h-4 w-4 animate-spin" />} Recevoir le code par SMS
                </Button>
            </div>
            {message && <p className="text-xs text-green-700">{message}</p>}
            {(echec || erreur) && <p className="text-xs text-red-600" role="alert">{echec ?? erreur}</p>}
        </div>
    );
}
