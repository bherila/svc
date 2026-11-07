import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ClientAutopayCard,
    InvoiceAutopayCard,
} from '@/components/billing/autopay-operator';
import type {
    ClientAutopayState,
    InvoiceAutopayState,
} from '@/components/billing/autopay-operator';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const inertia = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: inertia }));

const version = 'a'.repeat(64);
const LONG = 'Unbroken'.repeat(14);

function client(
    overrides: Partial<ClientAutopayState> = {},
): ClientAutopayState {
    return {
        client_id: '00000000-0000-4000-8000-000000000001',
        version,
        enabled: false,
        enabled_at: null,
        limit_amount: null,
        currency: 'USD',
        method: {
            label: 'Visa ending 4242',
            brand: 'visa',
            last4: '4242',
            exp_month: 8,
            exp_year: 2038,
        },
        consents: [],
        upcoming: [],
        update_href: '/api/v1/workspaces/w-1/clients/c-1/autopay',
        portal_href: '/portal/c-1/billing',
        stripe_connected: true,
        attestation_min: 10,
        attestation_max: 1000,
        ...overrides,
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
    inertia.reload.mockReset();
});

describe('the client autopay settings', () => {
    it('turns autopay on with the limit and an attestation, confirmed, through the API', async () => {
        const fetchMock = vi
            .fn<(url: string, init: RequestInit) => Promise<Response>>()
            .mockResolvedValue(
                new Response(
                    JSON.stringify({ data: client({ enabled: true }) }),
                    {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            );
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        render(<ClientAutopayCard autopay={client()} />);

        await user.click(
            screen.getByRole('button', { name: 'Turn on autopay' }),
        );
        const dialog = screen.getByRole('dialog');
        const confirm = within(dialog).getByRole('button', {
            name: 'Turn on autopay',
        });
        expect(confirm).toBeDisabled();

        await user.clear(
            within(dialog).getByLabelText('Charge up to, per invoice'),
        );
        await user.type(
            within(dialog).getByLabelText('Charge up to, per invoice'),
            '4,000',
        );
        await user.type(
            within(dialog).getByLabelText(
                'How did the client agree to autopay?',
            ),
            'Signed services agreement of Aug 28.',
        );
        expect(confirm).toBeEnabled();
        await user.click(confirm);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/v1/workspaces/w-1/clients/c-1/autopay');
        expect(init.method).toBe('PUT');
        expect(JSON.parse(init.body as string)).toEqual({
            expected_version: version,
            enabled: true,
            limit_amount: 400_000,
            attestation: 'Signed services agreement of Aug 28.',
            confirm: true,
        });
        expect(
            await screen.findByText(/billing contacts were emailed a notice/),
        ).toBeVisible();
    });

    it('refuses to turn on without a connection or a default method, and says so', () => {
        const { rerender } = render(
            <ClientAutopayCard autopay={client({ stripe_connected: false })} />,
        );
        expect(
            screen.getByRole('button', { name: 'Turn on autopay' }),
        ).toBeDisabled();
        expect(screen.getByText(/Connect Stripe under Payments/)).toBeVisible();

        rerender(<ClientAutopayCard autopay={client({ method: null })} />);
        expect(
            screen.getByRole('button', { name: 'Turn on autopay' }),
        ).toBeDisabled();
        expect(
            screen.getByText(/default saved payment method first/),
        ).toBeVisible();
    });

    it('shows the consent record, the wording and the upcoming charges, an over-limit invoice marked not charged', () => {
        render(
            <ClientAutopayCard
                autopay={client({
                    enabled: true,
                    limit_amount: 500_000,
                    consents: [
                        {
                            id: 'a',
                            action: 'enabled',
                            actor_name: 'Dana Example',
                            actor_role: 'client',
                            method: 'portal_checkbox',
                            limit_amount: 500_000,
                            currency: 'USD',
                            payment_method_label: 'Visa ending 4242',
                            wording: 'I authorize Example Studio to charge.',
                            attestation: null,
                            reason: null,
                            notice_sent: false,
                            occurred_at: '2030-10-03T14:12:00Z',
                        },
                        {
                            id: 'b',
                            action: 'enabled',
                            actor_name: 'Sam Example',
                            actor_role: 'provider',
                            method: 'provider_attestation',
                            limit_amount: 400_000,
                            currency: 'USD',
                            payment_method_label: 'Visa ending 4242',
                            wording: null,
                            attestation: 'Signed on Aug 28.',
                            reason: null,
                            notice_sent: true,
                            occurred_at: '2030-09-01T10:05:00Z',
                        },
                        {
                            id: 'c',
                            action: 'disabled',
                            actor_name: null,
                            actor_role: 'system',
                            method: 'automatic',
                            limit_amount: null,
                            currency: 'USD',
                            payment_method_label: null,
                            wording: null,
                            attestation: null,
                            reason: 'Default method removed',
                            notice_sent: false,
                            occurred_at: '2030-08-12T09:40:00Z',
                        },
                    ],
                    upcoming: [
                        {
                            invoice_id: 'i1',
                            invoice_number: 'INV-0142',
                            due_on: '2030-12-01',
                            amount: 375_000,
                            currency: 'USD',
                            state: 'will_charge',
                            state_label: 'Will autopay',
                        },
                        {
                            invoice_id: 'i2',
                            invoice_number: 'INV-0151',
                            due_on: '2030-12-15',
                            amount: 720_000,
                            currency: 'USD',
                            state: 'over_limit',
                            state_label: 'Not autopaid: over limit',
                        },
                    ],
                })}
            />,
        );

        expect(
            screen.getByText('“I authorize Example Studio to charge.”'),
        ).toBeVisible();
        expect(
            screen.getByText(
                /Attested: “Signed on Aug 28.” The client was emailed a notice/,
            ),
        ).toBeVisible();
        expect(screen.getByText('Default method removed')).toBeVisible();
        expect(screen.getByText('Sam Example (provider)')).toBeVisible();
        expect(screen.getByText('SVC (SVC)')).toBeVisible();
        expect(screen.getByText(/\$3,750.00 on /)).toBeVisible();
        expect(
            screen.getByText(/\$7,200.00 · over limit, not charged/),
        ).toBeVisible();
    });

    it('turns it off after confirmation', async () => {
        const fetchMock = vi
            .fn<(url: string, init: RequestInit) => Promise<Response>>()
            .mockResolvedValue(
                new Response(JSON.stringify({ data: client() }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        render(
            <ClientAutopayCard
                autopay={client({ enabled: true, limit_amount: 500_000 })}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Turn off autopay' }),
        );
        expect(fetchMock).not.toHaveBeenCalled();
        await user.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Turn off autopay',
            }),
        );

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(JSON.parse(fetchMock.mock.calls[0][1].body as string)).toEqual({
            expected_version: version,
            enabled: false,
        });
    });

    it('has no overflow risk with hostile names and wording', () => {
        const { container } = render(
            <ClientAutopayCard
                autopay={client({
                    enabled: true,
                    limit_amount: 500_000,
                    method: {
                        label: LONG,
                        brand: null,
                        last4: null,
                        exp_month: null,
                        exp_year: null,
                    },
                    consents: [
                        {
                            id: 'a',
                            action: 'enabled',
                            actor_name: LONG,
                            actor_role: 'provider',
                            method: 'provider_attestation',
                            limit_amount: 500_000,
                            currency: 'USD',
                            payment_method_label: LONG,
                            wording: null,
                            attestation: LONG.repeat(5),
                            reason: null,
                            notice_sent: true,
                            occurred_at: '2030-10-03T14:12:00Z',
                        },
                    ],
                    upcoming: [
                        {
                            invoice_id: 'i1',
                            invoice_number: LONG,
                            due_on: '2030-12-01',
                            amount: 375_000,
                            currency: 'USD',
                            state: 'will_charge',
                            state_label: 'Will autopay',
                        },
                    ],
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

function invoice(
    overrides: Partial<InvoiceAutopayState> = {},
): InvoiceAutopayState {
    return {
        invoice_id: '00000000-0000-4000-8000-000000000002',
        invoice_version: version,
        state: 'failed',
        state_label: 'Autopay failed',
        enabled: true,
        limit_amount: 500_000,
        currency: 'USD',
        amount: 375_000,
        method_label: 'Visa ending 4242',
        will_charge_on: null,
        verdict: 'already_attempted',
        can_skip: false,
        attempt: {
            id: '00000000-0000-4000-8000-000000000003',
            state: 'failed',
            amount: 375_000,
            currency: 'USD',
            payment_method_label: 'Visa ending 4242',
            failure_code: 'card_declined',
            failure_reason: 'Your card has insufficient funds.',
            payment_status: 'failed',
            skipped_by_name: null,
            skipped_at: null,
            claimed_at: '2030-12-01T15:00:00Z',
            resolved_at: '2030-12-01T15:00:01Z',
        },
        skip_href: null,
        settings_href: '/workspaces/w-1/clients/c-1/settings',
        ...overrides,
    };
}

describe('autopay on an operator invoice', () => {
    it('shows the failed attempt with its reason and that it is not retried', () => {
        render(<InvoiceAutopayCard autopay={invoice()} />);

        expect(screen.getByText('Autopay failed')).toBeVisible();
        expect(
            screen.getByText(/Your card has insufficient funds\./),
        ).toBeVisible();
        expect(
            screen.getByText(/Not retried; payment reminders resume\./),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Skip autopay for this invoice',
            }),
        ).toBeNull();
        expect(
            screen.getByRole('link', { name: 'View consent record' }),
        ).toHaveAttribute('href', '/workspaces/w-1/clients/c-1/settings');
    });

    it('skips an invoice only after confirmation, with the invoice version', async () => {
        const fetchMock = vi
            .fn<(url: string, init: RequestInit) => Promise<Response>>()
            .mockResolvedValue(
                new Response(JSON.stringify({ data: invoice() }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            );
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        render(
            <InvoiceAutopayCard
                autopay={invoice({
                    state: 'will_charge',
                    state_label: 'Will autopay',
                    attempt: null,
                    will_charge_on: '2030-12-01',
                    skip_href:
                        '/api/v1/workspaces/w-1/invoices/i-1/autopay/skip',
                })}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Skip autopay for this invoice',
            }),
        );
        expect(fetchMock).not.toHaveBeenCalled();
        await user.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Skip autopay',
            }),
        );

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/api/v1/workspaces/w-1/invoices/i-1/autopay/skip',
        );
        expect(JSON.parse(fetchMock.mock.calls[0][1].body as string)).toEqual({
            expected_version: version,
            confirm: true,
        });
    });

    it('says who skipped it and when, and renders nothing when autopay plays no part', () => {
        const { container, rerender } = render(
            <InvoiceAutopayCard
                autopay={invoice({
                    state: 'skipped',
                    state_label: 'Autopay skipped',
                    attempt: {
                        ...invoice().attempt!,
                        state: 'skipped',
                        skipped_by_name: 'Sam Example',
                        skipped_at: '2030-11-20T10:00:00Z',
                    },
                })}
            />,
        );
        expect(screen.getByText(/Skipped by Sam Example on/)).toBeVisible();

        rerender(
            <InvoiceAutopayCard
                autopay={invoice({ state: 'not_applicable', attempt: null })}
            />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('tells an operator when a claim has no answer from Stripe', () => {
        render(
            <InvoiceAutopayCard
                autopay={invoice({
                    state: 'needs_review',
                    state_label: 'Autopay needs review',
                    attempt: {
                        ...invoice().attempt!,
                        state: 'claimed',
                        failure_reason: null,
                    },
                })}
            />,
        );

        expect(
            screen.getByText(/Check Stripe before collecting by hand/),
        ).toBeVisible();
    });

    it('has no overflow risk with a hostile failure reason', () => {
        const { container } = render(
            <InvoiceAutopayCard
                autopay={invoice({
                    method_label: LONG,
                    attempt: {
                        ...invoice().attempt!,
                        failure_reason: LONG.repeat(6),
                    },
                })}
            />,
        );

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
