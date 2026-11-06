import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkspaceReviewers from '@/pages/workspaces/reviewers';
import { horizontalOverflowRisks } from '@/test/horizontal-overflow';
import { sharedPageProps } from '@/test/shared-page-props';
import { workspaceNavigation } from '@/test/workspace-navigation';
import type { ReviewerGrantsProps } from '@/types/review';

const api = vi.hoisted(() => ({ apiRequest: vi.fn() }));
const refresh = vi.hoisted(() => ({ refreshPageProps: vi.fn() }));

vi.mock('@/lib/api', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    apiRequest: api.apiRequest,
}));
vi.mock('@/lib/refresh-page-props', () => refresh);
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
            }),
        }),
    }),
}));

const LONG = 'Unbroken'.repeat(14);

function props(
    overrides: Partial<ReviewerGrantsProps> = {},
): ReviewerGrantsProps {
    return {
        workspaceName: 'Synthetic Provider',
        store_url: '/workspaces/ws-1/reviewers',
        clients: [
            {
                id: 'client-1',
                name: 'Synthetic Vet',
                projects: [{ id: 'project-1', name: 'Synthetic Project' }],
                contacts: [
                    {
                        id: 'contact-1',
                        name: 'Ben Reviewer',
                        email: 'ben@x.test',
                    },
                ],
            },
        ],
        workspaces: [{ id: 'ws-2', name: 'Synthetic Partner' }],
        grants: [
            {
                id: 'grant-1',
                client: 'Synthetic Vet',
                project: null,
                kind: 'contact',
                grantee: 'Ben Reviewer',
                detail: 'ben@x.test',
                granted_at: '2026-11-06T12:00:00Z',
                revoked_at: null,
                revoke_url: '/workspaces/ws-1/reviewers/grant-1',
            },
            {
                id: 'grant-2',
                client: 'Synthetic Vet',
                project: 'Synthetic Project',
                kind: 'workspace',
                grantee: 'Synthetic Partner',
                detail: null,
                granted_at: '2026-10-06T12:00:00Z',
                revoked_at: '2026-10-20T12:00:00Z',
                revoke_url: null,
            },
        ],
        ...overrides,
    };
}

describe('reviewer grants', () => {
    beforeEach(() => {
        api.apiRequest.mockReset();
        refresh.refreshPageProps.mockReset().mockResolvedValue(undefined);
    });

    it('lists who has access and who had it', () => {
        render(<WorkspaceReviewers {...props()} />);

        const active = screen.getByRole('list', { name: 'With access' });

        expect(within(active).getByText('Ben Reviewer')).toBeInTheDocument();
        expect(
            within(active).getByText(/Contact · ben@x.test · Synthetic Vet/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Taken back (1)', { exact: false }),
        ).toBeInTheDocument();
    });

    it('gives a contact access to a client, at the url the server sent', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 201, data: {} });
        render(<WorkspaceReviewers {...props()} />);

        await user.click(screen.getByLabelText('Contact'));
        await user.click(
            await screen.findByRole('option', {
                name: 'Ben Reviewer · ben@x.test',
            }),
        );
        await user.click(screen.getByRole('button', { name: 'Give access' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/workspaces/ws-1/reviewers',
                {
                    client_id: 'client-1',
                    project_id: null,
                    kind: 'contact',
                    contact_id: 'contact-1',
                    workspace_id: null,
                },
            ),
        );
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('gives a workspace access to one project', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 201, data: {} });
        render(<WorkspaceReviewers {...props()} />);

        await user.click(screen.getByLabelText('Projects'));
        await user.click(
            await screen.findByRole('option', { name: 'Synthetic Project' }),
        );
        await user.click(screen.getByLabelText('Who reviews'));
        await user.click(
            await screen.findByRole('option', {
                name: 'A workspace you share a project with',
            }),
        );
        await user.click(screen.getByLabelText('Workspace'));
        await user.click(
            await screen.findByRole('option', { name: 'Synthetic Partner' }),
        );
        await user.click(screen.getByRole('button', { name: 'Give access' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'POST',
                '/workspaces/ws-1/reviewers',
                {
                    client_id: 'client-1',
                    project_id: 'project-1',
                    kind: 'workspace',
                    contact_id: null,
                    workspace_id: 'ws-2',
                },
            ),
        );
    });

    it('cannot be submitted before someone is chosen', () => {
        render(<WorkspaceReviewers {...props()} />);

        expect(
            screen.getByRole('button', { name: 'Give access' }),
        ).toBeDisabled();
    });

    it('prints the server’s refusal and does not refresh', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({
            ok: false,
            status: 422,
            message: 'The given data was invalid.',
            errors: {
                contact_id: [
                    'That address belongs to someone on your own team.',
                ],
            },
        });
        render(<WorkspaceReviewers {...props()} />);

        await user.click(screen.getByLabelText('Contact'));
        await user.click(
            await screen.findByRole('option', {
                name: 'Ben Reviewer · ben@x.test',
            }),
        );
        await user.click(screen.getByRole('button', { name: 'Give access' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'That address belongs to someone on your own team.',
        );
        expect(refresh.refreshPageProps).not.toHaveBeenCalled();
    });

    it('takes access back only after confirming', async () => {
        const user = userEvent.setup();
        api.apiRequest.mockResolvedValue({ ok: true, status: 204, data: null });
        render(<WorkspaceReviewers {...props()} />);

        await user.click(screen.getByRole('button', { name: 'Take back…' }));
        expect(api.apiRequest).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Take back' }));

        await waitFor(() =>
            expect(api.apiRequest).toHaveBeenCalledWith(
                'DELETE',
                '/workspaces/ws-1/reviewers/grant-1',
                {},
            ),
        );
        expect(refresh.refreshPageProps).toHaveBeenCalled();
    });

    it('offers no way to take back what is already taken back', () => {
        render(<WorkspaceReviewers {...props()} />);

        expect(
            screen.getAllByRole('button', { name: 'Take back…' }),
        ).toHaveLength(1);
    });

    it('asks for a client first when there is none, and says why a list is empty', () => {
        render(
            <WorkspaceReviewers
                {...props({ clients: [], workspaces: [], grants: [] })}
            />,
        );

        expect(screen.getByText(/Add a client first/)).toBeInTheDocument();
        expect(
            screen.getByText(/Nobody outside your team can see/),
        ).toBeInTheDocument();
    });

    it('holds with unbroken names and no overflow risk', () => {
        const hostile = props();
        hostile.clients[0].name = LONG;
        hostile.clients[0].projects[0].name = LONG;
        hostile.clients[0].contacts[0] = {
            id: 'contact-1',
            name: LONG,
            email: `${LONG}@x.test`,
        };
        hostile.grants = hostile.grants.map((grant) => ({
            ...grant,
            client: LONG,
            grantee: LONG,
            detail: `${LONG}@x.test`,
        }));

        const { container } = render(<WorkspaceReviewers {...hostile} />);

        expect(horizontalOverflowRisks(container)).toEqual([]);
    });
});
