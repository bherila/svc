import { describe, expect, it } from 'vitest';
import {
    describeDuration,
    formatDuration,
    formatInvoiceDuration,
    parseDuration,
} from '@/lib/duration';
import type { DurationStyle } from '@/lib/duration';
import cases from '../../../tests/Fixtures/duration-cases.json';

describe('parseDuration', () => {
    it.each(cases.parse)('reads "$input"', ({ input, minutes }) => {
        expect(parseDuration(input)).toBe(minutes);
    });
});

describe('formatDuration', () => {
    it.each(cases.format)(
        '$minutes min as $style',
        ({ minutes, style, text }) => {
            expect(formatDuration(minutes, style as DurationStyle)).toBe(text);
        },
    );

    it('always renders invoices as decimal', () => {
        expect(formatInvoiceDuration(150)).toBe('2.5 h');
    });
});

describe('describeDuration', () => {
    it('says what it understood', () => {
        expect(describeDuration('2h30m', 'decimal')).toBe('= 2.5 h (150 min)');
        expect(describeDuration('2.5', 'clock')).toBe('= 2:30 (150 min)');
        expect(describeDuration('nope', 'decimal')).toBeNull();
    });
});
