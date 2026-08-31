import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { expect, test, vi } from 'vitest';
import Portal from '@/pages/portal';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    useForm: () => ({
        data: {},
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({
        props: { auth: { user: { name: 'Synthetic Client' } } },
    }),
}));

/**
 * The portal is fixed-dark by its own utilities, not by the `dark` root class,
 * and it must not opt into the legacy slate bridge.
 *
 * Those utilities mean the opposite thing here. `text-slate-950` on the
 * "Accept proposal" button is a dark label on a light `bg-cyan-300`, so the
 * bridge's `color: var(--foreground)` would paint it near-white on a light
 * background. A portal visitor has no appearance selector and never chose
 * dark - they would inherit `.dark` from `prefers-color-scheme` alone - so
 * this is not a preference they could undo.
 *
 * Asserted on the marker rather than on colour because jsdom applies no
 * stylesheet: the attribute is what gates the rules, so the attribute is the
 * thing worth pinning.
 */
test('the customer portal does not opt into the operator appearance bridge', () => {
    render(
        <Portal
            company={{
                id: 'company-1',
                name: 'Synthetic Client',
                proposals: [],
                agreements: [],
                invoices: [],
                projects: [],
            }}
        />,
    );

    expect(
        screen.getByRole('main').closest('[data-appearance-bridge]'),
    ).toBeNull();
    expect(document.querySelectorAll('[data-appearance-bridge]')).toHaveLength(
        0,
    );
});
