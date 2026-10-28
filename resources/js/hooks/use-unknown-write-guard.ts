import { useEffect } from 'react';
import { registerUnknownWriteHistoryGuard } from '@/lib/unknown-write-history';

/** Preserve a write with an unknown outcome across browser navigation. */
export function useUnknownWriteGuard(
    unknown: () => boolean,
    onBlocked: () => void,
) {
    useEffect(() => {
        const stopHistory = registerUnknownWriteHistoryGuard({
            unknown,
            blocked: onBlocked,
            entry: { state: window.history.state, href: window.location.href },
        });
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

        return () => {
            document.removeEventListener('inertia:before', stopNavigation);
            window.removeEventListener('beforeunload', stopUnload);
            stopHistory();
        };
    }, [unknown, onBlocked]);
}
