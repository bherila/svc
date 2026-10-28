import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SendInvoiceDialog } from '@/components/billing/send-invoice-dialog';

const inertia = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: inertia }));
const requestFetch =
    vi.fn<(url: string, init: RequestInit) => Promise<Response>>();
const changed = vi.fn();
let refreshedVersion = 'a'.repeat(64);
let refreshedSendHref: string | null;
const pageProps = () => ({
    props: {
        invoice: { id: props.invoiceId },
        version: refreshedVersion,
        actions: { send: refreshedSendHref },
        email: props.email,
    },
});
const version = 'a'.repeat(64);
const props = {
    open: true,
    onOpenChange: changed,
    sendHref:
        '/api/v1/workspaces/synthetic-workspace/invoices/synthetic-invoice/send',
    expectedVersion: version,
    invoiceId: 'synthetic-invoice',
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
    refreshedVersion = version;
    refreshedSendHref = props.sendHref;
    requestFetch.mockReset();
    requestFetch.mockImplementation(async () => outcome());
    vi.stubGlobal('fetch', requestFetch);
    inertia.reload.mockReset();
    inertia.reload.mockImplementation(
        (options: {
            onSuccess?: (page: ReturnType<typeof pageProps>) => void;
            onFinish?: () => void;
        }) => {
            options.onSuccess?.(pageProps());
            options.onFinish?.();
        },
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
        refreshedVersion = 'b'.repeat(64);
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
    it('releases an initial version conflict only after authoritative refresh and requires a fresh confirmed request', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({ message: 'Synthetic stale invoice' }),
                { status: 409 },
            ),
        );
        refreshedVersion = 'b'.repeat(64);
        refreshedSendHref = '/synthetic/refreshed-invoice/send';
        render(<SendInvoiceDialog {...props} />);
        fireEvent.change(screen.getByLabelText('Subject'), {
            target: { value: 'Keep my composed subject' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByText(
            /Review its current details, then confirm Send again/,
        );
        expect(requests()).toHaveLength(1);
        expect(screen.getByLabelText('Subject')).toBeEnabled();
        expect(screen.getByLabelText('Subject')).toHaveValue(
            'Keep my composed subject',
        );
        expect(changed).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
        expect(requests()[1].url).toBe(refreshedSendHref);
        expect(requests()[1].key).not.toBe(requests()[0].key);
        expect(requests()[1].body).toMatchObject({
            expected_version: refreshedVersion,
            confirm: true,
            subject: 'Keep my composed subject',
        });
    });

    it.each([409, 422])(
        'keeps a prior 408 latched through a later %s including field errors',
        async (status) => {
            requestFetch.mockResolvedValueOnce(
                new Response(JSON.stringify({ message: 'Synthetic timeout' }), {
                    status: 408,
                }),
            );
            const view = render(<SendInvoiceDialog {...props} />);
            fireEvent.click(screen.getByRole('button', { name: 'Send' }));
            await screen.findByRole('button', { name: 'Check delivery' });
            expect(
                screen.getByRole('button', { name: 'Cancel' }),
            ).toBeDisabled();
            requestFetch.mockResolvedValueOnce(
                new Response(
                    JSON.stringify({
                        message: 'Synthetic later refusal',
                        errors: { subject: ['Synthetic later field refusal'] },
                    }),
                    { status },
                ),
            );
            view.rerender(
                <SendInvoiceDialog
                    {...props}
                    sendHref={null}
                    expectedVersion={'c'.repeat(64)}
                    email={null}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Check delivery' }),
            );
            await screen.findByText(/Use Check delivery to recover/);
            expect(requests()[1]).toEqual(requests()[0]);
            expect(screen.getByLabelText('Subject')).toBeDisabled();
            expect(
                screen.getByRole('button', { name: 'Cancel' }),
            ).toBeDisabled();
            expect(inertia.reload).not.toHaveBeenCalled();
        },
    );

    it.each(['sent', 'failed'])(
        'keeps a known %s result through failed refresh without resending the API write',
        async (status) => {
            requestFetch.mockResolvedValueOnce(outcome(status, 'b'.repeat(64)));
            inertia.reload.mockImplementation(
                (options: { onFinish?: () => void }) => options.onFinish?.(),
            );
            render(<SendInvoiceDialog {...props} />);
            fireEvent.click(screen.getByRole('button', { name: 'Send' }));
            await screen.findByRole('button', { name: 'Refresh invoice' });
            expect(screen.getByLabelText('Subject')).toBeDisabled();
            expect(changed).not.toHaveBeenCalled();
            expect(requests()).toHaveLength(1);
            refreshedVersion = 'd'.repeat(64);
            inertia.reload.mockImplementation(
                (options: {
                    onSuccess?: (page: ReturnType<typeof pageProps>) => void;
                    onFinish?: () => void;
                }) => {
                    options.onSuccess?.(pageProps());
                    options.onFinish?.();
                },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh invoice' }),
            );

            if (status === 'sent') {
                await waitFor(() =>
                    expect(changed).toHaveBeenCalledWith(false),
                );
            } else {
                await screen.findByText(
                    /The message was not handed off. You can try again/,
                );
                expect(screen.getByLabelText('Subject')).toBeEnabled();
                fireEvent.click(screen.getByRole('button', { name: 'Send' }));
                await waitFor(() => expect(requests()).toHaveLength(2));
                expect(requests()[1].body.expected_version).toBe(
                    refreshedVersion,
                );
                expect(requests()[1].key).not.toBe(requests()[0].key);
            }

            expect(inertia.reload).toHaveBeenCalledTimes(
                status === 'sent' ? 2 : 3,
            );
            expect(requests()).toHaveLength(status === 'sent' ? 1 : 2);
        },
    );

    it('retains a refused conflict as read-only recovery when refresh fails and never repeats that refused write', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(JSON.stringify({ message: 'Synthetic conflict' }), {
                status: 409,
            }),
        );
        inertia.reload.mockImplementation(
            (options: { onFinish?: () => void }) => options.onFinish?.(),
        );
        render(<SendInvoiceDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByRole('button', { name: 'Refresh invoice' });
        expect(screen.getByLabelText('Subject')).toBeDisabled();
        expect(requests()).toHaveLength(1);
        refreshedVersion = 'e'.repeat(64);
        inertia.reload.mockImplementation(
            (options: {
                onSuccess?: (page: ReturnType<typeof pageProps>) => void;
                onFinish?: () => void;
            }) => {
                options.onSuccess?.(pageProps());
                options.onFinish?.();
            },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh invoice' }),
        );
        await screen.findByText(
            /Review its current details, then confirm Send again/,
        );
        expect(requests()).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await waitFor(() => expect(requests()).toHaveLength(2));
        expect(requests()[1].body.expected_version).toBe(refreshedVersion);
        expect(requests()[1].key).not.toBe(requests()[0].key);
    });

    it('uses the compose-open version rather than silently adopting background invoice changes', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(
                JSON.stringify({ message: 'Synthetic definite refusal' }),
                { status: 422 },
            ),
        );
        const view = render(<SendInvoiceDialog {...props} />);
        fireEvent.change(screen.getByLabelText('Subject'), {
            target: { value: 'Composed before background refresh' },
        });
        view.rerender(
            <SendInvoiceDialog
                {...props}
                expectedVersion={'f'.repeat(64)}
                sendHref="/changed-background-capability"
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByText('Synthetic definite refusal');
        expect(requests()[0].url).toBe(props.sendHref);
        expect(requests()[0].body.expected_version).toBe(version);
    });

    it('does not adopt another invoice from a required refresh', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(JSON.stringify({ message: 'Synthetic conflict' }), {
                status: 409,
            }),
        );
        inertia.reload.mockImplementation(
            (options: {
                onSuccess?: (page: ReturnType<typeof pageProps>) => void;
                onFinish?: () => void;
            }) => {
                const page = pageProps();
                page.props.invoice.id = 'synthetic-foreign-invoice';
                options.onSuccess?.(page);
                options.onFinish?.();
            },
        );
        render(<SendInvoiceDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByRole('button', { name: 'Refresh invoice' });
        expect(screen.getByLabelText('Subject')).toBeDisabled();
        expect(requests()).toHaveLength(1);
    });
    it.each([null, 'synthetic-other-invoice'])(
        'keeps a terminal response with invoice identity %s unresolved',
        async (responseId) => {
            requestFetch.mockResolvedValueOnce(
                new Response(
                    JSON.stringify({
                        data: {
                            id: responseId,
                            version,
                            delivery: { status: 'sent', error_summary: null },
                        },
                    }),
                    { status: 200 },
                ),
            );
            render(<SendInvoiceDialog {...props} />);
            fireEvent.click(screen.getByRole('button', { name: 'Send' }));
            await screen.findByText(/could not be matched to the invoice/);
            expect(screen.getByLabelText('Subject')).toBeDisabled();
            expect(changed).not.toHaveBeenCalled();
            expect(inertia.reload).not.toHaveBeenCalled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Check delivery' }),
            );
            await waitFor(() => expect(changed).toHaveBeenCalledWith(false));
            expect(requests()[1]).toEqual(requests()[0]);
        },
    );

    it('blocks native navigation while the send outcome is unresolved', async () => {
        requestFetch.mockResolvedValueOnce(
            new Response(JSON.stringify({ message: 'Synthetic timeout' }), {
                status: 408,
            }),
        );
        render(<SendInvoiceDialog {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));
        await screen.findByRole('button', { name: 'Check delivery' });
        const event = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: {
                visit: {
                    url: '/synthetic/other-invoice',
                    method: 'get',
                    only: [],
                },
            },
        });
        act(() => {
            document.dispatchEvent(event);
        });
        expect(event.defaultPrevented).toBe(true);
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(changed).not.toHaveBeenCalled();
    });
});
