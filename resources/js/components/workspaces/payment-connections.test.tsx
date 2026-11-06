import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PaymentConnections } from '@/components/workspaces/payment-connections';
import type { PaymentProcessorView } from '@/components/workspaces/payment-connections';

const mocks = vi.hoisted(() => ({
    reload: vi.fn(),
    apiRequest: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ router: { reload: mocks.reload } }));
vi.mock('@/lib/api', () => ({ apiRequest: mocks.apiRequest }));

function stripe(
    overrides: Partial<PaymentProcessorView> = {},
): PaymentProcessorView {
    return {
        processor: 'stripe',
        label: 'Stripe',
        connection: null,
        environment_path_active: false,
        reusable: [],
        save_url: '/synthetic/save',
        reuse_url: '/synthetic/reuse',
        remove_url: null,
        ...overrides,
    };
}

describe('payment connections', () => {
    beforeEach(() => {
        mocks.reload.mockReset();
        mocks.apiRequest.mockReset();
        mocks.apiRequest.mockResolvedValue({
            ok: true,
            status: 204,
            data: null,
        });
    });

    it('shows a stored key only as its masked hint', () => {
        const { container } = render(
            <PaymentConnections
                processors={[
                    stripe({
                        connection: {
                            display_name: 'Synthetic Business',
                            environment: 'live',
                            key_hint: 'rk_live_…a1b2',
                            location_id: null,
                            reused_from: null,
                            usable: true,
                            webhook_url: null,
                        },
                        save_url: '/synthetic/save',
                        remove_url: '/synthetic/remove',
                    }),
                ]}
            />,
        );

        expect(
            container.querySelector('[data-test="payment-stripe-hint"]')
                ?.textContent,
        ).toBe('rk_live_…a1b2');
        expect(screen.queryByLabelText(/restricted key/i)).toBeNull();
        // The stored "live" reads as a word, not as the column.
        expect(screen.getByText('Live')).toBeTruthy();
        expect(screen.queryByText('live')).toBeNull();
    });

    it('sends the key once through the session API and clears it', async () => {
        render(<PaymentConnections processors={[stripe()]} />);

        const field = screen.getByLabelText(
            'Stripe restricted key',
        ) as HTMLInputElement;
        expect(field.type).toBe('password');
        fireEvent.change(field, { target: { value: 'rk_test_synthetic_key' } });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        await waitFor(() => expect(mocks.reload).toHaveBeenCalledTimes(1));
        expect(mocks.apiRequest).toHaveBeenCalledTimes(1);
        expect(mocks.apiRequest).toHaveBeenCalledWith(
            'POST',
            '/synthetic/save',
            { secret: 'rk_test_synthetic_key' },
        );
        expect(field.value).toBe('');
    });

    it('shows the processor refusal and keeps the form', async () => {
        mocks.apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'Invalid',
            errors: { secret: ['Stripe did not accept this key.'] },
        });
        render(<PaymentConnections processors={[stripe()]} />);

        fireEvent.change(screen.getByLabelText('Stripe restricted key'), {
            target: { value: 'rk_test_synthetic_key' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Connect' }));

        expect(
            await screen.findByText('Stripe did not accept this key.'),
        ).toBeTruthy();
        expect(mocks.reload).not.toHaveBeenCalled();
    });

    it('offers no form to a reader who cannot manage connections', () => {
        render(
            <PaymentConnections
                processors={[
                    stripe({
                        save_url: null,
                        reuse_url: null,
                        remove_url: null,
                    }),
                ]}
            />,
        );

        expect(screen.queryByLabelText('Stripe restricted key')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Connect' })).toBeNull();
    });

    it('lists reusable connections by name', () => {
        render(
            <PaymentConnections
                processors={[
                    stripe({
                        reusable: [
                            {
                                id: 'synthetic-source',
                                label: 'Use the Stripe account connected to Synthetic Workspace',
                            },
                        ],
                    }),
                ]}
            />,
        );

        expect(screen.getByText('Or reuse a connection')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Use this connection' }),
        ).toBeTruthy();
    });
});
