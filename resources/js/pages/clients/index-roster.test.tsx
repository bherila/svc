import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientIndex from '@/pages/clients/index';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { roster, rosterHealth } from '@/test/revenue-fixtures';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
            }),
        }),
    }),
}));

const WORKSPACE = { id: 'workspace-1', name: 'Synthetic Workspace' };

describe('client index for owners and admins', () => {
    it('draws the revenue roster, not the plain list, when the server sent one', () => {
        const { container } = render(
            <ClientIndex
                workspace={WORKSPACE}
                companies={[]}
                roster={roster()}
                health={{ 'company-1': rosterHealth() }}
            />,
        );

        expect(container.querySelector('[data-slot="roster"]')).not.toBeNull();
        expect(
            screen.getByRole('columnheader', { name: /^Agreement health/ }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Draft invoices')).not.toBeInTheDocument();
        expect(screen.getAllByRole('main')).toHaveLength(1);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('keeps the plain list for a member, whose payload has no roster', () => {
        const { container } = render(
            <ClientIndex workspace={WORKSPACE} companies={[]} />,
        );

        expect(container.querySelector('[data-slot="roster"]')).toBeNull();
        expect(
            screen.getByText('No client companies in this workspace yet.'),
        ).toBeInTheDocument();
    });
});
