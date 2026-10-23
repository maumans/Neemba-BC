/**
 * Pastille de statut d'un ordre de mission (RG-M12-21) — libellés et couleurs : utils/odm.js ; « Échue » (RG-M12-49).
 */
import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';
import { infosStatutOdm } from '@/utils/odm';

export default function BadgeStatutOdm({ statut, echue = false, className }) {
    /* Complément du 22/10, RG-M12-49 : « Échue » s'affiche à la place du statut technique */
    const { libelle, variante } = echue ? { libelle: 'Échue', variante: 'destructive' } : infosStatutOdm(statut);

    return (
        <Badge variant={variante} className={cn('whitespace-nowrap', className)}>
            {libelle}
        </Badge>
    );
}
