import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { ClientContext } from '@/types/navigation';
import ClientContextLayout from './client-context-layout';

const visit = vi.fn();

let context: ClientContext | null = null;

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
    router: {
        visit: (...args: unknown[]) => visit(...args),
    },
    usePage: () => ({ props: { clientContext: context } }),
}));

function withContext(overrides: Partial<ClientContext> = {}): ClientContext {
    return {
        workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
        companies: [
            { id: 'company-1', name: 'Aa Synthetic Client' },
            { id: 'company-2', name: 'Bb Synthetic Client' },
        ],
        current_company_id: 'company-1',
        ...overrides,
    };
}

describe('client context layout', () => {
    it('marks the active tab and links every tab at the current company', () => {
        context = withContext();

        render(
            <ClientContextLayout
                active="overview"
                available={['overview', 'invoices']}
            >
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        const overview = screen.getByRole('link', { name: 'Overview' });
        expect(overview).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1',
        );
        expect(overview).toHaveAttribute('aria-current', 'page');

        const invoices = screen.getByRole('link', { name: 'Invoices' });
        expect(invoices).toHaveAttribute(
            'href',
            '/workspaces/workspace-1/clients/company-1/invoices',
        );
        expect(invoices).not.toHaveAttribute('aria-current');
    });

    /**
     * A tab strip that links to a route nobody has built reads as a broken
     * product, so a tab appears only once its page does. This is the assertion
     * that keeps `available` honest as the remaining tabs land.
     */
    it('shows only the tabs that exist yet', () => {
        context = withContext();

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        // Asserted against the real shared list rather than an override, so
        // this fails when a tab joins the strip without a route behind it -
        // which is the whole reason the list is single-sourced.
        expect(
            screen.getByRole('link', { name: 'Overview' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Time' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Tasks' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Invoices' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Manage' })).toBeNull();
    });

    /**
     * Only that the switcher is there and reachable by name. Base UI portals
     * the options and mounts them on open, so asserting the list here would be
     * asserting the library. *Which* companies it may contain is the part that
     * matters and it is proved server-side, where the payload is built:
     * `ClientContextTest::test_no_other_workspaces_client_reaches_the_switcher`.
     */
    it('offers a switcher reachable by an accessible name', () => {
        context = withContext();

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        expect(
            screen.getByRole('combobox', { name: 'Current client' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'All clients' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/clients');
    });

    /**
     * Off a workspace route, or on a workspace screen that is not inside one
     * company, there is nothing to switch between - so the page renders bare
     * rather than behind an empty switcher pointing nowhere.
     */
    it('renders the page alone when no company is selected', () => {
        context = withContext({ current_company_id: null });

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        expect(screen.getByText('Overview body')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Overview' })).toBeNull();
        expect(
            screen.queryByRole('combobox', { name: 'Current client' }),
        ).toBeNull();
    });

    it('renders the page alone outside any workspace', () => {
        context = null;

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        expect(screen.getByText('Overview body')).toBeInTheDocument();
        expect(
            screen.queryByRole('combobox', { name: 'Current client' }),
        ).toBeNull();
    });
});
