import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useDurationStyle } from '@/hooks/use-duration-style';
import { sharedPageProps } from '@/test/shared-page-props';

const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

describe('useDurationStyle', () => {
    it('defaults to decimal', () => {
        page.props = { ...sharedPageProps() };

        expect(renderHook(() => useDurationStyle()).result.current).toBe(
            'decimal',
        );
    });

    it('reads the signed-in person preference', () => {
        const shared = sharedPageProps();
        page.props = {
            ...shared,
            auth: { user: { ...shared.auth.user!, duration_style: 'clock' } },
        };

        expect(renderHook(() => useDurationStyle()).result.current).toBe(
            'clock',
        );
    });
});
