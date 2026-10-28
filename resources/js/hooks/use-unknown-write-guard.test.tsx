import { renderHook } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { useUnknownWriteGuard } from '@/hooks/use-unknown-write-guard';

function visit(url = '/synthetic/elsewhere', only: string[] = []) {
    const event = new CustomEvent('inertia:before', {
        cancelable: true,
        detail: { visit: { url, method: 'get', only } },
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
    unmount();
});
