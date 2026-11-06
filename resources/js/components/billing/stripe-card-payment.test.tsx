import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StripeCardPayment } from './stripe-card-payment';

const apiRequest = vi.fn();

vi.mock('@/lib/api', () => ({
    apiRequest: (...args: unknown[]) => apiRequest(...args),
    newIdempotencyKey: () => 'key-fixture',
}));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn(() => Promise.resolve(true)),
}));

function stripeStub(confirm: () => Promise<{ error?: { message?: string } }>) {
    const element = { mount: vi.fn(), destroy: vi.fn() };
    const elements = { create: vi.fn(() => element) };
    const instance = {
        elements: vi.fn(() => elements),
        confirmPayment: vi.fn(confirm),
    };
    window.Stripe = vi.fn(() => instance);

    return { element, elements, instance };
}

describe('paying by card', () => {
    beforeEach(() => {
        apiRequest.mockReset();
    });

    afterEach(() => {
        delete window.Stripe;
    });

    it('creates the intent on the server route, mounts Stripe’s form and confirms there', async () => {
        const stub = stripeStub(() => Promise.resolve({}));
        apiRequest.mockResolvedValue({
            ok: true,
            status: 201,
            data: { client_secret: 'cs_fixture' },
        });
        render(
            <StripeCardPayment
                intentHref="/workspaces/ws/invoices/inv/stripe-payment-intent"
                publishableKey="pk_test_fixture"
                primary
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pay by card' }));

        await screen.findByRole('button', { name: 'Pay now' });
        expect(apiRequest).toHaveBeenCalledWith(
            'POST',
            '/workspaces/ws/invoices/inv/stripe-payment-intent',
            { idempotency_key: 'key-fixture' },
            'key-fixture',
        );
        // Stripe's form is mounted by an effect after the button renders.
        await waitFor(() => expect(stub.element.mount).toHaveBeenCalled());
        expect(window.Stripe).toHaveBeenCalledWith('pk_test_fixture');
        expect(stub.instance.elements).toHaveBeenCalledWith({
            clientSecret: 'cs_fixture',
        });

        fireEvent.click(screen.getByRole('button', { name: 'Pay now' }));

        expect(await screen.findByRole('status')).toHaveTextContent(
            'Payment submitted',
        );
        expect(stub.instance.confirmPayment).toHaveBeenCalledWith(
            expect.objectContaining({ redirect: 'if_required' }),
        );
    });

    it('says why when the server refuses the intent', async () => {
        stripeStub(() => Promise.resolve({}));
        apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message:
                'A pending payment already reserves the remaining invoice balance.',
        });
        render(
            <StripeCardPayment
                intentHref="/pay"
                publishableKey="pk_test_fixture"
                primary
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pay by card' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'already reserves',
        );
        expect(
            screen.getByRole('button', { name: 'Pay by card' }),
        ).toBeEnabled();
    });

    it('keeps the form and says so when Stripe declines the card', async () => {
        stripeStub(() =>
            Promise.resolve({ error: { message: 'Your card was declined.' } }),
        );
        apiRequest.mockResolvedValue({
            ok: true,
            status: 201,
            data: { client_secret: 'cs_fixture' },
        });
        render(
            <StripeCardPayment
                intentHref="/pay"
                publishableKey="pk_test_fixture"
                primary
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pay by card' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Pay now' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Your card was declined.',
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Pay now' }),
            ).toBeEnabled(),
        );
    });

    it('does not offer a second try on an intent an earlier attempt already made', async () => {
        stripeStub(() => Promise.resolve({}));
        apiRequest.mockResolvedValue({
            ok: true,
            status: 201,
            data: { client_secret: null },
        });
        render(
            <StripeCardPayment
                intentHref="/pay"
                publishableKey="pk_test_fixture"
                primary={false}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pay by card' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'already in progress',
        );
    });
});
