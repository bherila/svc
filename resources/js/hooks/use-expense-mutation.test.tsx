import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '@/lib/api';
import { refreshPageProps } from '@/lib/refresh-page-props';
import { useExpenseMutation } from './use-expense-mutation';

const navigation = vi.hoisted(() => ({ on: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: navigation }));
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
    const guard = navigation.on.mock.calls.at(-1)?.[1];
    const event = {
        detail: {
            visit: { url: '/synthetic/other-client', method: 'get', only: [] },
        },
        preventDefault: vi.fn(),
    };
    act(() => guard(event));
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
        expect(event.preventDefault).toHaveBeenCalledOnce();
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
        expect(event.preventDefault).not.toHaveBeenCalled();
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
