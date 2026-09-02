import { createInertiaApp } from '@inertiajs/react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { CommandPalette } from '@/components/command-palette';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    // The palette is mounted beside every page rather than inside a layout,
    // because it has to open from screens that share no layout: the dashboard,
    // the workspace invoice list and the client tabs each have their own
    // chrome. A copy per layout would be three copies of one keyboard
    // shortcut, and the pages with no layout at all would simply not have it.
    //
    // It renders nothing until Cmd+K, so this costs a mounted component and no
    // request. The palette itself refuses to search when nobody is signed in,
    // because its endpoint sits behind `auth`.
    setup({ el, App, props }) {
        // Inertia types the mount point as nullable because a misconfigured
        // root template has none. There is nothing to render into in that
        // case, so this returns rather than pretending otherwise.
        if (el === null) {
            return;
        }

        const app = (
            <>
                <App {...props} />
                <CommandPalette />
            </>
        );

        if (el.hasChildNodes()) {
            hydrateRoot(el, app);

            return;
        }

        createRoot(el).render(app);
    },
});
