import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        // The widget is DOM code; testing it against anything else proves nothing.
        environment: 'jsdom',
        // A real origin: the widget rewrites the address bar, and history.replaceState
        // refuses to cross origins, so jsdom's about:blank default makes it throw.
        environmentOptions: { jsdom: { url: 'https://acme.test/pricing' } },
        include: ['resources/widget/__tests__/**/*.test.ts'],
    },
});
