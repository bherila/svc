import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { useInvoiceMutation } from '@/hooks/use-invoice-mutation';
import { apiRequest } from '@/lib/api';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), on: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: inertia }));
vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => crypto.randomUUID(),
}));

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(apiRequest).mockReset();
    inertia.reload.mockReset();
});

it.each([0, 408, 503])(
    'freezes the original request after %s and keeps later refusals latched',
    async (status) => {
        const done = vi.fn();
        const changedDone = vi.fn();
        const { result } = renderHook(() => useInvoiceMutation());
        const body = {
            expected_version: 'original-version',
            lines: [{ description: 'Original work' }],
        };
        vi.mocked(apiRequest).mockResolvedValueOnce({
            ok: false,
            status,
            message: 'Synthetic uncertain result',
        });
        await act(() =>
            result.current.submit('POST', '/original-invoice', body, done),
        );
        expect(result.current.locked).toBe(true);
        body.lines[0].description = 'Edited after uncertainty';
        const original = vi.mocked(apiRequest).mock.calls[0];
        expect(original[2]).toEqual({
            expected_version: 'original-version',
            lines: [{ description: 'Original work' }],
        });

        for (const refusal of [401, 403, 409, 419, 422]) {
            vi.mocked(apiRequest).mockResolvedValueOnce({
                ok: false,
                status: refusal,
                message: 'Synthetic later refusal',
            });
            await act(() =>
                result.current.submit(
                    'PATCH',
                    '/different-invoice',
                    { expected_version: 'new-version' },
                    changedDone,
                ),
            );
            expect(vi.mocked(apiRequest).mock.lastCall).toEqual(original);
            expect(result.current.locked).toBe(true);
            expect(result.current.saved).toBe(false);
            expect(result.current.busy).toBe(false);
            expect(inertia.reload).not.toHaveBeenCalled();
        }

        expect(done).not.toHaveBeenCalled();
        expect(changedDone).not.toHaveBeenCalled();
    },
);

it('retains a known saved result until refresh succeeds and retries only its read', async () => {
    const done = vi.fn();
    const { result } = renderHook(() => useInvoiceMutation());
    vi.mocked(apiRequest).mockResolvedValue({
        ok: true,
        status: 201,
        data: { id: 'synthetic-invoice' },
    });
    let pending: Promise<void>;
    act(() => {
        pending = result.current.submit(
            'POST',
            '/invoices',
            { invoice_number: 'Synthetic original' },
            done,
        );
    });
    await waitFor(() => expect(inertia.reload).toHaveBeenCalledTimes(1));
    expect(result.current.locked).toBe(true);
    expect(result.current.saved).toBe(true);
    await act(async () => {
        inertia.reload.mock.calls[0][0].onFinish();
        await pending;
    });
    expect(result.current.error).toContain('The change was saved');
    expect(result.current.locked).toBe(true);
    expect(done).not.toHaveBeenCalled();
    const firstKey = vi.mocked(apiRequest).mock.calls[0][3];

    act(() => {
        pending = result.current.submit('POST', '/new-url', {
            invoice_number: 'Edited',
        });
    });
    await waitFor(() => expect(inertia.reload).toHaveBeenCalledTimes(2));
    expect(apiRequest).toHaveBeenCalledTimes(1);
    await act(async () => {
        inertia.reload.mock.calls[1][0].onSuccess();
        inertia.reload.mock.calls[1][0].onFinish();
        await pending;
    });
    expect(done).toHaveBeenCalledTimes(1);
    expect(result.current.locked).toBe(false);
    expect(result.current.saved).toBe(false);
    inertia.reload.mockImplementation((options) => {
        options.onSuccess();
        options.onFinish();
    });
    await act(() =>
        result.current.submit('POST', '/new-url', {
            invoice_number: 'Deliberate next invoice',
        }),
    );
    expect(apiRequest).toHaveBeenCalledTimes(2);
    expect(vi.mocked(apiRequest).mock.calls[1][3]).not.toBe(firstKey);
});

it('allows a fresh corrected request after a definite first refusal', async () => {
    const { result } = renderHook(() => useInvoiceMutation());
    vi.mocked(apiRequest).mockResolvedValue({
        ok: false,
        status: 422,
        message: 'Synthetic definite refusal',
    });
    await act(() =>
        result.current.submit('POST', '/invoices', { invoice_number: '' }),
    );
    expect(result.current.locked).toBe(false);
    await act(() =>
        result.current.submit('POST', '/invoices', {
            invoice_number: 'Synthetic corrected',
        }),
    );
    expect(vi.mocked(apiRequest).mock.calls[1][2]).toEqual({
        invoice_number: 'Synthetic corrected',
    });
    expect(vi.mocked(apiRequest).mock.calls[1][3]).not.toBe(
        vi.mocked(apiRequest).mock.calls[0][3],
    );
});

it('blocks competing navigation and warns on unload until original outcome resolves', async () => {
    const stop = vi.fn();
    inertia.on.mockReturnValue(stop);
    const { result, unmount } = renderHook(() => useInvoiceMutation());
    vi.mocked(apiRequest).mockResolvedValue({
        ok: false,
        status: 408,
        message: 'Synthetic timeout',
    });
    await act(() => result.current.submit('POST', '/invoices', {}));
    const preventDefault = vi.fn();
    act(() =>
        inertia.on.mock.calls[0][1]({
            detail: {
                visit: { url: new URL('/another-page', window.location.href) },
            },
            preventDefault,
        }),
    );
    expect(preventDefault).toHaveBeenCalledOnce();
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    expect(unload.defaultPrevented).toBe(true);
    expect(result.current.error).toContain('before leaving');
    unmount();
    expect(stop).toHaveBeenCalledOnce();
});
