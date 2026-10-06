/**
 * Champs du formulaire de rejet d'un bon : motif prédéfini + commentaire
 * (obligatoire si le motif est « autre », facultatif sinon).
 *
 * Partagé par Validations/Show et BonsCaisse/Show pour que les deux écrans
 * envoient les mêmes champs (motif_rejet, commentaire) à validations.rejeter.
 */
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';

export default function ChampsRejet({ form, motifsRejet = {}, idPrefixe = 'rejet' }) {
    const motifAutre = form.data.motif_rejet === 'autre';

    return (
        <div className="my-4 space-y-4">
            <div>
                <Label htmlFor={`${idPrefixe}_motif`}>Motif du rejet *</Label>
                <select
                    id={`${idPrefixe}_motif`}
                    className="flex h-10 w-full mt-1 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    value={form.data.motif_rejet}
                    onChange={(e) => {
                        form.setData('motif_rejet', e.target.value);
                        if (e.target.value !== 'autre') {
                            form.clearErrors('commentaire');
                        }
                    }}
                    required
                >
                    <option value="" disabled>Sélectionnez un motif...</option>
                    {Object.entries(motifsRejet).map(([cle, libelle]) => (
                        <option key={cle} value={cle}>{libelle}</option>
                    ))}
                </select>
                {form.errors.motif_rejet && (
                    <p className="text-sm text-red-500 mt-1">{form.errors.motif_rejet}</p>
                )}
            </div>

            {form.data.motif_rejet && (
                <div>
                    <Label htmlFor={`${idPrefixe}_commentaire`}>
                        {motifAutre ? 'Précisions (obligatoire) *' : 'Commentaire (optionnel)'}
                    </Label>
                    <Textarea
                        id={`${idPrefixe}_commentaire`}
                        value={form.data.commentaire}
                        onChange={(e) => form.setData('commentaire', e.target.value)}
                        placeholder={motifAutre
                            ? 'Expliquez la raison du rejet en détail...'
                            : 'Ajouter une remarque facultative...'}
                        className={`mt-1 ${motifAutre ? 'min-h-[100px]' : 'min-h-[60px]'}`}
                        required={motifAutre}
                        minLength={motifAutre ? 5 : undefined}
                    />
                    {form.errors.commentaire && (
                        <p className="text-sm text-red-500 mt-1">{form.errors.commentaire}</p>
                    )}
                </div>
            )}
        </div>
    );
}
