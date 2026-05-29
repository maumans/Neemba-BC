import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Configuration Tailwind CSS pour l'application NEEMBA
 * Couleur principale : #fdc911 (or/jaune NEEMBA)
 * Thème construit autour de cette couleur avec des variantes complémentaires
 */

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            /* Palette de couleurs NEEMBA */
            colors: {
                /* Couleurs shadcn/ui basées sur les variables CSS */
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                primary: {
                    DEFAULT: 'hsl(var(--primary))',
                    foreground: 'hsl(var(--primary-foreground))',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary))',
                    foreground: 'hsl(var(--secondary-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive))',
                    foreground: 'hsl(var(--destructive-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted))',
                    foreground: 'hsl(var(--muted-foreground))',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent))',
                    foreground: 'hsl(var(--accent-foreground))',
                },
                popover: {
                    DEFAULT: 'hsl(var(--popover))',
                    foreground: 'hsl(var(--popover-foreground))',
                },
                card: {
                    DEFAULT: 'hsl(var(--card))',
                    foreground: 'hsl(var(--card-foreground))',
                },

                /* Couleur principale - Or NEEMBA #fdc911 */
                neemba: {
                    50: '#fffbeb',
                    100: '#fff3c6',
                    200: '#ffe588',
                    300: '#ffd34a',
                    400: '#fdc911',
                    500: '#f0b400',
                    600: '#cc8a00',
                    700: '#a36200',
                    800: '#864d08',
                    900: '#723f0c',
                    950: '#432001',
                },
                /* Couleur secondaire - Bleu foncé pour le contraste */
                marine: {
                    50: '#f0f4ff',
                    100: '#dbe4ff',
                    200: '#bac8ff',
                    300: '#89a4f7',
                    400: '#5c7cfa',
                    500: '#3b5bdb',
                    600: '#2b44b8',
                    700: '#1e3a8a',
                    800: '#1a2f6e',
                    900: '#162556',
                    950: '#0f172a',
                },
            },
            borderRadius: {
                lg: 'var(--radius)',
                md: 'calc(var(--radius) - 2px)',
                sm: 'calc(var(--radius) - 4px)',
            },
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            /* Animations pour les transitions de page */
            keyframes: {
                'fade-in': {
                    '0%': { opacity: '0', transform: 'translateY(10px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'slide-in-left': {
                    '0%': { opacity: '0', transform: 'translateX(-20px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
            },
            animation: {
                'fade-in': 'fade-in 0.3s ease-out',
                'slide-in-left': 'slide-in-left 0.3s ease-out',
            },
        },
    },

    plugins: [forms],
};
