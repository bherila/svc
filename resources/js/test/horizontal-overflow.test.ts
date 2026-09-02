import { describe, expect, it } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

/**
 * The checker other tests lean on, checked against the layout it was written
 * for.
 *
 * A guard nobody has seen fail is a guard nobody knows works, and this one can
 * only fail on markup a page test would have to be broken to produce. So the
 * shapes are stated here directly: the tree that shipped the sideways scroll,
 * and the same tree fixed.
 */
function tree(html: string): HTMLElement {
    const root = document.createElement('div');
    root.innerHTML = html;

    return root;
}

const LONG_ENTRY =
    'SOC 2 / Vanta (Week 35): policy approvals completed, control and automated-test progress, root lockout found and filed.';

describe('horizontal overflow risks', () => {
    /**
     * The shape that shipped: one nowrap row inside a grid whose column is
     * sized by its contents. Every section of the page grew to the width of
     * this one string, which is what made the whole page scroll rather than
     * just this row.
     */
    it('names a grid column that is sized by what is inside it', () => {
        const risks = horizontalOverflowRisks(
            tree(`
                <main class="mx-auto grid max-w-4xl gap-6 px-6 py-8">
                    <section>
                        <ul>
                            <li class="flex justify-between gap-4">
                                <span class="min-w-0 flex-1 truncate">${LONG_ENTRY}</span>
                            </li>
                        </ul>
                    </section>
                </main>
            `),
        );

        expect(risks).toHaveLength(1);
        expect(risks[0].rule).toBe('intrinsic-track');
        expect(risks[0].where).toContain('grid max-w-4xl');
    });

    it('accepts the same page once the column has a definite maximum', () => {
        expect(
            horizontalOverflowRisks(
                tree(`
                    <main class="mx-auto grid max-w-4xl grid-cols-1 gap-6 px-6 py-8">
                        <section>
                            <ul>
                                <li class="flex justify-between gap-4">
                                    <span class="min-w-0 flex-1 truncate">${LONG_ENTRY}</span>
                                </li>
                            </ul>
                        </section>
                    </main>
                `),
            ),
        ).toEqual([]);
    });

    /**
     * A grid with nothing in it has no contents to be sized by, and the utility
     * is routinely written on a container before its children exist.
     */
    it('leaves an empty grid alone', () => {
        expect(
            horizontalOverflowRisks(tree('<div class="grid gap-2"></div>')),
        ).toEqual([]);
    });

    it('leaves a grid alone when the column is declared in a style attribute', () => {
        expect(
            horizontalOverflowRisks(
                tree(
                    '<div class="grid gap-2" style="grid-template-columns: minmax(0, 1fr)"><p>row</p></div>',
                ),
            ),
        ).toEqual([]);
    });

    /**
     * A responsive column says nothing about the narrow window where the
     * overflow actually shows.
     */
    it('does not accept a column that only exists above a breakpoint', () => {
        const risks = horizontalOverflowRisks(
            tree('<div class="grid gap-2 sm:grid-cols-2"><p>row</p></div>'),
        );

        expect(risks.map((risk) => risk.rule)).toEqual(['intrinsic-track']);
    });

    /** Prose wraps at its spaces however much of it there is. */
    it('does not mind long text that can wrap', () => {
        expect(horizontalOverflowRisks(tree(`<p>${LONG_ENTRY}</p>`))).toEqual(
            [],
        );
    });

    it('names an unbroken run with nowhere to go', () => {
        const risks = horizontalOverflowRisks(
            tree(
                '<h1 class="text-2xl font-semibold">Wolfeschlegelsteinhausenbergerdorff-Diagnostics</h1>',
            ),
        );

        expect(risks).toHaveLength(1);
        expect(risks[0].rule).toBe('unbreakable-run');
        expect(risks[0].detail).toContain('Wolfeschlegelstein');
    });

    it.each([
        ['broken mid-run', 'wrap-anywhere'],
        ['clipped', 'truncate'],
        ['scrolled by its own container', 'overflow-x-auto'],
    ])('accepts an unbroken run that is %s', (_, utility) => {
        expect(
            horizontalOverflowRisks(
                tree(
                    `<div class="${utility}"><h1>Wolfeschlegelsteinhausenbergerdorff-Diagnostics</h1></div>`,
                ),
            ),
        ).toEqual([]);
    });

    /**
     * `overflow-wrap: break-word` breaks a word to fit a box that already has a
     * width; it does not lower the min-content size, so a flex item carrying it
     * is still sized to its longest word.
     */
    it('does not accept break-words as somewhere for the run to go', () => {
        expect(
            horizontalOverflowRisks(
                tree(
                    '<div class="break-words"><h1>Wolfeschlegelsteinhausenbergerdorff-Diagnostics</h1></div>',
                ),
            ),
        ).not.toEqual([]);
    });

    /** Wrapping text turns into one unbreakable run under a nowrap ancestor. */
    it('treats a whole nowrap sentence as one run', () => {
        const risks = horizontalOverflowRisks(
            tree(`<p class="whitespace-nowrap">${LONG_ENTRY}</p>`),
        );

        expect(risks.map((risk) => risk.rule)).toEqual(['unbreakable-run']);
    });

    it('lets an inner whitespace-normal put the wrapping back', () => {
        expect(
            horizontalOverflowRisks(
                tree(
                    `<div class="whitespace-nowrap"><p class="whitespace-normal">${LONG_ENTRY}</p></div>`,
                ),
            ),
        ).toEqual([]);
    });

    /**
     * Screen-reader-only text is a 1px clipped box; it has no width to give the
     * page, and a `<option>` is laid out by the browser's own widget.
     */
    it.each([
        ['sr-only text', '<span class="sr-only">'],
        ['a select option', '<option>'],
    ])('ignores %s', (_, open) => {
        const close = open.replace('<', '</').replace(/ .*>/, '>');

        expect(
            horizontalOverflowRisks(
                tree(
                    `${open}Wolfeschlegelsteinhausenbergerdorff-Diagnostics${close}`,
                ),
            ),
        ).toEqual([]);
    });
});
