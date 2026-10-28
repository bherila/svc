import { useEffect } from 'react';

/** Preserve a write with an unknown outcome across browser navigation. */
export function useUnknownWriteGuard(
    unknown: () => boolean,
    onBlocked: () => void,
) {
    useEffect(() => {
        const currentEntry = {
            state: window.history.state,
            href: window.location.href,
        };
        // Inertia restores popstate quietly, without emitting its before event.
        // Capture before its bubble listener and put back the entry we are keeping.
        const stopHistory = (event: PopStateEvent) => {
            if (!unknown()) {
                return;
            }

            event.stopImmediatePropagation();
            window.history.pushState(currentEntry.state, '', currentEntry.href);
            onBlocked();
        };
        const stopNavigation = (event: Event) => {
            if (!unknown()) {
                return;
            }

            const visit = (
                event as CustomEvent<{
                    visit: {
                        url: URL | string;
                        method: string;
                        only: string[];
                    };
                }>
            ).detail.visit;
            const destination = new URL(visit.url, window.location.href);
            const readingCurrentProps =
                visit.method === 'get' &&
                visit.only.length > 0 &&
                destination.href === window.location.href;

            if (!readingCurrentProps) {
                event.preventDefault();
                onBlocked();
            }
        };
        const stopUnload = (event: BeforeUnloadEvent) => {
            if (unknown()) {
                event.preventDefault();
            }
        };
        document.addEventListener('inertia:before', stopNavigation);
        window.addEventListener('beforeunload', stopUnload);
        window.addEventListener('popstate', stopHistory, { capture: true });

        return () => {
            document.removeEventListener('inertia:before', stopNavigation);
            window.removeEventListener('beforeunload', stopUnload);
            window.removeEventListener('popstate', stopHistory, {
                capture: true,
            });
        };
    }, [unknown, onBlocked]);
}
