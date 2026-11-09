import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SignupCheckout } from '@/components/packages/signup-checkout';
import { refreshPageProps } from '@/lib/refresh-page-props';
import type { PortalCheckout, PortalServicePayment } from '@/types/packages';

const apiRequest = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
}));
vi.mock('@/lib/api', () => ({
    apiRequest: (...args: unknown[]) => apiRequest(...args),
    newIdempotencyKey: () => 'key-fixture',
}));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn(() => Promise.resolve(true)),
}));

const CONFIRM = '/portal/company-1/payment-methods/confirm';

function checkout(overrides: Partial<PortalCheckout> = {}): PortalCheckout {
    return {
        due_now: {
            currency: 'USD',
            lines: [],
            total: 0,
            balance: 0,
            estimate: false,
        },
        pay_before_activation: false,
        pay_url: null,
        payment_options: null,
        card_setup: {
            setup_url: '/portal/company-1/payment-methods/setup',
            confirm_url: CONFIRM,
            publishable_key: 'pk_test_fixture',
        },
        autopay: {
            limit_amount: 50000,
            currency: 'USD',
            limit_label: '$500.00',
            satisfied: false,
            unavailable: null,
            wording: null,
            enable_url: null,
            enable_fields: null,
            billing_url: '/portal/company-1/billing',
        },
        ...overrides,
    };
}

const PAYMENT: PortalServicePayment = {
    state: 'not_charged',
    waiting_for: ['autopay'],
    signup_invoice: null,
    requires_autopay: true,
    autopay_limit_amount: 50000,
    pay_before_activation: false,
    lapses_on: '2026-11-23',
};

function view(overrides: Partial<PortalCheckout> = {}) {
    return (
        <SignupCheckout
            checkout={checkout(overrides)}
            payment={PAYMENT}
            companyName="Synthetic Client"
            accepted
        />
    );
}

describe('sign-up checkout', () => {
    beforeEach(() => {
        apiRequest.mockReset();
        vi.mocked(refreshPageProps).mockClear();
    });
    afterEach(() =>
        window.history.replaceState(
            null,
            '',
            '/portal/company-1/services/enrollment-1',
        ),
    );

    it('[ACC-4] reads a saved card back when Stripe returns the client to the service page', async () => {
        window.history.replaceState(
            null,
            '',
            '/portal/company-1/services/enrollment-1?setup_intent=seti_returned&setup_intent_client_secret=seti_returned_secret_x&redirect_status=succeeded',
        );
        apiRequest.mockResolvedValue({
            ok: true,
            status: 200,
            data: { outcome: 'saved', message: 'Payment method saved.' },
        });
        render(view());

        await waitFor(() =>
            expect(apiRequest).toHaveBeenCalledWith('POST', CONFIRM, {
                setup_intent: 'seti_returned',
            }),
        );
        expect(await screen.findByText('Payment method saved.')).toBeVisible();
        expect(refreshPageProps).toHaveBeenCalled();
        expect(window.location.pathname).toBe(
            '/portal/company-1/services/enrollment-1',
        );
        expect(window.location.search).toBe('');
    });

    it('says why when the returned card could not be confirmed', async () => {
        window.history.replaceState(
            null,
            '',
            '/portal/company-1/services/enrollment-1?setup_intent=seti_refused&redirect_status=failed',
        );
        apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'Stripe did not accept this payment method.',
        });
        render(view());

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Stripe did not accept this payment method.',
        );
        expect(refreshPageProps).not.toHaveBeenCalled();
    });

    it('asks nothing of the server without a return in the address, or without the confirm URL', () => {
        const { unmount } = render(view());
        unmount();
        window.history.replaceState(
            null,
            '',
            '/portal/company-1/services/enrollment-1?setup_intent=seti_returned',
        );
        render(view({ card_setup: null }));

        expect(apiRequest).not.toHaveBeenCalled();
        expect(window.location.search).toBe('?setup_intent=seti_returned');
    });
});
