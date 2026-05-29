/**
 * Composant Badge - shadcn/ui
 * Badge pour afficher les statuts des bons de caisse
 */
import * as React from 'react';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-neemba-400 text-neemba-950',
                secondary: 'border-transparent bg-secondary text-secondary-foreground',
                destructive: 'border-transparent bg-destructive text-destructive-foreground',
                outline: 'text-foreground',
                /* Variantes spécifiques aux statuts des bons de caisse */
                brouillon: 'border-transparent bg-gray-100 text-gray-700',
                en_attente: 'border-transparent bg-neemba-100 text-neemba-800',
                approuve: 'border-transparent bg-green-100 text-green-800',
                paye: 'border-transparent bg-blue-100 text-blue-800',
                rejete: 'border-transparent bg-red-100 text-red-800',
                regularise: 'border-transparent bg-purple-100 text-purple-800',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    }
);

function Badge({ className, variant, ...props }) {
    return <div className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
