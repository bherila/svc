import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import WorkspaceSelector from '@/pages/workspaces/index';
import { sharedPageProps } from '@/test/shared-page-props';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: vi.fn() },
    usePage: () => ({ props: sharedPageProps() }),
}));

describe('workspace selector', () => {
    it('offers one row per workspace and nothing beneath it', () => {
        render(
            <WorkspaceSelector
                workspaces={[
                    {
                        id: 'workspace-1',
                        name: 'Aa Synthetic Workspace',
                        enter_href: '/workspaces/workspace-1',
                    },
                    {
                        id: 'workspace-2',
                        name: 'Bb Synthetic Workspace',
                        enter_href: '/workspaces/workspace-2',
                    },
                ]}
            />,
        );

        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(
            screen.getByRole('link', { name: /Aa Synthetic Workspace/ }),
        ).toHaveAttribute('href', '/workspaces/workspace-1');
    });

    /**
     * The wordmark returns here from everywhere else, so on this screen it has
     * nowhere to go. A link that reloads the page you are on reads as broken.
     */
    it('does not link the wordmark to itself', () => {
        render(<WorkspaceSelector workspaces={[]} />);

        expect(screen.getByText('SVC').tagName).toBe('SPAN');
    });

    it('says what to do when there is no workspace yet', () => {
        render(<WorkspaceSelector workspaces={[]} />);

        expect(screen.queryByRole('listitem')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /New workspace/ }),
        ).toBeInTheDocument();
    });

    /**
     * The theme control and the account menu are here because they mean the
     * same thing on every screen; the switcher and the tabs are not, because
     * there is no workspace yet for them to be about.
     */
    it('carries the global controls and no client chrome', () => {
        render(<WorkspaceSelector workspaces={[]} />);

        expect(
            screen.getByRole('combobox', { name: 'Appearance' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Account and settings' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Current client' }),
        ).not.toBeInTheDocument();
    });
});
