import { beforeEach, describe, expect, it, vi } from 'vitest';
import { refreshPageProps } from '@/lib/refresh-page-props';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));

beforeEach(() => reload.mockReset());

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
});
