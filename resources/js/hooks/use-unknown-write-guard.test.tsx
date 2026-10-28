import { renderHook } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { useUnknownWriteGuard } from '@/hooks/use-unknown-write-guard';

function visit(
    url = '/synthetic/elsewhere',
    only: string[] = [],
    preserveState = true,
) {
    const event = new CustomEvent('inertia:before', {
        cancelable: true,
        detail: { visit: { url, method: 'get', only, preserveState } },
    });
    document.dispatchEvent(event);

    return event.defaultPrevented;
}

it('blocks unknown-write navigation and unload while permitting current partial reads', () => {
    const blocked = vi.fn();
    const { unmount } = renderHook(() =>
        useUnknownWriteGuard(() => true, blocked),
    );
    expect(visit()).toBe(true);
    expect(blocked).toHaveBeenCalledTimes(1);
    expect(visit(window.location.href, ['tasks'])).toBe(false);
    expect(visit(window.location.href, ['tasks'], false)).toBe(true);
    expect(visit('/synthetic/elsewhere', ['tasks'])).toBe(true);
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    expect(unload.defaultPrevented).toBe(true);
    unmount();
    expect(visit()).toBe(false);
});

it('allows navigation after success is known even when the refreshed read is pending', () => {
    let accepted = false;
    const { unmount } = renderHook(() =>
        useUnknownWriteGuard(() => !accepted, vi.fn()),
    );
    expect(visit()).toBe(true);
    accepted = true;
    expect(visit()).toBe(false);
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    expect(unload.defaultPrevented).toBe(false);
    const inertiaHistory = vi.fn();
    window.addEventListener('popstate', inertiaHistory);
    window.history.replaceState(
        { synthetic: 'accepted destination' },
        '',
        '/synthetic/accepted',
    );
    window.dispatchEvent(
        new PopStateEvent('popstate', { state: window.history.state }),
    );
    expect(inertiaHistory).toHaveBeenCalledTimes(1);
    expect(window.location.pathname).toBe('/synthetic/accepted');
    window.removeEventListener('popstate', inertiaHistory);
    unmount();
});

it.each(['back', 'forward'])(
    'keeps an unknown write mounted before Inertia handles history %s',
    (direction) => {
        const original = {
            page: { url: '/synthetic/original', props: { synthetic: true } },
        };
        window.history.replaceState(original, '', '/synthetic/original');
        const blocked = vi.fn();
        const { unmount } = renderHook(() =>
            useUnknownWriteGuard(() => true, blocked),
        );
        const inertiaHistory = vi.fn();
        window.addEventListener('popstate', inertiaHistory);
        // Both directions have already changed the URL before popstate fires.
        window.history.replaceState(
            { page: { url: `/synthetic/${direction}` } },
            '',
            `/synthetic/${direction}`,
        );
        window.dispatchEvent(
            new PopStateEvent('popstate', { state: window.history.state }),
        );
        expect(window.location.pathname).toBe('/synthetic/original');
        expect(window.history.state).toEqual(original);
        expect(inertiaHistory).not.toHaveBeenCalled();
        expect(blocked).toHaveBeenCalledTimes(1);
        unmount();
        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(inertiaHistory).toHaveBeenCalledTimes(1);
        window.removeEventListener('popstate', inertiaHistory);
    },
);

it('restores the current successful filter navigation when a later write becomes unknown', () => {
    window.history.replaceState({ page: 'initial' }, '', '/synthetic/list');
    let unknown = false;
    const isUnknown = () => unknown;
    const blocked = vi.fn();
    const { unmount } = renderHook(() =>
        useUnknownWriteGuard(isUnknown, blocked),
    );
    const filtered = { page: 'filtered', props: { synthetic: true } };
    window.history.replaceState(filtered, '', '/synthetic/list?filter=current');
    document.dispatchEvent(new CustomEvent('inertia:navigate'));
    unknown = true;
    window.history.replaceState(
        { page: 'previous' },
        '',
        '/synthetic/previous',
    );
    window.dispatchEvent(
        new PopStateEvent('popstate', { state: window.history.state }),
    );
    expect(window.location.pathname + window.location.search).toBe(
        '/synthetic/list?filter=current',
    );
    expect(window.history.state).toEqual(filtered);
    expect(blocked).toHaveBeenCalledTimes(1);
    unmount();
});

it('keeps refreshed props for an allowed current-page read while preserving an unknown write', () => {
    window.history.replaceState(
        { page: 'before read' },
        '',
        '/synthetic/current',
    );
    const blocked = vi.fn();
    const { unmount } = renderHook(() =>
        useUnknownWriteGuard(() => true, blocked),
    );
    const refreshed = {
        page: 'after read',
        props: { syntheticVersion: 'new' },
    };
    window.history.replaceState(refreshed, '', '/synthetic/current');
    document.dispatchEvent(new CustomEvent('inertia:navigate'));
    window.history.replaceState(
        { page: 'previous' },
        '',
        '/synthetic/previous',
    );
    window.dispatchEvent(
        new PopStateEvent('popstate', { state: window.history.state }),
    );
    expect(window.location.pathname).toBe('/synthetic/current');
    expect(window.history.state).toEqual(refreshed);
    expect(blocked).toHaveBeenCalledTimes(1);
    unmount();
});
