import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

/**
 * Tests des utilitaires JavaScript (formatage, montant en lettres, messages, statuts).
 * Lancement : npm test
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'node',
        include: ['tests/js/**/*.test.js'],
    },
});
