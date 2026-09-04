import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Welcome from '@/pages/welcome';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: null } } }),
}));

describe('welcome page', () => {
    it('carries the shared large gear as decorative background artwork', () => {
        const { container } = render(<Welcome />);
        const mask = container.querySelector('#svc-gear-hole');
        const gear = container.querySelector(
            'svg[aria-hidden="true"] g[mask="url(#svc-gear-hole)"]',
        );

        expect(mask).toBeInTheDocument();
        expect(gear).toBeInTheDocument();
        expect(gear?.querySelectorAll(':scope > rect')).toHaveLength(12);
    });
});
