import { describe, expect, it } from 'vitest';
import {
    authorizationWording,
    autopayMoney,
    limitField,
    parseLimit,
} from '@/lib/autopay';

describe('autopay wording and amounts', () => {
    it('composes the authorization sentence exactly as the server stores it', () => {
        // The same literal as tests/Unit/Billing/Autopay/AutopayWordingTest.php.
        expect(
            authorizationWording({
                provider: 'Example Studio',
                company: 'Northwind Example Co.',
                method: 'Visa ending 4242',
                limit: 500_000,
                currency: 'USD',
            }),
        ).toBe(
            'I authorize Example Studio to charge Visa ending 4242 for Northwind Example Co. invoices on their due dates, up to $5,000.00 each, until I turn autopay off.',
        );
    });

    it('writes money like the server does', () => {
        expect(autopayMoney(1, 'usd')).toBe('$0.01');
        expect(autopayMoney(123_456_789, 'USD')).toBe('$1,234,567.89');
        expect(autopayMoney(500_000, 'eur')).toBe('EUR 5,000.00');
    });

    it('reads a typed limit strictly, in minor units', () => {
        expect(parseLimit('5,000.00')).toBe(500_000);
        expect(parseLimit('$5000')).toBe(500_000);
        expect(parseLimit(' 12.5 ')).toBe(1_250);
        expect(parseLimit('0.01')).toBe(1);

        for (const bad of [
            '',
            '0',
            '0.00',
            '-5',
            '5.001',
            '1e3',
            'five',
            '1,00,0x',
        ]) {
            expect(parseLimit(bad), bad).toBeNull();
        }
    });

    it('writes a limit back into a field', () => {
        expect(limitField(500_000)).toBe('5000.00');
        expect(limitField(null)).toBe('');
    });
});
