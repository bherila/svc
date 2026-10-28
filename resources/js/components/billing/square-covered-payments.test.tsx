import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { SquareCoveredPayments } from '@/components/billing/square-covered-payments';
import type { ApiSchema } from '@/lib/api';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

const refreshed = vi.hoisted(() => vi.fn());
vi.mock('@/lib/refresh-page-props', () => ({ refreshPageProps: refreshed }));
const reviewUrl = '/api/v1/synthetic-covered-review';
const recordUrl = '/api/v1/synthetic-confirmed-record';
const version = 'a'.repeat(64);
function review(
    refused = false,
): ApiSchema<'SquareCoveredPaymentReviewResponse'>['data'] {
    return {
        invoice_id: '00000000-0000-4000-8000-000000000001',
        invoice_number: 'SYNTHETIC-INVOICE-' + 'X'.repeat(250),
        invoice_version: version,
        balance_amount: 15000,
        currency: 'USD',
        square_invoice_id: 'inv_fixture_review',
        square_invoice_number: 'SYNTHETIC-COVERED-' + 'X'.repeat(250),
        payments: [
            {
                square_payment_id: 'synthetic_payment_' + 'X'.repeat(170),
                amount: 12345,
                refunded_amount: 0,
                currency: 'USD',
                received_on: '2030-02-01',
                reviewed_version: 'b'.repeat(64),
                recorded_payment_id: null,
                refusal: refused
                    ? 'This payment exceeds the remaining invoice balance.'
                    : null,
                record_href: refused ? null : recordUrl,
            },
        ],
    };
}
function response(data: unknown, status = 200) {
    return new Response(JSON.stringify({ data }), { status });
}
afterEach(() => {
    vi.unstubAllGlobals();
    refreshed.mockReset();
});

it('reviews first and sends explicit confirmation with both captured versions to the finished URL', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response(review()))
        .mockResolvedValueOnce(response({ payment_id: 'synthetic-record' }));
    vi.stubGlobal('fetch', fetchMock);
    const { container } = render(<SquareCoveredPayments href={reviewUrl} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review payments' }));
    await screen.findByText('synthetic_payment_' + 'X'.repeat(170));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(horizontalOverflowRisks(container)).toEqual([]);
    fireEvent.click(
        screen.getByRole('button', { name: 'Record this payment' }),
    );
    expect(screen.getByRole('alertdialog')).toHaveTextContent(
        'remaining balance is $150.00',
    );
    expect(fetchMock).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm recording' }));
    await vi.waitFor(() => expect(refreshed).toHaveBeenCalledOnce());
    const [url, init] = fetchMock.mock.calls[1] as [string, RequestInit];
    expect(url).toBe(recordUrl);
    expect(JSON.parse(String(init.body))).toEqual({
        expected_version: version,
        reviewed_version: 'b'.repeat(64),
        confirm: true,
    });
});

it('shows refused payments without offering a record action', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response(review(true))));
    render(<SquareCoveredPayments href={reviewUrl} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review payments' }));
    await screen.findByText(
        'This payment exceeds the remaining invoice balance.',
    );
    expect(
        screen.queryByRole('button', { name: 'Record this payment' }),
    ).not.toBeInTheDocument();
});

it('requires a fresh review when the invoice or provider facts changed', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response(review()))
        .mockResolvedValueOnce(
            new Response(
                JSON.stringify({
                    message:
                        'Square payment facts changed. Review the payment again.',
                }),
                { status: 409 },
            ),
        );
    vi.stubGlobal('fetch', fetchMock);
    render(<SquareCoveredPayments href={reviewUrl} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review payments' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Record this payment' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Confirm recording' }));
    await screen.findByText(
        'Square payment facts changed. Review the payment again.',
    );
    expect(
        screen.queryByRole('button', { name: 'Record this payment' }),
    ).not.toBeInTheDocument();
    expect(refreshed).not.toHaveBeenCalled();
});

it('keeps one receipt key when retrying after a lost response', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response(review()))
        .mockRejectedValueOnce(new Error('Synthetic lost response'))
        .mockResolvedValueOnce(response({ payment_id: 'synthetic-record' }));
    vi.stubGlobal('fetch', fetchMock);
    render(<SquareCoveredPayments href={reviewUrl} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review payments' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Record this payment' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Confirm recording' }));
    await screen.findByText(
        'SVC could not be reached. Check the connection and try again.',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Confirm recording' }));
    await vi.waitFor(() => expect(refreshed).toHaveBeenCalledOnce());
    const first = fetchMock.mock.calls[1][1] as RequestInit;
    const retry = fetchMock.mock.calls[2][1] as RequestInit;
    expect(first.headers).toEqual(retry.headers);
    expect(first.body).toEqual(retry.body);
});
