import { router } from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    PortalAutopayCard,
    PortalInvoiceAutopayCard,
} from '@/components/billing/autopay-portal';
import type {
    PortalAutopay,
    PortalInvoiceAutopay,
} from '@/components/billing/autopay-portal';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), delete: vi.fn() },
}));

const LONG = 'Unbroken'.repeat(14);

function autopay(overrides: Partial<PortalAutopay> = {}): PortalAutopay {
    return {
        enabled: false,
        enabled_at: null,
        limit_amount: null,
        currency: 'USD',
        method: { label: 'Visa ending 4242' },
        upcoming: [],
        consents: [],
        enable_href: '/portal/company-1/autopay',
        disable_href: null,
        wording: {
            provider: 'Example Studio',
            company: 'Northwind Example Co.',
            method: 'Visa ending 4242',
            currency: 'USD',
        },
        suggested_limit: 500_000,
        largest_invoice: 375_000,
        ...overrides,
    };
}

const SENTENCE =
    'I authorize Example Studio to charge Visa ending 4242 for Northwind Example Co. invoices on their due dates, up to $5,000.00 each, until I turn autopay off.';

beforeEach(() => {
    vi.mocked(router.post).mockReset();
    vi.mocked(router.delete).mockReset();
});

describe('the portal autopay card', () => {
    it('turns autopay on only with a valid limit and the ticked authorization, sending the exact wording', async () => {
        const user = userEvent.setup();
        render(
            <PortalAutopayCard
                providerName="Example Studio"
                autopay={autopay()}
                available
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Turn on autopay' }),
        );
        const dialog = screen.getByRole('dialog');
        const confirm = within(dialog).getByRole('button', {
            name: 'Turn on autopay',
        });
        const limit = within(dialog).getByLabelText(
            /Charge up to, per invoice/,
        );

        expect(limit).toHaveValue('5000.00');
        expect(
            within(dialog).getByText(
                /Your largest recent invoice was \$3,750.00/,
            ),
        ).toBeVisible();
        expect(confirm).toBeDisabled();

        await user.click(
            within(dialog).getByRole('checkbox', { name: SENTENCE }),
        );
        expect(confirm).toBeEnabled();

        await user.clear(limit);
        await user.type(limit, 'lots');
        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'Enter an amount',
        );
        expect(confirm).toBeDisabled();

        await user.clear(limit);
        await user.type(limit, '5,000.00');
        await user.click(confirm);

        expect(router.post).toHaveBeenCalledWith(
            '/portal/company-1/autopay',
            { limit_amount: 500_000, wording: SENTENCE, authorized: true },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows the refusal the server gives', async () => {
        const user = userEvent.setup();
        vi.mocked(router.post).mockImplementation((_url, _data, options) => {
            options?.onError?.({
                authorized:
                    'Autopay needs a default saved payment method. Add one first.',
            });
        });
        render(
            <PortalAutopayCard
                providerName="Example Studio"
                autopay={autopay()}
                available
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Turn on autopay' }),
        );
        const dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('checkbox'));
        await user.click(
            within(dialog).getByRole('button', { name: 'Turn on autopay' }),
        );

        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'Autopay needs a default saved payment method',
        );
    });

    it('shows what is on, and turns it off only after confirmation', async () => {
        const user = userEvent.setup();
        render(
            <PortalAutopayCard
                providerName="Example Studio"
                available
                autopay={autopay({
                    enabled: true,
                    limit_amount: 500_000,
                    disable_href: '/portal/company-1/autopay',
                    upcoming: [
                        {
                            invoice_id: 'i1',
                            invoice_number: 'INV-0142',
                            due_on: '2030-12-01',
                            amount: 375_000,
                            currency: 'USD',
                            state: 'will_charge',
                        },
                    ],
                    consents: [
                        {
                            id: 'c1',
                            action: 'enabled',
                            actor_name: 'Dana Example',
                            actor_role: 'client',
                            limit_amount: 500_000,
                            currency: 'USD',
                            reason: null,
                            occurred_at: '2030-10-03T14:12:00Z',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Visa ending 4242 (default)')).toBeVisible();
        expect(screen.getByText('$5,000.00')).toBeVisible();
        expect(screen.getByText(/\$3,750.00 on .* · INV-0142/)).toBeVisible();
        expect(screen.getByText('On')).toBeVisible();

        await user.click(
            screen.getByRole('button', { name: 'Turn off autopay' }),
        );
        expect(router.delete).not.toHaveBeenCalled();
        await user.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Turn off autopay',
            }),
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/portal/company-1/autopay',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('offers no button to someone who may not consent, and says why', () => {
        render(
            <PortalAutopayCard
                providerName="Example Studio"
                available
                autopay={autopay({ enable_href: null })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Turn on autopay' }),
        ).toBeNull();
        expect(
            screen.getByText(
                'Only a contact of this company can turn on autopay.',
            ),
        ).toBeVisible();
    });

    it('explains a missing default method and an unconnected provider', () => {
        const { rerender } = render(
            <PortalAutopayCard
                providerName="Example Studio"
                available
                autopay={autopay({ wording: null })}
            />,
        );
        expect(
            screen.getByText(/needs a default saved payment method/),
        ).toBeVisible();

        rerender(
            <PortalAutopayCard
                providerName="Example Studio"
                available={false}
                autopay={autopay()}
            />,
        );
        expect(
            screen.getByText(/has not set up card payments yet/),
        ).toBeVisible();
    });

    it('reads a hostile provider name and history without overflow risk', () => {
        const { container } = render(
            <PortalAutopayCard
                providerName={LONG}
                available
                autopay={autopay({
                    enabled: true,
                    limit_amount: 500_000,
                    method: { label: LONG },
                    disable_href: '/x',
                    consents: [
                        {
                            id: 'c1',
                            action: 'enabled',
                            actor_name: LONG,
                            actor_role: 'client',
                            limit_amount: 500_000,
                            currency: 'USD',
                            reason: LONG,
                            occurred_at: '2030-10-03T14:12:00Z',
                        },
                    ],
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

describe('autopay on a portal invoice', () => {
    function invoiceAutopay(
        overrides: Partial<PortalInvoiceAutopay> = {},
    ): PortalInvoiceAutopay {
        return {
            state: 'will_charge',
            state_label: 'Will autopay',
            amount: 375_000,
            currency: 'USD',
            limit_amount: 500_000,
            method_label: 'Visa ending 4242',
            will_charge_on: '2030-12-01',
            due_on: '2030-12-01',
            failure_reason: null,
            attempted_on: null,
            manage_href: '/portal/company-1/billing',
            ...overrides,
        };
    }

    it('says when it will charge, with nothing to do', () => {
        render(<PortalInvoiceAutopayCard autopay={invoiceAutopay()} />);

        expect(
            screen.getByRole('heading', { name: /Autopay on/ }),
        ).toBeVisible();
        expect(
            screen.getByText(
                /charge Visa ending 4242 for \$3,750.00 on the due date. Nothing to do./,
            ),
        ).toBeVisible();
        expect(screen.queryByRole('link', { name: /Pay \$/ })).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Manage autopay' }),
        ).toHaveAttribute('href', '/portal/company-1/billing');
    });

    it('says a charge failed, why, and offers to pay by hand', () => {
        render(
            <PortalInvoiceAutopayCard
                autopay={invoiceAutopay({
                    state: 'failed',
                    failure_reason: 'Your card has insufficient funds.',
                    attempted_on: '2030-12-01T15:00:00Z',
                    will_charge_on: null,
                })}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Autopay couldn’t charge your card',
            }),
        ).toBeVisible();
        expect(
            screen.getByText(
                /Your card has insufficient funds. We won’t try again automatically. Please pay by hand./,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Pay $3,750.00' }),
        ).toHaveAttribute('href', '#pay');
    });

    it('says an invoice over the limit will not be charged, naming both amounts', () => {
        render(
            <PortalInvoiceAutopayCard
                autopay={invoiceAutopay({
                    state: 'over_limit',
                    amount: 720_000,
                    will_charge_on: null,
                })}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'This invoice won’t be charged automatically',
            }),
        ).toBeVisible();
        expect(
            screen.getByText(
                /\$7,200.00 is over your \$5,000.00 autopay limit. Pay it here by/,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Pay $7,200.00' }),
        ).toBeVisible();
    });
});
