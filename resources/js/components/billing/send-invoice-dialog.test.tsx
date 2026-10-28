import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SendInvoiceDialog } from '@/components/billing/send-invoice-dialog';

const inertia = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: inertia }));
const requestFetch =
    vi.fn<(url: string, init: RequestInit) => Promise<Response>>();
const changed = vi.fn();
const version = 'a'.repeat(64);
const props = {
    open: true,
    onOpenChange: changed,
    sendHref:
        '/api/v1/workspaces/synthetic-workspace/invoices/synthetic-invoice/send',
    expectedVersion: version,
    email: {
        from: 'SVC <invoices@example.test>',
        self: 'synthetic-owner@example.test',
        default_subject: 'Synthetic invoice',
        suggested_recipients: [
            { email: 'billing@example.test', label: 'Billing' },
        ],
    },
};

function outcome(status: string | null = 'sent', currentVersion = version) {
    return new Response(
        JSON.stringify({
            data: {
                id: 'synthetic-invoice',
                version: currentVersion,
                delivery:
                    status === null
                        ? null
                        : {
                              id: 'synthetic-delivery',
                              status,
                              error_summary:
                                  status === 'failed'
                                      ? 'Email delivery failed (RuntimeException).'
                                      : null,
                          },
            },
        }),
        { status: 200 },
    );
}

function requests() {
    return requestFetch.mock.calls.map(([url, init]) => ({
        url,
        key: new Headers(init.headers).get('Idempotency-Key'),
        body: JSON.parse(String(init.body)),
    }));
}

beforeEach(() => {
    changed.mockReset();
    requestFetch.mockReset();
    requestFetch.mockImplementation(async () => outcome());
    vi.stubGlobal('fetch', requestFetch);
    inertia.reload.mockReset();
    inertia.reload.mockImplementation((options: { onFinish?: () => void }) =>
        options.onFinish?.(),
    );
});
afterEach(() => vi.unstubAllGlobals());

describe('invoice send outcome', () => {
    it('sends the composed message through the server capability and closes only on acceptance', async () => {
        render(<SendInvoiceDialog {...props} />);
        fireEvent.change(screen.getByLabelText('Subject'), {
            target: { value: 'Synthetic edited subject' },
        });
        fireEvent.change(screen.getByLabelText('Covering note (optional)'), {
            target: { value: 'Synthetic covering note' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(requests()[0]).toEqual({
            url: props.sendHref,
            key: expect.any(String),
            body: {
                expected_version: version,
                confirm: true,
                recipients: ['billing@example.test'],
                subject: 'Synthetic edited subject',
                message: 'Synthetic covering note',
                bcc_self: true,
            },
        });
        expect(requests()[0].key).not.toBe('');
    });

    it('recovers a lost response using the unchanged body and key even if page version changes', async () => {
        requestFetch.mockRejectedValueOnce(
            new TypeError('Synthetic lost response'),
        );
        const view = render(<SendInvoiceDialog {...props} />);
        fireEvent.change(screen.getByLabelText('Covering note (optional)'), {
            target: { value: 'Keep this note' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByRole('button', {
            name: 'Check delivery',
        });
        expect(
            screen.getByLabelText('Covering note (optional)'),
        ).toBeDisabled();
        expect(screen.getByLabelText('Subject')).toBeDisabled();
        expect(changed).not.toHaveBeenCalled();
        view.rerender(
            <SendInvoiceDialog
                {...props}
                sendHref="/synthetic/other-invoice/send"
                expectedVersion={'c'.repeat(64)}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check delivery' }));
        await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
        expect(requests()).toHaveLength(2);
        expect(requests()[1]).toEqual(requests()[0]);
    });

    it.each(['pending', 'sending', null])(
        'keeps %s unresolved and checks the same attempt without a new send',
        async (status) => {
            requestFetch.mockResolvedValueOnce(outcome(status));
            render(<SendInvoiceDialog {...props} />);
            fireEvent.click(screen.getByRole('button', { name: 'Send' }));
            await screen.findByRole('button', {
                name: 'Check delivery',
            });
            expect(changed).not.toHaveBeenCalled();
            expect(inertia.reload).not.toHaveBeenCalled();
            expect(screen.getByLabelText('Subject')).toBeDisabled();
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Check delivery',
                }),
            );
            await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
            expect(requests()[1]).toEqual(requests()[0]);
        },
    );

    it('allows an explicit new attempt only after a proven preparation failure', async () => {
        requestFetch.mockResolvedValueOnce(outcome('failed', 'b'.repeat(64)));
        render(<SendInvoiceDialog {...props} />);
        fireEvent.change(screen.getByLabelText('Covering note (optional)'), {
            target: { value: 'Preserve failed note' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByText(/The message was not handed off/);
        expect(screen.getByLabelText('Covering note (optional)')).toHaveValue(
            'Preserve failed note',
        );
        expect(screen.getByLabelText('Subject')).not.toBeDisabled();
        expect(changed).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Subject'), {
            target: { value: 'Synthetic retry subject' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
        expect(requests()[1].key).not.toBe(requests()[0].key);
        expect(requests()[1].body).toMatchObject({
            expected_version: 'b'.repeat(64),
            subject: 'Synthetic retry subject',
            message: 'Preserve failed note',
            confirm: true,
        });
    });

    it('keeps field validation editable and starts a new corrected attempt', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({
                    message: 'Synthetic subject required',
                    errors: { subject: ['Synthetic subject required'] },
                }),
                { status: 422 },
            ),
        );
        render(<SendInvoiceDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByText('Synthetic subject required');
        expect(screen.getByLabelText('Subject')).not.toBeDisabled();
        fireEvent.change(screen.getByLabelText('Subject'), {
            target: { value: 'Synthetic corrected subject' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
        expect(requests()[1].key).not.toBe(requests()[0].key);
        expect(requests()[1].body.subject).toBe('Synthetic corrected subject');
    });

    it('treats server errors as uncertain and retains the same attempt', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({ message: 'Synthetic result unavailable' }),
                { status: 500 },
            ),
        );
        render(<SendInvoiceDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByRole('button', {
            name: 'Check delivery',
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Synthetic result unavailable',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check delivery' }));
        await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
        expect(requests()[1]).toEqual(requests()[0]);
    });

    it('requires a recipient before beginning an attempt', async () => {
        render(
            <SendInvoiceDialog
                {...props}
                email={{ ...props.email, suggested_recipients: [] }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Add at least one recipient.',
        );
        expect(requestFetch).not.toHaveBeenCalled();
    });
});
