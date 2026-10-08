/**
 * Pastille de statut d'un ordre de mission (RG-M12-21) — libellés et couleurs : utils/odm.js.
 */
import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';
import { infosStatutOdm } from '@/utils/odm';

export default function BadgeStatutOdm({ statut, className }) {
    const { libelle, variante } = infosStatutOdm(statut);

    return (
        <Badge variant={variante} className={cn('whitespace-nowrap', className)}>
            {libelle}
        </Badge>
    );
}
