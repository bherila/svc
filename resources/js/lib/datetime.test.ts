import { describe, expect, it } from 'vitest';
import {
    formatDay,
    formatTimestamp,
    formatTimestampDate,
} from '@/lib/datetime';

/**
 * These run in whatever zone the test process is in, so they assert the
 * properties that must hold everywhere rather than one locale's exact string.
 * The one thing worth pinning precisely is the calendar-date rule: a `YYYY-MM-DD`
 * must render as that day in every zone, and a naive `new Date(...)` breaks it
 * for every reader west of Greenwich.
 */
describe('formatTimestamp', () => {
    it('does not print the stored ISO string', () => {
        const formatted = formatTimestamp('2026-01-01T00:00:00.000000Z');

        expect(formatted).not.toContain('T');
        expect(formatted).not.toContain('Z');
        expect(formatted).not.toBe('2026-01-01T00:00:00.000000Z');
    });

    it('says nothing rather than throwing on an absent or broken value', () => {
        expect(formatTimestamp(null)).toBe('—');
        expect(formatTimestamp(undefined)).toBe('—');
        expect(formatTimestamp('')).toBe('—');
        expect(formatTimestamp('not a date')).toBe('—');
    });

    it('drops the time where only the day is being reported', () => {
        const withTime = formatTimestamp('2026-06-15T13:45:00.000000Z');
        const dayOnly = formatTimestampDate('2026-06-15T13:45:00.000000Z');

        expect(withTime.length).toBeGreaterThan(dayOnly.length);
    });
});

describe('formatDay', () => {
    it('keeps the calendar day it was given, whatever zone the reader is in', () => {
        const formatted = formatDay('2026-01-01');

        // The year and the day-of-month are what a timezone slip would move.
        expect(formatted).toContain('2026');
        expect(formatted).toContain('1');
        expect(formatted).not.toContain('31');
        expect(formatted).not.toContain('2025');
    });

    it('leaves a value it does not recognise alone', () => {
        expect(formatDay('sometime in June')).toBe('sometime in June');
        expect(formatDay(null)).toBe('—');
        expect(formatDay('   ')).toBe('—');
    });
});
