import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { usePlanningMutation } from '@/components/tasks/planning-controls';

const { request, key } = vi.hoisted(() => ({ request: vi.fn(), key: vi.fn() }));
vi.mock('@/lib/api', () => ({ apiRequest: request, newIdempotencyKey: key }));
beforeEach(() => {
    let sequence = 0;
    key.mockReset().mockImplementation(() => `synthetic-attempt-${++sequence}`);
    request.mockReset().mockResolvedValue({ ok: true, data: {} });
});

it('retains the original request across timeout, later refusal and changed inputs until reconciliation', async () => {
    request
        .mockResolvedValueOnce({
            ok: false,
            status: 408,
            message: 'Synthetic timeout',
        })
        .mockResolvedValueOnce({
            ok: false,
            status: 403,
            message: 'Synthetic authority changed',
        });
    const refresh = vi.fn().mockResolvedValue(undefined);
    const saved = vi.fn();
    const { result } = renderHook(() => usePlanningMutation(refresh));
    const body = { expected_version: 'synthetic-old', ids: ['original'] };
    await act(async () =>
        result.current.run('POST', '/synthetic/original', body, saved),
    );
    expect(result.current.locked).toBe(true);
    const original = request.mock.calls[0];
    body.ids.push('edited');
    await act(async () =>
        result.current.run('PATCH', '/synthetic/changed', {
            ids: ['different'],
        }),
    );
    expect(request.mock.calls[1]).toEqual(original);
    expect(result.current.locked).toBe(true);
    expect(saved).not.toHaveBeenCalled();
    await act(async () => result.current.retry());
    expect(request.mock.calls[2]).toEqual(original);
    expect(original[2].ids).toEqual(['original']);
    expect(key).toHaveBeenCalledTimes(1);
    expect(saved).toHaveBeenCalledTimes(1);
    expect(result.current.locked).toBe(false);
});

it('retries only the required read after a known successful write and failed refresh', async () => {
    const refresh = vi
        .fn()
        .mockRejectedValueOnce(new Error('Synthetic read failure'))
        .mockResolvedValue(undefined);
    const saved = vi.fn();
    const { result } = renderHook(() => usePlanningMutation(refresh));
    await act(async () =>
        result.current.run(
            'POST',
            '/synthetic/create',
            { name: 'Synthetic label' },
            saved,
        ),
    );
    expect(result.current.unresolved).toBe(true);
    expect(saved).not.toHaveBeenCalled();
    await act(async () => result.current.retry());
    expect(request).toHaveBeenCalledTimes(1);
    expect(refresh).toHaveBeenCalledTimes(2);
    expect(saved).toHaveBeenCalledTimes(1);
    expect(result.current.unresolved).toBe(false);
});

it('permits a fresh corrected attempt after an initial definitive validation refusal', async () => {
    request.mockResolvedValueOnce({
        ok: false,
        status: 422,
        message: 'Synthetic validation',
    });
    const { result } = renderHook(() =>
        usePlanningMutation(vi.fn().mockResolvedValue(undefined)),
    );
    await act(async () =>
        result.current.run('POST', '/synthetic/create', { name: '' }),
    );
    expect(result.current.locked).toBe(false);
    await act(async () =>
        result.current.run('POST', '/synthetic/create', {
            name: 'Corrected synthetic label',
        }),
    );
    expect(request.mock.calls[1][2]).toEqual({
        name: 'Corrected synthetic label',
    });
    expect(request.mock.calls[1][3]).not.toBe(request.mock.calls[0][3]);
});

it('discards a draft only after an explicit authorized refresh succeeds', async () => {
    const refresh = vi
        .fn()
        .mockRejectedValueOnce(new Error('Synthetic refresh refusal'))
        .mockResolvedValue(undefined);
    const discard = vi.fn();
    const { result } = renderHook(() => usePlanningMutation(refresh));
    await act(async () => result.current.reload(discard));
    expect(discard).not.toHaveBeenCalled();
    expect(result.current.error).toContain('Your choices are preserved');
    await act(async () => result.current.reload(discard));
    expect(discard).toHaveBeenCalledTimes(1);
    expect(request).not.toHaveBeenCalled();
});
