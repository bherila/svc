/**
 * "Skip to content": the first focusable element on every page.
 *
 * A keyboard or switch user otherwise tabs through the whole navbar - the
 * switcher, every tab, search, help, appearance, account - on every page load.
 * It is invisible until it takes focus, then pinned top-left above everything.
 *
 * Pages draw their own `<main>` (see docs/ui.md), so the target is found at
 * click time rather than named by id: the first `<main>` on the page gets
 * `tabindex="-1"` and focus, which also moves a screen reader's reading
 * position there. A plain `href="#..."` would scroll and leave focus behind.
 */
export function SkipLink() {
    return (
        <a
            href="#main"
            data-slot="skip-link"
            className="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:start-2 focus-visible:top-2 focus-visible:z-[100] focus-visible:rounded-lg focus-visible:bg-background focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:text-foreground focus-visible:shadow-md focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
            onClick={(event) => {
                const main = document.querySelector('main');

                if (main === null) {
                    return;
                }

                event.preventDefault();

                if (!main.hasAttribute('tabindex')) {
                    main.setAttribute('tabindex', '-1');
                }

                main.focus();
                main.scrollIntoView({ block: 'start' });
            }}
        >
            Skip to content
        </a>
    );
}
