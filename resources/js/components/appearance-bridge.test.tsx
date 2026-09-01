import { readFileSync } from 'node:fs';
import { expect, test } from 'vitest';

test('legacy bridge selectors contribute no ancestor specificity', () => {
    const source = readFileSync('resources/css/app.css', 'utf8');
    const bridgeSelectors = source
        .split('\n')
        .map((line) => line.trim())
        .filter(
            (line) =>
                line.includes('[data-appearance-bridge]') &&
                (line.endsWith(',') || line.endsWith('{')),
        );

    expect(bridgeSelectors).not.toHaveLength(0);
    expect(bridgeSelectors).toEqual(
        bridgeSelectors.filter((selector) =>
            selector.startsWith(':where(.dark [data-appearance-bridge])'),
        ),
    );
});
