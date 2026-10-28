import { beforeEach, describe, expect, it, vi } from 'vitest';
import { refreshPageProps } from '@/lib/refresh-page-props';

const { reload, on } = vi.hoisted(() => ({ reload: vi.fn(), on: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { reload, on } }));

beforeEach(() => {
    reload.mockReset();
    on.mockReset();
});

describe('authoritative page refresh', () => {
    it('waits for fresh props before allowing a mutation workflow to finish', async () => {
        const finished = vi.fn();
        const refresh = refreshPageProps({
            only: ['portal'],
            onFinish: finished,
        });
        const settled = vi.fn();
        void refresh.then(settled);
        await Promise.resolve();
        expect(settled).not.toHaveBeenCalled();
        expect(finished).not.toHaveBeenCalled();
        const options = reload.mock.calls[0][0];
        expect(options.only).toEqual(['portal']);
        options.onFinish();
        await refresh;
        expect(finished).toHaveBeenCalledOnce();
        expect(settled).toHaveBeenCalledOnce();
    });

    it('keeps complete refreshes for financial workflows without an explicit scope', async () => {
        const refresh = refreshPageProps();
        const options = reload.mock.calls[0][0];
        expect(options).not.toHaveProperty('only');
        options.onFinish();
        await expect(refresh).resolves.toBeUndefined();
    });

    it('settles on completion without swallowing a caller callback error', async () => {
        const failure = new Error('Synthetic caller completion error');
        const refresh = refreshPageProps({
            onFinish: () => {
                throw failure;
            },
        });
        expect(() => reload.mock.calls[0][0].onFinish()).toThrow(failure);
        await expect(refresh).resolves.toBeUndefined();
    });
    it('allows an explicit consumer to require confirmed success rather than mere completion', async () => {
        const refresh = refreshPageProps({
            only: ['planning_details'],
            requireSuccess: true,
        });
        const options = reload.mock.calls[0][0];
        expect(options).not.toHaveProperty('requireSuccess');
        options.onSuccess();
        options.onFinish();
        await expect(refresh).resolves.toBeUndefined();
    });

    it('rejects a required-success refresh that finishes without new authorized props', async () => {
        const refresh = refreshPageProps({
            only: ['planning_details'],
            requireSuccess: true,
        });
        const refusal = expect(refresh).rejects.toThrow(
            'Current page props could not be refreshed.',
        );
        reload.mock.calls[0][0].onFinish();
        await refusal;
    });
});

it('keeps required refresh failures in the caller recovery flow without Inertia default throws or error overlays', async () => {
    const refresh = refreshPageProps({
        only: ['expenses'],
        requireSuccess: true,
    });
    const refusal = expect(refresh).rejects.toThrow(
        'Current page props could not be refreshed.',
    );
    const options = reload.mock.calls[0][0];
    expect(options.onNetworkError(new Error('Synthetic network outage'))).toBe(
        false,
    );
    expect(options.onHttpException({ status: 503 })).toBe(false);
    options.onFinish();
    await refusal;

    const ordinary = refreshPageProps();
    const ordinaryOptions = reload.mock.calls[1][0];
    expect(ordinaryOptions).not.toHaveProperty('onNetworkError');
    expect(ordinaryOptions).not.toHaveProperty('onHttpException');
    ordinaryOptions.onFinish();
    await ordinary;
});

it('rejects a required read refused by a global before listener without waiting for callbacks Inertia never sends', async () => {
    const stop = vi.fn();
    let before: (event: CustomEvent) => void = () => {};
    on.mockImplementation((_name, callback) => {
        before = callback;

        return stop;
    });
    reload.mockImplementationOnce(() => {
        const event = new CustomEvent('inertia:before', {
            cancelable: true,
            detail: { visit: { method: 'get', url: window.location.href } },
        });
        before(event);
        // A later listener may refuse it after our observer already ran.
        event.preventDefault();
    });
    const finished = vi.fn();
    await expect(
        refreshPageProps({ requireSuccess: true, onFinish: finished }),
    ).rejects.toThrow('Current page props could not be refreshed.');
    expect(finished).toHaveBeenCalledOnce();
    expect(stop).toHaveBeenCalled();
});

it.each([false, true])(
    'delivers authoritative refreshed props to the consumer before finishing (strict=%s)',
    async (requireSuccess) => {
        const saved = vi.fn();
        const finished = vi.fn();
        const refresh = refreshPageProps({
            requireSuccess,
            onSuccess: saved,
            onFinish: finished,
        });
        const page = {
            props: {
                task: {
                    title: 'Synthetic current title',
                    version: 'v'.repeat(64),
                },
            },
        };
        expect(saved).not.toHaveBeenCalled();
        reload.mock.calls[0][0].onSuccess(page);
        expect(saved).toHaveBeenCalledWith(page);
        expect(finished).not.toHaveBeenCalled();
        reload.mock.calls[0][0].onFinish();
        await refresh;
        expect(finished).toHaveBeenCalledOnce();
    },
);
