import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Frontend unit/component tests (IMPLEMENTATION-CONTRACTS.md §17.4 — `vitest run`
// is part of `composer conform`). Playwright owns the end-to-end journey only
// (tests/Browser, §17.1); everything below runs in jsdom without a server.
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['resources/js/test/setup.ts'],
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        css: false,
        testTimeout: 20000,
    },
});
