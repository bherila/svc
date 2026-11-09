import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PackagesIndex from '@/pages/packages/index';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import {
    listItem,
    packageTerms,
    packageVersion,
} from '@/test/package-fixtures';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { PackagesIndexProps } from '@/types/packages';

const nav = vi.hoisted(() => ({
    packagesHref: '/workspaces/workspace-1/packages' as string | null,
}));

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
    router: { visit: vi.fn() },
    usePage: () => ({
        props: sharedPageProps({
            workspaceNavigation: workspaceNavigation({
                current_client_id: null,
                packages_href: nav.packagesHref,
            }),
        }),
    }),
}));

const LONG = 'Unbroken'.repeat(14);

function props(
    overrides: Partial<PackagesIndexProps> = {},
): PackagesIndexProps {
    const published = packageVersion(3, packageTerms(), true);

    return {
        packages: [
            listItem({
                id: 'package-1',
                status: 'published',
                published: { ...published, setup_fee_total: 15000 },
                draft: null,
                public_url:
                    'https://svc.example.test/s/tok123/synthetic-hosting',
                edit_url: '/workspaces/workspace-1/packages/package-1',
            }),
            listItem({
                id: 'package-2',
                link_name: 'pro-hosting',
                status: 'draft',
                draft: packageVersion(
                    1,
                    packageTerms({ name: 'Pro Hosting', summary: null }),
                ),
                edit_url: '/workspaces/workspace-1/packages/package-2',
            }),
        ],
        new_package_url: '/workspaces/workspace-1/packages/new',
        catalog_url: 'https://svc.example.test/s/tok123',
        stripe_connected: false,
        ...overrides,
    };
}

describe('packages list', () => {
    beforeEach(() => {
        nav.packagesHref = '/workspaces/workspace-1/packages';
    });

    it('links every action at the url the server sent', () => {
        render(<PackagesIndex {...props()} />);

        expect(
            screen.getByRole('link', { name: /New package/ }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/packages/new');
        expect(
            screen.getByRole('link', { name: 'Edit Synthetic Hosting' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/packages/package-1');
        expect(
            screen.getByRole('link', { name: 'Edit Pro Hosting' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/packages/package-2');
    });

    it('shows price, status and version as words', () => {
        render(<PackagesIndex {...props()} />);

        const row = screen.getByRole('row', { name: /Synthetic Hosting/ });

        expect(within(row).getByText('$45.00 per month')).toBeInTheDocument();
        expect(within(row).getByText('setup $150.00')).toBeInTheDocument();
        expect(within(row).getByText('Published')).toBeInTheDocument();
        expect(within(row).getByText('Listed')).toBeInTheDocument();
        expect(screen.getByText('Draft')).toBeInTheDocument();
        expect(screen.queryByText('published')).not.toBeInTheDocument();
    });

    it('copies the public link of a published package and the catalog link', async () => {
        const user = userEvent.setup();
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
        render(<PackagesIndex {...props()} />);

        // A draft has no public link to copy.
        expect(
            screen.getAllByRole('button', { name: /^Copy link/ }),
        ).toHaveLength(2);

        await user.click(
            screen.getByRole('button', {
                name: 'Copy link: Synthetic Hosting',
            }),
        );
        expect(writeText).toHaveBeenLastCalledWith(
            'https://svc.example.test/s/tok123/synthetic-hosting',
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Copy link: the public catalog',
            }),
        );
        expect(writeText).toHaveBeenLastCalledWith(
            'https://svc.example.test/s/tok123',
        );
    });

    it('says Stripe is not connected', () => {
        render(<PackagesIndex {...props()} />);

        expect(screen.getByText(/Not connected\./)).toBeInTheDocument();
    });

    it('offers the Clients / Packages row only when the server sent packages_href', () => {
        const { unmount } = render(<PackagesIndex {...props()} />);

        const tabs = screen.getByRole('navigation', {
            name: 'Clients and packages',
        });
        expect(
            within(tabs).getByRole('link', { name: 'All clients' }),
        ).toHaveAttribute('href', '/workspaces/workspace-1/clients');
        expect(
            within(tabs).getByRole('link', { name: 'Packages' }),
        ).toHaveAttribute('aria-current', 'page');
        unmount();

        nav.packagesHref = null;
        render(<PackagesIndex {...props()} />);
        expect(
            screen.queryByRole('navigation', { name: 'Clients and packages' }),
        ).not.toBeInTheDocument();
    });

    it('[X-4] draws inside the workspace shell and survives hostile names', () => {
        const hostile = props({
            packages: [
                listItem({
                    link_name: LONG.toLowerCase(),
                    status: 'published',
                    published: packageVersion(
                        1,
                        packageTerms({ name: LONG, summary: LONG.repeat(3) }),
                        true,
                    ),
                    draft: null,
                    public_url: `https://svc.example.test/s/tok123/${LONG.toLowerCase()}`,
                }),
            ],
            catalog_url: `https://svc.example.test/s/${LONG.toLowerCase()}`,
        });
        const { container } = render(<PackagesIndex {...hostile} />);

        const main = container.querySelector('main');
        expect(main).not.toBeNull();
        expect(main?.className).toContain('max-w-6xl');
        expect(main?.className).not.toMatch(/max-w-(?!6xl)/);
        expect(main?.className).toContain('grid-cols-1');
        expect(screen.getByRole('banner')).toBeInTheDocument();
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(horizontalOverflowRisks(container)).toEqual([]);
    });

    it('invites the first package when there are none', () => {
        render(<PackagesIndex {...props({ packages: [] })} />);

        expect(screen.getByText(/No packages yet/)).toBeInTheDocument();
    });
});
