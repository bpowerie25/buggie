import { defineConfig } from 'vite';

// A loader, not a bundle: this package injects the widget your Buggie instance
// already serves, so it stays tiny and never drifts out of step with the server.
export default defineConfig({
    build: {
        lib: {
            entry: 'src/index.ts',
            formats: ['es', 'cjs'],
            fileName: (format) => (format === 'es' ? 'index.js' : 'index.cjs'),
        },
        minify: 'esbuild',
        sourcemap: true,
    },
});
