import { defineConfig } from 'vite';

/**
 * The widget is built separately from the application: a single self-contained IIFE
 * at a stable path, with no hashing, because customers embed the URL in their own
 * pages and it must keep working across our deploys.
 */
export default defineConfig({
    // Without this, Vite copies the whole of public/ into the output directory —
    // which, since the output lives inside public/, means public/widget/public.
    publicDir: false,

    build: {
        outDir: 'public/widget',
        emptyOutDir: true,
        target: 'es2018',
        lib: {
            entry: 'resources/widget/index.ts',
            name: 'BuggyWidget',
            formats: ['iife'],
            fileName: () => 'buggy.js',
        },
        rollupOptions: {
            output: { inlineDynamicImports: true, extend: true },
        },
    },
});
