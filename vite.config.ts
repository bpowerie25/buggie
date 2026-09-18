import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [bunny('Inter', { weights: [400, 500, 600, 700] })],
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
    server: {
        host: '0.0.0.0',
        // The app is served from nginx on :8080; assets come from the host Vite server.
        origin: 'http://localhost:5173',
        cors: { origin: /https?:\/\/([a-z0-9-]+\.)*buggie\.localhost(:\d+)?$/ },
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
