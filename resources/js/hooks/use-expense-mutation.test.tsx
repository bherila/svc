import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { useExpenseMutation } from './use-expense-mutation';

vi.mock('@/lib/api', () => ({
    apiRequest: vi.fn(),
    newIdempotencyKey: () => crypto.randomUUID(),
}));
vi.mock('@/lib/refresh-page-props', () => ({ refreshPageProps: vi.fn() }));

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(apiRequest).mockReset();
    vi.mocked(refreshPageProps).mockReset();
});

function tryNavigation() {
    const event = new CustomEvent('inertia:before', {
        cancelable: true,
        detail: {
            visit: { url: '/synthetic/other-client', method: 'get', only: [] },
        },
    });
    act(() => {
        document.dispatchEvent(event);
    });
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);

    return { event, unload };
}

describe('expense receipt navigation policy', () => {
    it('prevents losing an unknown financial result', async () => {
        vi.mocked(apiRequest).mockResolvedValue({
            ok: false,
            status: 408,
            message: 'Synthetic unknown result',
        });
        const { result } = renderHook(() => useExpenseMutation(['expenses']));
        await act(() =>
            result.current.submit('POST', '/synthetic/expense', {
                amount: 1250,
            }),
        );
        const { event, unload } = tryNavigation();
        expect(event.defaultPrevented).toBe(true);
        expect(unload.defaultPrevented).toBe(true);
    });

    it('allows leaving a confirmed save while retaining its read-only recovery when mounted', async () => {
        vi.mocked(apiRequest).mockResolvedValue({
            ok: true,
            status: 201,
            data: {},
        });
        vi.mocked(refreshPageProps).mockRejectedValueOnce(
            new Error('Synthetic refresh failure'),
        );
        const { result } = renderHook(() => useExpenseMutation(['expenses']));
        await act(() =>
            result.current.submit('POST', '/synthetic/expense', {
                amount: 1250,
            }),
        );
        expect(result.current.saved).toBe(true);
        expect(result.current.locked).toBe(true);
        const { event, unload } = tryNavigation();
        expect(event.defaultPrevented).toBe(false);
        expect(unload.defaultPrevented).toBe(false);
        vi.mocked(refreshPageProps).mockResolvedValueOnce();
        await act(() =>
            result.current.submit('POST', '/synthetic/changed-url', {
                amount: 9999,
            }),
        );
        expect(apiRequest).toHaveBeenCalledOnce();
        expect(refreshPageProps).toHaveBeenCalledTimes(2);
        expect(result.current.locked).toBe(false);
    });
});
