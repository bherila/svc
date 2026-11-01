import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClientInvoiceDetail from '@/pages/clients/invoice';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
}));

const requestFetch =
    vi.fn<(url: string, init: RequestInit) => Promise<Response>>();

beforeEach(() => {
    requestFetch.mockReset();
    requestFetch.mockImplementation(
        async () => new Response(JSON.stringify({ data: {} }), { status: 200 }),
    );
    vi.stubGlobal('fetch', requestFetch);
    inertia.reload.mockReset();
    inertia.reload.mockImplementation(
        (options: { onSuccess?: () => void; onFinish?: () => void }) => {
            options.onSuccess?.();
            options.onFinish?.();
        },
    );
});

afterEach(() => vi.unstubAllGlobals());

function apiRequests() {
    return requestFetch.mock.calls.map(([url, init]) => ({
        url,
        method: init.method,
        body: JSON.parse(String(init.body)),
    }));
}

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: inertia,
    usePage: () => ({
        props: sharedPageProps({ workspaceNavigation: workspaceNavigation() }),
    }),
}));

type Props = Parameters<typeof ClientInvoiceDetail>[0];

function props(overrides: Partial<Props> = {}): Props {
    return {
        company: { id: 'company-1', name: 'Synthetic Client' },
        invoices_href: '/workspaces/w-1/clients/company-1/invoices',
        pdf_href: '/workspaces/w-1/invoices/invoice-1/pdf',
        version: '0'.repeat(64),
        actions: {
            issue: null,
            send: null,
            payment: '/api/v1/workspaces/w-1/payments',
            void: null,
        },
        email: null,
        deliveries: [],
        invoice: {
            id: 'invoice-1',
            invoice_number: 'INV-SYNTH-1',
            status: 'issued',
            currency: 'USD',
            issue_date: '2026-08-15',
            due_date: '2026-09-14',
            total_amount: 10000,
            paid_amount: 0,
            balance_amount: 10000,
        },
        lines: [],
        line_detail: {},
        payments: [],
        // West of UTC, so the browser's UTC day and the workspace's differ for
        // part of every evening.
        timezone: 'America/Los_Angeles',
        ...overrides,
    };
}

describe('recording a payment against an invoice', () => {
    beforeEach(() => {
        // Only `Date`, so React and testing-library keep their own timers.
        // 05:30 UTC on the 30th is still the 29th in Los Angeles, which is the
        // whole point: the default is the workspace's day, not UTC's.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-08-30T05:30:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    /**
     * The form had amount, method and reference and nothing else, so every
     * payment recorded here was dated the day it was typed in - a cheque that
     * cleared last Tuesday could only be entered as having arrived today.
     */
    it('offers a date defaulted to today on the workspace calendar and sends it', async () => {
        render(<ClientInvoiceDetail {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));

        const received = screen.getByLabelText('Received');
        expect(received).toHaveValue('2026-08-29');

        fireEvent.change(received, { target: { value: '2026-08-24' } });
        fireEvent.click(screen.getByRole('button', { name: 'Record' }));

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(apiRequests()).toContainEqual({
            url: '/api/v1/workspaces/w-1/payments',
            method: 'POST',
            body: expect.objectContaining({
                expected_version: '0'.repeat(64),
                invoice_id: 'invoice-1',
                confirm: true,
                received_on: '2026-08-24',
            }),
        });
    });

    /**
     * Warned, not refused. A deposit or an advance retainer applied to an
     * invoice issued afterwards is a real arrangement, so the service accepts
     * the write and the form says what is unusual about it before submit -
     * naming the invoice's own issue date rather than cautioning generally.
     */
    it('warns while the date is being entered when it predates the issue date', () => {
        render(<ClientInvoiceDetail {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));

        expect(screen.queryByRole('status')).toBeNull();

        fireEvent.change(screen.getByLabelText('Received'), {
            target: { value: '2026-07-01' },
        });

        expect(screen.getByRole('status')).toHaveTextContent(
            'This payment is dated before the invoice was issued on Aug 15, 2026.',
        );

        // And it is a warning rather than a gate: the control stays live.
        expect(screen.getByRole('button', { name: 'Record' })).toBeEnabled();
    });

    /**
     * A backdate does not outlive the payment it was typed for.
     *
     * The dialog is state on a page that stays mounted, so the question is not
     * whether the fields are reset but whether they can be shown holding the
     * last payment's values. They cannot: `setPaying(true)` is reached from one
     * control, and that control re-seeds the amount, the date and the reference
     * on the same click - so the form is never displayed carrying anything the
     * previous submission left. Verified here rather than reasoned about,
     * including across a change of day, because reasoning is what a second
     * `setPaying(true)` added later would quietly invalidate.
     */
    it('re-seeds the form every time it is opened, not once at mount', async () => {
        render(<ClientInvoiceDetail {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
        fireEvent.change(screen.getByLabelText('Received'), {
            target: { value: '2026-07-01' },
        });
        fireEvent.change(screen.getByLabelText('Reference'), {
            target: { value: 'CHK-1041' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Record' }));

        // The dialog closed on success, and the day has since turned over.
        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(screen.queryByLabelText('Received')).toBeNull();
        vi.setSystemTime(new Date('2026-09-02T12:00:00Z'));

        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
        expect(screen.getByLabelText('Received')).toHaveValue('2026-09-02');
        // A reference identifies one payment; the second cheque of a sitting
        // must not arrive under the first one's number.
        expect(screen.getByLabelText('Reference')).toHaveValue('');
    });

    /** The same fact wherever the payment is shown beside its invoice. */
    it('marks a recorded payment that predates the issue date', () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    payments: [
                        {
                            id: 'payment-1',
                            status: 'succeeded',
                            method: 'check',
                            reference: null,
                            received_on: '2026-07-01',
                            correct_date_href:
                                '/api/v1/workspaces/w-1/payments/payment-1',
                            amount: 4000,
                            refunded_amount: 0,
                            currency: 'USD',
                        },
                        {
                            id: 'payment-2',
                            status: 'succeeded',
                            method: 'wire',
                            reference: null,
                            received_on: '2026-08-20',
                            correct_date_href:
                                '/api/v1/workspaces/w-1/payments/payment-2',
                            amount: 1000,
                            refunded_amount: 0,
                            currency: 'USD',
                        },
                    ],
                })}
            />,
        );

        const rows = screen.getAllByRole('row');
        const marked = rows.filter((row) =>
            within(row).queryByText('predates issue'),
        );
        expect(marked).toHaveLength(1);
        expect(marked[0]).toHaveTextContent('Jul 1, 2026');

        expect(screen.getByRole('status')).toHaveTextContent(
            'One payment above is dated before this invoice was issued on Aug 15, 2026.',
        );
    });
});

/**
 * Correcting the date, and only the date.
 *
 * There is no payment-edit path in this application by design - a payment is
 * corrected through its status or its refunded amount - and this does not add
 * one. A mistyped day moves no money, and the remedy before this was to cancel
 * the payment and record it again, which invents a cancellation that never
 * happened and leaves it in the client's history.
 */
describe('correcting a recorded payment date', () => {
    function payment(overrides: Record<string, unknown> = {}) {
        return {
            id: 'payment-1',
            version: '1'.repeat(64),
            status: 'succeeded',
            method: 'check',
            reference: null,
            received_on: '2026-08-20',
            correct_date_href: '/api/v1/workspaces/w-1/payments/payment-1',
            amount: 4000,
            refunded_amount: 0,
            currency: 'USD',
            ...overrides,
        };
    }

    it('sends the corrected date to the URL the server supplied', async () => {
        render(<ClientInvoiceDetail {...props({ payments: [payment()] })} />);

        fireEvent.click(screen.getByRole('button', { name: 'Correct date' }));

        const field = screen.getByLabelText(
            'Corrected date for the $40.00 payment',
        );
        // Seeded with the date on the row, so a correction starts from what is
        // recorded rather than from an empty field.
        expect(field).toHaveValue('2026-08-20');

        fireEvent.change(field, { target: { value: '2026-08-18' } });

        // A reason is required, as payments.correct requires it: Save waits
        // for one, and a blank one is not a reason.
        const save = screen.getByRole('button', { name: 'Save' });
        expect(save).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Reason for the correction'), {
            target: { value: '   ' },
        });
        expect(save).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Reason for the correction'), {
            target: { value: ' Bank statement shows the 18th ' },
        });
        expect(save).toBeEnabled();
        fireEvent.click(save);

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(apiRequests()).toContainEqual({
            url: '/api/v1/workspaces/w-1/payments/payment-1',
            method: 'PATCH',
            body: {
                expected_version: '1'.repeat(64),
                confirm: true,
                received_on: '2026-08-18',
                reason: 'Bank statement shows the 18th',
            },
        });
    });

    /**
     * One row's correction cannot bleed into another's.
     *
     * The editor is a single `correcting` id and a single date, which is what
     * makes two rows unable to be open at once - and also what would let a date
     * typed against one row appear under another. It does not, because opening
     * any row seeds the date from that row before it becomes the open one.
     */
    it('seeds each row from its own recorded date, after a save and after a cancel', async () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    payments: [
                        payment(),
                        payment({
                            id: 'payment-2',
                            amount: 2500,
                            received_on: '2026-08-05',
                            correct_date_href:
                                '/api/v1/workspaces/w-1/payments/payment-2',
                        }),
                    ],
                })}
            />,
        );

        const open = (index: number) =>
            fireEvent.click(
                screen.getAllByRole('button', { name: 'Correct date' })[index],
            );

        // Saved on the first row.
        open(0);
        fireEvent.change(
            screen.getByLabelText('Corrected date for the $40.00 payment'),
            { target: { value: '2026-08-18' } },
        );
        fireEvent.change(screen.getByLabelText('Reason for the correction'), {
            target: { value: 'Synthetic statement' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());

        open(1);
        expect(
            screen.getByLabelText('Corrected date for the $25.00 payment'),
        ).toHaveValue('2026-08-05');
        // And the reason given for the first row does not carry over.
        expect(screen.getByLabelText('Reason for the correction')).toHaveValue(
            '',
        );

        // Abandoned on the second row, which is the sharper case: nothing was
        // submitted, so nothing else had a reason to clear the field.
        fireEvent.change(
            screen.getByLabelText('Corrected date for the $25.00 payment'),
            { target: { value: '2026-07-04' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        open(0);
        expect(
            screen.getByLabelText('Corrected date for the $40.00 payment'),
        ).toHaveValue('2026-08-20');
        // And one at a time: the other row is a date and a control again.
        expect(
            screen.queryByLabelText('Corrected date for the $25.00 payment'),
        ).toBeNull();
    });

    /** A capability the viewer does not have arrives as null, and is not drawn. */
    it('offers nothing to a viewer the server sent no URL for', () => {
        render(
            <ClientInvoiceDetail
                {...props({ payments: [payment({ correct_date_href: null })] })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Correct date' }),
        ).toBeNull();
    });
});

describe("a payment's deep link", () => {
    /**
     * `/finance-links/payments/{id}` redirects here with `#payment-{id}`;
     * the row has to carry that anchor or the link lands at the top of the
     * page with nothing to say which payment it meant.
     */
    it('anchors each payment row at its public id', () => {
        const { container } = render(
            <ClientInvoiceDetail
                {...props({
                    payments: [
                        {
                            id: 'payment-uuid-1',
                            status: 'succeeded',
                            method: 'wire',
                            reference: null,
                            received_on: '2026-08-20',
                            correct_date_href: null,
                            amount: 4000,
                            refunded_amount: 0,
                            currency: 'USD',
                        },
                    ],
                })}
            />,
        );

        const row = container.querySelector('#payment-payment-uuid-1');
        expect(row?.tagName).toBe('TR');
        expect(row).toHaveTextContent('$40.00');
    });
});

describe('a payment reconciled in finance', () => {
    function paymentWith(
        finance_reconciliation: { hrefs: string[] } | null,
    ): Props['payments'][number] {
        return {
            id: 'payment-1',
            status: 'succeeded',
            method: 'wire',
            reference: null,
            received_on: '2026-08-20',
            correct_date_href: null,
            amount: 4000,
            refunded_amount: 0,
            currency: 'USD',
            finance_reconciliation,
        };
    }

    it('links the badge to the transaction URL the server sent', () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    payments: [
                        paymentWith({
                            hrefs: ['https://finance.example.test/t/abc'],
                        }),
                    ],
                })}
            />,
        );

        const link = screen.getByRole('link', {
            name: 'Reconciled in finance',
        });
        expect(link).toHaveAttribute(
            'href',
            'https://finance.example.test/t/abc',
        );
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('shows the badge without a link when no link was configured', () => {
        render(
            <ClientInvoiceDetail
                {...props({ payments: [paymentWith({ hrefs: [] })] })}
            />,
        );

        expect(screen.getByText('Reconciled in finance')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /finance/i })).toBeNull();
    });

    it('links each transaction of a split payment', () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    payments: [
                        paymentWith({
                            hrefs: [
                                'https://finance.example.test/t/one',
                                'https://finance.example.test/t/two',
                            ],
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Finance transaction 1 of 2' }),
        ).toHaveAttribute('href', 'https://finance.example.test/t/one');
        expect(
            screen.getByRole('link', { name: 'Finance transaction 2 of 2' }),
        ).toHaveAttribute('href', 'https://finance.example.test/t/two');
    });

    it('shows nothing for a payment the server sent no reconciliation for', () => {
        render(
            <ClientInvoiceDetail
                {...props({ payments: [paymentWith(null)] })}
            />,
        );

        expect(screen.queryByText('Reconciled in finance')).toBeNull();
    });
});

describe('voiding an invoice', () => {
    it('requires a reason and sends it with the void', async () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    actions: {
                        ...props().actions,
                        void: '/api/v1/workspaces/w-1/invoices/invoice-1/void',
                    },
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Void' }));
        const confirm = within(screen.getByRole('alertdialog')).getByRole(
            'button',
            { name: 'Void' },
        );
        expect(confirm).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Reason'), {
            target: { value: ' Issued to the wrong client ' },
        });
        expect(confirm).toBeEnabled();
        fireEvent.click(confirm);

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(apiRequests()).toContainEqual({
            url: '/api/v1/workspaces/w-1/invoices/invoice-1/void',
            method: 'POST',
            body: {
                expected_version: '0'.repeat(64),
                confirm: true,
                reason: 'Issued to the wrong client',
            },
        });
    });
});

describe('reopening an issued invoice for reissue', () => {
    const reopenHref = '/api/v1/workspaces/w-1/invoices/invoice-1/reopen';

    function withReopen(overrides: Partial<Props> = {}) {
        return props({
            actions: { ...props().actions, reopen: reopenHref },
            ...overrides,
        });
    }

    it('offers nothing when the server offers no reopen', () => {
        render(<ClientInvoiceDetail {...props()} />);

        expect(screen.queryByRole('button', { name: 'Reopen' })).toBeNull();
    });

    it('requires a reason, confirms, and sends both with the version it was opened on', async () => {
        render(<ClientInvoiceDetail {...withReopen()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        const dialog = screen.getByRole('alertdialog');
        expect(
            within(dialog).getByText('Reopen this invoice?'),
        ).toBeInTheDocument();
        const confirm = within(dialog).getByRole('button', { name: 'Reopen' });
        expect(confirm).toBeDisabled();

        fireEvent.change(within(dialog).getByLabelText('Reason'), {
            target: { value: ' Late approvals missed the application ' },
        });
        expect(confirm).toBeEnabled();
        fireEvent.click(confirm);

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(apiRequests()).toEqual([
            {
                url: reopenHref,
                method: 'POST',
                body: {
                    expected_version: '0'.repeat(64),
                    confirm: true,
                    reason: 'Late approvals missed the application',
                },
            },
        ]);
    });

    it('offers void and reopen side by side without crossing their reasons', async () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    actions: {
                        ...props().actions,
                        reopen: reopenHref,
                        void: '/api/v1/workspaces/w-1/invoices/invoice-1/void',
                    },
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        fireEvent.change(screen.getByLabelText('Reason'), {
            target: { value: 'Reopen reason' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await vi.waitFor(() =>
            expect(screen.queryByRole('alertdialog')).toBeNull(),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Void' }));
        expect(screen.getByText('Void this invoice?')).toBeInTheDocument();
        expect(screen.getByLabelText('Reason')).toHaveValue('');
    });

    it("shows the server's refusal in the dialog and leaves it open", async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({
                    message: 'A paid invoice cannot be reopened.',
                }),
                { status: 422 },
            ),
        );
        render(<ClientInvoiceDetail {...withReopen()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        const dialog = screen.getByRole('alertdialog');
        fireEvent.change(within(dialog).getByLabelText('Reason'), {
            target: { value: 'Synthetic reopen reason' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Reopen' }));

        expect(
            await within(dialog).findByText(
                'A paid invoice cannot be reopened.',
            ),
        ).toBeInTheDocument();
        expect(dialog).toBeInTheDocument();
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('keeps the confirmation mounted and non-cancellable after an uncertain handoff', async () => {
        requestFetch.mockRejectedValueOnce(
            new TypeError('Synthetic lost response'),
        );
        render(<ClientInvoiceDetail {...withReopen()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        const dialog = await screen.findByRole('alertdialog');
        fireEvent.change(within(dialog).getByLabelText('Reason'), {
            target: { value: 'Synthetic reopen reason' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Reopen' }));

        await within(dialog).findByRole('button', {
            name: 'Check original request',
        });
        expect(
            within(dialog).getByRole('button', { name: 'Cancel' }),
        ).toBeDisabled();
        expect(within(dialog).getByLabelText('Reason')).toBeDisabled();
    });

    it('says a reopened draft was reopened, why, and offers the ordinary issue', () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    actions: {
                        ...props().actions,
                        issue: '/api/v1/workspaces/w-1/invoices/invoice-1/issue',
                    },
                    invoice: { ...props().invoice, status: 'draft' },
                    reopen: {
                        reopened_at: '2026-09-01T12:00:00Z',
                        reason: 'Late approvals missed the application',
                        reissued_at: null,
                    },
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(/Reopened/);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Reason: Late approvals missed the application',
        );
        expect(screen.getByRole('button', { name: 'Issue' })).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Reopen' })).toBeNull();
    });

    it('says a reissued invoice was reissued under the same number', () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    reopen: {
                        reopened_at: '2026-09-01T12:00:00Z',
                        reason: 'Late approvals missed the application',
                        reissued_at: '2026-09-02T12:00:00Z',
                    },
                })}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            /Reissued .* under the same number, after being reopened/,
        );
    });

    it('shows no reopen note for an invoice that was never reopened', () => {
        render(<ClientInvoiceDetail {...props()} />);

        expect(screen.queryByRole('status')).toBeNull();
    });
});

describe('reviewing and correcting an issued invoice', () => {
    it('uses server-supplied correction capabilities and posts a revisioned audit request', async () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    actions: {
                        issue: null,
                        send: null,
                        payment: null,
                        void: null,
                        correct:
                            '/api/v1/workspaces/w-1/invoices/invoice-1/correct',
                    },
                    invoice: {
                        ...props().invoice,
                        document_revision: 3,
                    },
                    lines: [
                        {
                            id: 'line-1',
                            type: 'retainer',
                            description: 'Original wording',
                            quantity: '1.0000',
                            hours: null,
                            line_date: null,
                            unit_amount: 10000,
                            tax_amount: 0,
                            total_amount: 10000,
                            money_correctable: false,
                        },
                    ],
                })}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Correct invoice' }),
        );
        expect(screen.getByLabelText('Quantity')).toBeDisabled();
        expect(screen.getByLabelText('Unit amount')).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Description'), {
            target: { value: 'Corrected wording' },
        });
        fireEvent.change(screen.getByLabelText('Reason for correction'), {
            target: { value: 'Fix the issued description.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save audited correction' }),
        );

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(apiRequests()).toContainEqual({
            url: '/api/v1/workspaces/w-1/invoices/invoice-1/correct',
            method: 'POST',
            body: expect.objectContaining({
                expected_version: '0'.repeat(64),
                confirm: true,
                reason: 'Fix the issued description.',
                lines: [
                    expect.objectContaining({
                        id: 'line-1',
                        description: 'Corrected wording',
                        quantity: '1',
                        unit_amount: 10000,
                    }),
                ],
            }),
        });
    });

    it('starts a later correction from the refreshed invoice props', () => {
        const editable = {
            issue: null,
            send: null,
            payment: null,
            void: null,
            correct: '/api/v1/workspaces/w-1/invoices/invoice-1/correct',
        };
        const { rerender } = render(
            <ClientInvoiceDetail
                {...props({
                    actions: editable,
                    lines: [
                        {
                            id: 'line-1',
                            type: 'adjustment',
                            description: 'First revision',
                            quantity: '1.0000',
                            hours: null,
                            line_date: null,
                            unit_amount: 10000,
                            tax_amount: 0,
                            total_amount: 10000,
                            money_correctable: true,
                        },
                    ],
                })}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Correct invoice' }),
        );
        fireEvent.change(screen.getByLabelText('Description'), {
            target: { value: 'Unsaved stale edit' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        rerender(
            <ClientInvoiceDetail
                {...props({
                    actions: editable,
                    invoice: {
                        ...props().invoice,
                        document_revision: 2,
                        due_date: '2026-10-01',
                    },
                    lines: [
                        {
                            id: 'line-1',
                            type: 'adjustment',
                            description: 'Committed second revision',
                            quantity: '2.0000',
                            hours: null,
                            line_date: null,
                            unit_amount: 12500,
                            tax_amount: 0,
                            total_amount: 25000,
                            money_correctable: true,
                        },
                    ],
                })}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Correct invoice' }),
        );

        expect(screen.getByLabelText('Description')).toHaveValue(
            'Committed second revision',
        );
        expect(screen.getByLabelText('Quantity')).toHaveValue('2');
        expect(screen.getByLabelText('Unit amount')).toHaveValue('125.00');
        expect(screen.getByLabelText('Due date')).toHaveValue('2026-10-01');
    });

    it('shows review state and distinguishes automatic delivery revisions', () => {
        render(
            <ClientInvoiceDetail
                {...props({
                    email: {
                        from: 'Synthetic Sender <sender@synthetic.test>',
                        suggested_recipients: [],
                        default_subject: 'Invoice INV-SYNTH-1',
                        self: 'operator@synthetic.test',
                    },
                    invoice: {
                        ...props().invoice,
                        automatic_delivery_status: 'held',
                        automatic_delivery_due_at: '2026-09-16T12:00:00Z',
                        automatic_delivery_note: 'Held for review.',
                    },
                    administrator_notification: {
                        id: 'notice-1',
                        status: 'sent',
                        sent_at: '2026-09-15T12:00:00Z',
                        failed_at: null,
                        attempt_count: 1,
                        error_summary: null,
                        invoice_revision: 1,
                    },
                    deliveries: [
                        {
                            id: 'delivery-1',
                            status: 'sent',
                            origin: 'automatic',
                            invoice_revision: 2,
                            recipients: ['billing@synthetic.test'],
                            bcc: [],
                            subject: 'Invoice INV-SYNTH-1',
                            sent_at: '2026-09-16T12:00:00Z',
                            failed_at: null,
                            error_summary: null,
                            provider_status: null,
                            provider_status_at: null,
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Automatic client delivery')).toBeVisible();
        expect(screen.getByText('Held for review.')).toBeVisible();
        expect(screen.getByText('Administrator review notice')).toBeVisible();
        expect(screen.getByText(/Automatic · revision 2/)).toBeVisible();
    });
});

describe('changing a draft due date through the API', () => {
    const detailsUrl = '/api/v1/workspaces/w-1/invoices/invoice-1/details';
    const draft = (): Partial<Props> => ({
        actions: {
            issue: '/api/v1/workspaces/w-1/invoices/invoice-1/issue',
            send: null,
            payment: null,
            void: null,
            update_details: detailsUrl,
        },
        invoice: {
            ...props().invoice,
            status: 'draft',
            issue_date: null,
            due_date: '2026-09-30',
        },
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        inertia.reload.mockReset();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('patches the details operation with the version and reloads the page', async () => {
        document.cookie = 'XSRF-TOKEN=synthetic%3Dtoken';
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: { id: 'invoice-1' } }), {
                status: 200,
            }),
        );
        vi.stubGlobal('fetch', fetchMock);
        render(<ClientInvoiceDetail {...props(draft())} />);

        fireEvent.click(screen.getByRole('button', { name: 'Change dates' }));
        const input = screen.getByLabelText('Due date');
        expect(input).toHaveValue('2026-09-30');
        fireEvent.change(input, { target: { value: '2026-10-15' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save dates' }));

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(detailsUrl);
        expect(init.method).toBe('PATCH');
        expect(init.credentials).toBe('same-origin');
        const headers = init.headers as Record<string, string>;
        expect(headers['X-XSRF-TOKEN']).toBe('synthetic=token');
        expect(headers['Idempotency-Key']).toMatch(/\S+/);
        expect(JSON.parse(String(init.body))).toEqual({
            expected_version: '0'.repeat(64),
            due_date: '2026-10-15',
        });
    });

    it('sends only a changed scheduled issue date and shows it on the draft', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ data: { id: 'invoice-1' } }), {
                status: 200,
            }),
        );
        vi.stubGlobal('fetch', fetchMock);
        const scheduled = props(draft());
        render(
            <ClientInvoiceDetail
                {...scheduled}
                invoice={{
                    ...scheduled.invoice,
                    scheduled_issue_date: '2026-10-01',
                }}
            />,
        );

        expect(screen.getByText(/scheduled to issue/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Change dates' }));
        const input = screen.getByLabelText('Scheduled issue date');
        expect(input).toHaveValue('2026-10-01');
        fireEvent.change(input, { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save dates' }));

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(JSON.parse(String(init.body))).toEqual({
            expected_version: '0'.repeat(64),
            scheduled_issue_date: null,
        });
    });

    it('closes an unchanged form without asking the server', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        render(<ClientInvoiceDetail {...props(draft())} />);

        fireEvent.click(screen.getByRole('button', { name: 'Change dates' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save dates' }));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.queryByLabelText('Due date')).toBeNull();
    });

    it('shows the server refusal and keeps the form open', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(
                    JSON.stringify({
                        message: 'The due date cannot precede the issue date.',
                    }),
                    { status: 422 },
                ),
            ),
        );
        render(<ClientInvoiceDetail {...props(draft())} />);

        fireEvent.click(screen.getByRole('button', { name: 'Change dates' }));
        fireEvent.change(screen.getByLabelText('Due date'), {
            target: { value: '2026-08-01' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save dates' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'The due date cannot precede the issue date.',
        );
        expect(screen.getByLabelText('Due date')).toBeInTheDocument();
        expect(inertia.reload).not.toHaveBeenCalled();
    });

    it('is not offered without the capability', () => {
        render(<ClientInvoiceDetail {...props()} />);

        expect(
            screen.queryByRole('button', { name: 'Change dates' }),
        ).toBeNull();
    });
});

describe('the Square link on an invoice', () => {
    const linkUrl = '/api/v1/workspaces/w-1/invoices/invoice-1/square-link';
    const version = 'a'.repeat(64);
    const square = (
        overrides: Partial<NonNullable<Props['square']>> = {},
    ): NonNullable<Props['square']> => ({
        invoice_id: 'invoice-1',
        invoice_version: version,
        link: null,
        covers: [],
        agreement_series_key: null,
        square_collects: false,
        payment_mismatch: null,
        collection_refusal: null,
        link_href: linkUrl,
        unlink_href: null,
        ...overrides,
    });
    const linked = {
        id: 'link-1',
        square_invoice_id: 'inv:0-fixture1',
        square_invoice_number: '000001-R-0002',
        series_key: '000001',
        currency: 'USD',
        match_method: 'suggested' as const,
        score: 85,
        reasons: ['exact amount', 'period slot'],
        linked_at: '2026-10-08T12:00:00Z',
        linked_by: 'Synthetic Operator',
    };

    afterEach(() => {
        vi.unstubAllGlobals();
        inertia.reload.mockReset();
    });

    it('shows the link, how it was matched and that Square collects, with no Square address', () => {
        const { container } = render(
            <ClientInvoiceDetail
                {...props({
                    square: square({
                        link: linked,
                        square_collects: true,
                        collection_refusal: 'Square collects it',
                        unlink_href: linkUrl,
                    }),
                })}
            />,
        );

        expect(screen.getByText('000001-R-0002')).toBeVisible();
        expect(screen.getByText('Suggested, score 85')).toBeVisible();
        expect(screen.getByText('exact amount · period slot')).toBeVisible();
        expect(screen.getByText(/by Synthetic Operator/)).toBeVisible();
        expect(screen.getByText(/Square collects this invoice/)).toBeVisible();
        expect(screen.getByRole('button', { name: 'Re-point' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Unlink' })).toBeVisible();
        expect(container.innerHTML).not.toMatch(/squareup|square\.link/i);
    });

    it('uses the server-provided hosted URL and labels the mirrored Square status', () => {
        const url = 'https://squareupsandbox.com/pay-invoice/inv_fixture_1';
        render(
            <ClientInvoiceDetail
                {...props({
                    square: square({
                        link: linked,
                        hosted_page: {
                            url,
                            square_invoice_number: '000001-R-0001',
                            square_status: 'PARTIALLY_PAID',
                            synced_at: null,
                        },
                    }),
                })}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'View or pay in Square' }),
        ).toHaveAttribute('href', url);
        expect(screen.getByText('Partially paid')).toBeVisible();
    });

    it('shows the outside-payment warning with the finished dashboard URL', () => {
        const dashboardUrl =
            'https://squareupsandbox.com/dashboard/invoices/inv_fixture_1';
        render(
            <ClientInvoiceDetail
                {...props({
                    square: square({
                        link: linked,
                        payment_mismatch: {
                            code: 'paid_outside_square',
                            dashboard_url: dashboardUrl,
                        },
                    }),
                })}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Paid in SVC while Square still has an open balance',
        );
        expect(
            screen.getByRole('link', { name: 'the Square dashboard' }),
        ).toHaveAttribute('href', dashboardUrl);
    });

    it('links through the API with the invoice version and reloads', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(
                new Response(JSON.stringify({ data: {} }), { status: 200 }),
            );
        vi.stubGlobal('fetch', fetchMock);
        render(<ClientInvoiceDetail {...props({ square: square() })} />);

        expect(
            screen.getByText('Not linked to a Square invoice.'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Link to Square invoice' }),
        );
        fireEvent.change(screen.getByLabelText('Square invoice id'), {
            target: { value: ' inv:0-fixture1 ' },
        });
        fireEvent.change(screen.getByLabelText('Square invoice number'), {
            target: { value: '000001-R-0003' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Link' }));

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(linkUrl);
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual({
            confirm: true,
            expected_version: version,
            square_invoice_id: 'inv:0-fixture1',
            square_invoice_number: '000001-R-0003',
            match_method: 'manual',
            replace: false,
        });
    });

    it('re-points an already linked invoice explicitly and reports a refusal', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(
                JSON.stringify({
                    message:
                        'Square invoice 000002-R-0001 is already linked to invoice SYN-2. Unlink it there first.',
                }),
                { status: 409 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);
        render(
            <ClientInvoiceDetail
                {...props({
                    square: square({ link: linked, unlink_href: linkUrl }),
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Re-point' }));
        fireEvent.change(screen.getByLabelText('Square invoice id'), {
            target: { value: 'inv:0-fixture2' },
        });
        fireEvent.change(screen.getByLabelText('Square invoice number'), {
            target: { value: '000002-R-0001' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Re-point link' }));

        expect(
            await screen.findByText(/already linked to invoice SYN-2/),
        ).toBeVisible();
        expect(inertia.reload).not.toHaveBeenCalled();
        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(JSON.parse(String(init.body)).replace).toBe(true);
    });

    it('unlinks only after confirmation', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(
                new Response(JSON.stringify({ data: {} }), { status: 200 }),
            );
        vi.stubGlobal('fetch', fetchMock);
        render(
            <ClientInvoiceDetail
                {...props({
                    square: square({ link: linked, unlink_href: linkUrl }),
                })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Unlink' }));
        expect(fetchMock).not.toHaveBeenCalled();
        const dialog = await screen.findByRole('alertdialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Unlink' }));

        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(linkUrl);
        expect(init.method).toBe('DELETE');
        expect(JSON.parse(String(init.body))).toEqual({
            expected_version: version,
            confirm: true,
        });
    });

    it('renders nothing about Square for a viewer who cannot manage the invoice', () => {
        render(<ClientInvoiceDetail {...props()} />);

        expect(screen.queryByText('Square')).toBeNull();
    });
});

describe('retaining invoice writes until their outcome and refresh are known', () => {
    function response(status: number) {
        return new Response(
            JSON.stringify({ message: 'Synthetic refusal', data: {} }),
            { status },
        );
    }
    function request(index: number) {
        const [url, init] = requestFetch.mock.calls[index];

        return {
            url,
            method: init.method,
            body: init.body,
            key: (init.headers as Record<string, string>)['Idempotency-Key'],
        };
    }

    it('keeps a payment edit on its observed version before the first write', async () => {
        const initial = props();
        const { rerender } = render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
        fireEvent.change(screen.getByLabelText('Amount'), {
            target: { value: '40.00' },
        });
        rerender(
            <ClientInvoiceDetail
                {...initial}
                version={'2'.repeat(64)}
                actions={{
                    ...initial.actions,
                    payment: '/changed-payment-url',
                }}
                invoice={{ ...initial.invoice, currency: 'EUR' }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Record' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(1));
        expect(request(0).url).toBe(initial.actions.payment);
        expect(JSON.parse(String(request(0).body))).toMatchObject({
            expected_version: initial.version,
            currency: 'USD',
            amount: 4000,
            invoice_id: initial.invoice.id,
        });
    });

    it('retains an uncertain payment through later refusal and retries a known saved result only by refreshing', async () => {
        requestFetch.mockResolvedValueOnce(response(408));
        const initial = props();
        const { rerender } = render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
        fireEvent.change(screen.getByLabelText('Reference'), {
            target: { value: 'Synthetic original cheque' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Record' }));
        await screen.findByRole('button', { name: 'Check original request' });
        expect(screen.getByLabelText('Reference')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Record payment' }),
        ).toBeDisabled();
        rerender(
            <ClientInvoiceDetail
                {...initial}
                version={'3'.repeat(64)}
                actions={{ ...initial.actions, payment: null }}
            />,
        );
        fireEvent.change(screen.getByLabelText('Reference'), {
            target: { value: 'Edited after timeout' },
        });
        requestFetch.mockResolvedValueOnce(response(422));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
        await vi.waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check original request' }),
            ).toBeEnabled(),
        );
        expect(screen.getByLabelText('Reference')).toBeDisabled();
        inertia.reload.mockImplementation(
            (options: { onFinish?: () => void }) => options.onFinish?.(),
        );
        requestFetch.mockResolvedValueOnce(response(200));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await screen.findByText(
            /The change was saved, but the page could not refresh/,
        );
        expect(screen.getByLabelText('Reference')).toBeDisabled();
        expect(requestFetch).toHaveBeenCalledTimes(3);
        expect(request(2)).toEqual(request(0));
        inertia.reload.mockImplementation(
            (options: { onSuccess?: () => void; onFinish?: () => void }) => {
                options.onSuccess?.();
                options.onFinish?.();
            },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh saved result' }),
        );
        await vi.waitFor(() =>
            expect(screen.queryByLabelText('Reference')).toBeNull(),
        );
        expect(requestFetch).toHaveBeenCalledTimes(3);
    });

    it('uses the date edit baseline and retains its key even when capabilities disappear after a timeout', async () => {
        requestFetch.mockResolvedValueOnce(response(408));
        const initial = props({
            actions: {
                issue: null,
                send: null,
                payment: null,
                void: null,
                update_details: '/original-date-url',
            },
            invoice: { ...props().invoice, status: 'draft' },
        });
        const { rerender } = render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(screen.getByRole('button', { name: 'Change dates' }));
        fireEvent.change(screen.getByLabelText('Due date'), {
            target: { value: '2026-11-30' },
        });
        rerender(
            <ClientInvoiceDetail
                {...initial}
                version={'4'.repeat(64)}
                invoice={{ ...initial.invoice, due_date: '2026-12-31' }}
                actions={{ ...initial.actions, update_details: null }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save dates' }));
        await screen.findByRole('button', { name: 'Check original request' });
        expect(request(0).url).toBe('/original-date-url');
        expect(JSON.parse(String(request(0).body))).toEqual({
            expected_version: initial.version,
            due_date: '2026-11-30',
        });
        expect(screen.getByLabelText('Due date')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        requestFetch.mockResolvedValueOnce(response(403));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });

    it('does not pair an existing correction draft with a refreshed invoice version', async () => {
        const initial = props({
            actions: { ...props().actions, correct: '/original-correction' },
            lines: [
                {
                    id: 'synthetic-line',
                    type: 'service',
                    description: 'Original work',
                    quantity: '1.0000',
                    hours: null,
                    line_date: null,
                    unit_amount: 100,
                    total_amount: 100,
                },
            ],
        });
        const { rerender } = render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Correct invoice' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for correction'), {
            target: { value: 'Synthetic correction reason' },
        });
        rerender(
            <ClientInvoiceDetail
                {...initial}
                version={'5'.repeat(64)}
                actions={{
                    ...initial.actions,
                    correct: '/refreshed-correction',
                }}
                lines={[
                    { ...initial.lines[0], description: 'Concurrent work' },
                ]}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Save audited correction' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(1));
        expect(request(0).url).toBe('/original-correction');
        expect(JSON.parse(String(request(0).body))).toMatchObject({
            expected_version: initial.version,
            lines: [{ id: 'synthetic-line', description: 'Original work' }],
        });
    });

    it('keeps a payment-date edit on the payment version and URL observed when opened', async () => {
        requestFetch.mockResolvedValueOnce(response(408));
        const payment = {
            id: 'synthetic-payment',
            version: 'original-payment-version',
            status: 'succeeded',
            method: 'check',
            reference: null,
            received_on: '2026-08-20',
            correct_date_href: '/original-payment-date',
            amount: 4000,
            refunded_amount: 0,
            currency: 'USD',
        };
        const initial = props({ payments: [payment] });
        const { rerender } = render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(screen.getByRole('button', { name: 'Correct date' }));
        fireEvent.change(screen.getByLabelText('Reason for the correction'), {
            target: { value: 'Synthetic bank date' },
        });
        rerender(
            <ClientInvoiceDetail
                {...initial}
                payments={[
                    {
                        ...payment,
                        version: 'refreshed-payment-version',
                        correct_date_href: '/changed-payment-date',
                    },
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await screen.findByRole('button', { name: 'Check original request' });
        expect(request(0).url).toBe('/original-payment-date');
        expect(JSON.parse(String(request(0).body))).toMatchObject({
            expected_version: 'original-payment-version',
            received_on: '2026-08-20',
            reason: 'Synthetic bank date',
        });
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        requestFetch.mockResolvedValueOnce(response(422));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
    });

    it('keeps a void confirmation mounted and non-cancellable after an uncertain handoff', async () => {
        requestFetch.mockRejectedValueOnce(
            new TypeError('Synthetic lost response'),
        );
        const initial = props({
            actions: { ...props().actions, void: '/original-void' },
        });
        render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(screen.getByRole('button', { name: 'Void' }));
        const dialog = await screen.findByRole('alertdialog');
        fireEvent.change(within(dialog).getByLabelText('Reason'), {
            target: { value: 'Synthetic void reason' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Void' }));
        await within(dialog).findByRole('button', {
            name: 'Check original request',
        });
        expect(dialog).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: 'Cancel' }),
        ).toBeDisabled();
        expect(within(dialog).getByLabelText('Reason')).toBeDisabled();
        requestFetch.mockResolvedValueOnce(response(404));
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Check original request',
            }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
        expect(dialog).toBeInTheDocument();
    });
    it('keeps unknown compose mounted after a same-invoice capability refresh', async () => {
        requestFetch.mockResolvedValueOnce(response(408));
        const email = {
            from: 'SVC <invoices@example.test>',
            self: 'operator@example.test',
            default_subject: 'Original invoice',
            suggested_recipients: [
                { email: 'billing@example.test', label: 'Billing' },
            ],
        };
        const initial = props({
            actions: { ...props().actions, send: '/original-send' },
            email,
        });
        const { rerender } = render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send to client' }));
        fireEvent.change(screen.getByLabelText('Subject'), {
            target: { value: 'Original compose body' },
        });
        fireEvent.click(screen.getByRole('button', { name: /^Send$/ }));
        await screen.findByRole('button', { name: 'Check delivery' });
        rerender(
            <ClientInvoiceDetail
                {...initial}
                actions={{ ...initial.actions, send: null }}
                email={null}
                version={'8'.repeat(64)}
            />,
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByLabelText('Subject')).toHaveValue(
            'Original compose body',
        );
        expect(screen.getByLabelText('Subject')).toBeDisabled();
        requestFetch.mockResolvedValueOnce(response(409));
        fireEvent.click(screen.getByRole('button', { name: 'Check delivery' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
    });

    it('initializes a different invoice composer with its own defaults after a known result', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({
                    data: {
                        id: 'invoice-1',
                        version: '2'.repeat(64),
                        delivery: { status: 'sent', error_summary: null },
                    },
                }),
                { status: 200 },
            ),
        );
        inertia.reload.mockImplementation(
            (options: { onFinish?: () => void }) => options.onFinish?.(),
        );
        const email = {
            from: 'SVC <invoices@example.test>',
            self: 'operator@example.test',
            default_subject: 'First invoice',
            suggested_recipients: [
                { email: 'first@example.test', label: 'First' },
            ],
        };
        const initial = props({
            actions: { ...props().actions, send: '/first-send' },
            email,
        });
        const { rerender } = render(<ClientInvoiceDetail {...initial} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send to client' }));
        fireEvent.change(screen.getByLabelText('Subject'), {
            target: { value: 'First private compose draft' },
        });
        fireEvent.change(screen.getByLabelText('Covering note (optional)'), {
            target: { value: 'First private note' },
        });
        fireEvent.click(screen.getByRole('button', { name: /^Send$/ }));
        await screen.findByRole('button', { name: 'Refresh invoice' });
        rerender(
            <ClientInvoiceDetail
                {...initial}
                invoice={{ ...initial.invoice, id: 'invoice-2' }}
                actions={{ ...initial.actions, send: '/second-send' }}
                email={{
                    ...email,
                    default_subject: 'Second invoice',
                    suggested_recipients: [
                        { email: 'second@example.test', label: 'Second' },
                    ],
                }}
                version={'9'.repeat(64)}
            />,
        );
        expect(screen.getByLabelText('Subject')).toHaveValue('Second invoice');
        expect(screen.getByLabelText('Covering note (optional)')).toHaveValue(
            '',
        );
        expect(screen.getByText('second@example.test')).toBeVisible();
        expect(screen.queryByText('first@example.test')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /^Send$/ }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1).url).toBe('/second-send');
        expect(JSON.parse(String(request(1).body))).toMatchObject({
            expected_version: '9'.repeat(64),
            recipients: ['second@example.test'],
            subject: 'Second invoice',
            message: null,
        });
        expect(request(1).key).not.toBe(request(0).key);
    });
});
