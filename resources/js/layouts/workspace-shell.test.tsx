import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { sharedPageProps } from '@/test/shared-page-props';
import { clientOption, workspaceNavigation } from '@/test/workspace-navigation';
import type { RelyingApplication } from '@/types/auth';
import type { WorkspaceNavigation } from '@/types/navigation';
import WorkspaceShell from './workspace-shell';

const visit = vi.fn();

let navigation: WorkspaceNavigation | null = null;
let applications: RelyingApplication[] = [];

beforeEach(() => {
    applications = [];
    visit.mockClear();
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    // `method` and `as` are Inertia's own props rather than DOM attributes, and
    // `as` changes the element - a non-GET Link renders a button, not an
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
        post: vi.fn(),
    },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: navigation,
            applications,
        }),
    }),
}));

/** The bar, in document order, as a reader scans it left to right. */
function navbarText(): string[] {
    const bar = screen.getByRole('banner');

    return Array.from(bar.querySelectorAll('a, button, [role="combobox"]')).map(
        (element) => (element.textContent ?? '').trim(),
    );
}

describe('workspace shell', () => {
    it('reads left to right: SVC, the client, then that client’s modules', () => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        const order = navbarText();

        expect(order[0]).toBe('SVC');
        expect(order[1]).toBe('Aa Synthetic Client');
        expect(order.slice(2, 6)).toEqual([
            'Client Home',
            'Invoices',
            'Time',
            'Tasks',
        ]);
    });

    it('sends the wordmark to the workspace selector rather than the public home', () => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell>
                <p>Body</p>
            </WorkspaceShell>,
        );

        expect(
            screen.getByRole('link', { name: 'Choose workspace' }),
        ).toHaveAttribute('href', '/app');
    });

    it('puts search, appearance and the account menu after the tabs', () => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        const order = navbarText();

        expect(order.indexOf('Tasks')).toBeLessThan(
            order.findIndex((label) => label.startsWith('Search')),
        );
        expect(
            screen.getByRole('combobox', { name: 'Appearance' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Account and settings' }),
        ).toBeInTheDocument();
    });

    /**
     * The bar names the client, not the tenant. The payload carries no
     * workspace name at all, so this is really a guard on the shape: a future
     * edit that reintroduces one has to add it to the contract first.
     */
    it('names no workspace and offers no directory or operations screen', () => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        for (const label of [
            'Overview',
            'Manage',
            'All clients',
            'Operations',
        ]) {
            expect(screen.queryByText(label)).not.toBeInTheDocument();
        }
    });

    it.each([
        ['home', 'Client Home'],
        ['invoices', 'Invoices'],
        ['time', 'Time'],
        ['tasks', 'Tasks'],
    ] as const)('marks %s as the current page', (module, label) => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell activeModule={module}>
                <p>Body</p>
            </WorkspaceShell>,
        );

        expect(screen.getByRole('link', { name: label })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            screen
                .getAllByRole('link')
                .filter((link) => link.getAttribute('aria-current') === 'page'),
        ).toHaveLength(1);
    });

    /**
     * A module with no destination is a module this viewer has no page for -
     * expenses everywhere for now, and every module but Home on the portal
     * until its own screens land. Hidden rather than disabled: a tab that
     * cannot be opened is a promise the bar cannot keep.
     */
    it('hides a module the viewer has no destination for', () => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        expect(
            screen.queryByRole('link', { name: 'Expenses' }),
        ).not.toBeInTheDocument();
    });

    it('keeps the current module when the client changes', async () => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell activeModule="invoices">
                <p>Body</p>
            </WorkspaceShell>,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Current client' }),
        );
        await userEvent.click(
            screen.getByRole('link', { name: 'Bb Synthetic Client' }),
        );

        expect(visit).toHaveBeenCalledWith(
            '/workspaces/workspace-1/clients/company-2/invoices',
        );
    });

    /**
     * Switching to a client whose route family has no such module lands on its
     * home rather than on a URL assembled in the browser. This is the portal
     * case: the same person can be an operator of one company and a client of
     * another.
     */
    it('falls back to client home when the new client has no such module', async () => {
        navigation = workspaceNavigation({
            clients: [
                clientOption('company-1', 'Aa Synthetic Client'),
                {
                    id: 'company-2',
                    name: 'Bb Synthetic Client',
                    destinations: {
                        home: '/portal/company-2',
                        invoices: null,
                        time: null,
                        expenses: null,
                        tasks: null,
                    },
                },
            ],
        });

        render(
            <WorkspaceShell activeModule="invoices">
                <p>Body</p>
            </WorkspaceShell>,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Current client' }),
        );
        await userEvent.click(
            screen.getByRole('link', { name: 'Bb Synthetic Client' }),
        );

        expect(visit).toHaveBeenCalledWith('/portal/company-2');
    });

    it('filters the client list as you type', async () => {
        navigation = workspaceNavigation();

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Current client' }),
        );
        await userEvent.type(
            screen.getByRole('textbox', { name: 'Filter clients' }),
            'Bb',
        );

        expect(
            screen.getByRole('link', { name: 'Bb Synthetic Client' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Aa Synthetic Client' }),
        ).not.toBeInTheDocument();
    });

    /**
     * The screen that asks the reader to choose a client is the screen that
     * most needs the switcher. Its predecessor rendered the page bare here,
     * which is exactly backwards.
     */
    it('still renders the bar when no client is selected', () => {
        navigation = workspaceNavigation({
            current_client_id: null,
        });

        render(
            <WorkspaceShell>
                <p>Choose a client company to continue</p>
            </WorkspaceShell>,
        );

        expect(
            screen.getByRole('link', { name: 'Choose workspace' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Current client' }),
        ).toHaveTextContent('Select a client');
        expect(
            screen.queryByRole('link', { name: 'Invoices' }),
        ).not.toBeInTheDocument();
    });

    it('renders nothing but the page off a workspace route', () => {
        navigation = null;

        render(
            <WorkspaceShell>
                <p>Body</p>
            </WorkspaceShell>,
        );

        expect(screen.queryByRole('banner')).not.toBeInTheDocument();
        expect(screen.getByText('Body')).toBeInTheDocument();
    });

    it('signs out with a POST, and offers the provider’s other apps', async () => {
        navigation = workspaceNavigation();
        applications = [
            {
                key: 'other',
                name: 'Synthetic Sibling App',
                url: 'https://example.test/app',
            },
        ];

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Account and settings' }),
        );

        expect(
            await screen.findByRole('menuitem', { name: 'Sign out' }),
        ).toHaveAttribute('data-method', 'post');
        expect(
            screen.getByRole('menuitem', { name: 'Synthetic Sibling App' }),
        ).toHaveAttribute('href', 'https://example.test/app');
    });

    it('offers workspace settings only to a viewer who may manage the workspace', async () => {
        navigation = workspaceNavigation({
            workspace_settings_href: '/workspaces/workspace-1/settings',
            permissions: {
                manage_workspace: false,
                create_client: false,
                manage_current_client: false,
            },
        });

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Account and settings' }),
        );

        expect(
            await screen.findByRole('menuitem', { name: 'Sign out' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Workspace settings' }),
        ).not.toBeInTheDocument();
    });

    it('offers adding a client only to a viewer who may create one', async () => {
        navigation = workspaceNavigation({
            permissions: {
                manage_workspace: false,
                create_client: false,
                manage_current_client: false,
            },
        });

        render(
            <WorkspaceShell activeModule="home">
                <p>Body</p>
            </WorkspaceShell>,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Current client' }),
        );

        expect(
            screen.queryByRole('button', { name: 'Add client' }),
        ).not.toBeInTheDocument();
    });
});
