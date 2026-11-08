import { router } from '@inertiajs/react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaymentMethodsCard } from '@/components/billing/payment-methods-portal';
import type {
    MethodSetup,
    SavedMethod,
} from '@/components/billing/payment-methods-portal';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const apiRequest = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), delete: vi.fn() },
}));
vi.mock('@/lib/api', () => ({
    apiRequest: (...args: unknown[]) => apiRequest(...args),
    newIdempotencyKey: () => 'key-fixture',
}));
vi.mock('@/lib/refresh-page-props', () => ({
    refreshPageProps: vi.fn(() => Promise.resolve(true)),
}));

const LONG = 'Unbroken'.repeat(14);

const SETUP: MethodSetup = {
    setup_href: '/portal/c1/payment-methods/setup',
    confirm_href: '/portal/c1/payment-methods/confirm',
    publishable_key: 'pk_test_fixture',
};

const DEFAULT_METHOD: SavedMethod = {
    id: 'm1',
    label: 'Visa ending 4242',
    type: 'card',
    exp_month: 8,
    exp_year: 2038,
    is_default: true,
    default_href: null,
    remove_href: '/portal/c1/payment-methods/m1',
};

const OTHER_METHOD: SavedMethod = {
    id: 'm2',
    label: `Bank account ending 6789 ${LONG}`,
    type: 'us_bank_account',
    exp_month: null,
    exp_year: null,
    is_default: false,
    default_href: '/portal/c1/payment-methods/m2/default',
    remove_href: '/portal/c1/payment-methods/m2',
};

function card(
    overrides: Partial<Parameters<typeof PaymentMethodsCard>[0]> = {},
) {
    return (
        <PaymentMethodsCard
            providerName="Example Studio"
            companyName="Northwind Example Co."
            methods={[DEFAULT_METHOD, OTHER_METHOD]}
            setup={SETUP}
            notice={null}
            autopayEnabled
            {...overrides}
        />
    );
}

function stripeStub(confirm: () => Promise<unknown>) {
    const element = { mount: vi.fn(), destroy: vi.fn() };
    const elements = { create: vi.fn(() => element) };
    const instance = {
        elements: vi.fn(() => elements),
        confirmPayment: vi.fn(),
        confirmSetup: vi.fn(confirm),
    };
    window.Stripe = vi.fn(() => instance) as unknown as typeof window.Stripe;

    return { element, elements, instance };
}

beforeEach(() => {
    apiRequest.mockReset();
    vi.mocked(router.post).mockReset();
    vi.mocked(router.delete).mockReset();
});

afterEach(() => {
    delete window.Stripe;
    window.history.replaceState(null, '', '/');
});

describe('the portal payment methods card', () => {
    it('lists methods with the actions the server allowed', () => {
        render(card());

        expect(screen.getByText('Default')).toBeVisible();
        expect(
            screen.getByText('Expires 08/38 · Used for autopay'),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Make default: Visa ending 4242',
            }),
        ).toBeNull();
        expect(
            screen.getByRole('button', {
                name: `Make default: ${OTHER_METHOD.label}`,
            }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Add payment method' }),
        ).toBeVisible();
    });

    it('shows no controls, and the reason, when the server sent no URLs', () => {
        render(
            card({
                setup: null,
                notice: 'Only a contact with access to the whole company can add, remove or change payment methods.',
                methods: [
                    { ...DEFAULT_METHOD, remove_href: null },
                    { ...OTHER_METHOD, default_href: null, remove_href: null },
                ],
            }),
        );

        expect(screen.getByText(/Only a contact with access/)).toBeVisible();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('makes a method the default through the finished URL', async () => {
        const user = userEvent.setup();
        render(card());

        await user.click(
            screen.getByRole('button', {
                name: `Make default: ${OTHER_METHOD.label}`,
            }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/portal/c1/payment-methods/m2/default',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows the refusal the server gives when the default cannot change', async () => {
        const user = userEvent.setup();
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onError?.({
                payment_method: 'Stripe could not complete that.',
            });
        });
        render(card());

        await user.click(
            screen.getByRole('button', {
                name: `Make default: ${OTHER_METHOD.label}`,
            }),
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Stripe could not complete that.',
        );
    });

    it('warns that autopay turns off before removing the default, and removes only on confirmation', async () => {
        const user = userEvent.setup();
        render(card());

        await user.click(
            screen.getByRole('button', { name: 'Remove Visa ending 4242' }),
        );
        const dialog = screen.getByRole('alertdialog');

        expect(within(dialog).getByText(/autopay will turn off/)).toBeVisible();
        expect(router.delete).not.toHaveBeenCalled();

        await user.click(
            within(dialog).getByRole('button', {
                name: 'Remove payment method',
            }),
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/portal/c1/payment-methods/m1',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('does not claim autopay turns off when it is off, or when the method is not the default', async () => {
        const user = userEvent.setup();
        const { unmount } = render(card({ autopayEnabled: false }));

        await user.click(
            screen.getByRole('button', { name: 'Remove Visa ending 4242' }),
        );
        expect(
            within(screen.getByRole('alertdialog')).getByText(/Autopay is off/),
        ).toBeVisible();
        unmount();

        render(card());
        await user.click(
            screen.getByRole('button', {
                name: `Remove ${OTHER_METHOD.label}`,
            }),
        );
        expect(
            within(screen.getByRole('alertdialog')).getByText(
                /Your default method and autopay are not affected/,
            ),
        ).toBeVisible();
    });

    it('adds a method only after the client agrees, mounting Stripe’s form and reading the outcome back', async () => {
        const user = userEvent.setup();
        const stub = stripeStub(() =>
            Promise.resolve({
                setupIntent: { id: 'seti_fixture', status: 'succeeded' },
            }),
        );
        apiRequest
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                data: { client_secret: 'seti_secret_fixture' },
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                data: { outcome: 'saved', message: 'Payment method saved.' },
            });
        render(card());

        await user.click(
            screen.getByRole('button', { name: 'Add payment method' }),
        );
        const dialog = screen.getByRole('dialog');
        const next = within(dialog).getByRole('button', { name: 'Continue' });

        expect(next).toBeDisabled();
        await user.click(
            within(dialog).getByRole('checkbox', {
                name: /Save this method for future payments from Example Studio/,
            }),
        );
        await user.click(next);

        await within(dialog).findByRole('button', {
            name: 'Save payment method',
        });
        expect(apiRequest).toHaveBeenNthCalledWith(
            1,
            'POST',
            SETUP.setup_href,
            {},
            'key-fixture',
        );
        expect(window.Stripe).toHaveBeenCalledWith('pk_test_fixture');
        expect(stub.instance.elements).toHaveBeenCalledWith({
            clientSecret: 'seti_secret_fixture',
        });
        await waitFor(() => expect(stub.element.mount).toHaveBeenCalled());

        await user.click(
            within(dialog).getByRole('button', { name: 'Save payment method' }),
        );

        expect(await within(dialog).findByRole('status')).toHaveTextContent(
            'Payment method saved.',
        );
        expect(stub.instance.confirmSetup).toHaveBeenCalledWith(
            expect.objectContaining({ redirect: 'if_required' }),
        );
        expect(apiRequest).toHaveBeenNthCalledWith(
            2,
            'POST',
            SETUP.confirm_href,
            {
                setup_intent: 'seti_fixture',
            },
        );
    });

    it('says why when the server refuses to start, and when Stripe does not accept the method', async () => {
        const user = userEvent.setup();
        apiRequest.mockResolvedValueOnce({
            ok: false,
            status: 422,
            message: 'Stripe could not complete that.',
        });
        render(card());

        await user.click(
            screen.getByRole('button', { name: 'Add payment method' }),
        );
        const dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('checkbox'));
        stripeStub(() =>
            Promise.resolve({ error: { message: 'Your card was declined.' } }),
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );

        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'Stripe could not complete that.',
        );

        apiRequest.mockResolvedValueOnce({
            ok: true,
            status: 201,
            data: { client_secret: 'seti_secret_fixture' },
        });
        await user.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        await user.click(
            await within(dialog).findByRole('button', {
                name: 'Save payment method',
            }),
        );

        expect(await within(dialog).findByRole('alert')).toHaveTextContent(
            'Your card was declined.',
        );
        expect(apiRequest).toHaveBeenCalledTimes(2);
    });

    it('reads a setup back when Stripe returns the client to the page', async () => {
        window.history.replaceState(
            null,
            '',
            '/portal/c1/billing?setup_intent=seti_returned&setup_intent_client_secret=seti_returned_secret_x&redirect_status=succeeded',
        );
        apiRequest.mockResolvedValue({
            ok: true,
            status: 200,
            data: {
                outcome: 'pending',
                message: 'Stripe is still verifying this payment method.',
            },
        });
        render(card());

        expect(await screen.findByRole('status')).toHaveTextContent(
            'still verifying',
        );
        expect(apiRequest).toHaveBeenCalledWith('POST', SETUP.confirm_href, {
            setup_intent: 'seti_returned',
        });
        expect(window.location.search).toBe('');
    });

    it('has no overflow risk with unbroken names', () => {
        const { container } = render(
            card({ companyName: LONG, providerName: LONG }),
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
