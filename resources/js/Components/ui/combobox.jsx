/**
 * Combobox - Select searchable NEEMBA
 * 
 * Composant de sélection avec recherche intégrée.
 * Utilise Radix Popover pour le dropdown.
 * Remplace les champs texte pour les données paramétrées (site, service, etc.).
 */
import * as React from 'react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { cn } from '@/lib/utils';

const Combobox = React.forwardRef(
    ({ options = [], value, onChange, placeholder = 'Sélectionner...', searchPlaceholder = 'Rechercher...', className, disabled = false, error }, ref) => {
        const [open, setOpen] = React.useState(false);
        const [search, setSearch] = React.useState('');
        const inputRef = React.useRef(null);

        const filtered = React.useMemo(() => {
            if (!search) return options;
            const lower = search.toLowerCase();
            return options.filter((opt) => {
                const label = typeof opt === 'string' ? opt : opt.label;
                return label.toLowerCase().includes(lower);
            });
        }, [options, search]);

        const displayValue = React.useMemo(() => {
            if (!value) return '';
            const found = options.find((opt) => {
                const val = typeof opt === 'string' ? opt : opt.value;
                return val === value;
            });
            if (!found) return value;
            return typeof found === 'string' ? found : found.label;
        }, [value, options]);

        const handleSelect = (optValue) => {
            onChange(optValue === value ? '' : optValue);
            setOpen(false);
            setSearch('');
        };

        return (
            <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
                <PopoverPrimitive.Trigger asChild disabled={disabled}>
                    <button
                        ref={ref}
                        type="button"
                        role="combobox"
                        aria-expanded={open}
                        className={cn(
                            'flex h-9 w-full items-center justify-between rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                            error && 'border-red-500',
                            className
                        )}
                    >
                        <span className={cn('truncate', !displayValue && 'text-muted-foreground')}>
                            {displayValue || placeholder}
                        </span>
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                    </button>
                </PopoverPrimitive.Trigger>

                <PopoverPrimitive.Portal>
                    <PopoverPrimitive.Content
                        className="z-50 w-[--radix-popover-trigger-width] rounded-md border bg-popover p-0 text-popover-foreground shadow-md outline-none animate-in fade-in-0 zoom-in-95"
                        sideOffset={4}
                        align="start"
                    >
                        {/* Champ de recherche */}
                        <div className="flex items-center border-b px-3">
                            <Search className="mr-2 h-4 w-4 shrink-0 opacity-50" />
                            <input
                                ref={inputRef}
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={searchPlaceholder}
                                className="flex h-10 w-full bg-transparent py-3 text-sm outline-none placeholder:text-muted-foreground"
                            />
                        </div>

                        {/* Liste des options */}
                        <div className="max-h-[200px] overflow-y-auto p-1">
                            {filtered.length === 0 ? (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    Aucun résultat.
                                </p>
                            ) : (
                                filtered.map((opt) => {
                                    const optValue = typeof opt === 'string' ? opt : opt.value;
                                    const optLabel = typeof opt === 'string' ? opt : opt.label;
                                    const isSelected = optValue === value;

                                    return (
                                        <button
                                            key={optValue}
                                            type="button"
                                            onClick={() => handleSelect(optValue)}
                                            className={cn(
                                                'relative flex w-full cursor-pointer select-none items-center rounded-sm py-1.5 pl-8 pr-2 text-sm outline-none hover:bg-accent hover:text-accent-foreground',
                                                isSelected && 'bg-accent text-accent-foreground'
                                            )}
                                        >
                                            <span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
                                                {isSelected && <Check className="h-4 w-4" />}
                                            </span>
                                            {optLabel}
                                        </button>
                                    );
                                })
                            )}
                        </div>
                    </PopoverPrimitive.Content>
                </PopoverPrimitive.Portal>
            </PopoverPrimitive.Root>
        );
    }
);

Combobox.displayName = 'Combobox';

export { Combobox };
