import { render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import PortalBilling from './billing';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn(), delete: vi.fn() },
}));
vi.mock('@/layouts/workspace-shell', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const LONG = 'Unbroken'.repeat(14);

function props(
    overrides: Partial<ComponentProps<typeof PortalBilling>> = {},
): ComponentProps<typeof PortalBilling> {
    return {
        company: { id: 'company-1', name: LONG },
        provider_name: LONG,
        home_href: '/portal/company-1',
        autopay_available: true,
        autopay: {
            enabled: true,
            enabled_at: '2030-10-03T14:12:00Z',
            limit_amount: 500_000,
            currency: 'USD',
            method: { label: 'Visa ending 4242' },
            upcoming: [],
            consents: [],
            enable_href: '/portal/company-1/autopay',
            disable_href: '/portal/company-1/autopay',
            wording: {
                provider: LONG,
                company: LONG,
                method: 'Visa ending 4242',
                currency: 'USD',
            },
            suggested_limit: 500_000,
            largest_invoice: null,
        },
        payment_methods: [
            {
                id: 'm1',
                label: 'Visa ending 4242',
                type: 'card',
                exp_month: 8,
                exp_year: 2038,
                is_default: true,
            },
            {
                id: 'm2',
                label: `Bank account ending 6789 ${LONG}`,
                type: 'us_bank_account',
                exp_month: null,
                exp_year: null,
                is_default: false,
            },
        ],
        ...overrides,
    };
}

describe('the portal billing page', () => {
    it('shows autopay and the saved methods, marking the default one that autopay uses', () => {
        render(<PortalBilling {...props()} />);

        expect(screen.getByRole('heading', { name: 'Autopay' })).toBeVisible();
        const methods = screen
            .getByRole('heading', { name: 'Payment methods' })
            .closest('[aria-labelledby]') as HTMLElement;

        expect(within(methods).getByText('Default')).toBeVisible();
        expect(
            within(methods).getByText('Expires 08/38 · Used for autopay'),
        ).toBeVisible();
        expect(within(methods).getByText('No expiry')).toBeVisible();
    });

    it('says plainly that no method is saved', () => {
        render(<PortalBilling {...props({ payment_methods: [] })} />);

        expect(screen.getByText(/No payment method is saved/)).toBeVisible();
    });

    it('has no overflow risk with unbroken names', () => {
        const { container } = render(<PortalBilling {...props()} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
