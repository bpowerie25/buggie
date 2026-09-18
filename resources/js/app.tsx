import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Buggy';

type PageModule = { default: ResolvedComponent };

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // The resolver hands back the component, not the module wrapping it.
    resolve: (name) =>
        resolvePageComponent<PageModule>(
            `./pages/${name}.tsx`,
            import.meta.glob<PageModule>('./pages/**/*.tsx'),
        ).then((module) => module.default),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#6366f1', showSpinner: false },
});
