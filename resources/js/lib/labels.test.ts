import { describe, expect, it } from 'vitest';
import { statusLabel } from './labels';

describe('statusLabel', () => {
    /**
     * The columns hold the billing enums' own vocabulary, which is the right
     * thing to store and the wrong thing to print. `partially_paid` sat in a
     * badge on the client's own home screen, beside a figure they were being
     * asked to pay.
     */
    it('reads a stored status as words', () => {
        expect(statusLabel('partially_paid')).toBe('Partially paid');
        expect(statusLabel('one_time')).toBe('One time');
        expect(statusLabel('open')).toBe('Open');
    });

    /**
     * Sentence case, not Title Case: these sit in small badges beside running
     * text, and Title Case makes every one of them shout.
     */
    it('capitalises only the first word', () => {
        expect(statusLabel('partially_paid')).not.toBe('Partially Paid');
    });

    /**
     * A formatter rather than a lookup table, so a status it has never seen
     * still reads as words. On a screen about money, an unknown enum case
     * spelled out is a better failure than a blank badge.
     */
    it('spells out a status it does not know', () => {
        expect(statusLabel('awaiting_counter_signature')).toBe(
            'Awaiting counter signature',
        );
    });

    it('says nothing rather than nothing at all when there is no status', () => {
        expect(statusLabel(null)).toBe('—');
        expect(statusLabel(undefined)).toBe('—');
        expect(statusLabel('')).toBe('—');
    });
});
