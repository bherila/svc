import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { sharedPageProps } from '@/test/shared-page-props';
import type { RelyingApplication } from '@/types/auth';
import type { ClientContext } from '@/types/navigation';
import ClientContextLayout from './client-context-layout';

const visit = vi.fn();

let context: ClientContext | null = null;
let applications: RelyingApplication[] = [];

beforeEach(() => {
    applications = [];
});

vi.mock('@inertiajs/react', () => ({
    // `method` and `as` are Inertia's own props rather than DOM attributes,
    // and `as` changes the element - a non-GET Link renders a button, not an
    // anchor. Honoured here rather than forwarded, so the sign-out item is the
    // element the real one would be.
    Link: ({
        href,
        children,
        method,
        as,
        ...rest
    }: {
        href: string;
        children: ReactNode;
        method?: string;
        as?: string;
    }) =>
        as === 'button' ? (
            <button type="button" data-method={method} {...rest}>
                {children}
            </button>
        ) : (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
    router: {
        visit: (...args: unknown[]) => visit(...args),
    },
    usePage: () => ({
        props: sharedPageProps({ clientContext: context, applications }),
    }),
}));

function withContext(overrides: Partial<ClientContext> = {}): ClientContext {
    return {
        workspace: { id: 'workspace-1', name: 'Synthetic Workspace' },
        companies: [
            { id: 'company-1', name: 'Aa Synthetic Client' },
            { id: 'company-2', name: 'Bb Synthetic Client' },
        ],
        current_company_id: 'company-1',
        can_manage: true,
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
        expect(
            screen.getByRole('link', { name: 'Invoices' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Tasks' })).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Manage' }),
        ).toBeInTheDocument();
    });

    /**
     * Manage is the one tab that depends on who is looking, so it is asserted
     * in both directions. A tab that is merely hidden is not a check - the
     * action authorizes on its own - but offering it to someone who would be
     * refused is a broken product.
     */
    it('offers Manage only to a viewer who may manage', () => {
        context = withContext({ can_manage: true });

        const { unmount } = render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        expect(
            screen.getByRole('link', { name: 'Manage' }),
        ).toBeInTheDocument();
        unmount();

        context = withContext({ can_manage: false });

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        expect(screen.queryByRole('link', { name: 'Manage' })).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Overview' }),
        ).toBeInTheDocument();
    });

    /**
     * The switcher names the selected client on its face rather than behind a
     * label, because it is the only thing on a client screen that says which
     * client's money is on display. *Which* companies it may contain is proved
     * server-side, where the payload is built:
     * `ClientContextTest::test_no_other_workspaces_client_reaches_the_switcher`.
     */
    it('names the current client on the switcher itself', () => {
        context = withContext();

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        expect(
            screen.getByRole('button', { name: 'Current client' }),
        ).toHaveTextContent('Aa Synthetic Client');
        expect(
            screen.getByRole('combobox', { name: 'Appearance' }),
        ).toBeInTheDocument();
        expect(
            document.querySelector('[data-appearance-bridge]'),
        ).not.toBeNull();
    });

    /**
     * A client reached by direct link that the switcher may not list - a
     * scoped member's case - must not make the bar claim a different client.
     * Naming the workspace, or the first option, would be worse than saying
     * nothing, because both read as a statement about the page's data.
     */
    it('claims no client when the selected one is not among the options', () => {
        context = withContext({ current_company_id: 'company-9' });

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        const switcher = screen.getByRole('button', { name: 'Current client' });

        expect(switcher).toHaveTextContent('Select a client');
        expect(switcher).not.toHaveTextContent('Aa Synthetic Client');
    });

    /**
     * The one way out of the application, and the only place a client screen
     * says whose session this is. Both were unreachable from this chrome
     * before: `/logout` had a route and a generated action, and nothing
     * rendered it - so an operator working inside a client could not sign out
     * without first navigating to the dashboard.
     */
    it('offers identity, the workspace screens and sign out from the far end', async () => {
        context = withContext();
        applications = [
            { key: 'finance', name: 'Finance', url: 'https://example.com/f' },
        ];

        render(
            <ClientContextLayout active="overview">
                <p>Overview body</p>
            </ClientContextLayout>,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Account and settings' }),
        );

        expect(
            await screen.findByText('operator@example.com'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'All clients' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/clients');
        expect(
            screen.getByRole('menuitem', { name: 'Operations' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/operations');
        expect(
            screen.getByRole('menuitem', { name: 'Finance' }),
        ).toHaveAttribute('href', 'https://example.com/f');
        expect(
            screen.getByRole('menuitem', { name: 'Sign out' }),
        ).toBeInTheDocument();
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
            screen.queryByRole('button', { name: 'Current client' }),
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
            screen.queryByRole('button', { name: 'Current client' }),
        ).toBeNull();
    });
});
