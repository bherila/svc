/**
 * The two ways a page ends up wider than the window, checked without a browser.
 *
 * A client whose time entries were one-line notes fitted; the same screen with
 * a real week's descriptions on it scrolled sideways, and every section on the
 * page - the invoice row, the headings, the rules between sections - stretched
 * past the right edge with it. Nothing about that page was wrong at the width
 * it was designed against, which is exactly why it reached production: the
 * fixtures were short and the layout only fails on long data.
 *
 * jsdom performs no layout, so no test in this suite can measure a width.
 * `getBoundingClientRect` returns zeroes and `scrollWidth` is always 0, and a
 * headless browser is a dependency and a CI lane this repository does not have.
 * What it can do is read the rendered tree and apply the two CSS rules that
 * produce the overflow, against data long enough to trigger them. That is the
 * trade: this catches the shapes that overflow rather than the pixels, so the
 * fixtures a page is checked with have to be hostile - a client name with no
 * spaces in it, a description that runs for a paragraph, an identifier nobody
 * would type - or the rules have nothing to fire on.
 *
 * ## The rules
 *
 * **An intrinsically sized track.** `grid` with no `grid-cols-*` leaves the
 * column at `auto`, whose minimum is the min-content width of everything in it.
 * min-content passes straight through blocks, through flex containers, and
 * through `overflow: hidden` - clipping changes what paints, not what an
 * element reports as its smallest size - so one `whitespace-nowrap` string
 * anywhere in the subtree sizes the whole column to that string, and `max-w-*`
 * on the grid container does not constrain the track it contains. Tailwind's
 * `grid-cols-1` is `repeat(1, minmax(0, 1fr))`; the `0` is the fix, because a
 * track with a definite maximum also gives its items an automatic minimum size
 * of zero, and the subtree is bounded by the container again.
 *
 * **A run that cannot break.** Text wraps at spaces, so ordinary prose is
 * never the problem however long it is; an unbroken run - a URL, an identifier,
 * a name run together - is as wide as it is, and a `truncate` or
 * `whitespace-nowrap` ancestor makes the whole string one such run. Whatever
 * box holds it keeps its own width and the text simply paints outside it, which
 * scrolls the document. It needs somewhere for the overflow to go: clipped
 * (`truncate`, `overflow-hidden`), scrolled by a container of its own
 * (`overflow-x-auto` - the right answer for a wide table), or broken mid-run
 * (`wrap-anywhere`, `break-all`).
 *
 * `break-words` is deliberately not accepted as an escape. `overflow-wrap:
 * break-word` breaks a long word to fit a box that already has a width, but it
 * does not lower the min-content size, so a flex or grid item carrying it is
 * still sized to its longest word and still pushes its container open.
 */

/** Anywhere the overflow can go: clipped, scrolled, or broken mid-run. */
const ESCAPES: ReadonlySet<string> = new Set([
    'truncate',
    'overflow-hidden',
    'overflow-clip',
    'overflow-auto',
    'overflow-scroll',
    'overflow-x-auto',
    'overflow-x-scroll',
    'overflow-x-hidden',
    'overflow-x-clip',
    'break-all',
    'wrap-anywhere',
]);

/** Utilities that make an entire string one unbreakable run. */
const NOWRAP: ReadonlySet<string> = new Set(['truncate', 'whitespace-nowrap']);

/** Utilities that put wrapping back, below an ancestor that took it away. */
const WRAP: ReadonlySet<string> = new Set([
    'whitespace-normal',
    'whitespace-pre-wrap',
    'whitespace-pre-line',
]);

/**
 * How long a run may be before it is treated as a width the page has to find.
 *
 * Thirty characters is roughly 230px at the body size these screens use, which
 * still fits the narrowest phone with its padding. Dates, money, statuses and
 * ordinary words are all far below it; the things above it are identifiers,
 * URLs, email addresses and whole nowrap sentences - each of which is a real
 * horizontal scrollbar on a narrow window.
 */
const LONGEST_SAFE_RUN = 30;

/** Elements whose text is laid out by the browser's own widget, not by us. */
const NOT_LAID_OUT_HERE: ReadonlySet<string> = new Set([
    'option',
    'script',
    'style',
    'title',
]);

export type HorizontalOverflowRisk = {
    rule: 'intrinsic-track' | 'unbreakable-run';
    where: string;
    detail: string;
};

function classesOf(element: Element): string[] {
    return (element.getAttribute('class') ?? '').split(/\s+/).filter(Boolean);
}

/** Where the risk is, as something readable in a failure message. */
function describe(element: Element): string {
    const classes = classesOf(element).join(' ');

    return classes === ''
        ? `<${element.tagName.toLowerCase()}>`
        : `<${element.tagName.toLowerCase()} class="${classes}">`;
}

function ancestors(node: Node, root: ParentNode): Element[] {
    const chain: Element[] = [];

    for (
        let element = node.parentElement;
        element !== null;
        element = element.parentElement
    ) {
        chain.push(element);

        if ((element as Node) === (root as Node)) {
            break;
        }
    }

    return chain;
}

/**
 * Is this a grid whose column is sized by its contents rather than by the page?
 *
 * A responsive `sm:grid-cols-2` does not answer for the narrow window where the
 * overflow shows, so only an unprefixed column counts. `grid-flow-col` and
 * `auto-cols-*` describe a strip that is meant to run wide - it belongs in a
 * scroll container - and are left to the run rule below.
 */
function hasIntrinsicColumns(element: Element): boolean {
    const classes = classesOf(element);

    if (!classes.includes('grid')) {
        return false;
    }

    if (element.children.length === 0) {
        return false;
    }

    if ((element as HTMLElement).style.gridTemplateColumns !== '') {
        return false;
    }

    return !classes.some((name) =>
        /^(grid-cols-|auto-cols-|grid-flow-col)/.test(name),
    );
}

/**
 * Does this text inherit `white-space: nowrap` from something above it?
 *
 * `white-space` inherits, so the nearest ancestor that speaks for it decides:
 * a `whitespace-normal` inside a `truncate` puts wrapping back, and looking
 * further up after finding it would report the outer rule instead of the one
 * in force.
 */
function inheritedNowrap(chain: Element[]): boolean {
    for (const element of chain) {
        const classes = classesOf(element);

        if (classes.some((name) => WRAP.has(name))) {
            return false;
        }

        if (classes.some((name) => NOWRAP.has(name))) {
            return true;
        }
    }

    return false;
}

/** The longest run of text that has to be placed without a break in it. */
function longestRun(text: string, nowrap: boolean): string {
    const collapsed = text.replace(/\s+/g, ' ').trim();

    if (nowrap) {
        return collapsed;
    }

    return collapsed
        .split(' ')
        .reduce(
            (longest, word) => (word.length > longest.length ? word : longest),
            '',
        );
}

/**
 * Every element and string in this tree that would widen the window.
 *
 * Returns the offenders rather than throwing, so a test can name them: an
 * assertion that a page "does not overflow" is only useful if failing it says
 * which string and which container.
 */
export function horizontalOverflowRisks(
    root: ParentNode,
): HorizontalOverflowRisk[] {
    const risks: HorizontalOverflowRisk[] = [];

    for (const element of root.querySelectorAll('*')) {
        if (hasIntrinsicColumns(element)) {
            risks.push({
                rule: 'intrinsic-track',
                where: describe(element),
                detail: 'grid without grid-cols-*: the column is sized by the widest thing inside it, not by the page',
            });
        }
    }

    const walker = (root.ownerDocument ?? document).createTreeWalker(
        root as Node,
        NodeFilter.SHOW_TEXT,
    );

    for (
        let node = walker.nextNode();
        node !== null;
        node = walker.nextNode()
    ) {
        const parent = node.parentElement;

        if (parent === null || node.textContent === null) {
            continue;
        }

        const chain = ancestors(node, root);

        if (
            NOT_LAID_OUT_HERE.has(parent.tagName.toLowerCase()) ||
            chain.some((element) => classesOf(element).includes('sr-only'))
        ) {
            continue;
        }

        const nowrap = inheritedNowrap(chain);

        const run = longestRun(node.textContent, nowrap);

        if (run.length <= LONGEST_SAFE_RUN) {
            continue;
        }

        const escaped = chain.some((element) =>
            classesOf(element).some((name) => ESCAPES.has(name)),
        );

        if (!escaped) {
            risks.push({
                rule: 'unbreakable-run',
                where: describe(parent),
                detail: `${run.length} characters with nowhere to break or be clipped: ${JSON.stringify(
                    run.slice(0, 60),
                )}`,
            });
        }
    }

    return risks;
}
