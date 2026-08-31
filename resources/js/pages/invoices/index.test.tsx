import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { expect, test, vi } from 'vitest';
import WorkspaceInvoices from '@/pages/invoices/index';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

test('offers the appearance selector above the workspace invoice list', () => {
    render(
        <WorkspaceInvoices
            workspace={{ id: 'workspace-1', name: 'Synthetic Workspace' }}
            invoices={[]}
        />,
    );

    expect(
        screen.getByRole('combobox', { name: 'Appearance' }),
    ).toBeInTheDocument();
});
