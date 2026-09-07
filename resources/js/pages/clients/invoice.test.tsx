import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ClientInvoiceDetail from '@/pages/clients/invoice';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

const inertia = vi.hoisted(() => ({ post: vi.fn(), visit: vi.fn() }));

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
        actions: {
            issue: null,
            send: null,
            payment: '/workspaces/w-1/invoices/invoice-1/payments',
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
    it('offers a date defaulted to today on the workspace calendar and sends it', () => {
        render(<ClientInvoiceDetail {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));

        const received = screen.getByLabelText('Received');
        expect(received).toHaveValue('2026-08-29');

        fireEvent.change(received, { target: { value: '2026-08-24' } });
        fireEvent.click(screen.getByRole('button', { name: 'Record' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/workspaces/w-1/invoices/invoice-1/payments',
            expect.objectContaining({ received_on: '2026-08-24' }),
            expect.anything(),
        );
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
