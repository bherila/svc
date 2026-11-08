import { afterEach, describe, expect, it } from 'vitest';
import {
    collectUiConsistencyRisks,
    uiConsistencyRisks,
} from '@/test/ui-consistency';

function mount(html: string) {
    document.documentElement.style.setProperty('--primary', 'rgb(10, 20, 30)');
    document.body.innerHTML = html;
}

afterEach(() => {
    document.body.innerHTML = '';
});

const GOOD_CARD = `
    <div data-slot="card" style="border-top-left-radius:12px;padding-top:20px">
        <div data-slot="card-title" style="font-size:16px">Title</div>
    </div>`;

describe('uiConsistencyRisks', () => {
    it('passes a card that matches the tokens', () => {
        mount(GOOD_CARD);

        expect(collectUiConsistencyRisks()).toEqual([]);
    });

    it('reports a card whose radius, padding or title differs', () => {
        mount(`
            <div data-slot="card" style="border-top-left-radius:14px;padding-top:16px">
                <div data-slot="card-title" style="font-size:14px">Title</div>
            </div>`);

        expect(collectUiConsistencyRisks().map((risk) => risk.rule)).toEqual([
            'card-radius',
            'card-padding',
            'card-title-size',
        ]);
    });

    it('reports more than one filled button, and only then', () => {
        const filled = 'style="background-color:var(--primary)"';

        mount(`<button ${filled}>Pay</button>`);
        expect(collectUiConsistencyRisks()).toEqual([]);

        mount(`<button ${filled}>Pay</button><a href="/x" ${filled}>Issue</a>`);
        expect(collectUiConsistencyRisks().map((risk) => risk.rule)).toEqual([
            'filled-buttons',
        ]);
    });

    it('counts an open modal dialog alone, because the page behind it is inert', () => {
        const filled = 'style="background-color:var(--primary)"';

        mount(`
            <button ${filled}>Log time</button>
            <div role="dialog" data-open>
                <button ${filled}>Save</button>
                <button>Cancel</button>
            </div>`);
        expect(collectUiConsistencyRisks()).toEqual([]);

        mount(`
            <button ${filled}>Log time</button>
            <div role="dialog" data-open>
                <button ${filled}>Save</button>
                <button ${filled}>Discard</button>
            </div>`);
        expect(collectUiConsistencyRisks().map((risk) => risk.rule)).toEqual([
            'filled-buttons',
        ]);

        // A dialog that is closed (or not modal) leaves the page counted whole.
        mount(`
            <button ${filled}>Log time</button>
            <div role="dialog"><button ${filled}>Save</button></div>`);
        expect(collectUiConsistencyRisks().map((risk) => risk.rule)).toEqual([
            'filled-buttons',
        ]);
    });

    it('reports a bordered link in a card body', () => {
        mount(`
            <div data-slot="card-content">
                <a href="/x" style="border:1px solid red">Open</a>
                <a href="/y">Plain</a>
            </div>`);

        const risks = collectUiConsistencyRisks();

        expect(risks).toHaveLength(1);
        expect(risks[0]?.rule).toBe('bordered-link-in-card');
    });

    it('reports a button with no accessible name, and an action with no icon', () => {
        mount(`
            <button><svg></svg></button>
            <button aria-label="Close"><svg></svg></button>
            <button data-slot="button"><svg></svg>Approve</button>
            <button data-slot="button">Save</button>`);

        const risks = collectUiConsistencyRisks();

        expect(risks.map((risk) => [risk.rule, risk.severity])).toEqual([
            ['action-button-name', 'error'],
            ['action-button-icon', 'advisory'],
        ]);
    });

    it('runs through a page', async () => {
        mount(GOOD_CARD);

        await expect(
            uiConsistencyRisks({ evaluate: async (fn) => fn() }),
        ).resolves.toEqual([]);
    });
});
