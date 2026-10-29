import { defineConfig } from 'vite';
import { resolve } from 'node:path';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: { '@': resolve(__dirname, 'resources/js') },
    },
    server: {
        // Dev-only file watching: source dirs only. vendor/ holds tens of
        // thousands of files (faker etc.) and exhausts inotify watches
        // (ENOSPC) without this. node_modules is ignored by Vite by default.
        watch: { ignored: ['**/storage/framework/views/**', '**/vendor/**', '**/test-results/**'] },
    },
});
