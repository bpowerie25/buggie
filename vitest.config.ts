import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        // The widget is DOM code; testing it against anything else proves nothing.
        environment: 'jsdom',
        include: ['resources/widget/__tests__/**/*.test.ts'],
    },
});
