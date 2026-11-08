import { useEffect, useState } from 'react';

/**
 * Whether a scroll container's content is bigger than the box, tracked live.
 *
 * A box that scrolls must be reachable by keyboard (WCAG 2.1.1) - but only one
 * that actually scrolls, or every container would be a pointless tab stop. Put
 * the first returned value (a callback ref) on the container and spread the second on it: while it
 * overflows it gets `tabIndex={0}`. Give it an accessible name as well
 * (`aria-label` with `role="region"`) where it holds no focusable content of its
 * own. In jsdom there is no `ResizeObserver`, so nothing is ever scrollable.
 */
export function useScrollable<T extends HTMLElement>() {
    // A callback ref held in state, so a container that mounts later (a dialog's
    // body, opened after this component rendered) is measured when it appears.
    const [box, attach] = useState<T | null>(null);
    const [scrollable, setScrollable] = useState(false);

    useEffect(() => {
        if (box === null || typeof ResizeObserver === 'undefined') {
            return undefined;
        }

        const measure = () =>
            setScrollable(
                box.scrollWidth > box.clientWidth ||
                    box.scrollHeight > box.clientHeight,
            );
        const observer = new ResizeObserver(measure);

        measure();
        observer.observe(box);

        for (const child of Array.from(box.children)) {
            observer.observe(child);
        }

        // Content added later (options loaded after the dialog opened) changes
        // the scroll height without resizing the box.
        const mutations = new MutationObserver(() => {
            measure();

            for (const child of Array.from(box.children)) {
                observer.observe(child);
            }
        });

        mutations.observe(box, { childList: true, subtree: true });

        return () => {
            observer.disconnect();
            mutations.disconnect();
        };
    }, [box]);

    return [
        attach,
        scrollable ? ({ tabIndex: 0 } as const) : ({} as const),
    ] as const;
}
