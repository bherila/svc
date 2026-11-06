import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ProjectTimeCard } from '@/pages/operations';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { post: vi.fn(), reload: vi.fn() },
    useForm: () => ({}),
}));

const LONG = 'ProjectNameWithNoSpaceToBreakAt'.repeat(8);

const project = {
    id: 'project-1',
    name: LONG,
    time_url:
        '/workspaces/workspace-1/clients/company-1/time?log_project=project-1',
    time_entries: [
        {
            id: 'entry-1',
            worked_on: '2026-10-01',
            minutes: 90,
            description: `Entry ${'unbroken'.repeat(20)}`,
            status: 'approved',
            is_billable: true,
            is_deferred: true,
        },
    ],
};

describe('the per-project time card on the classic Operations screen', () => {
    it('lets a long project name wrap beside its Log time link instead of running under it', () => {
        const { container } = render(<ProjectTimeCard project={project} />);
        const name = screen.getByRole('heading', { name: LONG });
        const link = screen.getByRole('link', { name: 'Log time' });

        expect(link).toHaveAttribute('href', project.time_url);
        // The name takes the room the link leaves and breaks anywhere; the
        // link keeps its width; both sit in a row that wraps.
        expect(name).toHaveClass('min-w-0', 'flex-1', 'wrap-anywhere');
        expect(link).toHaveClass('shrink-0');
        expect(name.parentElement).toHaveClass('flex', 'flex-wrap');
        expect(link.parentElement).toBe(name.parentElement);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('still lists the entries, deferred ones marked', () => {
        render(<ProjectTimeCard project={project} />);

        expect(
            screen.getByText(/2026-10-01 · 90 min · Entry/),
        ).toHaveTextContent('· deferred');
    });
});
