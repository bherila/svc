import { useEffect } from 'react';

const TARGET = 'main-content';

/**
 * "Skip to content": the first focusable element on every page.
 *
 * A keyboard or switch user otherwise tabs through the whole navbar - the
 * switcher, every tab, search, help, appearance, account - on every page load.
 * It is invisible until it takes focus, then pinned top-left above everything.
 *
 * Pages draw their own `<main>` (see docs/ui.md), so the target is found, not
 * named by each page: on mount the first `<main>` gets the id this link points
 * at and `tabindex="-1"`, which makes it a valid, focusable destination (a
 * skip link to nothing is itself an accessibility failure). Activating the link
 * moves focus there, which also moves a screen reader's reading position.
 */
export function SkipLink() {
    useEffect(() => {
        const main = document.querySelector('main');

        if (main === null) {
            return;
        }

        if (main.id === '') {
            main.id = TARGET;
        }

        if (!main.hasAttribute('tabindex')) {
            main.setAttribute('tabindex', '-1');
        }
    });

    return (
        <a
            href={`#${TARGET}`}
            data-slot="skip-link"
            className="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:start-2 focus-visible:top-2 focus-visible:z-[100] focus-visible:rounded-lg focus-visible:bg-background focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:text-foreground focus-visible:shadow-md focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
            onClick={(event) => {
                const main = document.querySelector('main');

                if (main === null) {
                    return;
                }

                event.preventDefault();
                main.focus();
                main.scrollIntoView({ block: 'start' });
            }}
        >
            Skip to content
        </a>
    );
}
