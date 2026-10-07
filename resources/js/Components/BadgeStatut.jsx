/**
 * Pastille de statut d'un bon de caisse (SFD §1.4) — libellés et couleurs : utils/statuts.js.
 */
import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';
import { infosStatut } from '@/utils/statuts';

export default function BadgeStatut({ statut, className }) {
    const { libelle, variante } = infosStatut(statut);

    return (
        <Badge variant={variante} className={cn('whitespace-nowrap', className)}>
            {libelle}
        </Badge>
    );
}
