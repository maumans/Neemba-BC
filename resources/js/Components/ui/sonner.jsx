import { Toaster as Sonner } from 'sonner';

/**
 * Composant Toaster NEEMBA
 * 
 * Wrapper autour de Sonner pour les notifications toast.
 * S'intègre avec les flash messages Laravel/Inertia.
 */
function Toaster({ ...props }) {
    return (
        <Sonner
            className="toaster group"
            position="top-right"
            toastOptions={{
                classNames: {
                    toast: 'group toast group-[.toaster]:bg-white group-[.toaster]:text-gray-950 group-[.toaster]:border-gray-200 group-[.toaster]:shadow-lg group-[.toaster]:rounded-xl',
                    description: 'group-[.toast]:text-gray-500',
                    actionButton: 'group-[.toast]:bg-neemba-500 group-[.toast]:text-white',
                    cancelButton: 'group-[.toast]:bg-gray-100 group-[.toast]:text-gray-500',
                    success: 'group-[.toaster]:!bg-green-50 group-[.toaster]:!text-green-800 group-[.toaster]:!border-green-200',
                    error: 'group-[.toaster]:!bg-red-50 group-[.toaster]:!text-red-800 group-[.toaster]:!border-red-200',
                    warning: 'group-[.toaster]:!bg-amber-50 group-[.toaster]:!text-amber-800 group-[.toaster]:!border-amber-200',
                    info: 'group-[.toaster]:!bg-blue-50 group-[.toaster]:!text-blue-800 group-[.toaster]:!border-blue-200',
                },
            }}
            richColors
            closeButton
            duration={4000}
            {...props}
        />
    );
}

export { Toaster };
