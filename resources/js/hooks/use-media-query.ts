import { useSyncExternalStore } from 'react';

/**
 * Whether a media query matches, following changes. False where there is no
 * `matchMedia` (jsdom, server rendering), so the wide layout is the default.
 */
export function useMediaQuery(query: string): boolean {
    return useSyncExternalStore(
        (notify) => {
            if (typeof window === 'undefined' || !window.matchMedia) {
                return () => {};
            }

            const list = window.matchMedia(query);

            list.addEventListener('change', notify);

            return () => list.removeEventListener('change', notify);
        },
        () =>
            typeof window !== 'undefined' && window.matchMedia
                ? window.matchMedia(query).matches
                : false,
        () => false,
    );
}
