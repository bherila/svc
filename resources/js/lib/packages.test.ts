import { describe, expect, it } from 'vitest';
import { cadenceLabel, majorText, priceLine, textBlocks } from '@/lib/packages';

describe('package helpers', () => {
    it('words every cadence, including one it has not met', () => {
        expect(cadenceLabel('semi_annual')).toBe('Every 6 months');
        expect(cadenceLabel('fortnightly')).toBe('Fortnightly');
        expect(priceLine(4500, 'USD', 'annual')).toBe('$45.00 per year');
        expect(priceLine(90000, 'USD', 'one_time')).toBe('$900.00 once');
    });

    it('writes minor units as the text an input holds', () => {
        expect(majorText(4500)).toBe('45.00');
        expect(majorText(5)).toBe('0.05');
        expect(majorText(null)).toBe('');
    });

    it('reads dash lines as a list and everything else as plain text', () => {
        expect(textBlocks('- one\n* two\n\nHello\nthere\n<b>x</b>')).toEqual([
            { type: 'list', items: ['one', 'two'] },
            { type: 'paragraph', text: 'Hello there <b>x</b>' },
        ]);
        expect(textBlocks(null)).toEqual([]);
    });
});
