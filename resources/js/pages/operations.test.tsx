import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AgreementActivationButton,
    InvoiceIssueButton,
    InvoiceForm,
    PaymentForm,
} from '@/pages/operations';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(
        (options: { onSuccess?: () => void; onFinish?: () => void }) => {
            options.onSuccess?.();
            options.onFinish?.();
        },
    ),
}));
const requestFetch = vi.fn();

beforeEach(() => {
    requestFetch.mockReset();
    requestFetch.mockImplementation(
        async () => new Response(JSON.stringify({ data: {} }), { status: 200 }),
    );
    vi.stubGlobal('fetch', requestFetch);
});

afterEach(() => vi.unstubAllGlobals());

/**
 * A stub that keeps the part of `useForm` this file is about: its lifetime.
 *
 * Inertia's form survives its own submission - the component stays mounted
 * across the redirect and the data it holds is whatever the last render left
 * there - which is precisely the behaviour a naive stub hides. So `setData`
 * writes, `reset` restores the values captured at mount rather than today's,
 * and `post` runs the success callback the way a 302 back to this page does.
 */
const form = vi.hoisted(() => ({
    data: {} as Record<string, unknown>,
    initial: null as Record<string, unknown> | null,
    errors: {} as Record<string, string>,
    processing: false,
    setData: vi.fn((key: string, value: unknown) => {
        form.data[key] = value;
    }),
    transform: vi.fn(),
    post: vi.fn((_href: string, options?: { onSuccess?: () => void }) =>
        options?.onSuccess?.(),
    ),
    reset: vi.fn((...keys: string[]) => {
        if (keys.length === 0) {
            keys = Object.keys(form.initial ?? {});
        }

        for (const key of keys) {
            form.data[key] = form.initial?.[key];
        }
    }),
}));

describe('drafting an invoice from operations', () => {
    beforeEach(() => {
        form.data = {};
        form.initial = null;
        form.errors = {};
    });

    it('uses the finished capability and retains its attempt key and values after a lost response', async () => {
        requestFetch.mockRejectedValueOnce(
            new TypeError('Synthetic lost response'),
        );
        render(
            <InvoiceForm
                href="/synthetic/invoice-create"
                clientId="synthetic-company"
            />,
        );
        fireEvent.change(screen.getByPlaceholderText('Invoice number'), {
            target: { value: 'SYNTHETIC-CREATED' },
        });
        fireEvent.change(screen.getByPlaceholderText('Line description'), {
            target: { value: 'Synthetic service' },
        });
        fireEvent.change(screen.getByPlaceholderText('Unit price'), {
            target: { value: '123.45' },
        });
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await screen.findByRole('alert');
        expect(screen.getByPlaceholderText('Invoice number')).toHaveValue(
            'SYNTHETIC-CREATED',
        );
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await vi.waitFor(() => expect(inertia.reload).toHaveBeenCalled());
        expect(requestFetch.mock.calls).toHaveLength(2);
        const first = requestFetch.mock.calls[0];
        const second = requestFetch.mock.calls[1];
        expect(first[0]).toBe('/synthetic/invoice-create');
        expect(JSON.parse(first[1].body)).toEqual({
            company_id: 'synthetic-company',
            invoice_number: 'SYNTHETIC-CREATED',
            due_date: null,
            currency: 'USD',
            is_visible_to_client: false,
            manual_lines: [
                {
                    type: 'service',
                    description: 'Synthetic service',
                    quantity: '1',
                    unit_amount: 12345,
                    tax_amount: 0,
                },
            ],
        });
        expect(first[1].headers['Idempotency-Key']).toBe(
            second[1].headers['Idempotency-Key'],
        );
        expect(first[1].body).toBe(second[1].body);
    });
});

vi.mock('@inertiajs/react', async () => {
    const { useRef } = await import('react');

    return {
        router: inertia,
        useForm: (initial: Record<string, unknown>) => {
            // Captured once, like the real hook: the initial values are the ones
            // the page loaded with, not the ones the clock says on a later render.
            const initialized = useRef(false);

            if (!initialized.current) {
                initialized.current = true;
                form.initial = { ...initial };
                form.data = { ...initial };
            }

            return form;
        },
    };
});

function invoice(overrides: Record<string, unknown> = {}) {
    return {
        id: 'invoice-1',
        version: '1'.repeat(64),
        issue_href: null,
        payment_href: '/api/v1/workspaces/workspace-1/payments',
        pdf_href: '/workspaces/workspace-1/invoices/invoice-1/pdf',
        invoice_number: 'INV-SYNTH-1',
        status: 'issued',
        total_amount: 10000,
        paid_amount: 0,
        balance_amount: 10000,
        currency: 'USD',
        issue_date: '2026-08-15',
        due_date: '2026-09-14',
        attachments: [],
        ...overrides,
    };
}

describe('agreement activation', () => {
    it('shows an overlap refusal beside the confirmed API activation control', async () => {
        const message =
            'This agreement cannot overlap another active agreement. Ask an operator to verify its terms.';
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({ message, errors: { engagement: [message] } }),
                { status: 422 },
            ),
        );
        render(
            <AgreementActivationButton
                href="/synthetic/agreements/activate"
                version={'a'.repeat(64)}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Activate' }));
        const dialog = await screen.findByRole('dialog');
        expect(requestFetch).not.toHaveBeenCalled();
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Activate',
            }),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(message);
        expect(JSON.parse(String(requestFetch.mock.calls[0][1].body))).toEqual({
            expected_version: 'a'.repeat(64),
            confirm: true,
        });
    });
});

describe('issuing an invoice from operations', () => {
    it('uses the server capability and retains the same attempt key after an uncertain response', async () => {
        requestFetch.mockRejectedValueOnce(
            new TypeError('Synthetic lost response'),
        );
        render(
            <InvoiceIssueButton
                invoice={invoice({
                    status: 'draft',
                    issue_href:
                        '/api/v1/workspaces/workspace-1/invoices/invoice-1/issue',
                })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Issue' }));
        await vi.waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'SVC could not be reached',
            ),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        const [url, first] = requestFetch.mock.calls[0] as [
            string,
            RequestInit,
        ];
        const [, second] = requestFetch.mock.calls[1] as [string, RequestInit];
        expect(url).toBe(
            '/api/v1/workspaces/workspace-1/invoices/invoice-1/issue',
        );
        expect(JSON.parse(String(first.body))).toEqual({
            expected_version: '1'.repeat(64),
            confirm: true,
        });
        expect(
            (first.headers as Record<string, string>)['Idempotency-Key'],
        ).toBe((second.headers as Record<string, string>)['Idempotency-Key']);
    });
});

/**
 * This form has always held `received_on`, defaulted it and posted it. It never
 * rendered it, so the value an operator could not see was the day they happened
 * to be typing on - and it is the column an external finance system
 * reconciles by.
 */
describe('recording a payment from the operations screen', () => {
    beforeEach(() => {
        form.data = {};
        form.initial = null;
        form.errors = {};
        // 05:30 UTC on the 30th is the 29th in Los Angeles: the default is the
        // workspace's day rather than UTC's.
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-08-30T05:30:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the date it was already sending, on the workspace calendar', () => {
        render(
            <PaymentForm invoice={invoice()} timezone="America/Los_Angeles" />,
        );

        const received = screen.getByLabelText('Payment received on');
        expect(received).toHaveValue('2026-08-29');

        fireEvent.change(received, { target: { value: '2026-08-24' } });
        expect(form.setData).toHaveBeenCalledWith('received_on', '2026-08-24');
    });

    it('warns about a date before the invoice was issued without blocking it', () => {
        const { rerender } = render(
            <PaymentForm invoice={invoice()} timezone="America/Los_Angeles" />,
        );

        fireEvent.change(screen.getByLabelText('Payment received on'), {
            target: { value: '2026-07-01' },
        });
        rerender(
            <PaymentForm invoice={invoice()} timezone="America/Los_Angeles" />,
        );

        expect(screen.getByRole('status')).toHaveTextContent(
            'This payment is dated before the invoice was issued on Aug 15, 2026.',
        );
        expect(
            screen.getByRole('button', { name: 'Record payment' }),
        ).toBeEnabled();
    });

    /**
     * A backdate must not outlive the payment it was typed for.
     *
     * Inertia keeps this form mounted across the redirect, so the date an
     * operator entered to record last Tuesday's cheque stayed in the field, and
     * the next payment recorded in the same sitting carried it. That is the
     * same wrong-period defect the field exists to fix, arrived at from the
     * other side and quieter: a stale date reads exactly like the default it
     * replaced, so there is nothing to notice.
     *
     * The clock is moved on between the two payments, which is what separates
     * the fix from `form.reset('received_on')` - reset restores the value
     * captured when the page loaded, and this screen can sit open overnight.
     */
    it('returns the date to today after a payment is recorded', async () => {
        const screenProps = {
            invoice: invoice(),
            timezone: 'America/Los_Angeles',
        };
        const { container, rerender } = render(
            <PaymentForm {...screenProps} />,
        );

        fireEvent.change(screen.getByLabelText('Payment received on'), {
            target: { value: '2026-08-10' },
        });
        expect(form.data.received_on).toBe('2026-08-10');

        // The page has been open past its own midnight, twice.
        vi.setSystemTime(new Date('2026-09-02T12:00:00Z'));
        fireEvent.submit(container.querySelector('form') as HTMLFormElement);

        await vi.waitFor(() =>
            expect(form.data.received_on).toBe('2026-09-02'),
        );
        expect(form.data.received_on).toBe('2026-09-02');
        const [url, request] = requestFetch.mock.calls[0] as [
            string,
            RequestInit,
        ];
        expect(url).toBe('/api/v1/workspaces/workspace-1/payments');
        expect(JSON.parse(String(request.body))).toEqual(
            expect.objectContaining({
                invoice_id: 'invoice-1',
                expected_version: '1'.repeat(64),
                confirm: true,
                received_on: '2026-08-10',
                amount: 10000,
            }),
        );
        rerender(<PaymentForm {...screenProps} />);
        expect(screen.getByLabelText('Payment received on')).toHaveValue(
            '2026-09-02',
        );

        // The reference is cleared and the retry key replaced, as before: this
        // adds the date to that set rather than replacing it.
        expect(form.data.reference).toBe('');
        fireEvent.submit(container.querySelector('form') as HTMLFormElement);
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(
            requestFetch.mock.calls[1][1].headers['Idempotency-Key'],
        ).not.toBe(requestFetch.mock.calls[0][1].headers['Idempotency-Key']);
    });

    /**
     * A bounded date is refused by the service as a `billing` error, and this
     * form used to drop every one of them: the operator pressed the button and
     * the screen sat there.
     */
    it('shows the service refusal it used to swallow', () => {
        form.errors = {
            billing:
                'A payment cannot be dated before 2024-08-29. Nothing has been changed.',
        };
        render(
            <PaymentForm invoice={invoice()} timezone="America/Los_Angeles" />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'A payment cannot be dated before 2024-08-29.',
        );
    });
});

describe('invoice operations recovery and edit baselines', () => {
    beforeEach(() => {
        form.data = {};
        form.initial = null;
        form.errors = {};
        inertia.reload.mockImplementation(
            (options: { onSuccess?: () => void; onFinish?: () => void }) => {
                options.onSuccess?.();
                options.onFinish?.();
            },
        );
    });
    function failure(status: number) {
        return new Response(JSON.stringify({ message: 'Synthetic refusal' }), {
            status,
        });
    }
    function request(index: number) {
        const [url, init] = requestFetch.mock.calls[index];

        return {
            url,
            method: init.method,
            body: init.body,
            key: init.headers['Idempotency-Key'],
        };
    }
    it('keeps an unknown draft intact across editing/context changes and only refreshes after known save', async () => {
        requestFetch.mockResolvedValueOnce(failure(408));
        const { rerender } = render(
            <InvoiceForm href="/original-drafts" clientId="original-company" />,
        );
        fireEvent.change(screen.getByPlaceholderText('Invoice number'), {
            target: { value: 'SYN-ORIGINAL' },
        });
        fireEvent.change(screen.getByPlaceholderText('Line description'), {
            target: { value: 'Original work' },
        });
        fireEvent.change(screen.getByPlaceholderText('Unit price'), {
            target: { value: '25.00' },
        });
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await screen.findByRole('button', { name: 'Check original request' });
        expect(
            screen.getByRole('button', { name: 'Reset draft form' }),
        ).toBeDisabled();
        expect(screen.getByPlaceholderText('Invoice number')).toBeDisabled();
        rerender(<InvoiceForm href={null} clientId="different-company" />);
        form.data.invoice_number = 'Edited after unknown';
        requestFetch.mockResolvedValueOnce(failure(422));
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
        await vi.waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check original request' }),
            ).toBeEnabled(),
        );
        inertia.reload.mockImplementation(
            (options: { onFinish?: () => void }) => options.onFinish?.(),
        );
        fireEvent.submit(screen.getByRole('form', { name: 'Draft invoice' }));
        await screen.findByText(
            /The change was saved, but the page could not refresh/,
        );
        expect(requestFetch).toHaveBeenCalledTimes(3);
        expect(request(2)).toEqual(request(0));
        expect(form.data.invoice_number).toBe('Edited after unknown');
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
            expect(
                screen.queryByRole('form', { name: 'Draft invoice' }),
            ).toBeNull(),
        );
        expect(requestFetch).toHaveBeenCalledTimes(3);
    });
    it('keeps an old payment edit and uncertain request on their observed version, then starts fresh after verified refresh', async () => {
        requestFetch.mockResolvedValueOnce(failure(408));
        const { container, rerender } = render(
            <PaymentForm invoice={invoice()} timezone="UTC" />,
        );
        fireEvent.change(screen.getByLabelText('Payment amount'), {
            target: { value: '25.00' },
        });
        rerender(
            <PaymentForm
                invoice={invoice({
                    version: '2'.repeat(64),
                    payment_href: '/new-payments',
                })}
                timezone="UTC"
            />,
        );
        fireEvent.submit(container.querySelector('form') as HTMLFormElement);
        await screen.findByRole('button', { name: 'Check original request' });
        expect(JSON.parse(request(0).body)).toMatchObject({
            expected_version: '1'.repeat(64),
            amount: 2500,
        });
        expect(request(0).url).toBe('/api/v1/workspaces/workspace-1/payments');
        expect(screen.getByLabelText('Payment amount')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Reset payment form' }),
        ).toBeDisabled();
        rerender(
            <PaymentForm
                invoice={invoice({
                    version: '3'.repeat(64),
                    payment_href: null,
                })}
                timezone="UTC"
            />,
        );
        form.data.amount = '99.00';
        requestFetch.mockResolvedValueOnce(failure(403));
        fireEvent.submit(container.querySelector('form') as HTMLFormElement);
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
        await vi.waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Check original request' }),
            ).toBeEnabled(),
        );
        rerender(
            <PaymentForm
                invoice={invoice({
                    version: '4'.repeat(64),
                    payment_href: '/current-payments',
                    balance_amount: 5000,
                })}
                timezone="UTC"
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await vi.waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Record payment' }),
            ).toBeEnabled(),
        );
        expect(request(2)).toEqual(request(0));
        expect(screen.getByLabelText('Payment amount')).toHaveValue(50);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(4));
        expect(request(3).key).not.toBe(request(0).key);
        expect(request(3).url).toBe('/current-payments');
        expect(JSON.parse(request(3).body)).toMatchObject({
            expected_version: '4'.repeat(64),
            amount: 5000,
        });
    });
    it('keeps an unknown issue request available when refreshed props remove its capability', async () => {
        requestFetch.mockResolvedValueOnce(failure(408));
        const { rerender } = render(
            <InvoiceIssueButton
                invoice={invoice({ issue_href: '/original-issue' })}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Issue' }));
        await screen.findByRole('button', { name: 'Check original request' });
        rerender(
            <InvoiceIssueButton
                invoice={invoice({ issue_href: null, version: '8'.repeat(64) })}
            />,
        );
        requestFetch.mockResolvedValueOnce(failure(409));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check original request' }),
        );
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(2));
        expect(request(1)).toEqual(request(0));
    });
    it('opens a newly available payment form with current defaults rather than an earlier hidden draft', async () => {
        const { rerender } = render(
            <PaymentForm
                invoice={invoice({ payment_href: null })}
                timezone="UTC"
            />,
        );
        expect(screen.queryByLabelText('Payment amount')).toBeNull();
        rerender(
            <PaymentForm
                invoice={invoice({
                    payment_href: '/available-payment',
                    version: '9'.repeat(64),
                    balance_amount: 2500,
                })}
                timezone="UTC"
            />,
        );
        expect(screen.getByLabelText('Payment amount')).toHaveValue(25);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));
        await vi.waitFor(() => expect(requestFetch).toHaveBeenCalledTimes(1));
        expect(JSON.parse(request(0).body)).toMatchObject({
            expected_version: '9'.repeat(64),
            amount: 2500,
        });
    });
});
