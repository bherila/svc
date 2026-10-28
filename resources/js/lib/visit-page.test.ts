import { beforeEach, describe, expect, it, vi } from 'vitest';
import { visitPage } from './visit-page';

const transport = vi.hoisted(() => ({ visit: vi.fn(), on: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: transport }));

beforeEach(() => {
    transport.visit.mockReset();
    transport.on.mockReset();
    transport.on.mockReturnValue(vi.fn());
});

describe('confirmed page visits', () => {
    it('requires success even when Inertia reports a finished request', async () => {
        transport.visit.mockImplementationOnce((_url, options) =>
            options.onFinish(),
        );
        await expect(visitPage('/synthetic/destination')).rejects.toThrow(
            'could not load',
        );
        transport.visit.mockImplementationOnce((_url, options) => {
            options.onSuccess();
            options.onFinish();
        });
        await expect(
            visitPage('/synthetic/destination'),
        ).resolves.toBeUndefined();
    });

    it.each(['onCancel', 'onError', 'onNetworkError', 'onHttpException'])(
        'settles %s without a later finish and suppresses default transport errors',
        async (callback) => {
            transport.visit.mockImplementationOnce((_url, options) => {
                const result = options[callback]();

                if (
                    callback === 'onNetworkError' ||
                    callback === 'onHttpException'
                ) {
                    expect(result).toBe(false);
                }
            });
            await expect(visitPage('/synthetic/destination')).rejects.toThrow(
                'could not load',
            );
            expect(transport.on.mock.results[0].value).toHaveBeenCalled();
        },
    );

    it('observes a global refusal after all before listeners run, with no later callback', async () => {
        transport.visit.mockImplementationOnce(() => {
            const event = new CustomEvent('inertia:before', {
                cancelable: true,
                detail: { visit: { url: '/synthetic/destination' } },
            });
            transport.on.mock.calls[0][1](event);
            // A later registered listener can be the one refusing this visit.
            event.preventDefault();
        });
        await expect(visitPage('/synthetic/destination')).rejects.toThrow(
            'could not load',
        );
        expect(transport.on.mock.results[0].value).toHaveBeenCalled();
    });

    it('ignores another destination and removes the observer after a successful matching visit', async () => {
        transport.visit.mockImplementationOnce((_url, options) => {
            const unrelated = new CustomEvent('inertia:before', {
                cancelable: true,
                detail: { visit: { url: '/synthetic/other' } },
            });
            unrelated.preventDefault();
            transport.on.mock.calls[0][1](unrelated);
            const current = new CustomEvent('inertia:before', {
                cancelable: true,
                detail: { visit: { url: '/synthetic/destination' } },
            });
            transport.on.mock.calls[0][1](current);
            options.onSuccess();
        });
        await expect(
            visitPage('/synthetic/destination'),
        ).resolves.toBeUndefined();
        expect(transport.on.mock.results[0].value).toHaveBeenCalled();
    });

    it('releases the observer if starting a visit throws', async () => {
        transport.visit.mockImplementationOnce(() => {
            throw new Error('Synthetic start failure');
        });
        await expect(visitPage('/synthetic/destination')).rejects.toThrow(
            'could not load',
        );
        expect(transport.on.mock.results[0].value).toHaveBeenCalled();
    });
});
