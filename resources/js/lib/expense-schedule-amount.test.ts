import { describe, expect, it } from 'vitest';
import { formatScheduleAmount } from './expense-schedule-amount';

describe('schedule amount display', () => {
    it('localizes fraction digits with the same locale as the integer', () => {
        expect(
            formatScheduleAmount('9007199254740993', 'USD', 'ar-EG'),
        ).toContain('٩٠٬٠٧١٬٩٩٢٬٥٤٧٬٤٠٩٫٩٣');
    });
    it('preserves every minor unit above JavaScript integer precision', () => {
        expect(formatScheduleAmount('9007199254740993', 'USD')).toBe(
            '$90,071,992,547,409.93',
        );
        expect(formatScheduleAmount('9223372036854775807', 'USD')).toBe(
            '$92,233,720,368,547,758.07',
        );
        expect(formatScheduleAmount('1', 'USD')).toBe('$0.01');
        expect(formatScheduleAmount('1200', 'USD')).toBe('$12.00');
    });
});
