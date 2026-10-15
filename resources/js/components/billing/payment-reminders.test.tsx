import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    PaymentReminderCadenceCard,
    PaymentRemindersCard,
} from '@/components/billing/payment-reminders';
import type {
    CompanyRemindersState,
    InvoiceRemindersState,
} from '@/components/billing/payment-reminders';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const inertia = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: inertia }));

const version = 'a'.repeat(64);
const sendUrl = '/api/v1/workspaces/w-1/invoices/invoice-1/reminders';
const unbroken = `accounts-payable-${'x'.repeat(80)}@reminders.example.test`;

function reminders(
    overrides: Partial<InvoiceRemindersState> = {},
): InvoiceRemindersState {
    return {
        invoice_id: '00000000-0000-4000-8000-000000000001',
        invoice_version: version,
        manual: {
            available: true,
            refusal: null,
            pay_link: 'square',
            recipients: ['billing@reminders.example.test'],
        },
        cadence: {
            enabled: true,
            enabled_at: '2030-01-01T12:00:00Z',
            hold: null,
            schedule: [
                {
                    step: 'due_minus_3',
                    label: '3 days before the due date',
                    scheduled_on: '2030-03-12',
                    state: 'sent',
                },
                {
                    step: 'due',
                    label: 'On the due date',
                    scheduled_on: '2030-03-15',
                    state: 'upcoming',
                },
                {
                    step: 'due_plus_7',
                    label: '7 days overdue',
                    scheduled_on: '2030-03-22',
                    state: 'upcoming',
                },
                {
                    step: 'due_plus_14',
                    label: '14 days overdue',
                    scheduled_on: '2030-03-29',
                    state: 'upcoming',
                },
            ],
        },
        reminders: [],
        send_href: sendUrl,
        ...overrides,
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
    inertia.reload.mockReset();
});

describe('payment reminders on an invoice', () => {
    it('sends through the API with the invoice version only after confirmation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    data: {
                        status: 'sent',
                        recipients: ['billing@reminders.example.test'],
                        error_summary: null,
                    },
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);
        render(<PaymentRemindersCard reminders={reminders()} />);

        expect(screen.getByText(/a link to pay on Square/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Send reminder' }));
        expect(fetchMock).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Send reminder' }).at(-1)!,
        );

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(sendUrl);
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual({
            expected_version: version,
            confirm: true,
        });
        expect(
            screen.getByText(
                'Reminder sent to billing@reminders.example.test.',
            ),
        ).toBeVisible();
    });

    it('retries a send whose answer was lost with the same idempotency key', async () => {
        const fetchMock = vi
            .fn()
            .mockRejectedValueOnce(new TypeError('Failed to fetch'))
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify({
                        data: {
                            status: 'sent',
                            recipients: ['billing@reminders.example.test'],
                            error_summary: null,
                        },
                    }),
                    { status: 200 },
                ),
            );
        vi.stubGlobal('fetch', fetchMock);
        render(<PaymentRemindersCard reminders={reminders()} />);
        const send = async () => {
            fireEvent.click(
                screen.getAllByRole('button', { name: 'Send reminder' })[0],
            );
            fireEvent.click(
                screen
                    .getAllByRole('button', { name: 'Send reminder' })
                    .at(-1)!,
            );
        };

        await send();
        expect(
            await screen.findByText(/SVC could not be reached/),
        ).toBeVisible();
        await send();
        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());

        const keys = fetchMock.mock.calls.map(
            ([, init]) =>
                (init as RequestInit & { headers: Record<string, string> })
                    .headers['Idempotency-Key'],
        );
        expect(keys).toHaveLength(2);
        expect(keys[0]).toBeTruthy();
        expect(keys[1]).toBe(keys[0]);
    });

    it('says why a reminder cannot be sent and offers no button', () => {
        render(
            <PaymentRemindersCard
                reminders={reminders({
                    send_href: null,
                    manual: {
                        available: false,
                        pay_link: null,
                        recipients: [],
                        refusal: {
                            code: 'square_page_missing',
                            message:
                                'The linked Square invoice has no Square payment page in SVC’s Square mirror.',
                        },
                    },
                    cadence: {
                        ...reminders().cadence,
                        hold: {
                            code: 'square_autopay',
                            message:
                                'Square charges the card on file for this invoice automatically.',
                        },
                    },
                })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Send reminder' }),
        ).toBeNull();
        expect(screen.getByText(/no Square payment page/)).toBeVisible();
        expect(screen.getByText(/charges the card on file/)).toBeVisible();
    });

    it('shows each reminder as words and keeps long addresses inside the window', () => {
        const { container } = render(
            <PaymentRemindersCard
                reminders={reminders({
                    reminders: [
                        {
                            id: '00000000-0000-4000-8000-000000000002',
                            origin: 'automatic',
                            step: 'due_minus_3',
                            channel: 'email',
                            pay_link: 'svc_portal',
                            square_invoice_number: null,
                            recipients: [unbroken, unbroken],
                            subject: 'Payment reminder: invoice SYN',
                            currency: 'USD',
                            amount_due: 12345,
                            due_on: '2030-03-15',
                            status: 'cancelled',
                            attempt_number: 1,
                            triggered_by: 'scheduler',
                            triggered_by_name: null,
                            queued_at: '2030-03-12T14:00:00Z',
                            sent_at: null,
                            failed_at: '2030-03-12T14:00:01Z',
                            next_attempt_at: null,
                            error_summary: `Not sent: ${'z'.repeat(120)}`,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getAllByText('3 days before the due date').length).toBe(
            2,
        );
        expect(screen.getByText('Client portal')).toBeVisible();
        expect(screen.getByText('Cancelled')).toBeVisible();
        expect(container.innerHTML).not.toMatch(/svc_portal|due_minus_3/);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});

describe('the client reminder cadence', () => {
    const cadence = (
        overrides: Partial<CompanyRemindersState> = {},
    ): CompanyRemindersState => ({
        client_id: '00000000-0000-4000-8000-000000000003',
        version,
        enabled: false,
        enabled_at: null,
        steps: [
            {
                step: 'due_minus_3',
                label: '3 days before the due date',
                offset_days: -3,
            },
            { step: 'due', label: 'On the due date', offset_days: 0 },
        ],
        update_href: '/api/v1/workspaces/w-1/clients/c-1/payment-reminders',
        ...overrides,
    });

    it('switches the cadence through the API with the client version', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(
                new Response(JSON.stringify({ data: {} }), { status: 200 }),
            );
        vi.stubGlobal('fetch', fetchMock);
        render(<PaymentReminderCadenceCard cadence={cadence()} />);

        fireEvent.click(
            screen.getByRole('switch', {
                name: 'Automatically remind this client about unpaid invoices',
            }),
        );

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(
            '/api/v1/workspaces/w-1/clients/c-1/payment-reminders',
        );
        expect(init.method).toBe('PUT');
        expect(JSON.parse(String(init.body))).toEqual({
            expected_version: version,
            enabled: true,
        });
    });

    it('reports a refusal where it was asked', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        message: 'The client has changed; read it and retry.',
                    }),
                    { status: 409 },
                ),
            ),
        );
        render(
            <PaymentReminderCadenceCard
                cadence={cadence({
                    enabled: true,
                    enabled_at: '2030-01-01T12:00:00Z',
                })}
            />,
        );

        fireEvent.click(screen.getByRole('switch'));

        expect(
            await screen.findByText(
                'The client has changed; read it and retry.',
            ),
        ).toBeVisible();
        expect(inertia.reload).not.toHaveBeenCalled();
    });
});
